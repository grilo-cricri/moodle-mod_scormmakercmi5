<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_scormmakercmi5\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/cm_api.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\router;
use mod_scormmakercmi5\local\runtime\actor;
use mod_scormmakercmi5\local\runtime\launcher;
use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verifier;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\backup_scormmakercmi5_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_scormmakercmi5_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_scormmakercmi5_activity_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_scormmakercmi5_activity_structure_step::class)]
/**
 * Tests for backup and restore (design §12, NF7): course backup/restore with and without user data, activity
 * duplication, and the recycle bin (activity and whole course).
 *
 * Scenario: a signed package v1 (block b1 with AU 1, AU 2 at the root); the learner passes AU 1 (0.9) and leaves the
 * session open (initialized), writes a State document with and one without registration; then the package is replaced
 * by a signed v2 (one more AU) — the registration stays pinned to v1. Each version has one itemdef row.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_scormmakercmi5_activity_task
 * @covers     \backup_scormmakercmi5_activity_structure_step
 * @covers     \restore_scormmakercmi5_activity_task
 * @covers     \restore_scormmakercmi5_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string[] Tables of learner data. */
    private const USER_TABLES = ['reg', 'regau', 'regblock', 'session', 'stmt', 'state'];

    /**
     * Without user data: only the current version comes back, with the same structure, hashes, item definitions and
     * files, and new LMS IRIs; no learner data.
     */
    public function test_without_user_data(): void {
        global $DB;
        $this->setup_scenario();
        $old = $this->instance_row();
        $oldcurrent = $DB->get_record('scormmakercmi5_pkg', ['id' => $old->currentpkgid], '*', MUST_EXIST);

        $courseid = $this->backup_and_restore($this->course, false);

        $new = $this->restored_instance($courseid);
        $pkgs = $DB->get_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $new->id]);
        $this->assertCount(1, $pkgs);
        $pkg = reset($pkgs);
        $this->assertSame((int)$pkg->id, (int)$new->currentpkgid);
        $this->assertSame(2, (int)$pkg->versionnum);
        $fields = ['filename', 'zipcontenthash', 'totalbytes', 'filecount', 'publishercourseid', 'cmi5xml', 'sigstatus',
            'sigkid', 'manifest', 'signature', 'smcourseid', 'smversion'];
        foreach ($fields as $field) {
            $this->assertSame((string)$oldcurrent->$field, (string)$pkg->$field, $field);
        }
        $this->assertSame('verified', $pkg->sigstatus);
        $this->assert_rechecked_on_target($pkg);

        foreach (['au', 'block', 'pkgfile', 'itemdef'] as $table) {
            $this->assertSame(
                $DB->count_records('scormmakercmi5_' . $table, ['pkgid' => $oldcurrent->id]),
                $DB->count_records('scormmakercmi5_' . $table, ['pkgid' => $pkg->id]),
                $table
            );
        }
        $this->assertEquals($this->pkgfiles((int)$oldcurrent->id), $this->pkgfiles((int)$pkg->id));
        $this->assertSame(
            $this->area_count($this->instance, 'content', (int)$oldcurrent->id),
            $this->area_count($new, 'content', (int)$pkg->id)
        );
        $this->assertSame(1, $this->area_count($new, 'package', (int)$pkg->id));

        $this->assertNotEmpty($new->lmscourseiri);
        $this->assertNotSame($old->lmscourseiri, $new->lmscourseiri);
        $oldaus = $this->aus_of((int)$oldcurrent->id);
        $newaus = $this->aus_of((int)$pkg->id);
        $this->assertSame(array_column($oldaus, 'publisherid'), array_column($newaus, 'publisherid'));
        $this->assertEmpty(array_intersect(array_column($oldaus, 'lmsactivityid'), array_column($newaus, 'lmsactivityid')));
        $oldblocks = $this->blocks_of((int)$oldcurrent->id);
        $newblocks = $this->blocks_of((int)$pkg->id);
        $this->assertCount(count($oldblocks), $newblocks);
        $this->assertEmpty(array_intersect(array_column($oldblocks, 'lmsiri'), array_column($newblocks, 'lmsiri')));
        // The AU inside the block points to the restored block.
        $blockids = array_column($newblocks, 'id');
        foreach ($newaus as $au) {
            $this->assertTrue((int)$au->blockid === 0 || in_array($au->blockid, $blockids), 'blockid mapped');
        }

        foreach (self::USER_TABLES as $table) {
            $this->assertSame(0, $this->instance_rows($table, (int)$new->id), $table);
        }
    }

    /**
     * With user data: both versions, the learner data with every id mapped, the IRIs kept, the registration pinned to
     * the restored v1, currentpkgid on the restored v2, the grade recomputed, and a launch resumes the registration.
     *
     * Restored on the same site, the registration, session and statement UUIDs would collide with the original's
     * (unique columns): the copy gets new ones and the payloads and LaunchData are rewritten with them; the rest of
     * every payload is byte for byte the original. The State document written without a registration is not copied:
     * its key (user, activity IRI, stateId) is the original's, which the copy shares and the AU of the copy reads.
     */
    public function test_with_user_data(): void {
        global $CFG, $DB;
        $this->setup_scenario();
        $old = $this->instance_row();
        // Subproject D: the new columns travel with the backup. The structure holds a view.php link of this module,
        // which the link encoders would rewrite (and the restore remap) if the column were not sent in base64.
        $cm = get_coursemodule_from_instance('scormmakercmi5', $old->id, 0, false, MUST_EXIST);
        $structure = json_encode(['v' => 1, 'blocos' => [['id' => 'b1', 'texto' => '<a href="' . $CFG->wwwroot
            . '/mod/scormmakercmi5/view.php?id=' . $cm->id . '">aula</a>']]], JSON_UNESCAPED_SLASHES);
        $DB->set_field('scormmakercmi5_pkg', 'richstructure', $structure, ['scormmakercmi5id' => $old->id]);
        $DB->set_field('scormmakercmi5_stmt', 'smblock', 'b1', ['scormmakercmi5id' => $old->id]);
        $DB->set_field('scormmakercmi5_stmt', 'smblocktype', 'video', ['scormmakercmi5id' => $old->id]);
        $DB->set_field('scormmakercmi5_stmt', 'smprofilever', 2, ['scormmakercmi5id' => $old->id]);
        $oldpkgs = $this->pkgs_by_version((int)$old->id);
        $oldreg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $old->id], '*', MUST_EXIST);
        $oldsessions = array_values($DB->get_records('scormmakercmi5_session', ['regid' => $oldreg->id], 'id'));
        $oldstmts = array_values($DB->get_records('scormmakercmi5_stmt', ['scormmakercmi5id' => $old->id], 'id'));
        $select = "scormmakercmi5id = ? AND registration <> ''";
        $oldstates = array_values($DB->get_records_select('scormmakercmi5_state', $select, [$old->id], 'id'));
        $noreg = $DB->count_records('scormmakercmi5_state', ['registration' => '']);
        $this->assertSame(1, $noreg);
        $oldcounts = $this->user_counts((int)$old->id);
        $oldcounts['state'] -= $noreg;
        $prefs = $DB->count_records('scormmakercmi5_agentprof');
        // A gradebook grade that differs from the AU scores: the restored grade must be recomputed, not copied.
        $tampered = (object)['userid' => $this->user->id, 'rawgrade' => 10];
        grade_update('mod/scormmakercmi5', $this->course->id, 'mod', 'scormmakercmi5', $old->id, 0, $tampered);
        $grades = grade_get_grades($this->course->id, 'mod', 'scormmakercmi5', (int)$old->id, (int)$this->user->id);
        $this->assertEqualsWithDelta(10.0, (float)reset($grades->items)->grades[$this->user->id]->grade, 0.00001);

        $courseid = $this->backup_and_restore($this->course, true);

        $new = $this->restored_instance($courseid);
        $richstructures = $DB->get_fieldset_select('scormmakercmi5_pkg', 'richstructure', 'scormmakercmi5id = ?', [$new->id]);
        $this->assertSame([$structure], array_values(array_unique($richstructures)), 'byte for byte, link untouched');
        $fields = 'id, smblock, smblocktype, smprofilever';
        foreach ($DB->get_records('scormmakercmi5_stmt', ['scormmakercmi5id' => $new->id], 'id', $fields) as $row) {
            $this->assertSame(['b1', 'video', 2], [$row->smblock, $row->smblocktype, (int)$row->smprofilever]);
        }
        $pkgs = $this->pkgs_by_version((int)$new->id);
        $this->assertSame([1, 2], array_keys($pkgs));
        $this->assertSame((int)$pkgs[2]->id, (int)$new->currentpkgid);
        $this->assertSame($old->lmscourseiri, $new->lmscourseiri);
        foreach ([1, 2] as $v) {
            $this->assert_rechecked_on_target($pkgs[$v]);
            $this->assertSame(
                array_column($this->aus_of((int)$oldpkgs[$v]->id), 'lmsactivityid'),
                array_column($this->aus_of((int)$pkgs[$v]->id), 'lmsactivityid'),
                "lmsactivityid v{$v}"
            );
            $this->assertSame(
                array_column($this->blocks_of((int)$oldpkgs[$v]->id), 'lmsiri'),
                array_column($this->blocks_of((int)$pkgs[$v]->id), 'lmsiri'),
                "lmsiri v{$v}"
            );
            $this->assertSame(
                $this->area_count($this->instance, 'content', (int)$oldpkgs[$v]->id),
                $this->area_count($new, 'content', (int)$pkgs[$v]->id),
                "content v{$v}"
            );
        }
        $this->assertSame($oldcounts, $this->user_counts((int)$new->id));
        $this->assertSame($prefs, $DB->count_records('scormmakercmi5_agentprof'), 'agentprof is not restored');

        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $new->id], '*', MUST_EXIST);
        $this->assertSame((int)$this->user->id, (int)$reg->userid);
        $this->assertSame((int)$pkgs[1]->id, (int)$reg->pkgid);
        $this->assertNotSame($oldreg->registration, $reg->registration);
        $this->assertSame($oldreg->satisfied, $reg->satisfied);
        $this->assertSame($oldreg->statementcount, $reg->statementcount);

        $v1aus = array_column($this->aus_of((int)$pkgs[1]->id), 'id');
        $v1blocks = array_column($this->blocks_of((int)$pkgs[1]->id), 'id');
        foreach ($DB->get_records('scormmakercmi5_regau', ['regid' => $reg->id]) as $row) {
            $this->assertContains($row->auid, $v1aus);
        }
        $regblocks = $DB->get_records('scormmakercmi5_regblock', ['regid' => $reg->id]);
        $this->assertNotEmpty($regblocks);
        foreach ($regblocks as $row) {
            $this->assertContains($row->blockid, $v1blocks);
        }

        $sessions = array_values($DB->get_records('scormmakercmi5_session', ['regid' => $reg->id], 'id'));
        $map = [$oldreg->registration => $reg->registration];
        foreach ($sessions as $i => $session) {
            $this->assertContains($session->auid, $v1aus);
            $this->assertSame((int)$this->user->id, (int)$session->userid);
            $this->assertNotSame($oldsessions[$i]->sessionuuid, $session->sessionuuid);
            $map[$oldsessions[$i]->sessionuuid] = $session->sessionuuid;
        }
        $sessionids = array_column($sessions, 'id');

        $stmts = array_values($DB->get_records('scormmakercmi5_stmt', ['scormmakercmi5id' => $new->id], 'id'));
        $this->assertNotEmpty($stmts);
        foreach ($stmts as $i => $stmt) {
            $orig = $oldstmts[$i];
            $this->assertSame((int)$pkgs[1]->id, (int)$stmt->pkgid);
            $this->assertSame((int)$reg->id, (int)$stmt->regid);
            $this->assertSame((int)$this->user->id, (int)$stmt->userid);
            $this->assertContains($stmt->auid, $v1aus);
            $this->assertTrue($stmt->sessionid === null || in_array($stmt->sessionid, $sessionids), 'sessionid mapped');
            $this->assertNotSame($orig->statementid, $stmt->statementid);
            foreach (['verb', 'objecthash', 'objectid', 'timestampms', 'storedms', 'source', 'cmi5kind'] as $field) {
                $this->assertSame((string)$orig->$field, (string)$stmt->$field, $field);
            }
            $expected = strtr($orig->payload, $map + [$orig->statementid => $stmt->statementid]);
            $this->assertSame($expected, $stmt->payload);
        }

        $states = array_values($DB->get_records('scormmakercmi5_state', ['scormmakercmi5id' => $new->id], 'id'));
        $this->assertCount(count($oldstates), $states);
        foreach ($states as $i => $state) {
            $orig = $oldstates[$i];
            $this->assertSame((int)$this->user->id, (int)$state->userid);
            $this->assertSame($orig->activityhash, $state->activityhash);
            $this->assertSame($orig->stateid, $state->stateid);
            $this->assertSame($reg->registration, $state->registration);
            $this->assertSame((int)$reg->id, (int)$state->regid);
            $this->assertSame(strtr($orig->content, $map), $state->content);
            $this->assertSame(sha1($state->content), $state->etag);
        }
        $this->assertSame($noreg, $DB->count_records('scormmakercmi5_state', ['registration' => '']));

        // The grade comes from the restored scores.
        $grades = grade_get_grades($courseid, 'mod', 'scormmakercmi5', (int)$new->id, (int)$this->user->id);
        $this->assertEqualsWithDelta(90.0, (float)reset($grades->items)->grades[$this->user->id]->grade, 0.00001);

        // A new launch on the copy resumes the registration on v1.
        $this->course = get_course($courseid);
        $this->instance = $new;
        $this->instance->cmid = get_coursemodule_from_instance('scormmakercmi5', $new->id)->id;
        $v1au = $this->aus_of((int)$pkgs[1]->id)[0];
        [$session] = $this->launch_with_token((int)$this->user->id, (int)$v1au->id);
        $this->assertSame((int)$reg->id, (int)$session->regid);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_reg', ['scormmakercmi5id' => $new->id]));
    }

    /**
     * An open session (initialized) comes back abandoned, with no fetch key or token.
     */
    public function test_open_session_comes_back_abandoned(): void {
        global $DB;
        $this->setup_scenario();
        $open = $DB->get_records_select('scormmakercmi5_session', 'status IN (0, 1)');
        $this->assertNotEmpty($open);
        $this->assertNotNull(reset($open)->tokenhash);

        $courseid = $this->backup_and_restore($this->course, true);

        $new = $this->restored_instance($courseid);
        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $new->id], '*', MUST_EXIST);
        $sessions = $DB->get_records('scormmakercmi5_session', ['regid' => $reg->id]);
        $this->assertCount(count($open), $sessions);
        foreach ($sessions as $session) {
            $this->assertSame(3, (int)$session->status);
            $this->assertNull($session->fetchkeyhash);
            $this->assertNull($session->tokenhash);
            $this->assertSame(0, (int)$session->tokenexpires);
            $this->assertGreaterThan(0, (int)$session->timeendedms);
        }
    }

    /**
     * The backup carries neither the Agent Profile, nor fetch keys, nor tokens, nor the recheck state; the payloads
     * are not exposed to the link encoders (the LaunchData returnURL is a view.php link of this module).
     */
    public function test_backup_content(): void {
        global $CFG, $DB;
        $this->setup_scenario();
        $this->assertGreaterThan(0, $DB->count_records('scormmakercmi5_agentprof'));

        $backupid = $this->backup($this->course, true);

        $files = glob($CFG->tempdir . '/backup/' . $backupid . '/activities/scormmakercmi5_*/scormmakercmi5.xml');
        $this->assertCount(1, $files);
        $xml = file_get_contents($files[0]);
        $this->assertStringContainsString('<regs>', $xml);
        $this->assertStringContainsString('<stmts>', $xml);
        $absents = ['agentprof', 'tokenhash', 'fetchkeyhash', 'tokenexpires', 'recheckstatus', 'timefullrecheck',
            'fullrecheckstatus', 'SCORMMAKERCMI5VIEWBYID'];
        foreach ($absents as $absent) {
            $this->assertStringNotContainsString($absent, $xml);
        }
    }

    /**
     * An AU outside the package travels with its origin; the restore takes the origin from the url again (a backup
     * cannot open the CORS of the site to another origin) and the version comes back unsigned, reason external_au,
     * even when the backup says verified.
     */
    public function test_external_au(): void {
        global $CFG, $DB;
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html?x=1#y'],
        ]), ['signed' => true]);
        $old = $this->instance_row();
        $origin = $DB->get_field('scormmakercmi5_au', 'auorigin', ['pkgid' => $old->currentpkgid]);
        $this->assertSame('https://cursos.exemplo.com', $origin);

        $backupid = $this->backup($this->course, false);
        $files = glob($CFG->tempdir . '/backup/' . $backupid . '/activities/scormmakercmi5_*/scormmakercmi5.xml');
        $xml = file_get_contents($files[0]);
        $this->assertStringContainsString('<auorigin>https://cursos.exemplo.com</auorigin>', $xml);
        // A hand-made backup: another origin, and a version that claims to be verified.
        $forged = '<auorigin>https://malicioso.exemplo</auorigin>';
        $xml = str_replace('<auorigin>https://cursos.exemplo.com</auorigin>', $forged, $xml);
        $xml = str_replace('<sigstatus>unsigned</sigstatus>', '<sigstatus>verified</sigstatus>', $xml);
        file_put_contents($files[0], $xml);
        $courseid = $this->restore($backupid, $this->course, false);

        $new = $this->restored_instance($courseid);
        $au = $DB->get_record('scormmakercmi5_au', ['pkgid' => $new->currentpkgid], '*', MUST_EXIST);
        $this->assertSame('https://cursos.exemplo.com', $au->auorigin);
        $this->assertSame('https://cursos.exemplo.com/au/index.html?x=1#y', $au->url);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $new->currentpkgid], '*', MUST_EXIST);
        $this->assertSame('unsigned', $pkg->sigstatus);
        $this->assertSame('external_au', $pkg->sigreason);
        $this->assertFalse($DB->record_exists('scormmakercmi5_au', ['auorigin' => 'https://malicioso.exemplo']));
    }

    /**
     * With allowexternalau off, a restored AU outside the package keeps no origin (no CORS opens to it), the restore
     * logs a warning, and launching it gives the readable err_externalauoff.
     */
    public function test_external_au_restore_when_disabled(): void {
        global $DB;
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html'],
        ]));
        $backupid = $this->backup($this->course, false);
        set_config('allowexternalau', 0, 'mod_scormmakercmi5');
        $courseid = $this->restore($backupid, $this->course, false);

        $new = $this->restored_instance($courseid);
        $au = $DB->get_record('scormmakercmi5_au', ['pkgid' => $new->currentpkgid], '*', MUST_EXIST);
        $this->assertNull($au->auorigin);
        $this->assertSame('https://cursos.exemplo.com/au/index.html', $au->url);
        $cm = get_fast_modinfo($courseid)->get_cm($new->cmid);
        try {
            launcher::launch($cm, $new, (int)$au->id, (int)$this->user->id, 'Normal');
            $this->fail('launched');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_externalauoff', $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
        }
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session', ['auid' => $au->id, 'kind' => launcher::KIND_LAUNCH]));
    }

    /**
     * A restored signed package with intact files rechecks as verified (keyring of the target site).
     */
    public function test_restored_package_rechecks_verified(): void {
        global $DB;
        $this->setup_scenario();
        $courseid = $this->backup_and_restore($this->course, true);
        $new = $this->restored_instance($courseid);
        foreach ($this->pkgs_by_version((int)$new->id) as $pkg) {
            $result = verifier::recheck($pkg, keyring::from_config(), PHP_INT_MAX);
            $this->assertSame('verified', $result->status, 'v' . $pkg->versionnum);
            $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id]);
            $this->assertSame('verified', $row->recheckstatus);
            $this->assertSame('verified', $row->fullrecheckstatus);
        }
    }

    /**
     * A registration left out of the restore (its user cannot be mapped) takes its children with it: nothing of it is
     * attached to the registration of the learner restored before it.
     */
    public function test_skipped_registration_keeps_its_children_out(): void {
        global $CFG, $DB;
        $this->setup_scenario();
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $instance = $this->instance_row();
        $this->learn_au((int)$other->id, (int)$this->aus_of((int)$instance->currentpkgid)[0]->id, vocab::VERB_COMPLETED);
        $mine = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $this->user->id]);
        $theirs = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $other->id]);
        $this->assertGreaterThan((int)$mine->id, (int)$theirs->id, 'the skipped registration comes after a restored one');
        $before = $this->reg_children((int)$mine->id);
        $this->assertGreaterThan(0, $this->reg_children((int)$theirs->id)['regau']);

        $backupid = $this->backup($this->course, true);
        // The other learner's registration points to a user that is not in the backup.
        $files = glob($CFG->tempdir . '/backup/' . $backupid . '/activities/scormmakercmi5_*/scormmakercmi5.xml');
        $xml = file_get_contents($files[0]);
        $pattern = '#(<reg id="' . $theirs->id . '">\s*<userid>)' . $other->id . '(</userid>)#';
        $xml = preg_replace($pattern, '${1}999999${2}', $xml, -1, $count);
        $this->assertSame(1, $count);
        file_put_contents($files[0], $xml);
        $courseid = $this->restore($backupid, $this->course, true);

        $new = $this->restored_instance($courseid);
        $regs = $DB->get_records('scormmakercmi5_reg', ['scormmakercmi5id' => $new->id]);
        $this->assertCount(1, $regs);
        $reg = reset($regs);
        $this->assertSame((int)$this->user->id, (int)$reg->userid);
        $this->assertSame($before, $this->reg_children((int)$reg->id));
        foreach (['stmt', 'state'] as $table) {
            $params = ['scormmakercmi5id' => $new->id, 'userid' => $other->id];
            $this->assertSame(0, $DB->count_records('scormmakercmi5_' . $table, $params), $table);
        }
    }

    /**
     * Restoring without user data from a backup made with user data: only the current version and its files, no
     * learner data.
     */
    public function test_without_user_data_from_a_backup_with_user_data(): void {
        global $DB;
        $this->setup_scenario();
        $old = $this->instance_row();

        $courseid = $this->restore($this->backup($this->course, true), $this->course, false);

        $new = $this->restored_instance($courseid);
        $pkgs = $DB->get_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $new->id]);
        $this->assertCount(1, $pkgs);
        $pkg = reset($pkgs);
        $this->assertSame((int)$pkg->id, (int)$new->currentpkgid);
        $this->assertSame(2, (int)$pkg->versionnum);
        foreach (['au', 'block', 'pkgfile', 'itemdef'] as $table) {
            $this->assertSame(
                $DB->count_records('scormmakercmi5_' . $table, ['pkgid' => $old->currentpkgid]),
                $DB->count_records('scormmakercmi5_' . $table, ['pkgid' => $pkg->id]),
                $table
            );
        }
        $context = \context_module::instance($new->cmid);
        $fs = get_file_storage();
        foreach (['content', 'package'] as $area) {
            $files = $fs->get_area_files($context->id, 'mod_scormmakercmi5', $area, false, 'id', false);
            $this->assertNotEmpty($files);
            foreach ($files as $file) {
                $this->assertSame((int)$pkg->id, (int)$file->get_itemid(), $area);
            }
        }
        $this->assertSame(
            $this->area_count($this->instance, 'content', (int)$old->currentpkgid),
            $this->area_count($new, 'content', (int)$pkg->id)
        );
        foreach (self::USER_TABLES as $table) {
            $this->assertSame(0, $this->instance_rows($table, (int)$new->id), $table);
        }
    }

    /**
     * On a site that does not trust the key, the restored package is not shown as verified: the verdict is the target
     * site's, not the one carried by the backup.
     */
    public function test_restore_on_a_site_without_the_key(): void {
        global $DB;
        $this->setup_scenario();
        $backupid = $this->backup($this->course, false);
        set_config('trustedkeys', '', 'mod_scormmakercmi5');

        $new = $this->restored_instance($this->restore($backupid, $this->course, false));

        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $new->currentpkgid], '*', MUST_EXIST);
        $this->assertSame('invalid', $pkg->sigstatus);
        $this->assertSame('unknownkid', $pkg->sigreason);
        $this->assertSame('invalid', $pkg->recheckstatus);
        $this->assertSame('unknownkid', $pkg->recheckreason);
    }

    /**
     * The UUID swap of a same-site copy ignores case (an AU may send upper-case ids).
     */
    public function test_uuid_swap_ignores_case(): void {
        $old = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        $new = '11111111-2222-4333-8444-555555555555';
        $text = '{"id":"' . strtoupper($old) . '","context":{"registration":"' . $old . '"}}';
        $this->assertSame(
            '{"id":"' . $new . '","context":{"registration":"' . $new . '"}}',
            \mod_scormmakercmi5\local\backup_verbatim::swap_uuids($text, [$old => $new])
        );
        $this->assertSame($text, \mod_scormmakercmi5\local\backup_verbatim::swap_uuids($text, []));
    }

    /**
     * Duplicating the activity (backup and restore without user data, same course).
     */
    public function test_duplicate_activity(): void {
        global $DB;
        $this->setup_scenario();
        $old = $this->instance_row();
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);

        $newcm = \mod_scormmakercmi5_test_cm_api::duplicate($this->course, $cm);

        $new = $DB->get_record('scormmakercmi5', ['id' => $newcm->instance], '*', MUST_EXIST);
        $new->cmid = $newcm->id;
        $this->assertNotEquals($old->id, $new->id);
        $pkgs = $DB->get_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $new->id]);
        $this->assertCount(1, $pkgs);
        $pkg = reset($pkgs);
        $this->assertSame((int)$pkg->id, (int)$new->currentpkgid);
        $this->assertNotSame($old->lmscourseiri, $new->lmscourseiri);
        $this->assertSame(
            $this->area_count($this->instance, 'content', (int)$old->currentpkgid),
            $this->area_count($new, 'content', (int)$pkg->id)
        );
        $this->assertEmpty(array_intersect(
            array_column($this->aus_of((int)$old->currentpkgid), 'lmsactivityid'),
            array_column($this->aus_of((int)$pkg->id), 'lmsactivityid')
        ));
        foreach (self::USER_TABLES as $table) {
            $this->assertSame(0, $this->instance_rows($table, (int)$new->id), $table);
        }
        // The original keeps its data.
        $this->assertSame(1, $this->instance_rows('reg', (int)$old->id));
    }

    /**
     * Recycle bin on: deleting the activity works, and restoring it from the bin brings the learner data back
     * unchanged (no collision with the deleted original, so every UUID and payload is the original one).
     */
    public function test_recycle_bin_activity(): void {
        global $DB;
        $this->setup_scenario();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $old = $this->instance_row();
        $snapshot = $this->snapshot((int)$old->id);

        \mod_scormmakercmi5_test_cm_api::delete($this->instance->cmid);

        $this->assertFalse($DB->record_exists('scormmakercmi5', ['id' => $old->id]));
        $bin = new \tool_recyclebin\course_bin($this->course->id);
        $items = $bin->get_items();
        $this->assertCount(1, $items);
        $bin->restore_item(reset($items));

        $new = $this->restored_instance((int)$this->course->id);
        $this->assert_same_learner_data($snapshot, $this->snapshot((int)$new->id));
        $grades = grade_get_grades($this->course->id, 'mod', 'scormmakercmi5', (int)$new->id, (int)$this->user->id);
        $this->assertEqualsWithDelta(90.0, (float)reset($grades->items)->grades[$this->user->id]->grade, 0.00001);
    }

    /**
     * Recycle bin on: deleting the whole course works, and restoring it from the category bin brings the activity and
     * its learner data back.
     */
    public function test_recycle_bin_course(): void {
        global $DB;
        $this->setup_scenario();
        set_config('categorybinenable', 1, 'tool_recyclebin');
        $old = $this->instance_row();
        $snapshot = $this->snapshot((int)$old->id);

        delete_course($this->course, false);

        $this->assertFalse($DB->record_exists('scormmakercmi5', ['id' => $old->id]));
        $bin = new \tool_recyclebin\category_bin($this->course->category);
        $items = $bin->get_items();
        $this->assertCount(1, $items);
        $bin->restore_item(reset($items));

        $instances = $DB->get_records('scormmakercmi5');
        $this->assertCount(1, $instances);
        $new = reset($instances);
        $this->assertNotEquals($this->course->id, $new->course);
        $this->assert_same_learner_data($snapshot, $this->snapshot((int)$new->id));
    }

    /**
     * Learner data of an instance in a comparable form (ids replaced by what they point to).
     *
     * @param int $instanceid Instance.
     * @return array
     */
    private function snapshot(int $instanceid): array {
        global $DB;
        $params = ['scormmakercmi5id' => $instanceid];
        $versions = $DB->get_records_menu('scormmakercmi5_pkg', $params, 'versionnum', 'id, versionnum');
        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instanceid], '*', MUST_EXIST);
        $stmts = [];
        foreach ($DB->get_records('scormmakercmi5_stmt', ['scormmakercmi5id' => $instanceid], 'id') as $row) {
            $stmts[] = [$row->statementid, $row->payload, $versions[$row->pkgid], $row->userid];
        }
        $states = [];
        foreach ($DB->get_records('scormmakercmi5_state', ['scormmakercmi5id' => $instanceid], 'id') as $row) {
            $states[] = [$row->registration, $row->stateid, $row->activityhash, $row->content, $row->userid];
        }
        $sessions = [];
        foreach ($DB->get_records('scormmakercmi5_session', ['regid' => $reg->id], 'id') as $row) {
            $sessions[] = [$row->sessionuuid, $row->status, $row->userid];
        }
        return [
            'versions' => array_values($versions),
            'reg' => [$reg->registration, $reg->userid, $versions[$reg->pkgid], $reg->satisfied],
            'lmscourseiri' => $DB->get_field('scormmakercmi5', 'lmscourseiri', ['id' => $instanceid]),
            'stmts' => $stmts,
            'states' => $states,
            'sessions' => $sessions,
            'counts' => $this->user_counts($instanceid),
        ];
    }

    /**
     * The restored snapshot equals the original one, except the open sessions, which come back abandoned.
     *
     * @param array $before Snapshot of the original.
     * @param array $after Snapshot of the restored copy.
     */
    private function assert_same_learner_data(array $before, array $after): void {
        foreach ($before['sessions'] as $i => $session) {
            if (in_array((int)$session[1], [0, 1], true)) {
                $before['sessions'][$i][1] = 3;
            }
        }
        $this->assertNotEmpty($before['stmts']);
        $this->assertEquals($before, $after);
    }

    /**
     * Course with the learner, the signed v1 with learner data, then the signed v2 as current version.
     */
    private function setup_scenario(): void {
        global $DB;
        $v1 = builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/b1', 'children' => [
                ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed'],
            ]],
            ['id' => 'https://example.com/au/2', 'url' => 'index.html'],
        ]]);
        $record = ['grade' => 100, 'signed' => true, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsatisfied' => 1];
        $this->setup_lrs_activity(self::NOW, $v1, $record);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');

        $instance = $this->instance_row();
        $au1 = $this->aus_of((int)$instance->currentpkgid)[0];
        [$session, $token] = $this->learn_au((int)$this->user->id, (int)$au1->id, vocab::VERB_PASSED, 0.9);
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);
        $query = [
            'activityId' => $au1->lmsactivityid,
            'agent' => json_encode(actor::for_user((int)$this->user->id)),
            'stateId' => 'marcador',
        ];
        $this->assertSame(204, $this->put_state($token, $query + ['registration' => $reg->registration], '{"pagina":3}'));
        $this->assertSame(204, $this->put_state($token, $query, '{"global":true}'));
        $this->add_itemdef((int)$instance->currentpkgid, 'q1');

        $v2 = builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/b1', 'children' => [
                ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed'],
            ]],
            ['id' => 'https://example.com/au/2', 'url' => 'index.html'],
            ['id' => 'https://example.com/au/3', 'url' => 'pagina.html'],
        ]]);
        $entries = builder::sign(
            builder::minimal_entries(['cmi5.xml' => $v2, 'pagina.html' => '<!doctype html><title>v2</title>']),
            builder::test_keypair()
        );
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5');
        $data = clone $instance;
        $data->instance = $instance->id;
        $data->coursemodule = $this->instance->cmid;
        $data->packagefile = $generator->create_draft_package($entries);
        unset($data->id);
        $this->assertTrue(scormmakercmi5_update_instance($data));
        $current = (int)$DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $instance->id]);
        $this->assertNotSame((int)$instance->currentpkgid, $current);
        $this->add_itemdef($current, 'q2');
    }

    /**
     * PUT of a State document.
     *
     * @param string $token Session token.
     * @param array $query Query.
     * @param string $body JSON body.
     * @return int HTTP status.
     */
    private function put_state(string $token, array $query, string $body): int {
        $request = $this->lrs_request('PUT', 'activities/state', $token, $query, $body, ['Content-Type' => 'application/json']);
        return router::dispatch($request)->status;
    }

    /**
     * Adds an item definition to a package version.
     *
     * @param int $pkgid Version.
     * @param string $item Item id.
     */
    private function add_itemdef(int $pkgid, string $item): void {
        global $DB;
        $DB->insert_record('scormmakercmi5_itemdef', [
            'pkgid' => $pkgid,
            'smversion' => '2026-09-30T12:00:00Z#0a1b2c3d',
            'smkind' => 'questao',
            'smitem' => $item,
            'objectid' => 'https://scormmaker.com.br/xapi/questao/' . $item,
            'name' => '{"pt-BR":"Questão"}',
            'definition' => '{"interactionType":"choice"}',
            'firstseenms' => self::NOW * 1000,
        ]);
    }

    /**
     * Backs the course up and extracts the backup in the temp dir.
     *
     * @param \stdClass $course Course.
     * @param bool $userdata With user data.
     * @return string Backup id (directory under tempdir/backup).
     */
    private function backup(\stdClass $course, bool $userdata): string {
        global $CFG, $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($userdata);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $backupid = 'scormmakercmi5_' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $backupid);
        $bc->destroy();
        return $backupid;
    }

    /**
     * Backs the course up and restores it into a new course.
     *
     * @param \stdClass $course Course.
     * @param bool $userdata With user data.
     * @return int New course id.
     */
    private function backup_and_restore(\stdClass $course, bool $userdata): int {
        return $this->restore($this->backup($course, $userdata), $course, $userdata);
    }

    /**
     * Restores an extracted backup into a new course.
     *
     * @param string $backupid Backup id.
     * @param \stdClass $course Course that was backed up (names and category).
     * @param bool $userdata With user data.
     * @return int New course id.
     */
    private function restore(string $backupid, \stdClass $course, bool $userdata): int {
        global $USER;
        $courseid = \restore_dbops::create_new_course($course->fullname . ' r', $course->shortname . '_r', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($userdata);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $courseid;
    }

    /**
     * The fresh row of the original instance.
     *
     * @return \stdClass
     */
    private function instance_row(): \stdClass {
        global $DB;
        return $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
    }

    /**
     * The only instance of a course.
     *
     * @param int $courseid Course.
     * @return \stdClass With cmid.
     */
    private function restored_instance(int $courseid): \stdClass {
        global $DB;
        $instance = $DB->get_record('scormmakercmi5', ['course' => $courseid], '*', MUST_EXIST);
        $instance->cmid = get_coursemodule_from_instance('scormmakercmi5', $instance->id, $courseid, false, MUST_EXIST)->id;
        return $instance;
    }

    /**
     * Package versions of an instance by versionnum.
     *
     * @param int $instanceid Instance.
     * @return array versionnum => row.
     */
    private function pkgs_by_version(int $instanceid): array {
        global $DB;
        $pkgs = [];
        foreach ($DB->get_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instanceid], 'versionnum') as $pkg) {
            $pkgs[(int)$pkg->versionnum] = $pkg;
        }
        return $pkgs;
    }

    /**
     * A restored version was verified on this site after the restore (signature and file map, no byte check yet):
     * the source site's recheck state did not travel.
     *
     * @param \stdClass $pkg Pkg row.
     */
    private function assert_rechecked_on_target(\stdClass $pkg): void {
        $this->assertSame('verified', $pkg->sigstatus);
        $this->assertNull($pkg->sigreason);
        $this->assertSame('verified', $pkg->recheckstatus);
        $this->assertNull($pkg->recheckreason);
        $this->assertSame(self::NOW, (int)$pkg->timerecheck);
        $this->assertNull($pkg->fullrecheckstatus);
        $this->assertNull($pkg->fullrecheckreason);
        $this->assertSame(0, (int)$pkg->timefullrecheck);
    }

    /**
     * Rows of each child table of a registration.
     *
     * @param int $regid Registration.
     * @return array
     */
    private function reg_children(int $regid): array {
        global $DB;
        $counts = [];
        foreach (['regau', 'regblock', 'session', 'stmt', 'state'] as $table) {
            $counts[$table] = $DB->count_records('scormmakercmi5_' . $table, ['regid' => $regid]);
        }
        return $counts;
    }

    /**
     * AUs of a version in file order.
     *
     * @param int $pkgid Version.
     * @return array
     */
    private function aus_of(int $pkgid): array {
        global $DB;
        return array_values($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder'));
    }

    /**
     * Blocks of a version in file order.
     *
     * @param int $pkgid Version.
     * @return array
     */
    private function blocks_of(int $pkgid): array {
        global $DB;
        return array_values($DB->get_records('scormmakercmi5_block', ['pkgid' => $pkgid], 'sortorder'));
    }

    /**
     * File hashes of a version, comparable across versions.
     *
     * @param int $pkgid Version.
     * @return array
     */
    private function pkgfiles(int $pkgid): array {
        global $DB;
        $fields = 'pathhash, path, sha256, contenthash, filesize';
        return array_values($DB->get_records('scormmakercmi5_pkgfile', ['pkgid' => $pkgid], 'pathhash', $fields));
    }

    /**
     * Number of files of a file area item.
     *
     * @param \stdClass $instance Instance with cmid.
     * @param string $area Area.
     * @param int $itemid Item.
     * @return int
     */
    private function area_count(\stdClass $instance, string $area, int $itemid): int {
        $context = \context_module::instance($instance->cmid);
        return count(get_file_storage()->get_area_files($context->id, 'mod_scormmakercmi5', $area, $itemid, 'id', false));
    }

    /**
     * Rows of learner data of an instance, per table.
     *
     * @param int $instanceid Instance.
     * @return array
     */
    private function user_counts(int $instanceid): array {
        $counts = [];
        foreach (self::USER_TABLES as $table) {
            $counts[$table] = $this->instance_rows($table, $instanceid);
        }
        return $counts;
    }

    /**
     * Rows of a learner data table that belong to an instance.
     *
     * @param string $table Table without prefix.
     * @param int $instanceid Instance.
     * @return int
     */
    private function instance_rows(string $table, int $instanceid): int {
        global $DB;
        if (in_array($table, ['reg', 'stmt', 'state'], true)) {
            return $DB->count_records('scormmakercmi5_' . $table, ['scormmakercmi5id' => $instanceid]);
        }
        $regids = $DB->get_fieldset_select('scormmakercmi5_reg', 'id', 'scormmakercmi5id = ?', [$instanceid]);
        if (!$regids) {
            return 0;
        }
        [$in, $params] = $DB->get_in_or_equal($regids);
        return $DB->count_records_select('scormmakercmi5_' . $table, 'regid ' . $in, $params);
    }
}
