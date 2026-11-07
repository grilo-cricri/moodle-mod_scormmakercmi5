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

use mod_scormmakercmi5\local\runtime\launcher;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\event\au_launched::class)]
/**
 * Tests for the au_launched event, as launch.php triggers it.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\event\au_launched
 */
final class au_launched_test extends \advanced_testcase {
    /**
     * The event names the session, the AU and the mode, and never carries the launch URL or its fetch key.
     */
    public function test_trigger(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $auid = (int)$DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $instance->currentpkgid], MUST_EXIST);
        $this->setUser($student);
        $result = launcher::launch($cm, $instance, $auid, (int)$student->id, 'Normal');
        $context = \context_module::instance($cm->id);

        $sink = $this->redirectEvents();
        $event = au_launched::create([
            'objectid' => $result->session->id,
            'context' => $context,
            'other' => ['auid' => (int)$result->session->auid, 'launchmode' => $result->session->launchmode],
        ]);
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(au_launched::class, $event);
        $this->assertSame('scormmakercmi5_session', $event->objecttable);
        $this->assertEquals($result->session->id, $event->objectid);
        $this->assertEquals($context, $event->get_context());
        $this->assertEquals($student->id, $event->userid);
        $this->assertSame(['auid' => $auid, 'launchmode' => 'Normal'], $event->other);
        $this->assertSame('c', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertEquals(new \moodle_url('/mod/scormmakercmi5/view.php', ['id' => $cm->id]), $event->get_url());
        $this->assertNotEmpty(au_launched::get_name());

        parse_str((string)parse_url($result->url->out(false), PHP_URL_QUERY), $query);
        $fetchkey = (string)parse_url($query['fetch'], PHP_URL_QUERY);
        $this->assertStringStartsWith('k=', $fetchkey);
        $logged = $event->get_description() . json_encode($event->get_data());
        $this->assertStringNotContainsString(substr($fetchkey, 2), $logged);
        $this->assertStringNotContainsString('fetch.php', $logged);
    }

    /**
     * auid and launchmode are required.
     */
    public function test_validation(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        $this->expectException(\coding_exception::class);
        au_launched::create([
            'objectid' => 1,
            'context' => \context_module::instance($instance->cmid),
            'other' => ['auid' => 1],
        ]);
    }
}
