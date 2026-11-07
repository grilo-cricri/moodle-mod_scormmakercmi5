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

namespace mod_scormmakercmi5\local\runtime;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\event\attempt_deleted;
use mod_scormmakercmi5\event\au_waived;
use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\attempt_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(\mod_scormmakercmi5\local\runtime\lms_statements::class, 'waived')]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\event\au_waived::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\event\attempt_deleted::class)]
/**
 * Tests for waiving an AU (R4.8, cmi5 §9.3.7) and deleting the attempt of a learner (design §2.2, §11).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\runtime\attempt_manager
 * @covers     \mod_scormmakercmi5\local\runtime\lms_statements::waived
 * @covers     \mod_scormmakercmi5\event\au_waived
 * @covers     \mod_scormmakercmi5\event\attempt_deleted
 */
final class attempt_manager_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string Publisher id of the course in cmi5.xml. */
    private const COURSE = 'https://example.com/course';

    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;

    /**
     * Waiving AU1 (in block b1) of a learner whose AU2 is satisfied: one waived statement of the LMS with a
     * synthetic session, regau waived, then the block and the course satisfied with that same session, completion
     * complete, the grade kept and the au_waived event.
     */
    public function test_waive_satisfies_the_course(): void {
        global $DB;
        $this->setup_activity();
        [$au1, $au2] = $this->aus();
        $this->learn_au((int)$this->user->id, (int)$au2->id, vocab::VERB_PASSED, 0.8);
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade((int)$this->user->id), 0.00001);
        $reg = $this->registration((int)$this->user->id);
        $this->assertSame(0, (int)$reg->satisfied);

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();
        attempt_manager::waive($this->instance_row(), $this->cm(), (int)$this->user->id, (int)$au1->id, 'Administrative');
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof au_waived));
        $sink->close();

        // The waived statement (cmi5 §9.3.7, §9.5.5.2, §9.6.2.2).
        $rows = $DB->get_records('scormmakercmi5_stmt', ['regid' => $reg->id, 'verb' => vocab::VERB_WAIVED]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(statement_store::SOURCE_LMS, (int)$row->source);
        $this->assertSame(statement_store::KIND_DEFINED, (int)$row->cmi5kind);
        $this->assertSame(1, (int)$row->success);
        $this->assertSame(1, (int)$row->completion);
        $this->assertEquals($au1->id, $row->auid);
        $session = $DB->get_record('scormmakercmi5_session', ['id' => $row->sessionid], '*', MUST_EXIST);
        $this->assertSame(launcher::KIND_SYNTHETIC, (int)$session->kind);
        $this->assertSame(launcher::STATUS_TERMINATED, (int)$session->status);
        $this->assertEquals($au1->id, $session->auid);
        $payload = json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(vocab::VERB_WAIVED, $payload['verb']['id']);
        $this->assertSame($au1->lmsactivityid, $payload['object']['id']);
        $this->assertTrue($payload['result']['success']);
        $this->assertTrue($payload['result']['completion']);
        $this->assertSame('Administrative', $payload['result']['extensions'][vocab::EXT_REASON]);
        $this->assertSame($reg->registration, $payload['context']['registration']);
        $this->assertSame($session->sessionuuid, $payload['context']['extensions'][vocab::EXT_SESSIONID]);
        $this->assertSame($au1->publisherid, $payload['context']['contextActivities']['grouping'][0]['id']);
        $categories = array_column($payload['context']['contextActivities']['category'], 'id');
        $this->assertContains(vocab::CAT_CMI5, $categories);
        $this->assertContains(vocab::CAT_MOVEON, $categories);
        $this->assertSame((string)$this->user->id, $payload['actor']['account']['name']);
        $this->assertArrayHasKey('authority', $payload);

        // The regau and the satisfaction it triggered, with the session of the waived statement (cmi5 §9.3.9).
        $regau = $DB->get_record('scormmakercmi5_regau', ['regid' => $reg->id, 'auid' => $au1->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$regau->waived);
        $this->assertSame('Administrative', $regau->waivedreason);
        $this->assertSame(1, (int)$regau->satisfied);
        $reg = $this->registration((int)$this->user->id);
        $this->assertSame(1, (int)$reg->satisfied);
        $satisfied = $DB->get_records('scormmakercmi5_stmt', ['regid' => $reg->id, 'verb' => vocab::VERB_SATISFIED], 'id');
        $this->assertCount(2, $satisfied);
        foreach ($satisfied as $statement) {
            $this->assertEquals($session->id, $statement->sessionid);
        }
        $types = array_map(
            fn($s) => json_decode($s->payload, true, 64, JSON_THROW_ON_ERROR)['object']['definition']['type'],
            array_values($satisfied)
        );
        $this->assertSame([vocab::TYPE_BLOCK, vocab::TYPE_COURSE], $types);
        $synthetic = ['regid' => $reg->id, 'kind' => launcher::KIND_SYNTHETIC];
        $this->assertSame(1, $DB->count_records('scormmakercmi5_session', $synthetic));
        $this->assertSame(
            (int)$DB->count_records('scormmakercmi5_stmt', ['regid' => $reg->id]),
            (int)$reg->statementcount
        );

        // Completion and grade after the commit.
        $this->assertSame(COMPLETION_COMPLETE, $this->completion_state((int)$this->user->id));
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade((int)$this->user->id), 0.00001);

        // The event.
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertEquals($regau->id, $event->objectid);
        $this->assertSame('scormmakercmi5_regau', $event->objecttable);
        $this->assertEquals($this->user->id, $event->relateduserid);
        $this->assertEquals($this->teacher->id, $event->userid);
        $this->assertSame(['auid' => (int)$au1->id, 'reason' => 'Administrative'], $event->other);
        $this->assertEquals(\context_module::instance($this->instance->cmid), $event->get_context());
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty(au_waived::get_name());
    }

    /**
     * Refusals: waived again, an AU already satisfied, a reason outside the cmi5 vocabulary, an AU of another
     * activity, someone who is not a learner of the activity, and a user without manageattempts.
     */
    public function test_waive_refusals(): void {
        global $DB;
        $this->setup_activity();
        [$au1, $au2] = $this->aus();
        $userid = (int)$this->user->id;
        $this->learn_au($userid, (int)$au2->id, vocab::VERB_PASSED, 0.8);
        $this->setUser($this->teacher);
        attempt_manager::waive($this->instance_row(), $this->cm(), $userid, (int)$au1->id, 'Tested Out');

        $this->assert_moodle_exception('err_alreadywaived', fn() =>
            attempt_manager::waive($this->instance_row(), $this->cm(), $userid, (int)$au1->id, 'Administrative'));
        $this->assert_moodle_exception('err_ausatisfied', fn() =>
            attempt_manager::waive($this->instance_row(), $this->cm(), $userid, (int)$au2->id, 'Administrative'));
        $this->assertSame(1, $DB->count_records('scormmakercmi5_stmt', ['verb' => vocab::VERB_WAIVED]));

        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        try {
            attempt_manager::waive($this->instance_row(), $this->cm(), (int)$other->id, (int)$au1->id, 'Outro');
            $this->fail('A reason outside the cmi5 vocabulary was accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertFalse($DB->record_exists('scormmakercmi5_reg', ['userid' => $other->id]));
        }

        $otherinstance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $this->course->id]);
        $foreign = $DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $otherinstance->currentpkgid], MUST_EXIST);
        $this->assert_moodle_exception('err_aunotinpkg', fn() =>
            attempt_manager::waive($this->instance_row(), $this->cm(), (int)$other->id, (int)$foreign, 'Administrative'));

        $stranger = $this->getDataGenerator()->create_user();
        $this->assert_moodle_exception('err_notlearner', fn() =>
            attempt_manager::waive($this->instance_row(), $this->cm(), (int)$stranger->id, (int)$au1->id, 'Administrative'));
        $this->assertFalse($DB->record_exists('scormmakercmi5_reg', ['userid' => $stranger->id]));

        // A learner who never launched: the registration is created and the AU waived.
        attempt_manager::waive($this->instance_row(), $this->cm(), (int)$other->id, (int)$au2->id, 'Equivalent AU');
        $reg = $this->registration((int)$other->id);
        $this->assertSame('Equivalent AU', $DB->get_field('scormmakercmi5_regau', 'waivedreason', ['regid' => $reg->id,
            'auid' => $au2->id]));
        $this->assertSame(0, (int)$reg->satisfied);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state((int)$other->id));

        $this->setUser($this->user);
        $this->expectException(\required_capability_exception::class);
        attempt_manager::waive($this->instance_row(), $this->cm(), $userid, (int)$au1->id, 'Administrative');
    }

    /**
     * Every reason of the cmi5 vocabulary (§9.5.5.2) is accepted and recorded as in the spec.
     */
    public function test_every_reason(): void {
        global $DB;
        $this->setup_activity();
        [$au1] = $this->aus();
        $this->setUser($this->teacher);
        foreach (vocab::WAIVE_REASONS as $reason) {
            $learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            attempt_manager::waive($this->instance_row(), $this->cm(), (int)$learner->id, (int)$au1->id, $reason);
            $payload = json_decode($DB->get_field('scormmakercmi5_stmt', 'payload', ['userid' => $learner->id,
                'verb' => vocab::VERB_WAIVED]), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($reason, $payload['result']['extensions'][vocab::EXT_REASON]);
        }
    }

    /**
     * Deleting the attempt of a learner removes the registration with its regau, regblock, sessions, statements and
     * State of that learner only; the Agent Profile stays; the grade goes back to null and the completion to
     * incomplete; the open session of the learner gets 401 at once; a new launch makes a new registration on the
     * current package version.
     */
    public function test_delete_attempt(): void {
        global $DB;
        $this->setup_activity();
        [$au1, $au2] = $this->aus();
        $userid = (int)$this->user->id;
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        foreach ([$userid, (int)$other->id] as $uid) {
            $this->learn_au($uid, (int)$au1->id, vocab::VERB_PASSED, 0.9);
            $this->learn_au($uid, (int)$au2->id, vocab::VERB_PASSED, 0.7);
        }
        // An open session of the learner, as in another tab.
        [$open, $token] = $this->learn_au($userid, (int)$au1->id, vocab::VERB_COMPLETED);
        $terminated = $this->cmi5_statement($open, vocab::VERB_TERMINATED);
        $old = $this->registration($userid);
        $this->assertSame(1, (int)$old->satisfied);
        $this->assertSame(COMPLETION_COMPLETE, $this->completion_state($userid));
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade($userid), 0.00001);
        $this->assertGreaterThan(0, $DB->count_records('scormmakercmi5_regblock', ['regid' => $old->id]));
        $this->assertGreaterThan(0, $DB->count_records('scormmakercmi5_state', ['userid' => $userid]));
        $otherbefore = $this->counts((int)$other->id);
        $prefs = $DB->count_records('scormmakercmi5_agentprof', ['userid' => $userid]);
        $this->assertSame(1, $prefs);

        $this->setUser($this->teacher);
        $sink = $this->redirectEvents();
        attempt_manager::delete_attempt($this->instance_row(), $this->cm(), $userid);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof attempt_deleted));
        $sink->close();

        $none = ['reg' => 0, 'regau' => 0, 'regblock' => 0, 'session' => 0, 'stmt' => 0, 'state' => 0];
        $this->assertSame($none, $this->counts($userid, (int)$old->id));
        $this->assertSame($prefs, $DB->count_records('scormmakercmi5_agentprof', ['userid' => $userid]));
        $this->assertSame($otherbefore, $this->counts((int)$other->id));
        $this->assertNull($this->gradebook_grade($userid));
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade((int)$other->id), 0.00001);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state($userid));
        $this->assertSame(COMPLETION_COMPLETE, $this->completion_state((int)$other->id));

        $this->assertCount(1, $events);
        $this->assertEquals($old->id, $events[0]->objectid);
        $this->assertSame('scormmakercmi5_reg', $events[0]->objecttable);
        $this->assertEquals($userid, $events[0]->relateduserid);
        $this->assertSame('d', $events[0]->crud);
        $this->assertNotEmpty($events[0]->get_description());
        $this->assertNotEmpty(attempt_deleted::get_name());

        // The token of the open session is refused at once.
        $this->assertSame(401, $this->post_statements($token, [$terminated])->status);

        // A new package version, then a new launch: a new registration on it.
        $draft = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_draft_package(
            builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml([
                ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed'],
            ])])
        );
        $data = $this->instance_row();
        $data->instance = $data->id;
        $data->coursemodule = $this->instance->cmid;
        $data->packagefile = $draft;
        $data->confirmreplace = 1;
        $this->assertTrue(scormmakercmi5_update_instance($data));
        $instance = $this->instance_row();
        $this->assertNotEquals($old->pkgid, $instance->currentpkgid);
        $newau = $DB->get_record('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], '*', MUST_EXIST);
        $this->launch_with_token($userid, (int)$newau->id);
        $new = $this->registration($userid);
        $this->assertNotEquals($old->id, $new->id);
        $this->assertNotSame($old->registration, $new->registration);
        $this->assertEquals($instance->currentpkgid, $new->pkgid);
    }

    /**
     * No attempt to delete, a learner of another group in separate groups, and a user without manageattempts.
     */
    public function test_delete_attempt_refusals(): void {
        global $DB;
        $this->setup_activity();
        [$au1] = $this->aus();
        $this->setUser($this->teacher);
        $this->assert_moodle_exception('err_noattempt', fn() =>
            attempt_manager::delete_attempt($this->instance_row(), $this->cm(), (int)$this->user->id));

        $this->learn_au((int)$this->user->id, (int)$au1->id, vocab::VERB_PASSED, 0.5);

        // Separate groups: a non-editing teacher allowed to manage attempts, in another group, cannot.
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->instance->cmid]);
        rebuild_course_cache($this->course->id, true);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $tutor = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $tutor->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $this->user->id]);
        $role = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        $context = \context_module::instance($this->instance->cmid);
        assign_capability('mod/scormmakercmi5:manageattempts', CAP_ALLOW, $role, $context->id);
        $this->setUser($tutor);
        $this->assert_moodle_exception('err_usernotingroup', fn() =>
            attempt_manager::delete_attempt($this->instance_row(), $this->cm(), (int)$this->user->id));
        $this->assert_moodle_exception('err_usernotingroup', fn() =>
            attempt_manager::waive($this->instance_row(), $this->cm(), (int)$this->user->id, (int)$au1->id, 'Administrative'));
        $this->assertTrue($DB->record_exists('scormmakercmi5_reg', ['userid' => $this->user->id]));

        $this->setUser($this->user);
        $this->expectException(\required_capability_exception::class);
        attempt_manager::delete_attempt($this->instance_row(), $this->cm(), (int)$this->user->id);
    }

    /**
     * While another request holds the registration lock (an ingestion, a launch), both actions refuse with
     * err_attemptbusy and change nothing; the course reset skips that registration and says so in its status.
     */
    public function test_busy_registration(): void {
        global $CFG, $DB;
        $this->setup_activity();
        [$au1] = $this->aus();
        $userid = (int)$this->user->id;
        $this->learn_au($userid, (int)$au1->id, vocab::VERB_COMPLETED);
        $reg = $this->registration($userid);
        $before = $this->counts($userid);

        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $timeout = new \ReflectionProperty(attempt_manager::class, 'locktimeout');
        $timeout->setValue(null, 0);
        $lock = launcher::registration_lock((int)$reg->id, 0);
        $this->assertNotFalse($lock);
        try {
            $this->setUser($this->teacher);
            $this->assert_moodle_exception('err_attemptbusy', fn() =>
                attempt_manager::waive($this->instance_row(), $this->cm(), $userid, (int)$au1->id, 'Administrative'));
            $this->assert_moodle_exception('err_attemptbusy', fn() =>
                attempt_manager::delete_attempt($this->instance_row(), $this->cm(), $userid));
            $status = reset_course_userdata((object)['id' => $this->course->id, 'reset_scormmakercmi5' => 1]);
            $mine = array_values(array_filter(
                $status,
                fn($s) => $s['component'] === get_string('modulenameplural', 'mod_scormmakercmi5')
            ));
            $this->assertSame(get_string('err_resetbusy', 'mod_scormmakercmi5', 1), $mine[0]['error']);
            $this->assertSame($before, $this->counts($userid));
            $this->assertSame(0, (int)$DB->get_field('scormmakercmi5_regau', 'waived', ['regid' => $reg->id,
                'auid' => $au1->id]));
        } finally {
            $lock->release();
            $timeout->setValue(null, 5);
        }
        attempt_manager::delete_attempt($this->instance_row(), $this->cm(), $userid);
        $this->assertFalse($DB->record_exists('scormmakercmi5_reg', ['id' => $reg->id]));
    }

    /**
     * The activity: block b1 {AU1 Passed} and AU2 Passed; grade 100; automatic completion with the rule on.
     */
    private function setup_activity(): void {
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/b1', 'children' => [
                ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed'],
            ]],
            ['id' => 'https://example.com/au/2', 'url' => 'index.html', 'moveOn' => 'Passed'],
        ]]), ['grade' => 100, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsatisfied' => 1]);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * The AUs of the current package, in order.
     *
     * @return \stdClass[]
     */
    private function aus(): array {
        global $DB;
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $this->instance->id], MUST_EXIST);
        return array_values($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder'));
    }

    /**
     * The instance row.
     *
     * @return \stdClass
     */
    private function instance_row(): \stdClass {
        global $DB;
        return $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
    }

    /**
     * The course module.
     *
     * @return \cm_info
     */
    private function cm(): \cm_info {
        return get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
    }

    /**
     * The registration of a learner.
     *
     * @param int $userid Learner.
     * @return \stdClass
     */
    private function registration(int $userid): \stdClass {
        global $DB;
        $key = ['scormmakercmi5id' => $this->instance->id, 'userid' => $userid];
        return $DB->get_record('scormmakercmi5_reg', $key, '*', MUST_EXIST);
    }

    /**
     * Row counts of the learner data of a user in the instance.
     *
     * @param int $userid User.
     * @param int|null $regid Registration (the current one if null).
     * @return array table => count.
     */
    private function counts(int $userid, ?int $regid = null): array {
        global $DB;
        $regid = $regid ?? (int)$this->registration($userid)->id;
        $instance = ['scormmakercmi5id' => $this->instance->id, 'userid' => $userid];
        return [
            'reg' => $DB->count_records('scormmakercmi5_reg', $instance),
            'regau' => $DB->count_records('scormmakercmi5_regau', ['regid' => $regid]),
            'regblock' => $DB->count_records('scormmakercmi5_regblock', ['regid' => $regid]),
            'session' => $DB->count_records('scormmakercmi5_session', ['regid' => $regid]),
            'stmt' => $DB->count_records('scormmakercmi5_stmt', $instance),
            'state' => $DB->count_records('scormmakercmi5_state', $instance),
        ];
    }

    /**
     * The stored completion state of a learner.
     *
     * @param int $userid Learner.
     * @return int
     */
    private function completion_state(int $userid): int {
        $info = new \completion_info(get_course($this->course->id));
        return (int)$info->get_data($this->cm(), false, $userid)->completionstate;
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

    /**
     * Asserts that a callback throws a moodle_exception of this plugin with the error code.
     *
     * @param string $code Error code.
     * @param callable $callback Callback.
     */
    private function assert_moodle_exception(string $code, callable $callback): void {
        try {
            $callback();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
            return;
        }
        $this->fail("No exception {$code}");
    }
}
