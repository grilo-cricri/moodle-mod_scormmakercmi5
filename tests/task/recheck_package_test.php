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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\task\recheck_package::class)]
/**
 * Tests for the background full recheck of a large package (design §5.4, §10.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\task\recheck_package
 */
final class recheck_package_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_signed_package;

    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /**
     * The task hashes every byte whatever the size limit and records the result and the date of the full check.
     */
    public function test_execute(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $clock = $this->mock_clock_with_frozen(self::NOW);
        set_config('recheckmaxmb', 1, 'mod_scormmakercmi5');
        $pkg = $this->create_signed_package(['grande.bin' => str_repeat('x', 1100000)]);

        $task = new recheck_package();
        $task->set_custom_data((object)['pkgid' => (int)$pkg->id]);
        $task->execute();
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('verified', $row->recheckstatus);
        $this->assertSame('verified', $row->fullrecheckstatus);
        $this->assertEquals(self::NOW, $row->timefullrecheck);
        $this->assertEquals(self::NOW, $row->timerecheck);

        $clock->bump(60);
        $this->tamper_filedir($this->content_file($pkg, 'grande.bin'), str_repeat('y', 1100000));
        $task->execute();
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('invalid', $row->recheckstatus);
        $this->assertSame('filechanged', $row->recheckreason);
        $this->assertSame('invalid', $row->fullrecheckstatus);
        $this->assertSame('filechanged', $row->fullrecheckreason);
        $this->assertEquals(self::NOW + 60, $row->timefullrecheck);
        $this->assertSame('verified', $row->sigstatus, 'the import status is never changed');
    }

    /**
     * A package deleted before the task runs is ignored.
     */
    public function test_missing_package(): void {
        $this->resetAfterTest();
        $task = new recheck_package();
        $task->set_custom_data((object)['pkgid' => 987654]);
        $this->expectNotToPerformAssertions();
        $task->execute();
    }
}
