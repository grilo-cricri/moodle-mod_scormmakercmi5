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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\access_check::class)]
/**
 * Tests for access_check, the "may this learner still take the activity" question behind the LRS token.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\runtime\access_check
 */
final class access_check_test extends \advanced_testcase {
    /**
     * A cm_info built for ANOTHER user (here the admin, who sees a hidden activity) must not decide for the learner:
     * the check looks at the modinfo of the user it was asked about.
     */
    public function test_cm_built_for_another_user_does_not_leak_visibility(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_instance([
            'course' => $course->id, 'visible' => 0,
        ]);
        $this->setAdminUser();
        $admincm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $this->assertTrue($admincm->uservisible, 'the admin sees the hidden activity');

        $this->assertFalse(access_check::user_can_launch($course, $admincm, (int)$student->id));

        // Made visible, the same call lets the learner in, with the cm of the admin or of the learner.
        set_coursemodule_visible($instance->cmid, 1);
        rebuild_course_cache($course->id, true);
        $admincm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $studentcm = get_fast_modinfo($course, $student->id)->get_cm($instance->cmid);
        $this->assertTrue(access_check::user_can_launch($course, $admincm, (int)$student->id));
        $this->assertTrue(access_check::user_can_launch($course, $studentcm, (int)$student->id));
    }
}
