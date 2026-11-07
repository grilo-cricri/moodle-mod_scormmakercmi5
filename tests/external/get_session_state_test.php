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

namespace mod_scormmakercmi5\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

use mod_scormmakercmi5\local\runtime\launcher;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\external\get_session_state::class)]
/**
 * Tests for the session state the player page polls (design §6.1 step 9).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\external\get_session_state
 */
final class get_session_state_test extends \externallib_advanced_testcase {
    /**
     * Launches the first AU of an instance as the current user.
     *
     * @param \stdClass $instance Instance.
     * @return \stdClass Session row.
     */
    private function launch(\stdClass $instance): \stdClass {
        global $DB, $USER;
        $cm = get_fast_modinfo($instance->course)->get_cm($instance->cmid);
        $auid = (int)$DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $instance->currentpkgid], MUST_EXIST);
        return launcher::launch($cm, $instance, $auid, (int)$USER->id, 'Normal')->session;
    }

    /**
     * Calls the function through the web service layer.
     *
     * @param int $cmid Course module id.
     * @param string $uuid Session uuid.
     * @return bool active
     */
    private function active(int $cmid, string $uuid): bool {
        $result = get_session_state::execute($cmid, $uuid);
        $result = \core_external\external_api::clean_returnvalue(get_session_state::execute_returns(), $result);
        return $result['active'];
    }

    /**
     * Own session: active while launched or initialized, not after a relaunch, terminated or of someone else.
     */
    public function test_execute(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5');
        $instance = $generator->create_instance(['course' => $course->id]);
        $second = $generator->create_instance(['course' => $course->id]);

        $this->setUser($student);
        $first = $this->launch($instance);
        $this->assertTrue($this->active($instance->cmid, $first->sessionuuid));
        $DB->set_field('scormmakercmi5_session', 'status', launcher::STATUS_INITIALIZED, ['id' => $first->id]);
        $this->assertTrue($this->active($instance->cmid, $first->sessionuuid));
        $this->assertFalse($this->active($second->cmid, $first->sessionuuid), 'session of another activity');

        $relaunch = $this->launch($instance);
        $this->assertFalse($this->active($instance->cmid, $first->sessionuuid), 'abandoned by the relaunch');
        $this->assertTrue($this->active($instance->cmid, $relaunch->sessionuuid));
        $DB->set_field('scormmakercmi5_session', 'status', launcher::STATUS_TERMINATED, ['id' => $relaunch->id]);
        $this->assertFalse($this->active($instance->cmid, $relaunch->sessionuuid), 'terminated');
        $this->assertFalse($this->active($instance->cmid, \core\uuid::generate()), 'unknown uuid');

        $this->setUser($other);
        $third = $this->launch($instance);
        $this->setUser($student);
        $this->assertFalse($this->active($instance->cmid, $third->sessionuuid), 'session of another user');
    }

    /**
     * Final review N-2: the answer says when the registration of the session reached maxstatementsperreg, so the
     * player can tell the learner that nothing more is recorded.
     */
    public function test_statement_limit(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        set_config('maxstatementsperreg', 5, 'mod_scormmakercmi5');
        $this->setUser($student);
        $session = $this->launch($instance);
        $call = fn() => \core_external\external_api::clean_returnvalue(
            get_session_state::execute_returns(),
            get_session_state::execute($instance->cmid, $session->sessionuuid)
        );
        $DB->set_field('scormmakercmi5_reg', 'statementcount', 4, ['id' => $session->regid]);
        $this->assertSame(['active' => true, 'statementlimit' => false], $call());
        $DB->set_field('scormmakercmi5_reg', 'statementcount', 5, ['id' => $session->regid]);
        $this->assertSame(['active' => true, 'statementlimit' => true], $call());
        $this->assertSame(['active' => false, 'statementlimit' => false], \core_external\external_api::clean_returnvalue(
            get_session_state::execute_returns(),
            get_session_state::execute($instance->cmid, \core\uuid::generate())
        ), 'unknown session');
    }

    /**
     * Without the view capability, or with the activity hidden, the call is refused.
     */
    public function test_access(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        $this->setUser($student);
        $session = $this->launch($instance);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $context = \context_module::instance($instance->cmid);
        assign_capability('mod/scormmakercmi5:view', CAP_PROHIBIT, $roleid, $context->id);
        // A real request builds modinfo afresh; the one cached by the launch above still says visible, and then
        // validate_context() passes and the require_capability() right after it refuses instead.
        get_fast_modinfo($course->id, 0, true);
        try {
            $this->active($instance->cmid, $session->sessionuuid);
            $this->fail('view prohibited must be refused');
        } catch (\core\exception\require_login_exception $e) {
            // Core hides an activity from a user without mod/<name>:view, so validate_context() already refuses it.
            $this->assertStringContainsString('hidden', $e->getMessage());
        }
        unassign_capability('mod/scormmakercmi5:view', $roleid, $context->id);
        get_fast_modinfo($course->id, 0, true);
        $this->assertTrue($this->active($instance->cmid, $session->sessionuuid), 'allowed again');

        set_coursemodule_visible($instance->cmid, 0);
        $this->expectException(\core\exception\require_login_exception::class);
        $this->active($instance->cmid, $session->sessionuuid);
    }
}
