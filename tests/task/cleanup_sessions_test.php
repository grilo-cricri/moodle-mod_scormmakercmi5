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

namespace mod_scormmakercmi5\task;

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\runtime\launcher;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\task\cleanup_sessions::class)]
/**
 * Tests for the session cleanup task (design §10.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\task\cleanup_sessions
 */
final class cleanup_sessions_test extends \advanced_testcase {
    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /** @var int Session timeout used in the test, seconds. */
    private const TIMEOUT = 3600;

    /**
     * Idle sessions are abandoned, recent ones kept, and old keys of ended sessions cleared.
     */
    public function test_execute(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $clock = $this->mock_clock_with_frozen(self::NOW);
        set_config('sessiontimeout', self::TIMEOUT, 'mod_scormmakercmi5');
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5');
        $instance = $generator->create_instance(['course' => $course->id]);
        $instance->cmid = (int)$instance->cmid;
        $cm = get_fast_modinfo($course->id)->get_cm($instance->cmid);
        $auid = (int)$DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $instance->currentpkgid]);
        $nowms = self::NOW * 1000;

        $sessions = [];
        foreach (['idle', 'recent', 'terminated', 'endedrecently'] as $name) {
            $user = $this->getDataGenerator()->create_and_enrol($course);
            $sessions[$name] = launcher::launch($cm, $instance, $auid, (int)$user->id, 'Normal')->session;
        }
        $DB->update_record('scormmakercmi5_session', (object)['id' => $sessions['idle']->id, 'status' => 1,
            'tokenhash' => str_repeat('1', 64), 'tokenexpires' => self::NOW + 10,
            'lastactivityms' => $nowms - (self::TIMEOUT + 1) * 1000]);
        $DB->update_record('scormmakercmi5_session', (object)['id' => $sessions['recent']->id, 'status' => 1,
            'lastactivityms' => $nowms - (self::TIMEOUT - 1) * 1000]);
        $DB->update_record('scormmakercmi5_session', (object)['id' => $sessions['terminated']->id, 'status' => 2,
            'tokenhash' => str_repeat('2', 64), 'timeendedms' => $nowms - 2 * DAYSECS * 1000,
            'lastactivityms' => $nowms - 2 * DAYSECS * 1000]);
        $DB->update_record('scormmakercmi5_session', (object)['id' => $sessions['endedrecently']->id, 'status' => 2,
            'tokenhash' => str_repeat('3', 64), 'timeendedms' => $nowms - 3600 * 1000,
            'lastactivityms' => $nowms - 2 * DAYSECS * 1000]);

        $task = new cleanup_sessions();
        $this->assertNotEmpty($task->get_name());
        ob_start();
        $task->execute();
        ob_end_clean();

        $idle = $DB->get_record('scormmakercmi5_session', ['id' => $sessions['idle']->id]);
        $this->assertEquals(launcher::STATUS_ABANDONED, $idle->status);
        $this->assertEquals(0, $idle->tokenexpires);
        $this->assertEquals($nowms, $idle->timeendedms);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_stmt', ['verb' => vocab::VERB_ABANDONED]));
        $this->assertTrue($DB->record_exists(
            'scormmakercmi5_stmt',
            ['verb' => vocab::VERB_ABANDONED, 'sessionid' => $idle->id]
        ));

        $recent = $DB->get_record('scormmakercmi5_session', ['id' => $sessions['recent']->id]);
        $this->assertEquals(launcher::STATUS_INITIALIZED, $recent->status);
        $this->assertNotNull($recent->fetchkeyhash);

        $terminated = $DB->get_record('scormmakercmi5_session', ['id' => $sessions['terminated']->id]);
        $this->assertEquals(launcher::STATUS_TERMINATED, $terminated->status);
        $this->assertNull($terminated->fetchkeyhash);
        $this->assertNull($terminated->tokenhash);

        $endedrecently = $DB->get_record('scormmakercmi5_session', ['id' => $sessions['endedrecently']->id]);
        $this->assertNotNull($endedrecently->fetchkeyhash);
        $this->assertSame(str_repeat('3', 64), $endedrecently->tokenhash);

        // The abandoned session loses its keys a day later.
        $this->assertNotNull($idle->fetchkeyhash);
        $clock->bump(DAYSECS + 1);
        ob_start();
        $task->execute();
        ob_end_clean();
        $idle = $DB->get_record('scormmakercmi5_session', ['id' => $sessions['idle']->id]);
        $this->assertNull($idle->fetchkeyhash);
        $this->assertNull($idle->tokenhash);
        $this->assertSame(1, $DB->count_records(
            'scormmakercmi5_stmt',
            ['verb' => vocab::VERB_ABANDONED, 'sessionid' => $idle->id]
        ), 'abandoned once');
    }

    /**
     * A session that cannot be abandoned (its AU row is gone) is reported and skipped; the others still are.
     */
    public function test_broken_session_does_not_stop_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        set_config('sessiontimeout', self::TIMEOUT, 'mod_scormmakercmi5');
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_fast_modinfo($course->id)->get_cm($instance->cmid);
        $auid = (int)$DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $instance->currentpkgid]);
        $nowms = self::NOW * 1000;
        $ids = [];
        foreach (['broken', 'good', 'ended'] as $name) {
            $user = $this->getDataGenerator()->create_and_enrol($course);
            $ids[$name] = (int)launcher::launch($cm, $instance, $auid, (int)$user->id, 'Normal')->session->id;
        }
        $idle = $nowms - (self::TIMEOUT + 1) * 1000;
        $DB->update_record('scormmakercmi5_session', (object)['id' => $ids['broken'], 'auid' => 999999, 'lastactivityms' => $idle]);
        $DB->update_record('scormmakercmi5_session', (object)['id' => $ids['good'], 'lastactivityms' => $idle]);
        $DB->update_record('scormmakercmi5_session', (object)['id' => $ids['ended'], 'status' => launcher::STATUS_TERMINATED,
            'timeendedms' => $nowms - 2 * DAYSECS * 1000]);

        ob_start();
        (new cleanup_sessions())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('Could not abandon cmi5 session ' . $ids['broken'], $output);
        $status = fn(string $name) => (int)$DB->get_field('scormmakercmi5_session', 'status', ['id' => $ids[$name]]);
        $this->assertSame(launcher::STATUS_LAUNCHED, $status('broken'));
        $this->assertSame(launcher::STATUS_ABANDONED, $status('good'));
        $ended = $DB->get_record('scormmakercmi5_session', ['id' => $ids['ended']]);
        $this->assertNull($ended->fetchkeyhash, 'hashes still cleared');
    }

    /**
     * A broken session is reported by id and class only: the message of the exception never reaches the cron log.
     */
    public function test_failure_log_has_no_message(): void {
        $this->resetAfterTest();
        $sessionid = $this->create_orphan_idle_session();
        ob_start();
        (new cleanup_sessions())->execute();
        $log = ob_get_clean();
        $this->assertStringContainsString('Could not abandon cmi5 session ' . $sessionid, $log);
        $this->assertDoesNotMatchRegularExpression('/session ' . $sessionid . ': \S+ .+/', $log);
    }

    /**
     * An open session idle past the timeout whose AU row is gone, so abandoning it throws (as in
     * test_broken_session_does_not_stop_run).
     *
     * @return int Session id.
     */
    private function create_orphan_idle_session(): int {
        global $DB;
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        set_config('sessiontimeout', self::TIMEOUT, 'mod_scormmakercmi5');
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_fast_modinfo($course->id)->get_cm($instance->cmid);
        $auid = (int)$DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $instance->currentpkgid]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $id = (int)launcher::launch($cm, $instance, $auid, (int)$user->id, 'Normal')->session->id;
        $DB->update_record('scormmakercmi5_session', (object)['id' => $id, 'auid' => 999999,
            'lastactivityms' => (self::NOW - self::TIMEOUT - 1) * 1000]);
        return $id;
    }

    /**
     * The task is scheduled hourly at a random minute.
     */
    public function test_schedule(): void {
        $this->resetAfterTest();
        $task = \core\task\manager::get_scheduled_task(cleanup_sessions::class);
        $this->assertNotNull($task);
        $this->assertSame('*', $task->get_hour());
        $this->assertMatchesRegularExpression('/^\d+$/', (string)$task->get_minute());
    }
}
