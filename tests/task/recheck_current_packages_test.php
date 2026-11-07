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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/signed_package.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\task\recheck_current_packages::class)]
/**
 * Tests for the scheduled recheck of the current package of every activity (the report no longer writes on a GET).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\task\recheck_current_packages
 */
final class recheck_current_packages_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_signed_package;

    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /**
     * The task records the verdict of every signed current package, a tampered one included, and leaves an
     * unsigned package alone.
     */
    public function test_execute_records_every_signed_package(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        $intact = $this->create_signed_package();
        $tampered = $this->create_signed_package(['unico.txt' => 'only here']);
        // Replaced through the File API: another contenthash, which the file-map step sees without reading bytes.
        $file = $this->content_file($tampered, 'unico.txt');
        $record = ['contextid' => $file->get_contextid(), 'component' => 'mod_scormmakercmi5', 'filearea' => 'content',
            'itemid' => $tampered->id, 'filepath' => '/', 'filename' => 'unico.txt'];
        $file->delete();
        get_file_storage()->create_file_from_string($record, 'changed');
        $course = $this->getDataGenerator()->create_course();
        $plain = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        $plainrow = $DB->get_record('scormmakercmi5_pkg', ['id' => $plain->currentpkgid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$plainrow->timerecheck);

        ob_start();
        (new recheck_current_packages())->execute();
        ob_end_clean();

        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $intact->id], '*', MUST_EXIST);
        $this->assertSame('verified', $row->recheckstatus);
        $this->assertEquals(self::NOW, $row->timerecheck);
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $tampered->id], '*', MUST_EXIST);
        $this->assertSame('invalid', $row->recheckstatus);
        $this->assertSame('filechanged', $row->recheckreason);
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $plainrow->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$row->timerecheck, 'an unsigned package is not touched');
    }

    /**
     * The task never reads the stored bytes: bytes changed behind the File API (same contenthash in the database)
     * are not seen by it, the signature and file-map steps are recorded, and the byte check is queued as the ad hoc
     * task recheck_package (once, however many runs), which is what finds the change.
     */
    public function test_execute_does_not_hash_bytes_and_queues_the_background_check(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        $pkg = $this->create_signed_package();
        $this->tamper_filedir($this->content_file($pkg, 'css/estilo.css'), 'body { color: red; }');

        foreach ([1, 2] as $run) {
            ob_start();
            (new recheck_current_packages())->execute();
            ob_end_clean();
        }

        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('verified', $row->recheckstatus, 'the bytes were not read');
        $this->assertEquals(self::NOW, $row->timerecheck);
        $this->assertEquals(0, $row->timefullrecheck, 'no byte check recorded by the task');
        $this->assertNull($row->fullrecheckstatus);
        $queued = \core\task\manager::get_adhoc_tasks(recheck_package::class);
        $this->assertCount(1, $queued);
        $this->assertEquals($pkg->id, reset($queued)->get_custom_data()->pkgid);

        // The ad hoc task is what hashes: it finds the change.
        reset($queued)->execute();
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('invalid', $row->recheckstatus);
        $this->assertSame('filechanged', $row->recheckreason);
    }

    /**
     * Oldest recheck first, and the run is capped.
     */
    public function test_oldest_recheck_goes_first_and_the_run_is_capped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        $fresh = $this->create_signed_package();
        $old = $this->create_signed_package(['unico.txt' => 'only here']);
        $DB->set_field('scormmakercmi5_pkg', 'timerecheck', self::NOW - 100, ['id' => $fresh->id]);
        $DB->set_field('scormmakercmi5_pkg', 'timerecheck', 5, ['id' => $old->id]);

        $task = new recheck_current_packages();
        $method = new \ReflectionMethod($task, 'pending_package_ids');
        $this->assertSame([(int)$old->id], $method->invoke($task, 1));
        $this->assertSame([(int)$old->id, (int)$fresh->id], $method->invoke($task, 5));
        $this->assertSame(200, recheck_current_packages::MAX_PER_RUN);
    }

    /**
     * One package that cannot be checked (its course module is gone) does not stop the others, and its timerecheck
     * still moves to now, so it goes to the back of the queue instead of taking a slot at the front of every run.
     */
    public function test_a_broken_package_does_not_stop_the_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        $broken = $this->create_signed_package();
        $DB->delete_records('course_modules', ['id' => $this->signedinstance->cmid]);
        $good = $this->create_signed_package();

        ob_start();
        (new recheck_current_packages())->execute();
        ob_end_clean();

        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $good->id], '*', MUST_EXIST);
        $this->assertSame('verified', $row->recheckstatus);
        $this->assertEquals(self::NOW, $DB->get_field('scormmakercmi5_pkg', 'timerecheck', ['id' => $broken->id]));
        $status = $DB->get_field('scormmakercmi5_pkg', 'recheckstatus', ['id' => $broken->id]);
        $this->assertNull($status, 'no verdict is recorded for a package that could not be checked');

        // A later package now goes before the broken one.
        $DB->set_field('scormmakercmi5_pkg', 'timerecheck', self::NOW - 1, ['id' => $good->id]);
        $task = new recheck_current_packages();
        $method = new \ReflectionMethod($task, 'pending_package_ids');
        $this->assertSame([(int)$good->id], $method->invoke($task, 1));
    }
}
