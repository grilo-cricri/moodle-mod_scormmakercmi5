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

namespace mod_scormmakercmi5\local\grading;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/cm_api.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\grading\grade_calculator::class)]
/**
 * Tests for the grade of the activity in the gradebook (design §6.6; R6.1).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\grading\grade_calculator
 */
final class grade_calculator_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /**
     * Two AUs: AU1 passed 0.9; AU2 failed 0.6, then failed 0.4 in a later session. Highest = (0.9 + 0.6) / 2,
     * last = (0.9 + 0.4) / 2; a lower failed never lowers the highest.
     */
    public function test_highest_and_last_through_ingestion(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100, 'grademethod' => 0]);
        [$au1, $au2] = $this->aus();

        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.9);
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);
        $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.6);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);
        $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.4);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);

        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $this->instance->id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(0.75, grade_calculator::registration_grade($reg, 0), 0.000001);
        $this->assertEqualsWithDelta(0.65, grade_calculator::registration_grade($reg, 1), 0.000001);
        $this->assertEqualsWithDelta(0.75, (float)$reg->grade, 0.000001);

        // Grading method changed to "last": the recompute from regau gives 65.
        $DB->set_field('scormmakercmi5', 'grademethod', 1, ['id' => $this->instance->id]);
        scormmakercmi5_update_grades($this->instance_row(), (int)$this->user->id);
        $this->assertEqualsWithDelta(65.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * Method "last" applied by the ingestion itself.
     */
    public function test_last_through_ingestion(): void {
        $this->setup_two_aus(['grade' => 100, 'grademethod' => 1]);
        [$au1, $au2] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.9);
        $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.6);
        $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.4);
        $this->assertEqualsWithDelta(65.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * No AU with a score: rawgrade null; a passed without score does not create a grade.
     */
    public function test_no_score_gives_null(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100]);
        [$au1] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, null);
        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $this->instance->id], '*', MUST_EXIST);
        $this->assertNull(grade_calculator::registration_grade($reg, 0));
        grade_calculator::update_user($this->instance_row(), (int)$this->user->id);
        $this->assertNull($this->gradebook_grade());
        $grades = scormmakercmi5_get_user_grades($this->instance_row(), (int)$this->user->id);
        $this->assertNull($grades[$this->user->id]->rawgrade);
    }

    /**
     * An AU without a pass score is graded by "scored" statements: the grade of a session is its latest "scored" by
     * timestamp (a restart inside the course), and the grading method takes the sessions as it takes passed/failed.
     * Only sessions launched in Normal mode and only statements about the AU itself count; an AU whose passed/failed
     * carries a score keeps that score.
     */
    public function test_scored_grades_an_au_without_pass_score(): void {
        $this->setup_two_aus(['grade' => 100, 'grademethod' => 0, 'browsemode' => 1, 'reviewmode' => 1], 'Completed');
        [$au1, $au2] = $this->aus();

        // One session: 95 is posted last but has the oldest timestamp, so the session grade is 70.
        $this->score((int)$au1->id, 'Normal', ['2026-09-30T12:00:01.000Z' => 40, '2026-09-30T12:00:02.000Z' => 70,
            '2026-09-30T11:59:00.000Z' => 95]);
        $this->assertEqualsWithDelta(70.0, $this->gradebook_grade(), 0.00001);
        // A second session with 90: the highest of the sessions.
        $this->score((int)$au1->id, 'Normal', ['2026-09-30T12:30:00.000Z' => 90]);
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);
        // Browse and Review never grade, even later.
        $this->score((int)$au1->id, 'Browse', ['2026-09-30T13:00:00.000Z' => 100]);
        $this->score((int)$au1->id, 'Review', ['2026-09-30T13:00:01.000Z' => 100]);
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);
        // A "scored" about something else than the AU is not its grade.
        $this->score((int)$au1->id, 'Normal', ['2026-09-30T14:00:00.000Z' => 100], 'https://example.com/au/1/questao/q1');
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);

        // AU2: a passed with a score wins over a later "scored".
        [$session, $token] = $this->launch_with_token(null, (int)$au2->id);
        $batch = [
            $this->cmi5_statement($session, vocab::VERB_INITIALIZED),
            $this->cmi5_statement($session, vocab::VERB_PASSED, 0.5),
            $this->scored_statement($session, 100, '2026-09-30T15:00:00.000Z'),
        ];
        $this->assertSame(200, $this->post_statements($token, $batch)->status);
        $this->assertEqualsWithDelta(70.0, $this->gradebook_grade(), 0.00001);
        $this->assertEqualsWithDelta(0.7, (float)$this->registration()->grade, 0.000001);

        // The recompute from scratch gives the same.
        scormmakercmi5_update_grades($this->instance_row(), (int)$this->user->id);
        $this->assertEqualsWithDelta(70.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * Two Normal sessions with only "scored", 90 then 40: "highest" gives 90 and "last" gives 40, through the ingestion
     * and through the recompute when the method changes, as for passed/failed.
     */
    public function test_scored_sessions_follow_the_grading_method(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100, 'grademethod' => grade_calculator::METHOD_HIGHEST], 'Completed');
        [$au1] = $this->aus();
        $this->score((int)$au1->id, 'Normal', ['2026-09-30T12:00:00.000Z' => 90]);
        $this->score((int)$au1->id, 'Normal', ['2026-09-30T13:00:00.000Z' => 40]);
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);
        $reg = $this->registration();
        $this->assertEqualsWithDelta(0.9, grade_calculator::registration_grade($reg, grade_calculator::METHOD_HIGHEST), 0.000001);
        $this->assertEqualsWithDelta(0.4, grade_calculator::registration_grade($reg, grade_calculator::METHOD_LAST), 0.000001);

        $DB->set_field('scormmakercmi5', 'grademethod', grade_calculator::METHOD_LAST, ['id' => $this->instance->id]);
        scormmakercmi5_update_grades($this->instance_row(), (int)$this->user->id);
        $this->assertEqualsWithDelta(40.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * Method "last" applied by the ingestion itself to "scored" sessions.
     */
    public function test_scored_last_through_ingestion(): void {
        $this->setup_two_aus(['grade' => 100, 'grademethod' => grade_calculator::METHOD_LAST], 'Completed');
        [$au1] = $this->aus();
        $this->score((int)$au1->id, 'Normal', ['2026-09-30T12:00:00.000Z' => 90]);
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);
        $this->score((int)$au1->id, 'Normal', ['2026-09-30T13:00:00.000Z' => 40]);
        $this->assertEqualsWithDelta(40.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * An AU with a masteryScore is graded by passed/failed only: a "scored" sent to it (the player of SCORM Maker only
     * sends one without a cut-off score, so this one is forged) gives no grade, while the AU without a masteryScore is
     * still graded by its "scored".
     */
    public function test_scored_ignored_on_an_au_with_mastery_score(): void {
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed', 'masteryScore' => '0.8'],
            ['id' => 'https://example.com/au/2', 'url' => 'index.html', 'moveOn' => 'Completed'],
        ]), ['grade' => 100]);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        [$au1, $au2] = $this->aus();
        $this->assertNotNull($au1->masteryscore);

        $this->score((int)$au1->id, 'Normal', ['2026-09-30T12:00:00.000Z' => 100]);
        $this->assertNull($this->gradebook_grade());
        $this->assertNull($this->registration()->grade);
        $this->assertNull(grade_calculator::registration_grade($this->registration(), grade_calculator::METHOD_HIGHEST));

        $this->score((int)$au2->id, 'Normal', ['2026-09-30T12:10:00.000Z' => 60]);
        $this->assertEqualsWithDelta(60.0, $this->gradebook_grade(), 0.00001);

        // The passed of AU1 (with the masteryscore extension cmi5 requires) counts as always: (0.9 + 0.6) / 2.
        [$session, $token] = $this->launch_with_token(null, (int)$au1->id);
        $passed = $this->cmi5_statement($session, vocab::VERB_PASSED, 0.9);
        $passed['context']['extensions'][vocab::EXT_MASTERYSCORE] = 0.8;
        $batch = [$this->cmi5_statement($session, vocab::VERB_INITIALIZED), $passed];
        $this->assertSame(200, $this->post_statements($token, $batch)->status);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);
        scormmakercmi5_update_grades($this->instance_row(), (int)$this->user->id);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * A "scored" from a Browse session alone gives no grade.
     */
    public function test_scored_only_in_browse_gives_no_grade(): void {
        $this->setup_two_aus(['grade' => 100, 'browsemode' => 1], 'Completed');
        [$au1] = $this->aus();
        $this->score((int)$au1->id, 'Browse', ['2026-09-30T12:00:00.000Z' => 80]);
        $this->assertNull($this->gradebook_grade());
        $this->assertNull($this->registration()->grade);
    }

    /**
     * Maximum grade 50 and a score of 0.85 → 42.5.
     */
    public function test_max_grade_scales(): void {
        $this->setup_two_aus(['grade' => 50]);
        [$au1] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.85);
        $this->assertEqualsWithDelta(42.5, $this->gradebook_grade(), 0.00001);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scormmakercmi5',
            'iteminstance' => $this->instance->id, 'courseid' => $this->course->id]);
        $this->assertEqualsWithDelta(50.0, (float)$item->grademax, 0.00001);
    }

    /**
     * scormmakercmi5_update_grades() resynchronises a grade removed from the gradebook by hand, and the one lost
     * when the post-commit step fails.
     */
    public function test_update_grades_resyncs(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100]);
        [$au1, $au2] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.9);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scormmakercmi5',
            'iteminstance' => $this->instance->id, 'courseid' => $this->course->id]);
        $DB->delete_records('grade_grades', ['itemid' => $item->id, 'userid' => $this->user->id]);
        $this->assertNull($this->gradebook_grade());

        // The grade step after the commit fails: the batch is stored, the gradebook stays behind.
        $hook = new \ReflectionProperty(statement_store::class, 'testhook');
        $hook->setValue(null, function (string $stage): void {
            if ($stage === 'grade') {
                throw new \coding_exception('grade failure');
            }
        });
        try {
            $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.6);
        } finally {
            $hook->setValue(null, null);
        }
        $this->assertDebuggingCalled();
        // The launch of AU2 repaired the grade removed by hand (0.9), the batch after it failed to push 0.75.
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);

        scormmakercmi5_update_grades($this->instance_row(), (int)$this->user->id);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);

        // All users (userid 0).
        $DB->delete_records('grade_grades', ['itemid' => $item->id, 'userid' => $this->user->id]);
        scormmakercmi5_update_grades($this->instance_row());
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * Final review N-1: a grade lost after the commit is repaired by repair_user() (view.php) and by the next launch,
     * not only by a later scored statement; repair_user() leaves a consistent grade alone.
     */
    public function test_repair_user_fixes_a_lost_grade(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100]);
        [$au1, $au2] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.9);
        $this->fail_grade_step(fn() => $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.6));
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001, 'the gradebook stayed behind');

        grade_calculator::repair_user($this->instance_row(), (int)$this->user->id);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);
        $this->assertEqualsWithDelta(0.75, (float)$this->registration()->grade, 0.00001);

        // Consistent: nothing is written again.
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scormmakercmi5',
            'iteminstance' => $this->instance->id, 'courseid' => $this->course->id]);
        $DB->set_field('grade_grades', 'timemodified', 1, ['itemid' => $item->id, 'userid' => $this->user->id]);
        grade_calculator::repair_user($this->instance_row(), (int)$this->user->id);
        $where = ['itemid' => $item->id, 'userid' => $this->user->id];
        $this->assertEquals(1, $DB->get_field('grade_grades', 'timemodified', $where));

        // Lost again, then repaired by the next launch (with no statement at all).
        $DB->delete_records('grade_grades', ['itemid' => $item->id, 'userid' => $this->user->id]);
        $this->assertNull($this->gradebook_grade());
        $this->launch_with_token(null, (int)$au1->id);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * repair_user() creates nothing for a learner without a registration, clears a grade left after the registration
     * is gone, and does nothing on a scale.
     */
    public function test_repair_user_without_registration_or_on_a_scale(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100]);
        grade_calculator::repair_user($this->instance_row(), (int)$this->user->id);
        $this->assertFalse($DB->record_exists('scormmakercmi5_reg', ['scormmakercmi5id' => $this->instance->id]));
        $this->assertNull($this->gradebook_grade());

        // A grade left in the gradebook after the registration is gone (the grade step of a delete failed).
        [$au1] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.9);
        $this->assertEqualsWithDelta(90.0, $this->gradebook_grade(), 0.00001);
        $DB->delete_records('scormmakercmi5_reg', ['scormmakercmi5id' => $this->instance->id]);
        grade_calculator::repair_user($this->instance_row(), (int)$this->user->id);
        $this->assertNull($this->gradebook_grade());

        $DB->set_field('scormmakercmi5', 'grade', -1, ['id' => $this->instance->id]);
        grade_calculator::repair_user($this->instance_row(), (int)$this->user->id);
        $this->assertNull($this->gradebook_grade());
    }

    /**
     * Runs a step with the grade update after the commit failing.
     *
     * @param callable $step Step.
     */
    private function fail_grade_step(callable $step): void {
        $hook = new \ReflectionProperty(statement_store::class, 'testhook');
        $hook->setValue(null, function (string $stage): void {
            if ($stage === 'grade') {
                throw new \coding_exception('grade failure');
            }
        });
        try {
            $step();
        } finally {
            $hook->setValue(null, null);
        }
        $this->assertDebuggingCalled();
    }

    /**
     * Changing the grading method in the settings (update_instance) recomputes the grades from regau.
     */
    public function test_grademethod_change_through_update_instance(): void {
        $this->setup_two_aus(['grade' => 100, 'grademethod' => 0]);
        [$au1, $au2] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.9);
        $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.6);
        $this->learn((int)$au2->id, vocab::VERB_FAILED, 0.4);
        $this->assertEqualsWithDelta(75.0, $this->gradebook_grade(), 0.00001);

        $data = $this->instance_row();
        $data->instance = $data->id;
        $data->coursemodule = $this->instance->cmid;
        $data->grademethod = 1;
        scormmakercmi5_update_instance($data);
        $this->assertEqualsWithDelta(65.0, $this->gradebook_grade(), 0.00001);
        $this->assertEqualsWithDelta(0.65, (float)$this->registration()->grade, 0.000001);

        // A new maximum grade: recomputed from the scores, not rescaled from the old grade (design §6.6).
        $data->grade = 20;
        scormmakercmi5_update_instance($data);
        $this->assertEqualsWithDelta(13.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * The rescale callback recomputes from regau with the new maximum grade.
     */
    public function test_rescale_callback(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100]);
        [$au1] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.8);
        $this->assertEqualsWithDelta(80.0, $this->gradebook_grade(), 0.00001);
        $DB->set_field('scormmakercmi5', 'grade', 10, ['id' => $this->instance->id]);
        $cm = get_coursemodule_from_id('scormmakercmi5', $this->instance->cmid, 0, false, MUST_EXIST);
        $this->assertTrue(scormmakercmi5_rescale_activity_grades($this->course, $cm, 0, 100, 0, 10));
        $this->assertEqualsWithDelta(8.0, $this->gradebook_grade(), 0.00001);
    }

    /**
     * A scale or "no grade" gets no automatic grade: the item has the right type and rawgrade is null, never a
     * negative value (score × negative scale id).
     */
    public function test_scale_and_no_grade(): void {
        global $DB;
        $this->setup_two_aus(['grade' => 100]);
        [$au1] = $this->aus();
        $this->learn((int)$au1->id, vocab::VERB_PASSED, 0.8);
        $params = ['itemtype' => 'mod', 'itemmodule' => 'scormmakercmi5', 'iteminstance' => $this->instance->id,
            'courseid' => $this->course->id];

        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Ruim,Bom,Ótimo']);
        $DB->set_field('scormmakercmi5', 'grade', -(int)$scale->id, ['id' => $this->instance->id]);
        $grades = scormmakercmi5_get_user_grades($this->instance_row(), (int)$this->user->id);
        $this->assertNull($grades[$this->user->id]->rawgrade);
        scormmakercmi5_update_grades($this->instance_row());
        $item = \grade_item::fetch($params);
        $this->assertSame((int)GRADE_TYPE_SCALE, (int)$item->gradetype);
        $this->assertSame((int)$scale->id, (int)$item->scaleid);
        // The 0–1 grade stays in reg.grade (reports use it).
        $this->assertEqualsWithDelta(0.8, (float)$this->registration()->grade, 0.000001);

        $DB->set_field('scormmakercmi5', 'grade', 0, ['id' => $this->instance->id]);
        $this->assertNull(scormmakercmi5_get_user_grades($this->instance_row(), (int)$this->user->id)[$this->user->id]->rawgrade);
        scormmakercmi5_update_grades($this->instance_row());
        $this->assertSame((int)GRADE_TYPE_NONE, (int)\grade_item::fetch($params)->gradetype);
    }

    /**
     * The grade item is created with the instance, follows its maximum grade, and is deleted with it.
     */
    public function test_grade_item_lifecycle(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->setup_two_aus(['grade' => 80]);
        $params = ['itemtype' => 'mod', 'itemmodule' => 'scormmakercmi5', 'iteminstance' => $this->instance->id,
            'courseid' => $this->course->id];
        $item = \grade_item::fetch($params);
        $this->assertNotFalse($item);
        $this->assertEqualsWithDelta(80.0, (float)$item->grademax, 0.00001);
        $this->assertSame((int)GRADE_TYPE_VALUE, (int)$item->gradetype);

        $instance = $this->instance_row();
        $instance->instance = $instance->id;
        $instance->grade = 40;
        $instance->coursemodule = $this->instance->cmid;
        scormmakercmi5_update_instance($instance);
        $this->assertEqualsWithDelta(40.0, (float)\grade_item::fetch($params)->grademax, 0.00001);

        \mod_scormmakercmi5_test_cm_api::delete($this->instance->cmid);
        $this->assertFalse(\grade_item::fetch($params));
    }

    /**
     * Course with two AUs (moveOn Passed) and the instance.
     *
     * @param array $record Instance fields.
     * @param string $moveon moveOn of both AUs.
     */
    private function setup_two_aus(array $record, string $moveon = 'Passed'): void {
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => $moveon],
            ['id' => 'https://example.com/au/2', 'url' => 'index.html', 'moveOn' => $moveon],
        ]), $record);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
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
     * Launches an AU and posts initialized + passed/failed with a score.
     *
     * @param int $auid AU.
     * @param string $verb passed or failed.
     * @param float|null $scaled Score.
     */
    private function learn(int $auid, string $verb, ?float $scaled): void {
        [$session, $token] = $this->launch_with_token(null, $auid);
        $batch = [$this->cmi5_statement($session, vocab::VERB_INITIALIZED), $this->cmi5_statement($session, $verb, $scaled)];
        $this->assertSame(200, $this->post_statements($token, $batch)->status);
    }

    /**
     * Launches an AU in a mode and posts initialized and one "scored" per timestamp.
     *
     * @param int $auid AU.
     * @param string $mode Normal, Browse or Review.
     * @param int[] $grades Timestamp => raw grade 0–100, in the order posted.
     * @param string|null $objectid Object of the statements, the AU when null.
     */
    private function score(int $auid, string $mode, array $grades, ?string $objectid = null): void {
        [$session, $token] = $this->launch_with_token(null, $auid, $mode);
        $batch = [$this->cmi5_statement($session, vocab::VERB_INITIALIZED)];
        foreach ($grades as $timestamp => $raw) {
            $batch[] = $this->scored_statement($session, $raw, $timestamp, $objectid);
        }
        $this->assertSame(200, $this->post_statements($token, $batch)->status);
    }

    /**
     * The "scored" statement of a SCORM Maker course with no pass score (Task 4 of the list after D): a cmi5 allowed
     * statement with the profile category and result.score {raw, min 0, max 100, scaled}.
     *
     * @param \stdClass $session Session.
     * @param int $raw Grade 0–100.
     * @param string $timestamp Timestamp.
     * @param string|null $objectid Object, the AU when null.
     * @return array
     */
    private function scored_statement(\stdClass $session, int $raw, string $timestamp, ?string $objectid = null): array {
        $st = $this->cmi5_statement($session, vocab::VERB_INITIALIZED);
        $st['verb'] = ['id' => 'http://adlnet.gov/expapi/verbs/scored', 'display' => ['pt-BR' => 'pontuou em']];
        $st['timestamp'] = $timestamp;
        if ($objectid !== null) {
            $st['object']['id'] = $objectid;
        }
        $st['context']['contextActivities']['category'] = [['id' => vocab::SM_PROFILE]];
        $st['result'] = ['score' => ['raw' => $raw, 'min' => 0, 'max' => 100, 'scaled' => $raw / 100]];
        return $st;
    }

    /**
     * The registration of the learner.
     *
     * @return \stdClass
     */
    private function registration(): \stdClass {
        global $DB;
        return $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $this->instance->id], '*', MUST_EXIST);
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
     * The learner's grade in the gradebook, null when there is none.
     *
     * @return float|null
     */
    private function gradebook_grade(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'scormmakercmi5', $this->instance->id, $this->user->id);
        $grade = $grades->items[0]->grades[$this->user->id]->grade ?? null;
        return $grade === null ? null : (float)$grade;
    }
}
