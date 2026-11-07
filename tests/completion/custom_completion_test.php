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

namespace mod_scormmakercmi5\completion;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\completion\custom_completion::class)]
/**
 * Tests for the completion rule "satisfy the cmi5 course" (design §6.6; R6.2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var array Instance with the rule on, automatic completion. */
    private const RULE_ON = ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsatisfied' => 1];

    /**
     * The defined rules, the sort order and the descriptions.
     */
    public function test_defined_rules_and_descriptions(): void {
        $this->setup_lrs_activity(self::NOW, $this->passed_xml(), self::RULE_ON);
        $this->assertSame(['completionsatisfied'], custom_completion::get_defined_custom_rules());
        $completion = new custom_completion($this->cm(), (int)$this->user->id);
        $order = ['completionview', 'completionsatisfied', 'completionusegrade', 'completionpassgrade'];
        $this->assertSame($order, $completion->get_sort_order());
        $this->assertSame(
            ['completionsatisfied' => get_string('completiondetail:satisfied', 'mod_scormmakercmi5')],
            $completion->get_custom_rule_descriptions()
        );
        $this->assertSame(
            [get_string('completionsatisfied', 'mod_scormmakercmi5')],
            mod_scormmakercmi5_get_completion_active_rule_descriptions($this->cm())
        );
    }

    /**
     * get_state() follows reg.satisfied; no registration is incomplete.
     */
    public function test_state_follows_registration(): void {
        global $DB;
        $this->setup_lrs_activity(self::NOW, $this->passed_xml(), self::RULE_ON);
        $completion = new custom_completion($this->cm(), (int)$this->user->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionsatisfied'));

        $this->launch_with_token();
        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $this->instance->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$reg->satisfied);
        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionsatisfied'));
        $DB->set_field('scormmakercmi5_reg', 'satisfied', 1, ['id' => $reg->id]);
        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionsatisfied'));
    }

    /**
     * Satisfying the course through the ingestion completes the activity.
     */
    public function test_ingestion_completes_activity(): void {
        $this->setup_lrs_activity(self::NOW, $this->passed_xml(), self::RULE_ON);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        $info = new \completion_info($this->course);
        $userid = (int)$this->user->id;
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);

        [$session, $token] = $this->launch_with_token();
        $batch = [$this->cmi5_statement($session, vocab::VERB_INITIALIZED)];
        $this->assertSame(200, $this->post_statements($token, $batch)->status);
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);

        $batch = [$this->cmi5_statement($session, vocab::VERB_PASSED)];
        $this->assertSame(200, $this->post_statements($token, $batch)->status);
        $this->assertSame(COMPLETION_COMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);
    }

    /**
     * A grade step that throws after the commit does not hold back the completion of the batch that satisfied the
     * course (the two run in separate tries).
     */
    public function test_grade_failure_still_completes(): void {
        $this->setup_lrs_activity(self::NOW, $this->passed_xml(), self::RULE_ON);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        [$session, $token] = $this->launch_with_token();
        $batch = [
            $this->cmi5_statement($session, vocab::VERB_INITIALIZED),
            $this->cmi5_statement($session, vocab::VERB_PASSED, 0.9),
        ];
        $this->with_failing_stage('grade', function () use ($token, $batch): void {
            $this->assertSame(200, $this->post_statements($token, $batch)->status);
        });
        $this->assertDebuggingCalled();
        $info = new \completion_info($this->course);
        $this->assertSame(COMPLETION_COMPLETE, (int)$info->get_data($this->cm(), false, (int)$this->user->id)->completionstate);
    }

    /**
     * A completion update that failed after the commit is repaired by repair_user() (called by view.php) and by the
     * next launch; repair_user() does nothing when the course is not satisfied.
     */
    public function test_failed_completion_is_repaired(): void {
        $this->setup_lrs_activity(self::NOW, $this->passed_xml(), self::RULE_ON);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        $userid = (int)$this->user->id;
        $info = new \completion_info($this->course);
        [$session, $token] = $this->launch_with_token();
        custom_completion::repair_user($this->cm(), $userid);
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);

        $batch = [$this->cmi5_statement($session, vocab::VERB_INITIALIZED), $this->cmi5_statement($session, vocab::VERB_PASSED)];
        $this->with_failing_stage('completion', function () use ($token, $batch): void {
            $this->assertSame(200, $this->post_statements($token, $batch)->status);
        });
        $this->assertDebuggingCalled();
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);

        custom_completion::repair_user($this->cm(), $userid);
        $this->assertSame(COMPLETION_COMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);

        // The same repair on launch: the stored state is reset by hand to the failure, then a launch completes it.
        global $DB;
        $DB->delete_records('course_modules_completion', ['coursemoduleid' => $this->instance->cmid, 'userid' => $userid]);
        \cache::make('core', 'completion')->purge();
        $this->assertSame(COMPLETION_INCOMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);
        $this->launch_with_token();
        $this->assertSame(COMPLETION_COMPLETE, (int)$info->get_data($this->cm(), false, $userid)->completionstate);
    }

    /**
     * A course whose AUs are all NotApplicable is satisfied when the registration is created (R6.3): the launch
     * completes the activity.
     */
    public function test_notapplicable_completes_at_launch(): void {
        $this->setup_lrs_activity(self::NOW, null, self::RULE_ON);
        $info = new \completion_info($this->course);
        $this->launch_with_token();
        $state = (int)$info->get_data($this->cm(), false, (int)$this->user->id)->completionstate;
        $this->assertSame(COMPLETION_COMPLETE, $state);
    }

    /**
     * With the rule off, a satisfied course does not complete the activity.
     */
    public function test_rule_off(): void {
        $this->setup_lrs_activity(self::NOW, null, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
        $info = new \completion_info($this->course);
        $this->launch_with_token();
        $state = (int)$info->get_data($this->cm(), false, (int)$this->user->id)->completionstate;
        $this->assertSame(COMPLETION_INCOMPLETE, $state);
        $this->assertSame([], mod_scormmakercmi5_get_completion_active_rule_descriptions($this->cm()));
    }

    /**
     * One AU with moveOn Passed.
     *
     * @return string
     */
    private function passed_xml(): string {
        return builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed']]);
    }

    /**
     * Runs $callback with the store's post-commit stage $stage throwing.
     *
     * @param string $stage Seam stage.
     * @param callable $callback Callback.
     */
    private function with_failing_stage(string $stage, callable $callback): void {
        $hook = new \ReflectionProperty(\mod_scormmakercmi5\local\lrs\statement_store::class, 'testhook');
        $hook->setValue(null, function (string $current) use ($stage): void {
            if ($current === $stage) {
                throw new \coding_exception($stage . ' failure');
            }
        });
        try {
            $callback();
        } finally {
            $hook->setValue(null, null);
        }
    }

    /**
     * The course module.
     *
     * @return \cm_info
     */
    private function cm(): \cm_info {
        return get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
    }
}
