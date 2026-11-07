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

namespace mod_scormmakercmi5\privacy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_scormmakercmi5\local\cmi5_vocab as vocab;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\privacy\provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\observer::class)]
/**
 * Privacy provider tests (design §11, NF2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\privacy\provider
 * @covers     \mod_scormmakercmi5\observer
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string Name of the activity. */
    private const NAME = 'Curso de privacidade';

    /** @var string Title of the only AU. */
    private const AU = 'Unidade 1';

    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /** @var \stdClass Second learner. */
    private \stdClass $user2;

    /** @var \stdClass Teacher who uploaded the package of the activity. */
    private \stdClass $teacher;

    /** @var \stdClass Another activity of the same course, where the first learner also has data. */
    private \stdClass $other;

    /** @var array<int, array<int, int>> Registration ids by instance and user, taken at the end of setup_data(). */
    private array $regids = [];

    /**
     * The eight tables with personal data are declared, with existing strings, and the AU outside the package as an
     * external location (it receives the actor and the registration in its launch URL).
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection(self::COMPONENT));
        $all = $collection->get_collection();
        $this->assertCount(9, $all);
        $external = array_values(array_filter($all, fn($item) => $item instanceof external_location));
        $this->assertCount(1, $external);
        $this->assertSame('externalau', $external[0]->get_name());
        $this->assertSame('privacy:metadata:externalau', $external[0]->get_summary());
        $this->assertSame([
            'actor' => 'privacy:metadata:externalau:actor',
            'registration' => 'privacy:metadata:externalau:registration',
            'statements' => 'privacy:metadata:externalau:statements',
            'state' => 'privacy:metadata:externalau:state',
            'agentprofile' => 'privacy:metadata:externalau:agentprofile',
        ], $external[0]->get_privacy_fields());
        $identifiers = ['privacy:metadata:externalau', 'privacy:metadata:externalau:actor',
            'privacy:metadata:externalau:registration', 'privacy:metadata:externalau:statements',
            'privacy:metadata:externalau:state', 'privacy:metadata:externalau:agentprofile'];
        foreach ($identifiers as $identifier) {
            $this->assertTrue(get_string_manager()->string_exists($identifier, self::COMPONENT), $identifier);
        }
        $items = array_values(array_filter($all, fn($item) => !$item instanceof external_location));
        $this->assertCount(8, $items);

        $expected = ['scormmakercmi5_pkg', 'scormmakercmi5_reg', 'scormmakercmi5_regau', 'scormmakercmi5_regblock',
            'scormmakercmi5_session', 'scormmakercmi5_stmt', 'scormmakercmi5_state', 'scormmakercmi5_agentprof'];
        $names = array_map(fn($item) => $item->get_name(), $items);
        sort($expected);
        sort($names);
        $this->assertSame($expected, $names);

        $sm = get_string_manager();
        foreach ($items as $item) {
            $this->assertInstanceOf(database_table::class, $item);
            $this->assertTrue($sm->string_exists($item->get_summary(), self::COMPONENT), $item->get_summary());
            foreach ($item->get_privacy_fields() as $field => $identifier) {
                $this->assertTrue($sm->string_exists($identifier, self::COMPONENT), $identifier);
            }
        }
        $session = array_values(array_filter($items, fn($item) => $item->get_name() === 'scormmakercmi5_session'))[0];
        $this->assertArrayHasKey('fetchkeyhash', $session->get_privacy_fields());
        $this->assertArrayHasKey('tokenhash', $session->get_privacy_fields());
    }

    /**
     * Contexts: the module (registration) and the user (Agent Profile) of a learner; the module of the teacher who
     * uploaded the package; nothing for a user without data; the module for State left without a registration.
     */
    public function test_get_contexts_for_userid(): void {
        $this->setup_data();
        $modctx = $this->modctx();
        $otherctx = \context_module::instance($this->other->cmid);
        $userctx = \context_user::instance($this->user->id);

        $ids = provider::get_contexts_for_userid((int)$this->user->id)->get_contextids();
        sort($ids);
        $expected = [$modctx->id, $otherctx->id, $userctx->id];
        sort($expected);
        $this->assertEquals($expected, $ids);

        $teacher = provider::get_contexts_for_userid((int)$this->teacher->id)->get_contextids();
        $this->assertEquals([$modctx->id], array_values($teacher));

        $nobody = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertCount(0, provider::get_contexts_for_userid((int)$nobody->id));

        // State written after the attempt was deleted (design §6.5) still points to the module.
        $orphan = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->insert_orphan_state((int)$orphan->id);
        $this->assertEquals([$modctx->id], array_values(provider::get_contexts_for_userid((int)$orphan->id)->get_contextids()));
    }

    /**
     * Export: the registration under [activity], the AU status, its sessions without token hashes, its statements
     * with the stored payload and its State under [activity, AU]; the preferences in the user context; the packages
     * uploaded by the teacher.
     */
    public function test_export_user_data(): void {
        global $DB;
        $this->setup_data();
        $modctx = $this->modctx();
        $userctx = \context_user::instance($this->user->id);
        $this->export_context_data_for_user((int)$this->user->id, $modctx, self::COMPONENT);
        $this->export_context_data_for_user((int)$this->user->id, $userctx, self::COMPONENT);

        $writer = writer::with_context($modctx);
        $this->assertTrue($writer->has_any_data());
        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $this->instance->id, 'userid' => $this->user->id]);
        $root = $writer->get_data([self::NAME]);
        $this->assertSame($reg->registration, $root->registration);
        $this->assertSame(1, $root->packageversion);
        $this->assertEqualsWithDelta(0.8, $root->grade, 0.00001);

        $au = $writer->get_data([self::NAME, self::AU]);
        $this->assertNotEmpty($au);
        $this->assertSame(get_string('yes'), $au->status['passed']);

        // Sessions: the launch (and any synthetic one), without the hashes of the fetch key and of the token.
        $sessions = array_values($DB->get_records('scormmakercmi5_session', ['regid' => $reg->id], 'timelaunchedms, id'));
        $this->assertCount(count($sessions), $au->sessions);
        foreach ($sessions as $i => $stored) {
            $session = (array)$au->sessions[$i];
            $this->assertArrayNotHasKey('fetchkeyhash', $session);
            $this->assertArrayNotHasKey('tokenhash', $session);
            $this->assertSame($stored->sessionuuid, $session['sessionuuid']);
        }
        $launch = $DB->get_record('scormmakercmi5_session', ['regid' => $reg->id, 'kind' => 0], '*', MUST_EXIST);
        $this->assertNotEmpty($launch->tokenhash);
        $this->assertNotEmpty($launch->fetchkeyhash);
        $this->assertStringNotContainsString($launch->tokenhash, json_encode($au));
        $this->assertStringNotContainsString($launch->fetchkeyhash, json_encode($au));

        // Statements: the LMS launched plus initialized and passed of the AU, with the payload as stored.
        $rows = $DB->get_records('scormmakercmi5_stmt', ['regid' => $reg->id], 'storedms, id');
        $this->assertCount(count($rows), $au->statements);
        $verbs = array_column(array_map(fn($s) => (array)$s, $au->statements), 'verb');
        $this->assertContains(vocab::VERB_PASSED, $verbs);
        foreach (array_values($rows) as $i => $row) {
            $exported = (array)$au->statements[$i];
            $this->assertSame($row->statementid, $exported['statementid']);
            $this->assertEquals(json_decode($row->payload), $exported['payload']);
            $this->assertSame(json_encode(json_decode($row->payload)), json_encode($exported['payload']));
        }

        // The LMS.LaunchData State of the AU.
        $this->assertNotEmpty($au->states);
        $this->assertSame('LMS.LaunchData', $au->states[0]['stateid']);

        // Preferences (Agent Profile) in the user context.
        $prefs = writer::with_context($userctx)->get_data([get_string('privacy:path:preferences', self::COMPONENT)]);
        $this->assertNotEmpty($prefs);
        $this->assertSame('cmi5LearnerPreferences', $prefs->profiles[0]['profileid']);
        $this->assertNotEmpty($prefs->profiles[0]['content']);

        // The data of the other activity is not in this context, and the learner uploaded no package.
        $this->assertEmpty($writer->get_data(['Outra atividade']));
        $otherkey = ['scormmakercmi5id' => $this->other->id, 'userid' => $this->user->id];
        $otherreg = $DB->get_field('scormmakercmi5_reg', 'registration', $otherkey, MUST_EXIST);
        $this->assertStringNotContainsString($otherreg, json_encode([$root, $au]));
        $this->assertEmpty($writer->get_data([self::NAME, get_string('privacy:path:packages', self::COMPONENT)]));

        // The teacher: the packages uploaded.
        \core_privacy\local\request\writer::reset();
        $this->export_context_data_for_user((int)$this->teacher->id, $modctx, self::COMPONENT);
        $writer = writer::with_context($modctx);
        $packages = $writer->get_data([self::NAME, get_string('privacy:path:packages', self::COMPONENT)]);
        $this->assertCount(1, $packages->packages);
        $this->assertSame(1, $packages->packages[0]['version']);
        $this->assertEmpty($writer->get_data([self::NAME, self::AU]));
    }

    /**
     * Deleting a module context removes every learner row of that instance, State without registration included,
     * blanks the uploader and leaves the other instance as it was.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->setup_data();
        $orphan = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->insert_orphan_state((int)$orphan->id);
        $before = $this->counts((int)$this->other->id);

        provider::delete_data_for_all_users_in_context($this->modctx());

        foreach ($this->counts((int)$this->instance->id) as $table => $count) {
            $this->assertSame(0, $count, $table);
        }
        $this->assertSame($before, $this->counts((int)$this->other->id));
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['scormmakercmi5id' => $this->instance->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$pkg->usermodified);
        $this->assertNull($this->gradebook_grade((int)$this->user->id));
        $this->assertNull($this->gradebook_grade((int)$this->user2->id));
        // The Agent Profile belongs to the user context.
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));
    }

    /**
     * Deleting a user in the module context removes only that learner and their grade; in the user context, the
     * Agent Profile; the teacher's package version stays, with usermodified 0.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->setup_data();
        $modctx = $this->modctx();
        $userctx = \context_user::instance($this->user->id);
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade((int)$this->user->id), 0.00001);
        $user2before = $this->user_counts((int)$this->user2->id, (int)$this->instance->id);
        $otherbefore = $this->user_counts((int)$this->user->id, (int)$this->other->id);

        provider::delete_data_for_user(new approved_contextlist($this->user, self::COMPONENT, [$modctx->id]));

        foreach ($this->user_counts((int)$this->user->id, (int)$this->instance->id) as $table => $count) {
            $this->assertSame(0, $count, $table);
        }
        $this->assertSame($user2before, $this->user_counts((int)$this->user2->id, (int)$this->instance->id));
        $this->assertSame($otherbefore, $this->user_counts((int)$this->user->id, (int)$this->other->id));
        $this->assertNull($this->gradebook_grade((int)$this->user->id));
        $this->assertEqualsWithDelta(50.0, $this->gradebook_grade((int)$this->user2->id), 0.00001);
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));

        provider::delete_data_for_user(new approved_contextlist($this->user, self::COMPONENT, [$userctx->id]));
        $this->assertFalse($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user2->id]));

        // The uploader: the package version is kept, without the user.
        $pkgid = (int)$DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $this->instance->id]);
        provider::delete_data_for_user(new approved_contextlist($this->teacher, self::COMPONENT, [$modctx->id]));
        $this->assertSame(0, (int)$DB->get_field('scormmakercmi5_pkg', 'usermodified', ['id' => $pkgid], MUST_EXIST));
        $this->assertSame($user2before, $this->user_counts((int)$this->user2->id, (int)$this->instance->id));

        // State left without a registration is found by user.
        $orphan = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->insert_orphan_state((int)$orphan->id);
        provider::delete_data_for_user(new approved_contextlist($orphan, self::COMPONENT, [$modctx->id]));
        $this->assertFalse($DB->record_exists('scormmakercmi5_state', ['userid' => $orphan->id]));
    }

    /**
     * Users in a context: both learners and the uploader in the module, the owner in the user context; deleting one of
     * them leaves the others.
     */
    public function test_users_in_context(): void {
        global $DB;
        $this->setup_data();
        $modctx = $this->modctx();

        $userlist = new userlist($modctx, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $ids = $userlist->get_userids();
        sort($ids);
        $expected = [(int)$this->user->id, (int)$this->user2->id, (int)$this->teacher->id];
        sort($expected);
        $this->assertEquals($expected, $ids);

        $userctx = \context_user::instance($this->user->id);
        $userlist = new userlist($userctx, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertEquals([(int)$this->user->id], array_values($userlist->get_userids()));

        $user2before = $this->user_counts((int)$this->user2->id, (int)$this->instance->id);
        provider::delete_data_for_users(new approved_userlist($modctx, self::COMPONENT, [(int)$this->user->id]));
        foreach ($this->user_counts((int)$this->user->id, (int)$this->instance->id) as $table => $count) {
            $this->assertSame(0, $count, $table);
        }
        $this->assertSame($user2before, $this->user_counts((int)$this->user2->id, (int)$this->instance->id));
        $this->assertNull($this->gradebook_grade((int)$this->user->id));

        $userlist = new userlist($modctx, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $ids = $userlist->get_userids();
        sort($ids);
        $expected = [(int)$this->user2->id, (int)$this->teacher->id];
        sort($expected);
        $this->assertEquals($expected, $ids);

        // The user context: only the listed owner loses the Agent Profile.
        provider::delete_data_for_users(new approved_userlist($userctx, self::COMPONENT, [(int)$this->user2->id]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));
        provider::delete_data_for_users(new approved_userlist($userctx, self::COMPONENT, [(int)$this->user->id]));
        $this->assertFalse($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user2->id]));
    }

    /**
     * A registration whose lock is held (an ingestion or a launch stuck on it) is still deleted, by user and by the
     * whole context: core closes the request as done whatever the provider throws, so nothing may be skipped. Every
     * learner is processed and the uploader is cleared.
     */
    public function test_busy_registration_is_deleted_anyway(): void {
        global $CFG, $DB;
        $this->setup_data();
        $modctx = $this->modctx();
        $user2before = $this->user_counts((int)$this->user2->id, (int)$this->instance->id);
        $regid = $this->regids[(int)$this->instance->id][(int)$this->user->id];
        $regid2 = $this->regids[(int)$this->instance->id][(int)$this->user2->id];

        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $timeout = new \ReflectionProperty(\mod_scormmakercmi5\local\runtime\attempt_manager::class, 'privacylocktimeout');
        $timeout->setValue(null, 0);
        $lock = \mod_scormmakercmi5\local\runtime\launcher::registration_lock($regid, 0);
        $this->assertNotFalse($lock);
        try {
            provider::delete_data_for_user(new approved_contextlist($this->user, self::COMPONENT, [$modctx->id]));
            foreach ($this->user_counts((int)$this->user->id, (int)$this->instance->id) as $table => $count) {
                $this->assertSame(0, $count, $table);
            }
            $this->assertNull($this->gradebook_grade((int)$this->user->id));
            $this->assertSame($user2before, $this->user_counts((int)$this->user2->id, (int)$this->instance->id));

            // The whole context, with the first of the learners busy.
            $lock2 = \mod_scormmakercmi5\local\runtime\launcher::registration_lock($regid2, 0);
            $this->assertNotFalse($lock2);
            try {
                $orphan = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
                $this->insert_orphan_state((int)$orphan->id);
                provider::delete_data_for_all_users_in_context($modctx);
            } finally {
                $lock2->release();
            }
            foreach ($this->counts((int)$this->instance->id) as $table => $count) {
                $this->assertSame(0, $count, $table);
            }
            $pkgkey = ['scormmakercmi5id' => $this->instance->id];
            $this->assertSame(0, (int)$DB->get_field('scormmakercmi5_pkg', 'usermodified', $pkgkey, MUST_EXIST));
        } finally {
            $lock->release();
            $timeout->setValue(null, 60);
        }
    }

    /**
     * Deleting the user context removes the Agent Profile of its owner only.
     */
    public function test_delete_user_context(): void {
        global $DB;
        $this->setup_data();
        provider::delete_data_for_all_users_in_context(\context_user::instance($this->user->id));
        $this->assertFalse($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user2->id]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_reg', ['userid' => $this->user->id]));
    }

    /**
     * Deleting a user account removes their Agent Profile: its context (the user's) goes with the account, so the
     * Privacy API could never reach it again.
     */
    public function test_user_deleted_removes_agent_profile(): void {
        global $DB;
        $this->setup_data();
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));
        delete_user($this->user);
        $this->assertFalse($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user->id]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $this->user2->id]));
    }

    /**
     * Deleting a user account removes the learner data of every activity, through the code of the Privacy API: the
     * registration, its sessions, the statements, the State and the Agent Profile; another learner keeps theirs. The
     * event fires after the user row is marked deleted, so the handler must not depend on the user being live.
     */
    public function test_user_deleted_removes_the_learner_data_of_every_activity(): void {
        global $DB;
        $this->setup_data();
        $userid = (int)$this->user->id;
        $regids = array_map('intval', $DB->get_fieldset_select('scormmakercmi5_reg', 'id', 'userid = ?', [$userid]));
        $this->assertCount(2, $regids, 'the learner has a registration in both activities');
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['userid' => $userid]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_state', ['userid' => $userid]));
        [$insql, $inparams] = $DB->get_in_or_equal($regids);
        $this->assertTrue($DB->record_exists_select('scormmakercmi5_session', "regid $insql", $inparams));
        $history = static fn() => $DB->count_records_select(
            'grade_grades_history',
            'userid = :userid AND action <> :deleted',
            ['userid' => $userid, 'deleted' => GRADE_HISTORY_DELETE]
        );
        $historybefore = $history();
        $keptreg = $DB->count_records('scormmakercmi5_reg', ['userid' => $this->user2->id]);
        $keptstmt = $DB->count_records('scormmakercmi5_stmt', ['userid' => $this->user2->id]);
        $this->assertGreaterThan(0, $keptreg);
        $this->assertGreaterThan(0, $keptstmt);

        delete_user($this->user);

        // Core removed their grades and completion before the event; the observer must not put rows back.
        $this->assertSame(0, $DB->count_records('grade_grades', ['userid' => $userid]), 'grade_grades');
        // Core logs the deletion itself in the history; an insert or update there would be the observer's.
        $this->assertSame($historybefore, $history(), 'grade_grades_history');
        $this->assertSame(0, $DB->count_records('course_modules_completion', ['userid' => $userid]), 'completion');
        foreach (['reg', 'stmt', 'state', 'agentprof'] as $table) {
            $this->assertSame(0, $DB->count_records('scormmakercmi5_' . $table, ['userid' => $userid]), $table);
        }
        $this->assertFalse($DB->record_exists_select('scormmakercmi5_session', "regid $insql", $inparams), 'session');
        $this->assertSame($keptreg, $DB->count_records('scormmakercmi5_reg', ['userid' => $this->user2->id]));
        $this->assertSame($keptstmt, $DB->count_records('scormmakercmi5_stmt', ['userid' => $this->user2->id]));
    }

    /**
     * The observer runs after the commit of an outer transaction (internal = false): a failure of the cascade, or a
     * wait for a registration lock, cannot roll the account deletion back or hold the transaction open.
     */
    public function test_user_deleted_observer_waits_for_the_outer_transaction(): void {
        global $DB;
        $this->setup_data();
        $userid = (int)$this->user->id;
        $transaction = $DB->start_delegated_transaction();
        delete_user($this->user);
        $this->assertTrue(
            $DB->record_exists('scormmakercmi5_reg', ['userid' => $userid]),
            'the observer must not run inside the outer transaction'
        );
        $transaction->allow_commit();
        $this->assertFalse($DB->record_exists('scormmakercmi5_reg', ['userid' => $userid]), 'after the commit');
    }

    /**
     * The activity (uploaded by a teacher), two learners with a launch and a passed (0.8 and 0.5), and another
     * activity where the first learner also launched.
     */
    private function setup_data(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->clock = $this->mock_clock_with_frozen(self::NOW);
        set_config('requireprefsread', 0, self::COMPONENT);
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        // The teacher uploads the package of the activity (pkg.usermodified); the admin, the other one.
        $this->setUser($this->teacher);
        $entries = \mod_scormmakercmi5_test_package_builder::minimal_entries([
            'cmi5.xml' => \mod_scormmakercmi5_test_package_builder::cmi5_xml([
                ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => self::AU],
            ]),
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator(self::COMPONENT);
        $this->instance = $generator->create_instance(['course' => $this->course->id, 'name' => self::NAME, 'grade' => 100,
            'package_entries' => $entries]);
        $this->setAdminUser();
        $this->other = $generator->create_instance(['course' => $this->course->id, 'name' => 'Outra atividade',
            'grade' => 100]);

        $this->user2 = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->learn_au((int)$this->user->id, $this->au_id((int)$this->instance->id), vocab::VERB_PASSED, 0.8);
        $this->learn_au((int)$this->user2->id, $this->au_id((int)$this->instance->id), vocab::VERB_PASSED, 0.5);

        $current = $this->instance;
        $this->instance = $this->other;
        $this->learn_au((int)$this->user->id, $this->au_id((int)$this->other->id), vocab::VERB_PASSED, 0.6);
        $this->instance = $current;

        // The registration ids, taken now: after a deletion the reg row is gone and could not lead to its children.
        foreach ($DB->get_records('scormmakercmi5_reg') as $reg) {
            $this->regids[(int)$reg->scormmakercmi5id][(int)$reg->userid] = (int)$reg->id;
            $this->assertGreaterThan(0, $DB->count_records('scormmakercmi5_regau', ['regid' => $reg->id]));
            $this->assertGreaterThan(0, $DB->count_records('scormmakercmi5_session', ['regid' => $reg->id]));
        }
    }

    /**
     * The only AU of the current package of an instance.
     *
     * @param int $instanceid Instance.
     * @return int
     */
    private function au_id(int $instanceid): int {
        global $DB;
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $instanceid], MUST_EXIST);
        return (int)$DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $pkgid], MUST_EXIST);
    }

    /**
     * The module context of the activity.
     *
     * @return \context_module
     */
    private function modctx(): \context_module {
        return \context_module::instance($this->instance->cmid);
    }

    /**
     * A State document of a user without a registration in the activity (design §6.5).
     *
     * @param int $userid User.
     */
    private function insert_orphan_state(int $userid): void {
        global $DB;
        $DB->insert_record('scormmakercmi5_state', [
            'scormmakercmi5id' => $this->instance->id,
            'userid' => $userid,
            'regid' => 0,
            'activityhash' => sha1('https://example.com/orphan'),
            'registration' => '',
            'stateid' => 'progresso',
            'contenttype' => 'application/json',
            'content' => '{"pagina":3}',
            'isbase64' => 0,
            'etag' => sha1('{"pagina":3}'),
            'updatedms' => self::NOW * 1000,
            'lmsowned' => 0,
        ]);
    }

    /**
     * Row counts of the learner data of an instance.
     *
     * @param int $instanceid Instance.
     * @return array table => count.
     */
    private function counts(int $instanceid): array {
        global $DB;
        $regids = array_values($this->regids[$instanceid] ?? []);
        [$in, $params] = $regids ? $DB->get_in_or_equal($regids) : ['= ?', [-1]];
        return [
            'reg' => $DB->count_records('scormmakercmi5_reg', ['scormmakercmi5id' => $instanceid]),
            'regau' => $DB->count_records_select('scormmakercmi5_regau', "regid $in", $params),
            'regblock' => $DB->count_records_select('scormmakercmi5_regblock', "regid $in", $params),
            'session' => $DB->count_records_select('scormmakercmi5_session', "regid $in", $params),
            'stmt' => $DB->count_records('scormmakercmi5_stmt', ['scormmakercmi5id' => $instanceid]),
            'state' => $DB->count_records('scormmakercmi5_state', ['scormmakercmi5id' => $instanceid]),
        ];
    }

    /**
     * Row counts of the learner data of a user in an instance.
     *
     * @param int $userid User.
     * @param int $instanceid Instance.
     * @return array table => count.
     */
    private function user_counts(int $userid, int $instanceid): array {
        global $DB;
        $key = ['scormmakercmi5id' => $instanceid, 'userid' => $userid];
        $regid = $this->regids[$instanceid][$userid] ?? -1;
        return [
            'reg' => $DB->count_records('scormmakercmi5_reg', $key),
            'regau' => $DB->count_records('scormmakercmi5_regau', ['regid' => $regid]),
            'regblock' => $DB->count_records('scormmakercmi5_regblock', ['regid' => $regid]),
            'session' => $DB->count_records('scormmakercmi5_session', ['regid' => $regid]),
            'stmt' => $DB->count_records('scormmakercmi5_stmt', $key),
            'state' => $DB->count_records('scormmakercmi5_state', $key),
        ];
    }

    /**
     * The grade of a learner in the gradebook, null when there is none.
     *
     * @param int $userid Learner.
     * @return float|null
     */
    private function gradebook_grade(int $userid): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'scormmakercmi5', $this->instance->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float)$grade;
    }
}
