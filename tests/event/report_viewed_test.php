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

namespace mod_scormmakercmi5\event;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\event\report_viewed::class)]
/**
 * Tests for the report_viewed event, as report.php triggers it.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\event\report_viewed
 */
final class report_viewed_test extends \advanced_testcase {
    /**
     * The event of the detail of a learner names the mode and the learner, and links back to that page.
     */
    public function test_trigger(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $this->setUser($teacher);

        $sink = $this->redirectEvents();
        report_viewed::create([
            'context' => $context,
            'relateduserid' => (int)$student->id,
            'other' => ['mode' => 'user'],
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(report_viewed::class, $event);
        $this->assertSame('r', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals($teacher->id, $event->userid);
        $url = new \moodle_url('/mod/scormmakercmi5/report.php', [
            'id' => $instance->cmid, 'mode' => 'user', 'userid' => $student->id,
        ]);
        $this->assertEquals($url, $event->get_url());
        $this->assertStringContainsString("'user' report", $event->get_description());
        $this->assertNotEmpty(report_viewed::get_name());
    }

    /**
     * The mode is required.
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        $this->expectException(\coding_exception::class);
        report_viewed::create(['context' => \context_module::instance($instance->cmid)]);
    }
}
