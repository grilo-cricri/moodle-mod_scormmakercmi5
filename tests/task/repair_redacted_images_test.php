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

require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/signed_package.php');

use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\task\repair_redacted_images::class)]
/**
 * Tests for the repair of the JPEG files that the EXIF remover of core rewrote at an import before 0.2.0.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\task\repair_redacted_images
 */
final class repair_redacted_images_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_signed_package;

    /**
     * Admin, EXIF remover on (the default of core).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('revokedkids', 'mod_scormmakercmi5');
        set_config('file_redactor_exifremoverenabled', 1);
    }

    /**
     * The JPEG fixture with EXIF.
     *
     * @return string
     */
    private static function exif_jpeg(): string {
        return file_get_contents(
            \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/images/exif.jpg'
        );
    }

    /**
     * Imports a signed package with two JPEG files the way 0.1.0 did: only the redactor of core listens to
     * before_file_created, so the stored JPEG files are rewritten.
     *
     * @return \stdClass The pkg row.
     */
    private function legacy_package(): \stdClass {
        $this->redirectHook(
            \core_files\hook\before_file_created::class,
            [\core_files\redactor\hook_listener::class, 'file_redaction_handler']
        );
        try {
            $pkg = $this->create_signed_package(['media/foto.jpg' => self::exif_jpeg(), 'media/b.jpeg' => self::exif_jpeg()]);
        } finally {
            $this->stopHookRedirections();
        }
        // The import of 0.2.0 tells the developer that a listener rewrote a package file (one per JPEG).
        $this->assertDebuggingCalledCount(2);
        $this->assertNotSame(sha1(self::exif_jpeg()), $this->content_file($pkg, 'media/foto.jpg')->get_contenthash());
        [$status, $reason] = $this->recheck($pkg);
        $this->assertSame([verification_result::STATUS_INVALID, 'filechanged'], [$status, $reason], 'the 0.1.0 state');
        return $pkg;
    }

    /**
     * Full recheck.
     *
     * @param \stdClass $pkg Pkg row.
     * @return array [status, reason].
     */
    private function recheck(\stdClass $pkg): array {
        $result = verifier::recheck($pkg, keyring::from_config(), PHP_INT_MAX);
        return [$result->status, $result->reason];
    }

    /**
     * Runs the task once.
     */
    private function run_task(): void {
        $task = new repair_redacted_images();
        $task->set_custom_data((object)[]);
        $task->execute();
    }

    /**
     * The rewritten JPEG files get the signed bytes back from the stored zip and the package is verified again.
     */
    public function test_repairs_a_redacted_package(): void {
        global $DB;
        $pkg = $this->legacy_package();
        $this->run_task();

        foreach (['media/foto.jpg', 'media/b.jpeg'] as $path) {
            $file = $this->content_file($pkg, $path);
            $this->assertSame(self::exif_jpeg(), $file->get_content(), $path);
            $this->assertSame('image/jpeg', $file->get_mimetype(), $path);
            $this->assertSame(
                sha1(self::exif_jpeg()),
                $DB->get_field('scormmakercmi5_pkgfile', 'contenthash', ['pkgid' => $pkg->id, 'pathhash' => sha1($path)])
            );
        }
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('verified', $row->recheckstatus, 'the task rechecks what it repaired');
        $this->assertSame('verified', $row->fullrecheckstatus);
        $this->assertSame([verification_result::STATUS_VERIFIED, null], $this->recheck($pkg));
        // No temporary file is left behind.
        $this->assertSame(0, $DB->count_records('files', [
            'component' => 'mod_scormmakercmi5', 'filearea' => 'content', 'itemid' => 0,
        ]));

        // Idempotent.
        $this->run_task();
        $this->assertSame([verification_result::STATUS_VERIFIED, null], $this->recheck($pkg));
    }

    /**
     * A JPEG whose bytes changed in the filedir after the import is not repaired: the package stays invalid.
     */
    public function test_filedir_tampering_is_not_repaired(): void {
        $pkg = $this->legacy_package();
        $file = $this->content_file($pkg, 'media/foto.jpg');
        $this->tamper_filedir($file, 'not a jpeg');
        $this->run_task();
        $this->assertSame('not a jpeg', $this->content_file($pkg, 'media/foto.jpg')->get_content());
        $this->assertSame(verification_result::STATUS_INVALID, $this->recheck($pkg)[0]);
    }

    /**
     * A JPEG replaced through the File API after the import is not repaired: the package stays invalid.
     */
    public function test_file_api_replacement_is_not_repaired(): void {
        $pkg = $this->legacy_package();
        $file = $this->content_file($pkg, 'media/foto.jpg');
        $record = ['contextid' => $file->get_contextid(), 'component' => 'mod_scormmakercmi5', 'filearea' => 'content',
            'itemid' => $file->get_itemid(), 'filepath' => $file->get_filepath(), 'filename' => $file->get_filename()];
        $file->delete();
        get_file_storage()->create_file_from_string($record, 'other bytes');
        $this->run_task();
        $this->assertSame('other bytes', $this->content_file($pkg, 'media/foto.jpg')->get_content());
        $this->assertSame([verification_result::STATUS_INVALID, 'filechanged'], $this->recheck($pkg));
    }

    /**
     * A stored zip whose entry does not have the signed SHA-256 gives nothing back: the package stays invalid.
     */
    public function test_tampered_zip_is_not_used(): void {
        $pkg = $this->legacy_package();
        $contextid = $this->signed_context()->id;
        $zips = get_file_storage()->get_area_files($contextid, 'mod_scormmakercmi5', 'package', $pkg->id, 'id', false);
        $entries = builder::minimal_entries(['media/foto.jpg' => self::exif_jpeg() . 'x', 'media/b.jpeg' => 'y']);
        $this->tamper_filedir(reset($zips), file_get_contents(builder::zip($entries)));
        $before = $this->content_file($pkg, 'media/foto.jpg')->get_contenthash();
        $this->run_task();
        $this->assertSame($before, $this->content_file($pkg, 'media/foto.jpg')->get_contenthash());
        $this->assertSame([verification_result::STATUS_INVALID, 'filechanged'], $this->recheck($pkg));
    }

    /**
     * Candidates: versions verified at import with a JPEG in the content area; an unsigned one is left alone.
     */
    public function test_candidates(): void {
        global $DB;
        [$sql, $params] = repair_redacted_images::candidates_sql();
        $this->assertEmpty($DB->get_records_sql($sql, $params + ['lastid' => 0]));
        $pkg = $this->legacy_package();
        $this->assertSame([(int)$pkg->id], array_map('intval', array_keys($DB->get_records_sql($sql, $params + ['lastid' => 0]))));
        $DB->set_field('scormmakercmi5_pkg', 'sigstatus', 'unsigned', ['id' => $pkg->id]);
        $this->assertEmpty($DB->get_records_sql($sql, $params + ['lastid' => 0]));
        // An unsigned version keeps the redacted bytes of the site.
        $before = $this->content_file($pkg, 'media/foto.jpg')->get_contenthash();
        $this->run_task();
        $this->assertSame($before, $this->content_file($pkg, 'media/foto.jpg')->get_contenthash());
    }

    /**
     * A version whose signature was INVALID at import is not a candidate either: only a verified package is stored
     * byte for byte, so its JPEG files keep the redaction of the site.
     */
    public function test_invalid_version_is_not_repaired(): void {
        global $DB;
        [$sql, $params] = repair_redacted_images::candidates_sql();
        $pkg = $this->legacy_package();
        $DB->set_field('scormmakercmi5_pkg', 'sigstatus', verification_result::STATUS_INVALID, ['id' => $pkg->id]);
        $this->assertEmpty($DB->get_records_sql($sql, $params + ['lastid' => 0]));
        $before = $this->content_file($pkg, 'media/foto.jpg')->get_contenthash();
        $this->run_task();
        $this->assertSame($before, $this->content_file($pkg, 'media/foto.jpg')->get_contenthash());
    }
}
