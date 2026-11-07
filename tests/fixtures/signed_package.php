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

/**
 * A signed package imported through the generator, and ways to tamper with its stored files (recheck tests).
 *
 * Load with require_once(core_component::get_component_directory('mod_scormmakercmi5')
 * . '/tests/fixtures/signed_package.php') and use the trait in an advanced_testcase.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/package_builder.php');

use mod_scormmakercmi5_test_package_builder as builder;

/**
 * Signed instance, its pkg row and the stored files of its content area.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait mod_scormmakercmi5_test_signed_package {
    /** @var \stdClass Instance (generator result, with cmid). */
    protected \stdClass $signedinstance;

    /**
     * Creates an activity whose package is signed with the test key (sm-test-1), trusted at import.
     *
     * @param array $extra Extra package entries (path => content).
     * @return \stdClass The pkg row.
     */
    protected function create_signed_package(array $extra = []): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $this->signedinstance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_instance([
            'course' => $course->id,
            'signed' => true,
            'package_entries' => builder::minimal_entries($extra + ['css/estilo.css' => 'body { color: #000; }']),
        ]);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $this->signedinstance->currentpkgid], '*', MUST_EXIST);
        $this->assertSame('verified', $pkg->sigstatus);
        return $pkg;
    }

    /**
     * Context of the signed activity.
     *
     * @return \context_module
     */
    protected function signed_context(): \context_module {
        return \context_module::instance($this->signedinstance->cmid);
    }

    /**
     * A stored file of the content area by its relative path.
     *
     * @param \stdClass $pkg Pkg row.
     * @param string $path Relative path ("css/estilo.css").
     * @return \stored_file
     */
    protected function content_file(\stdClass $pkg, string $path): \stored_file {
        $slash = strrpos($path, '/');
        $filepath = $slash === false ? '/' : '/' . substr($path, 0, $slash) . '/';
        $filename = $slash === false ? $path : substr($path, $slash + 1);
        $file = get_file_storage()->get_file(
            $this->signed_context()->id,
            'mod_scormmakercmi5',
            'content',
            (int)$pkg->id,
            $filepath,
            $filename
        );
        $this->assertNotFalse($file, $path);
        return $file;
    }

    /**
     * Overwrites the bytes of a stored file directly in the filedir: the contenthash in the database stays the same.
     *
     * @param \stored_file $file File.
     * @param string $bytes New bytes.
     */
    protected function tamper_filedir(\stored_file $file, string $bytes): void {
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($file, true);
        $this->assertFileExists($path);
        @chmod($path, 0666);
        $this->assertNotFalse(file_put_contents($path, $bytes));
    }
}
