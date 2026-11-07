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

namespace mod_scormmakercmi5;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\runtime\launcher;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_reset_userdata')]
#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_reset_gradebook')]
#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_reset_course_form_definition')]
#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_reset_course_form_defaults')]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\attempt_manager::class)]
/**
 * Tests for the course reset callbacks (design §15, NF2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::scormmakercmi5_reset_userdata
 * @covers     ::scormmakercmi5_reset_gradebook
 * @covers     ::scormmakercmi5_reset_course_form_definition
 * @covers     ::scormmakercmi5_reset_course_form_defaults
 * @covers     \mod_scormmakercmi5\local\runtime\attempt_manager
 */
final class reset_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var \stdClass Second learner of the course. */
    private \stdClass $other;

    /** @var \stdClass Instance of another course (with cmid and course). */
    private \stdClass $foreign;

    /** @var \stdClass Learner of the other course. */
    private \stdClass $foreignuser;

    /**
     * The form has the option, checked by default.
     */
    public function test_form(): void {
        $this->resetAfterTest();
        $form = new \MoodleQuickForm('reset', 'post', '');
        scormmakercmi5_reset_course_form_definition($form);
        $this->assertTrue($form->elementExists('reset_scormmakercmi5'));
        $this->assertSame('advcheckbox', $form->getElement('reset_scormmakercmi5')->getType());
        $this->assertSame(['reset_scormmakercmi5' => 1], scormmakercmi5_reset_course_form_defaults(new \stdClass()));
    }

    /**
     * Without the option nothing is deleted.
     */
    public function test_reset_without_the_option(): void {
        $this->setup_courses();
        $before = [$this->counts((int)$this->instance->id), $this->counts((int)$this->foreign->id)];
        $status = reset_course_userdata((object)['id' => $this->course->id]);
        $this->assertSame([], array_values(array_filter($status, fn($s) => $s['component'] === $this->component())));
        $this->assertSame($before, [$this->counts((int)$this->instance->id), $this->counts((int)$this->foreign->id)]);
        $this->assertEqualsWithDelta(90.0, $this->grade($this->course->id, $this->instance, (int)$this->user->id), 0.00001);
    }

    /**
     * With the option: every registration of the course is gone with its rows, the grades leave the gradebook, the
     * completion goes back to incomplete, the Agent Profile stays, the other course is untouched, and a new launch
     * creates a new registration.
     */
    public function test_reset_deletes_the_attempts_of_the_course_only(): void {
        global $DB;
        $this->setup_courses();
        $foreignbefore = $this->counts((int)$this->foreign->id);
        $oldreg = $this->registration((int)$this->user->id);
        $prefs = $DB->count_records('scormmakercmi5_agentprof');
        $this->assertSame(COMPLETION_COMPLETE, $this->completion_state((int)$this->user->id));

        $status = reset_course_userdata((object)['id' => $this->course->id, 'reset_scormmakercmi5' => 1]);

        $mine = array_values(array_filter($status, fn($s) => $s['component'] === $this->component()));
        $this->assertSame([[
            'component' => $this->component(),
            'item' => get_string('resetattempts', 'mod_scormmakercmi5'),
            'error' => false,
        ]], $mine);
        $none = array_fill_keys(['reg', 'regau', 'regblock', 'session', 'stmt', 'state'], 0);
        $this->assertSame($none, $this->counts((int)$this->instance->id));
        $this->assertSame($foreignbefore, $this->counts((int)$this->foreign->id));
        $this->assertSame($prefs, $DB->count_records('scormmakercmi5_agentprof'));
        $this->assertNull($this->grade($this->course->id, $this->instance, (int)$this->user->id));
        $this->assertNull($this->grade($this->course->id, $this->instance, (int)$this->other->id));
        $this->assertEqualsWithDelta(
            90.0,
            $this->grade($this->foreign->course, $this->foreign, (int)$this->foreignuser->id),
            0.00001
        );
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state((int)$this->user->id));

        [$au1] = $this->aus($this->instance);
        $this->launch_with_token((int)$this->user->id, (int)$au1->id);
        $new = $this->registration((int)$this->user->id);
        $this->assertNotEquals($oldreg->id, $new->id);
        $this->assertNotSame($oldreg->registration, $new->registration);
        $this->assertSame(0, (int)$new->satisfied);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state((int)$this->user->id));
    }

    /**
     * "Delete all grades" with the attempts: the attempts and the grades are gone (core resets the gradebook, then
     * grabs the grades again through scormmakercmi5_update_grades(), and no attempt is left to grade). Alone, it
     * deletes the grades and core grabs them again from the attempts, which stay: the grade is derived from the
     * scores (design §6.6). The gradebook callback empties the items of this course only.
     */
    public function test_reset_gradebook_grades(): void {
        $this->setup_courses();
        $before = $this->counts((int)$this->instance->id);
        reset_course_userdata((object)['id' => $this->course->id, 'reset_gradebook_grades' => 1]);
        $this->assertSame($before, $this->counts((int)$this->instance->id));
        $this->assertEqualsWithDelta(90.0, $this->grade($this->course->id, $this->instance, (int)$this->user->id), 0.00001);

        scormmakercmi5_reset_gradebook($this->course->id);
        $this->assertNull($this->grade($this->course->id, $this->instance, (int)$this->user->id));
        $this->assertNull($this->grade($this->course->id, $this->instance, (int)$this->other->id));
        $this->assertEqualsWithDelta(
            90.0,
            $this->grade($this->foreign->course, $this->foreign, (int)$this->foreignuser->id),
            0.00001
        );

        scormmakercmi5_update_grades($this->instance);
        $status = reset_course_userdata((object)[
            'id' => $this->course->id,
            'reset_scormmakercmi5' => 1,
            'reset_gradebook_grades' => 1,
        ]);
        $this->assertFalse(array_values(array_filter($status, fn($s) => $s['component'] === $this->component()))[0]['error']);
        $this->assertSame(0, $this->counts((int)$this->instance->id)['reg']);
        $this->assertNull($this->grade($this->course->id, $this->instance, (int)$this->user->id));
        $this->assertEqualsWithDelta(
            90.0,
            $this->grade($this->foreign->course, $this->foreign, (int)$this->foreignuser->id),
            0.00001
        );
    }

    /**
     * Final review N-3: an attempt whose lock is busy during the reset survives it, and so does its grade; the attempts
     * deleted lose theirs.
     */
    public function test_reset_with_a_busy_attempt_keeps_its_grade(): void {
        global $CFG;
        $this->setup_courses();
        $reg = $this->registration((int)$this->user->id);
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $timeout = new \ReflectionProperty(\mod_scormmakercmi5\local\runtime\attempt_manager::class, 'locktimeout');
        $timeout->setValue(null, 0);
        $lock = launcher::registration_lock((int)$reg->id, 0);
        $this->assertNotFalse($lock);
        try {
            $status = reset_course_userdata((object)['id' => $this->course->id, 'reset_scormmakercmi5' => 1]);
        } finally {
            $lock->release();
            $timeout->setValue(null, 5);
        }
        $mine = array_values(array_filter($status, fn($s) => $s['component'] === $this->component()));
        $this->assertSame(get_string('err_resetbusy', 'mod_scormmakercmi5', 1), $mine[0]['error']);
        $this->assertSame(1, $this->counts((int)$this->instance->id)['reg']);
        $this->assertEqualsWithDelta(90.0, $this->grade($this->course->id, $this->instance, (int)$this->user->id), 0.00001);
        $this->assertNull($this->grade($this->course->id, $this->instance, (int)$this->other->id));
    }

    /**
     * Course with two learners, each with statements, State, regau, regblock and a grade; another course with its
     * own learner and data.
     */
    private function setup_courses(): void {
        $cmi5xml = builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/b1', 'children' => [
                ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed'],
            ]],
        ]]);
        $record = ['grade' => 100, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsatisfied' => 1];
        $this->setup_lrs_activity(self::NOW, $cmi5xml, $record);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        $this->other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        [$au1] = $this->aus($this->instance);
        $this->learn_au((int)$this->user->id, (int)$au1->id, vocab::VERB_PASSED, 0.9);
        $this->learn_au((int)$this->other->id, (int)$au1->id, vocab::VERB_PASSED, 0.6);

        // The other course: its instance is used through the same trait helpers, then the fields are restored.
        $course = $this->course;
        $instance = $this->instance;
        $user = $this->user;
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_instance(
            ['course' => $this->course->id, 'package_entries' => builder::minimal_entries(['cmi5.xml' => $cmi5xml])] + $record
        );
        [$foreignau] = $this->aus($this->instance);
        $this->learn_au((int)$this->user->id, (int)$foreignau->id, vocab::VERB_PASSED, 0.9);
        $this->foreign = $this->instance;
        $this->foreignuser = $this->user;
        $this->course = $course;
        $this->instance = $instance;
        $this->user = $user;

        foreach ([$this->instance->id, $this->foreign->id] as $id) {
            $counts = $this->counts((int)$id);
            foreach ($counts as $table => $count) {
                $this->assertGreaterThan(0, $count, "{$table} of instance {$id}");
            }
        }
        $this->assertEqualsWithDelta(90.0, $this->grade($this->course->id, $this->instance, (int)$this->user->id), 0.00001);
        $this->assertEqualsWithDelta(60.0, $this->grade($this->course->id, $this->instance, (int)$this->other->id), 0.00001);
    }

    /**
     * The AUs of the current package of an instance, in order.
     *
     * @param \stdClass $instance Instance.
     * @return \stdClass[]
     */
    private function aus(\stdClass $instance): array {
        global $DB;
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $instance->id], MUST_EXIST);
        return array_values($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder'));
    }

    /**
     * Row counts of the learner data of an instance.
     *
     * @param int $instanceid Instance.
     * @return array table => count.
     */
    private function counts(int $instanceid): array {
        global $DB;
        $regids = $DB->get_fieldset_select('scormmakercmi5_reg', 'id', 'scormmakercmi5id = ?', [$instanceid]);
        $byreg = function (string $table) use ($DB, $regids): int {
            if (!$regids) {
                return 0;
            }
            [$insql, $params] = $DB->get_in_or_equal($regids);
            return $DB->count_records_select($table, "regid {$insql}", $params);
        };
        return [
            'reg' => count($regids),
            'regau' => $byreg('scormmakercmi5_regau'),
            'regblock' => $byreg('scormmakercmi5_regblock'),
            'session' => $byreg('scormmakercmi5_session'),
            'stmt' => $DB->count_records('scormmakercmi5_stmt', ['scormmakercmi5id' => $instanceid]),
            'state' => $DB->count_records('scormmakercmi5_state', ['scormmakercmi5id' => $instanceid]),
        ];
    }

    /**
     * The registration of a learner in the instance of the course.
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
     * The stored completion state of a learner in the instance of the course.
     *
     * @param int $userid Learner.
     * @return int
     */
    private function completion_state(int $userid): int {
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
        $info = new \completion_info(get_course($this->course->id));
        return (int)$info->get_data($cm, false, $userid)->completionstate;
    }

    /**
     * The grade of a learner in the gradebook, null when there is none.
     *
     * @param int $courseid Course.
     * @param \stdClass $instance Instance.
     * @param int $userid Learner.
     * @return float|null
     */
    private function grade(int $courseid, \stdClass $instance, int $userid): ?float {
        $grades = grade_get_grades($courseid, 'mod', 'scormmakercmi5', $instance->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float)$grade;
    }

    /**
     * The component name of the reset status rows.
     *
     * @return string
     */
    private function component(): string {
        return get_string('modulenameplural', 'mod_scormmakercmi5');
    }
}
