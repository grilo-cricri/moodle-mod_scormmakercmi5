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

require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5\local\package\analysis_structure;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\task\backfill_rich_structure::class)]
/**
 * Tests for the backfill of the analysis structure of package versions imported before 0.2.0 (subproject D).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\task\backfill_rich_structure
 */
final class backfill_rich_structure_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /**
     * Creates a course.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * The analysis structure of the shared contract.
     *
     * @return array
     */
    private static function contract_structure(): array {
        $path = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contrato-dados-ricos.json';
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['casos'][0]['estrutura'];
    }

    /**
     * An instance whose package version looks imported by 0.1.0 (richstructure NULL).
     *
     * @param array $extra Extra entries of the package.
     * @return \stdClass The pkg row.
     */
    private function legacy_package(array $extra): \stdClass {
        global $DB;
        $instance = $this->getDataGenerator()->create_module('scormmakercmi5', [
            'course' => $this->course->id,
            'package_entries' => builder::minimal_entries($extra),
        ]);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id], '*', MUST_EXIST);
        $DB->set_field('scormmakercmi5_pkg', 'richstructure', null, ['id' => $pkg->id]);
        return $pkg;
    }

    /**
     * The stored structure of a package version.
     *
     * @param \stdClass $pkg Pkg row.
     * @return string|null
     */
    private function stored(\stdClass $pkg): ?string {
        global $DB;
        return $DB->get_field('scormmakercmi5_pkg', 'richstructure', ['id' => $pkg->id]);
    }

    /**
     * A structure file of the package (contract structure).
     *
     * @return array
     */
    private static function with_structure(): array {
        return [analysis_structure::PATH => json_encode(self::contract_structure()), 'media/m1.jpg' => 'x'];
    }

    /**
     * The structure is read from the stored files and is the one a fresh import stores; the rest is left alone.
     */
    public function test_execute_fills_from_the_stored_files(): void {
        global $DB;
        $pkg = $this->legacy_package(self::with_structure());
        $plain = $this->legacy_package([]);
        $invalid = $this->legacy_package([analysis_structure::PATH => '{"v":2}']);

        (new backfill_rich_structure())->execute();
        $stored = analysis_structure::decode($this->stored($pkg));
        $this->assertSame('media/m1.jpg', $stored['blocos']['m1']['imagem']);
        $this->assertNull($stored['blocos']['tv1']['cenas'][0]['imagem'], 'media/tA.jpg is not in the package');
        $this->assertSame(
            analysis_structure::encode(analysis_structure::from_json(json_encode(self::contract_structure()), ['media/m1.jpg'])),
            $this->stored($pkg),
            'same JSON as the import'
        );
        $this->assertNull($this->stored($plain));
        $this->assertNull($this->stored($invalid));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(backfill_rich_structure::class), 'done: nothing queued');

        // Idempotent: a second run changes nothing, and a value already stored is never replaced.
        $DB->set_field('scormmakercmi5_pkg', 'richstructure', '{"v":1,"kept":true}', ['id' => $pkg->id]);
        $before = $DB->get_records('scormmakercmi5_pkg', null, 'id');
        (new backfill_rich_structure())->execute();
        $this->assertEquals($before, $DB->get_records('scormmakercmi5_pkg', null, 'id'));
    }

    /**
     * A stored file that no longer matches its recorded hash, or whose activity is gone, is skipped.
     */
    public function test_changed_or_orphan_file_skipped(): void {
        global $DB;
        $changed = $this->legacy_package(self::with_structure());
        $DB->set_field('scormmakercmi5_pkgfile', 'sha256', str_repeat('0', 64), [
            'pkgid' => $changed->id,
            'pathhash' => sha1(analysis_structure::PATH),
        ]);
        $orphan = $this->legacy_package(self::with_structure());
        $cm = get_coursemodule_from_instance('scormmakercmi5', $orphan->scormmakercmi5id);
        $DB->delete_records('course_modules', ['id' => $cm->id]);
        $good = $this->legacy_package(self::with_structure());

        $this->assertNull(backfill_rich_structure::run(0, 10, 1));
        $this->assertNull($this->stored($changed));
        $this->assertNull($this->stored($orphan));
        $this->assertNotNull($this->stored($good), 'the others go on');
    }

    /**
     * Batches: run() stops after its batches and gives the id to resume from; execute() queues the rest.
     */
    public function test_resumable_in_batches(): void {
        global $DB;
        $pkgs = [];
        foreach ([1, 2, 3] as $ignored) {
            $pkgs[] = $this->legacy_package(self::with_structure());
        }
        $next = backfill_rich_structure::run(0, 2, 1);
        $this->assertSame((int)$pkgs[1]->id, $next);
        $this->assertNotNull($this->stored($pkgs[1]));
        $this->assertNull($this->stored($pkgs[2]), 'not reached yet');

        $task = new backfill_rich_structure();
        $task->set_custom_data((object)['lastid' => $next, 'batch' => 1, 'batches' => 1]);
        $task->execute();
        $this->assertNotNull($this->stored($pkgs[2]));
        $queued = \core\task\manager::get_adhoc_tasks(backfill_rich_structure::class);
        $this->assertCount(1, $queued, 'a full batch: the rest is queued');
        $this->assertEquals((int)$pkgs[2]->id, reset($queued)->get_custom_data()->lastid);
        $DB->delete_records('task_adhoc');
        reset($queued)->execute();
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(backfill_rich_structure::class), 'nothing left: not queued');
    }

    /**
     * The name of the task.
     */
    public function test_name(): void {
        $this->assertSame(
            get_string('taskbackfillrichstructure', 'mod_scormmakercmi5'),
            (new backfill_rich_structure())->get_name()
        );
    }
}
