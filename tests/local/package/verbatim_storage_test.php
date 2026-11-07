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

namespace mod_scormmakercmi5\local\package;

defined('MOODLE_INTERNAL') || die();

require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/signed_package.php');

use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\verbatim_storage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\importer::class)]
/**
 * The package files are stored byte for byte: the EXIF remover of core (Moodle 4.4+) never rewrites a JPEG of the
 * package or content area, so a signed package with JPEG images stays verified on every recheck.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\package\verbatim_storage
 * @covers     \mod_scormmakercmi5\local\package\importer
 */
final class verbatim_storage_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_signed_package;

    /**
     * Admin, plugin key settings cleared.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('revokedkids', 'mod_scormmakercmi5');
    }

    /**
     * The JPEG fixture: 8x8 pixels with an APP1 Exif segment (Make and Orientation), see tests/fixtures/README.md.
     *
     * @return string
     */
    private static function exif_jpeg(): string {
        $path = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/images/exif.jpg';
        return file_get_contents($path);
    }

    /**
     * A small PNG.
     *
     * @return string
     */
    private static function png(): string {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /**
     * Extra entries of the package: a JPEG with EXIF, a PNG and a non-image file.
     *
     * @return array
     */
    private static function media(): array {
        return [
            'media/foto.jpg' => self::exif_jpeg(),
            'media/foto.jpeg' => self::exif_jpeg(),
            'media/icone.png' => self::png(),
            'media/dados.bin' => "\x00\x01\x02binário",
        ];
    }

    /**
     * Data provider: the EXIF remover of core on (the default) and off.
     *
     * @return array
     */
    public static function redactor_provider(): array {
        return ['redactor on' => [1], 'redactor off' => [0]];
    }

    /**
     * The fixture really carries EXIF and the remover of core really rewrites it (otherwise the tests prove nothing).
     */
    public function test_fixture_is_rewritten_by_core(): void {
        $this->assertSame('ScormMaker', exif_read_data('data://image/jpeg;base64,' . base64_encode(self::exif_jpeg()))['Make']);
        set_config('file_redactor_exifremoverenabled', 1);
        $source = make_request_directory() . '/foto.jpg';
        file_put_contents($source, self::exif_jpeg());
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => 'foto.jpg',
        ], $source);
        $this->assertNotSame(sha1(self::exif_jpeg()), $file->get_contenthash(), 'other areas are still redacted');
    }

    /**
     * Every file of a signed package is stored byte for byte, and the package stays verified on every recheck.
     *
     * @dataProvider redactor_provider
     * @param int $enabled Value of file_redactor_exifremoverenabled.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redactor_provider')]
    public function test_import_and_recheck_stay_verified(int $enabled): void {
        global $DB;
        set_config('file_redactor_exifremoverenabled', $enabled);
        $pkg = $this->create_signed_package(self::media());

        foreach (self::media() as $path => $bytes) {
            $file = $this->content_file($pkg, $path);
            $this->assertSame(sha1($bytes), $file->get_contenthash(), $path);
            $this->assertSame($bytes, $file->get_content(), $path);
        }
        $contextid = $this->signed_context()->id;
        $zip = get_file_storage()->get_area_files($contextid, 'mod_scormmakercmi5', 'package', $pkg->id, 'id', false);
        $this->assertSame($pkg->zipcontenthash, reset($zip)->get_contenthash());

        foreach ([1, 2] as $run) {
            $result = verifier::recheck($pkg, keyring::from_config(), PHP_INT_MAX);
            $this->assertSame(verification_result::STATUS_VERIFIED, $result->status, "run {$run}");
            $this->assertNull($result->reason, "run {$run}");
        }
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('verified', $row->fullrecheckstatus);
    }

    /**
     * A JPEG changed after the import still fails the recheck, with the remover on and off.
     *
     * @dataProvider redactor_provider
     * @param int $enabled Value of file_redactor_exifremoverenabled.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redactor_provider')]
    public function test_tampered_jpeg_still_fails(int $enabled): void {
        set_config('file_redactor_exifremoverenabled', $enabled);
        $pkg = $this->create_signed_package(self::media());
        $file = $this->content_file($pkg, 'media/foto.jpg');
        $this->tamper_filedir($file, self::exif_jpeg() . 'x');
        $result = verifier::recheck($pkg, keyring::from_config(), PHP_INT_MAX);
        $this->assertSame(verification_result::STATUS_INVALID, $result->status);
        $this->assertSame('filechanged', $result->reason);
    }

    /**
     * An UNSIGNED package keeps the redaction of the site (privacy: GPS position, camera serial): its JPEG files are
     * rewritten by the EXIF remover, its other files are stored as they are.
     */
    public function test_unsigned_package_is_redacted(): void {
        set_config('file_redactor_exifremoverenabled', 1);
        $pkg = $this->create_unsigned_package();
        foreach (self::media() as $path => $bytes) {
            $file = $this->content_file($pkg, $path);
            $redacted = $file->get_mimetype() === 'image/jpeg';
            $this->assertSame(!$redacted, sha1($bytes) === $file->get_contenthash(), $path);
            if ($redacted) {
                $this->assertStringNotContainsString('ScormMaker', $file->get_content(), $path);
            }
        }
    }

    /**
     * Data provider: packages that carry a signature file which does not verify.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return ['garbage signature file' => ['malformed'], 'key the site does not know' => ['unknownkid']];
    }

    /**
     * A package whose signature is INVALID gets the file processing of the site, like an unsigned one: only a
     * VERIFIED package is stored byte for byte, so a signature file alone (even garbage) never bypasses the redaction.
     *
     * @dataProvider invalid_provider
     * @param string $reason Expected reason of the invalid result.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid_package_is_redacted(string $reason): void {
        set_config('file_redactor_exifremoverenabled', 1);
        $pkg = $this->create_invalid_package($reason);
        foreach (self::media() as $path => $bytes) {
            $file = $this->content_file($pkg, $path);
            $redacted = $file->get_mimetype() === 'image/jpeg';
            $this->assertSame(!$redacted, sha1($bytes) === $file->get_contenthash(), $path);
            if ($redacted) {
                $this->assertStringNotContainsString('ScormMaker', $file->get_content(), $path);
            }
        }
    }

    /**
     * Restore of a backup: a signed package comes back byte for byte (and verified), an unsigned one redacted.
     */
    public function test_restore(): void {
        global $DB;
        set_config('file_redactor_exifremoverenabled', 1);
        $this->create_signed_package(self::media());
        $course = get_course($this->signedinstance->course);
        $this->create_unsigned_package($course);

        $backupid = $this->backup_course($course);
        $courseid = $this->restore_backup($backupid, $course);

        $statuses = [];
        foreach ($DB->get_records('scormmakercmi5', ['course' => $courseid]) as $instance) {
            $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id, $courseid, false, MUST_EXIST);
            $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
            $contextid = \context_module::instance($cm->id)->id;
            $file = get_file_storage()->get_file($contextid, 'mod_scormmakercmi5', 'content', $pkg->id, '/media/', 'foto.jpg');
            $this->assertNotFalse($file);
            if ($pkg->sigstatus === 'unsigned') {
                $this->assertStringNotContainsString('ScormMaker', $file->get_content());
            } else {
                $this->assertSame(self::exif_jpeg(), $file->get_content());
                $result = verifier::recheck($pkg, keyring::from_config(), PHP_INT_MAX);
                $this->assertSame(verification_result::STATUS_VERIFIED, $result->status);
            }
            $statuses[] = $pkg->sigstatus;
        }
        sort($statuses);
        $this->assertSame(['unsigned', 'verified'], $statuses);
    }

    /**
     * The status in a backup is a claim, not a fact: a backup that says "verified" for a version whose signature does
     * not hold on this site (here the key is not trusted; a hand-made backup is the same case) must not make the
     * restore store the files byte for byte. The JPEG, which the backup carries with its EXIF segment, still goes
     * through the listeners of before_file_created (the EXIF remover) and the version does not come back verified.
     */
    public function test_forged_verified_backup_is_redacted(): void {
        global $CFG, $DB;
        set_config('file_redactor_exifremoverenabled', 1);
        $this->create_signed_package(self::media());
        $course = get_course($this->signedinstance->course);
        $backupid = $this->backup_course($course);
        $files = glob($CFG->tempdir . '/backup/' . $backupid . '/activities/scormmakercmi5_*/scormmakercmi5.xml');
        $this->assertStringContainsString('<sigstatus>verified</sigstatus>', file_get_contents($files[0]));
        set_config('trustedkeys', '', 'mod_scormmakercmi5');

        $courseid = $this->restore_backup($backupid, $course);

        $instance = $DB->get_record('scormmakercmi5', ['course' => $courseid], '*', MUST_EXIST);
        $new = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $this->assertSame(verification_result::STATUS_INVALID, $new->sigstatus);
        $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id, $courseid, false, MUST_EXIST);
        $contextid = \context_module::instance($cm->id)->id;
        $file = get_file_storage()->get_file($contextid, 'mod_scormmakercmi5', 'content', $new->id, '/media/', 'foto.jpg');
        $this->assertNotFalse($file);
        $this->assertNotSame(sha1(self::exif_jpeg()), $file->get_contenthash(), 'the file went through the redactor');
        $this->assertStringNotContainsString('ScormMaker', $file->get_content());
    }

    /**
     * A signed manifest does not vouch for the images of a backup: with a JPEG swapped for another one (EXIF/GPS) at a
     * signed path, and the unsigned pkgfile.contenthash edited to match, the restore stores that file through the
     * listeners of before_file_created (redacted), while the files that are the signed ones stay byte for byte.
     */
    public function test_backup_with_swapped_image_is_redacted(): void {
        [$backupid, $course, $signedbytes, $new] = $this->backup_with_swapped_image();
        $this->assert_swapped_image_redacted($this->restore_backup($backupid, $course), $signedbytes, $new);
    }

    /**
     * The same backup, with the pathhash of the legitimate pkgfile row replaced by a decoy and a row of another path
     * (the signature file, which the file map skips) taking its place, carrying the hash of the swapped image: the
     * restore computes the key from the path, so the image still goes through the redactor.
     */
    public function test_backup_with_forged_pathhash_is_redacted(): void {
        global $DB;
        [$backupid, $course, $signedbytes, $new, $pkgxml] = $this->backup_with_swapped_image();
        $xml = file_get_contents($pkgxml);
        $photo = base64_encode('media/foto.jpg');
        $regex = '~<pkgfile id="(\d+)">(?:(?!</pkgfile>).)*<path>' . $photo . '</path>.*?</pkgfile>~s';
        $this->assertSame(1, preg_match($regex, $xml, $m));
        $legit = $m[0];
        $this->assertStringContainsString('<pathhash>' . sha1('media/foto.jpg') . '</pathhash>', $legit);
        $hash = sha1('media/foto.jpg');
        $decoy = str_replace('<pathhash>' . $hash . '</pathhash>', '<pathhash>' . sha1('decoy') . '</pathhash>', $legit);
        $forged = preg_replace('~<pathhash>[0-9a-f]+</pathhash>~', '<pathhash>' . sha1('media/foto.jpg') . '</pathhash>', $legit);
        $sigpath = base64_encode(verification_result::SIGNATURE_PATH);
        $forged = str_replace('<path>' . $photo . '</path>', '<path>' . $sigpath . '</path>', $forged);
        $swappedsha = hash('sha256', self::exif_jpeg());
        $forged = preg_replace('~<sha256>[0-9a-f]+</sha256>~', '<sha256>' . $swappedsha . '</sha256>', $forged);
        $forged = str_replace('<pkgfile id="' . $m[1] . '">', '<pkgfile id="99999">', $forged);
        file_put_contents($pkgxml, str_replace($legit, $decoy . $forged, $xml));

        $courseid = $this->restore_backup($backupid, $course);

        $this->assert_swapped_image_redacted($courseid, $signedbytes, $new);
        $instance = $DB->get_record('scormmakercmi5', ['course' => $courseid], '*', MUST_EXIST);
        foreach ($DB->get_records('scormmakercmi5_pkgfile', ['pkgid' => $instance->currentpkgid]) as $row) {
            $this->assertSame(sha1($row->path), $row->pathhash, $row->path);
        }
    }

    /**
     * Two rows for one path (a hand-made backup; the unique index would refuse the second one): the restore does not
     * fail with a database error, it leaves the second row out and logs it, and the first one decides.
     */
    public function test_backup_with_duplicate_pkgfile_path_is_restored(): void {
        global $DB;
        [$backupid, $course, $signedbytes, $new, $pkgxml] = $this->backup_with_swapped_image();
        $xml = file_get_contents($pkgxml);
        $photo = base64_encode('media/foto.jpg');
        $regex = '~<pkgfile id="(\d+)">(?:(?!</pkgfile>).)*<path>' . $photo . '</path>.*?</pkgfile>~s';
        $this->assertSame(1, preg_match($regex, $xml, $m));
        $copy = str_replace('<pkgfile id="' . $m[1] . '">', '<pkgfile id="99999">', $m[0]);
        $copy = preg_replace('~<sha256>[0-9a-f]+</sha256>~', '<sha256>' . str_repeat('0', 64) . '</sha256>', $copy);
        file_put_contents($pkgxml, str_replace($m[0], $m[0] . $copy, $xml));

        $courseid = $this->restore_backup($backupid, $course);

        $instance = $DB->get_record('scormmakercmi5', ['course' => $courseid], '*', MUST_EXIST);
        $key = ['pkgid' => $instance->currentpkgid, 'pathhash' => sha1('media/foto.jpg')];
        $rows = $DB->get_records('scormmakercmi5_pkgfile', $key);
        $this->assertCount(1, $rows);
        $this->assertNotSame(str_repeat('0', 64), reset($rows)->sha256, 'the first row stays');
        $this->assert_swapped_image_redacted($courseid, $signedbytes, $new);
    }

    /**
     * A signed package with two JPEGs, backed up, with foto.jpg swapped for another EXIF JPEG in the backup (files
     * pool, files.xml and the unsigned pkgfile.contenthash).
     *
     * @return array [backup id, course, signed bytes of the images, sha1 of the swapped image, pkg XML path]
     */
    private function backup_with_swapped_image(): array {
        global $CFG;
        set_config('file_redactor_exifremoverenabled', 1);
        $signedbytes = self::exif_jpeg() . 'x';
        $this->create_signed_package(['media/foto.jpg' => $signedbytes, 'media/foto.jpeg' => $signedbytes]);
        $course = get_course($this->signedinstance->course);
        $backupid = $this->backup_course($course);
        $dir = $CFG->tempdir . '/backup/' . $backupid;
        $swapped = self::exif_jpeg();

        $filesxml = $dir . '/files.xml';
        $xml = file_get_contents($filesxml);
        $old = sha1($signedbytes);
        $new = sha1($swapped);
        $this->assertStringContainsString('<contenthash>' . $old . '</contenthash>', $xml);
        $xml = preg_replace_callback('~<file id="[^"]*">.*?</file>~s', function ($m) use ($old, $new, $swapped) {
            $isimage = strpos($m[0], '<filename>foto.jpg</filename>') !== false;
            if (!$isimage || strpos($m[0], '<filearea>content</filearea>') === false) {
                return $m[0];
            }
            $m[0] = str_replace('<contenthash>' . $old . '</contenthash>', '<contenthash>' . $new . '</contenthash>', $m[0]);
            return preg_replace('~<filesize>\d+</filesize>~', '<filesize>' . strlen($swapped) . '</filesize>', $m[0]);
        }, $xml);
        file_put_contents($filesxml, $xml);
        $pool = $dir . '/files/' . substr($new, 0, 2);
        @mkdir($pool, 0777, true);
        file_put_contents($pool . '/' . $new, $swapped);
        $files = glob($dir . '/activities/scormmakercmi5_*/scormmakercmi5.xml');
        $pkgxml = file_get_contents($files[0]);
        $this->assertStringContainsString($old, $pkgxml);
        file_put_contents($files[0], str_replace($old, $new, $pkgxml));
        return [$backupid, $course, $signedbytes, $new, $files[0]];
    }

    /**
     * Checks a restored course: the swapped image was redacted, the signed files are byte for byte.
     *
     * @param int $courseid Restored course.
     * @param string $signedbytes Signed bytes of foto.jpeg.
     * @param string $swappedhash SHA-1 of the swapped image.
     */
    private function assert_swapped_image_redacted(int $courseid, string $signedbytes, string $swappedhash): void {
        global $DB;
        $instance = $DB->get_record('scormmakercmi5', ['course' => $courseid], '*', MUST_EXIST);
        $restored = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id, $courseid, false, MUST_EXIST);
        $contextid = \context_module::instance($cm->id)->id;
        $fs = get_file_storage();
        $foto = $fs->get_file($contextid, 'mod_scormmakercmi5', 'content', $restored->id, '/media/', 'foto.jpg');
        $this->assertNotFalse($foto);
        $this->assertNotSame($swappedhash, $foto->get_contenthash(), 'the swapped image went through the redactor');
        $this->assertStringNotContainsString('ScormMaker', $foto->get_content());
        $other = $fs->get_file($contextid, 'mod_scormmakercmi5', 'content', $restored->id, '/media/', 'foto.jpeg');
        $this->assertNotFalse($other);
        $this->assertSame($signedbytes, $other->get_content(), 'the signed image stays byte for byte');
        $css = $fs->get_file($contextid, 'mod_scormmakercmi5', 'content', $restored->id, '/css/', 'estilo.css');
        $this->assertSame('body { color: #000; }', $css->get_content());
    }

    /**
     * Backs a course up (without user data) and extracts the backup under the temporary directory.
     *
     * @param \stdClass $course Course.
     * @return string Backup id.
     */
    private function backup_course(\stdClass $course): string {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(false);
        $bc->execute_plan();
        $backupid = 'scormmakercmi5_' . uniqid();
        $bc->get_results()['backup_destination']->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            $CFG->tempdir . '/backup/' . $backupid
        );
        $bc->destroy();
        return $backupid;
    }

    /**
     * Restores an extracted backup (without user data) into a new course.
     *
     * @param string $backupid Backup id.
     * @param \stdClass $course Course that was backed up.
     * @return int New course id.
     */
    private function restore_backup(string $backupid, \stdClass $course): int {
        global $USER;
        $courseid = \restore_dbops::create_new_course('R', 'R' . uniqid(), $course->category);
        $rc = new \restore_controller(
            $backupid,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(false);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $courseid;
    }

    /**
     * The listeners put back what a listener between them changed, by path and by content, only for the package and
     * content areas of this plugin and only for a signed package version; anything not resolvable is redacted.
     */
    public function test_listeners_scope(): void {
        global $DB;
        $signed = (int)$this->create_signed_package(self::media())->id;
        $signedcmid = $this->signedinstance->cmid;
        $jpeg = self::exif_jpeg();
        $unsigned = (int)$this->create_unsigned_package()->id;
        $invalid = (int)$this->create_invalid_package('malformed')->id;
        $own = ['component' => 'mod_scormmakercmi5', 'mimetype' => 'image/jpeg'];
        $inpackage = ['filepath' => '/media/', 'filename' => 'foto.jpg'];
        $zipfile = array_values(get_file_storage()->get_area_files(
            \context_module::instance($signedcmid)->id,
            'mod_scormmakercmi5',
            'package',
            $signed,
            'id',
            false
        ))[0];
        $zipbytes = $zipfile->get_content();
        $zip = ['component' => 'mod_scormmakercmi5', 'filearea' => 'package', 'itemid' => $signed, 'filepath' => '/',
            'filename' => $zipfile->get_filename(), 'mimetype' => $zipfile->get_mimetype()];
        $cases = [
            'content, signed' => [$own + ['filearea' => 'content', 'itemid' => $signed] + $inpackage, true],
            'content, signed, other bytes' => [$own + ['filearea' => 'content', 'itemid' => $signed] + $inpackage, false,
                $jpeg . 'x'],
            'content, signed, path without a pkgfile row' => [$own + ['filearea' => 'content', 'itemid' => $signed,
                'filepath' => '/media/', 'filename' => 'other.jpg'], false],
            'package, the zip of the version' => [$zip, true, $zipbytes],
            'package, the zip name, other bytes' => [$zip, false, $zipbytes . 'x'],
            'package, other name' => [['filename' => 'extra.zip'] + $zip, false, $zipbytes],
            'package, extra image' => [['filename' => 'extra.jpg', 'mimetype' => 'image/jpeg'] + $zip, false, $jpeg],
            'package, the zip name as an image' => [['mimetype' => 'image/jpeg'] + $zip, false, $zipbytes],
            'package, in a folder' => [['filepath' => '/sub/'] + $zip, false, $zipbytes],
            'content, unsigned' => [$own + ['filearea' => 'content', 'itemid' => $unsigned] + $inpackage, false],
            'package, unsigned' => [$own + ['filearea' => 'package', 'itemid' => $unsigned], false],
            'content, invalid' => [$own + ['filearea' => 'content', 'itemid' => $invalid] + $inpackage, false],
            'package, invalid' => [$own + ['filearea' => 'package', 'itemid' => $invalid], false],
            'content, no such version' => [
                $own + ['filearea' => 'content', 'itemid' => $signed + $unsigned + $invalid + 100],
                false,
            ],
            'content, temporary file' => [$own + ['filearea' => 'content', 'itemid' => 0], false],
            'no mime type' => [['component' => 'mod_scormmakercmi5', 'filearea' => 'content', 'itemid' => $signed], false],
            'intro' => [$own + ['filearea' => 'intro', 'itemid' => 0], false],
            'other component' => [['component' => 'mod_scorm', 'filearea' => 'content', 'itemid' => $signed,
                'mimetype' => 'image/jpeg'], false],
            'no record' => [null, false],
        ];
        foreach ($cases as $name => $case) {
            $this->assert_kept($case[0], $case[1], $name, $case[2] ?? $jpeg);
        }
        // The import has no pkgfile rows yet when it stores the files: it hands the hashes over with expecting().
        $nofiles = $own + ['filearea' => 'content', 'itemid' => $signed + $unsigned + $invalid + 100] + $inpackage;
        $this->assert_kept($nofiles, false, 'no expectation, no rows', $jpeg);
        $key = ['pkgid' => $signed, 'pathhash' => sha1('media/foto.jpg')];
        $row = $DB->get_record('scormmakercmi5_pkgfile', $key, '*', MUST_EXIST);
        $DB->delete_records('scormmakercmi5_pkgfile', ['id' => $row->id]);
        $record = $own + ['filearea' => 'content', 'itemid' => $signed] + $inpackage;
        $this->assert_kept($record, false, 'row gone', $jpeg);
        verbatim_storage::expecting(
            $signed,
            ['media/foto.jpg' => hash('sha256', $jpeg)],
            fn() => $this->assert_kept($record, true, 'expected hash matches', $jpeg)
        );
        verbatim_storage::expecting(
            $signed,
            ['media/foto.jpg' => hash('sha256', $jpeg . 'x')],
            fn() => $this->assert_kept($record, false, 'expected hash differs', $jpeg)
        );
        $this->assert_kept($record, false, 'expectation gone', $jpeg);
        // A temporary file of the repair task, while it works for a signed version (and only then): not compared.
        $temp = $own + ['filearea' => 'content', 'itemid' => 0];
        verbatim_storage::for_temporary_files_of($signed, fn() => $this->assert_kept($temp, true, 'temporary, signed'));
        verbatim_storage::for_temporary_files_of($unsigned, fn() => $this->assert_kept($temp, false, 'temporary, unsigned'));
        verbatim_storage::for_temporary_files_of($invalid, fn() => $this->assert_kept($temp, false, 'temporary, invalid'));
        $this->assert_kept($temp, false, 'temporary, after');

        // Nothing changed in between: nothing is updated.
        $DB->insert_record('scormmakercmi5_pkgfile', (object)['pkgid' => $signed, 'pathhash' => sha1('media/foto.jpg'),
            'path' => 'media/foto.jpg', 'sha256' => hash('sha256', $jpeg), 'contenthash' => sha1($jpeg),
            'filesize' => strlen($jpeg)]);
        $hook = new \core_files\hook\before_file_created(filerecord: (object)$cases['content, signed'][0], filecontent: $jpeg);
        verbatim_storage::remember($hook);
        verbatim_storage::restore($hook);
        $this->assertFalse($hook->has_changed());
    }

    /**
     * The signature file is not covered by the signature (the verifier leaves it out of the comparison), so a restored
     * row for it must never keep a file as is: its SHA-256 would come from the backup, unchecked.
     */
    public function test_signature_file_row_is_not_kept_outside_the_import(): void {
        global $DB;
        $signed = (int)$this->create_signed_package(self::media())->id;
        $jpeg = self::exif_jpeg();
        $path = verification_result::SIGNATURE_PATH;
        // What a forged backup would carry: the row of the signature file, with the hash of the bytes it wants kept.
        $key = ['pkgid' => $signed, 'pathhash' => sha1($path)];
        $DB->set_field('scormmakercmi5_pkgfile', 'sha256', hash('sha256', $jpeg), $key);
        $this->assertSame(hash('sha256', $jpeg), $DB->get_field('scormmakercmi5_pkgfile', 'sha256', $key));
        $record = ['component' => 'mod_scormmakercmi5', 'mimetype' => 'image/jpeg', 'filearea' => 'content',
            'itemid' => $signed, 'filepath' => '/META-INF/', 'filename' => 'scormmaker-assinatura.json'];
        $this->assert_kept($record, false, 'signature file, row matches the bytes', $jpeg);
    }

    /**
     * Runs the two listeners around a change, by path and by content, and checks whether the original came back.
     *
     * @param array|null $record File record.
     * @param bool $kept Whether the original must come back.
     * @param string $name Case.
     * @param string $bytes The incoming bytes of the file.
     */
    private function assert_kept(?array $record, bool $kept, string $name, string $bytes = 'original'): void {
        $record = $record === null ? null : (object)$record;
        $original = make_request_directory() . '/original.jpg';
        file_put_contents($original, $bytes);
        $bypath = new \core_files\hook\before_file_created(filerecord: $record, filepath: $original);
        verbatim_storage::remember($bypath);
        $bypath->update_filepath('/tmp/redacted.jpg');
        verbatim_storage::restore($bypath);
        $this->assertSame($kept ? $original : '/tmp/redacted.jpg', $bypath->get_filepath(), $name);

        $bycontent = new \core_files\hook\before_file_created(filerecord: $record, filecontent: $bytes);
        verbatim_storage::remember($bycontent);
        $bycontent->update_filecontent('redacted');
        verbatim_storage::restore($bycontent);
        $this->assertSame($kept ? $bytes : 'redacted', $bycontent->get_filecontent(), $name);
    }

    /**
     * An activity whose package (the media of media()) is not signed.
     *
     * @param \stdClass|null $course Course (a new one by default).
     * @return \stdClass The pkg row.
     */
    private function create_unsigned_package(?\stdClass $course = null): \stdClass {
        global $DB;
        $course = $course ?? $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_instance([
            'course' => $course->id,
            'package_entries' => \mod_scormmakercmi5_test_package_builder::minimal_entries(self::media()),
        ]);
        $this->signedinstance = $instance;
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $this->assertSame('unsigned', $pkg->sigstatus);
        return $pkg;
    }

    /**
     * An activity whose package (the media of media()) carries a signature file that does not verify.
     *
     * @param string $reason "malformed" (a garbage signature file) or "unknownkid" (signed with a key the site
     *     does not trust).
     * @return \stdClass The pkg row.
     */
    private function create_invalid_package(string $reason): \stdClass {
        global $DB;
        $entries = \mod_scormmakercmi5_test_package_builder::minimal_entries(self::media());
        if ($reason === 'malformed') {
            $entries[verification_result::SIGNATURE_PATH] = 'not a signature';
        } else {
            unset_config('trustedkeys', 'mod_scormmakercmi5');
            $entries = \mod_scormmakercmi5_test_package_builder::sign(
                $entries,
                \mod_scormmakercmi5_test_package_builder::test_keypair()
            );
        }
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_instance([
            'course' => $course->id,
            'package_entries' => $entries,
        ]);
        $this->signedinstance = $instance;
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $this->assertSame([verification_result::STATUS_INVALID, $reason], [$pkg->sigstatus, $pkg->sigreason]);
        return $pkg;
    }
}
