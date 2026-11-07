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

namespace mod_scormmakercmi5\local\signature;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/signed_package.php');

use mod_scormmakercmi5\task\recheck_package;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\signature\verifier::class)]
/**
 * Tests for the recheck of a stored package when the rich report opens (design §5.4, R2.4, R3.4).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\signature\verifier
 */
final class recheck_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_signed_package;

    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /**
     * Admin, frozen clock, plugin key settings cleared.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->mock_clock_with_frozen(self::NOW);
        unset_config('revokedkids', 'mod_scormmakercmi5');
        unset_config('accepthmlkey', 'mod_scormmakercmi5');
    }

    /**
     * Rechecks a package with the keyring of the site, then returns the result and the pkg row as stored.
     *
     * @param \stdClass $pkg Pkg row.
     * @param int|null $maxbytes Size limit of the synchronous full check.
     * @return array [verification_result, \stdClass pkg row after the recheck].
     */
    private function recheck(\stdClass $pkg, ?int $maxbytes = null): array {
        global $DB;
        $result = verifier::recheck($pkg, keyring::from_config(), $maxbytes);
        return [$result, $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST)];
    }

    /**
     * An intact package stays verified, and the recheck is recorded.
     */
    public function test_intact(): void {
        $pkg = $this->create_signed_package();
        [$result, $row] = $this->recheck($pkg);
        $this->assertSame(verification_result::STATUS_VERIFIED, $result->status);
        $this->assertNull($result->reason);
        $this->assertFalse($result->deferred);
        $this->assertSame('verified', $row->recheckstatus);
        $this->assertNull($row->recheckreason);
        $this->assertEquals(self::NOW, $row->timerecheck);
        $this->assertEquals(self::NOW, $row->timefullrecheck);
        $this->assertEquals(self::NOW, $result->timefullcheck);
    }

    /**
     * A version with an AU outside the package is never verified by a recheck, whatever its stored signature says
     * (a hand-made backup, a row changed in the database): unsigned, reason external_au, recorded.
     */
    public function test_external_au_is_unsigned(): void {
        global $DB;
        $pkg = $this->create_signed_package();
        $this->assertSame(verification_result::STATUS_VERIFIED, $this->recheck($pkg)[0]->status);
        $auid = $DB->get_field('scormmakercmi5_au', 'id', ['pkgid' => $pkg->id], IGNORE_MULTIPLE);
        $DB->set_field('scormmakercmi5_au', 'auorigin', 'https://cursos.exemplo.com', ['id' => $auid]);

        [$result, $row] = $this->recheck($pkg);
        $this->assertSame(verification_result::STATUS_UNSIGNED, $result->status);
        $this->assertSame(verification_result::REASON_EXTERNAL_AU, $result->reason);
        $this->assertSame('external_au', verification_result::REASON_EXTERNAL_AU);
        $this->assertSame('unsigned', $row->recheckstatus);
        $this->assertSame('external_au', $row->recheckreason);
        $this->assertSame('verified', $row->sigstatus, 'the import status is not rewritten by a recheck');
    }

    /**
     * Review Focus 5: the kid revoked after the import fails the recheck; the import status is kept and nothing is
     * deleted.
     */
    public function test_revoked_after_import(): void {
        global $DB;
        $pkg = $this->create_signed_package();
        $files = $DB->count_records('scormmakercmi5_pkgfile', ['pkgid' => $pkg->id]);
        set_config('revokedkids', 'sm-test-1', 'mod_scormmakercmi5');
        [$result, $row] = $this->recheck($pkg);
        $this->assertSame(verification_result::STATUS_INVALID, $result->status);
        $this->assertSame('revokedkid', $result->reason);
        $this->assertSame('verified', $row->sigstatus);
        $this->assertNull($row->sigreason);
        $this->assertSame('invalid', $row->recheckstatus);
        $this->assertSame('revokedkid', $row->recheckreason);
        $this->assertSame($files, $DB->count_records('scormmakercmi5_pkgfile', ['pkgid' => $pkg->id]));

        // Back to trusted: verified again.
        unset_config('revokedkids', 'mod_scormmakercmi5');
        $this->assertSame('verified', $this->recheck($pkg)[0]->status);
    }

    /**
     * A package signed with an HML kid fails once the HML key is turned off.
     */
    public function test_hml_disabled(): void {
        global $DB;
        $kp = ['kid' => 'sm-hml-test'] + builder::test_keypair();
        set_config('trustedkeys', 'sm-hml-test=' . $kp['public'], 'mod_scormmakercmi5');
        set_config('accepthmlkey', 1, 'mod_scormmakercmi5');
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_instance([
            'course' => $course->id,
            'package_entries' => builder::sign(builder::minimal_entries(), $kp),
        ]);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $this->assertSame('verified', $pkg->sigstatus);
        set_config('accepthmlkey', 0, 'mod_scormmakercmi5');
        [$result] = $this->recheck($pkg);
        $this->assertSame('hmldisabled', $result->reason);
    }

    /**
     * A file deleted from the content area.
     */
    public function test_file_missing(): void {
        $pkg = $this->create_signed_package();
        $this->content_file($pkg, 'css/estilo.css')->delete();
        [$result, $row] = $this->recheck($pkg);
        $this->assertSame('filemissing', $result->reason);
        $this->assertSame('filemissing', $row->recheckreason);
    }

    /**
     * A stored file added to the content area.
     */
    public function test_file_extra(): void {
        $pkg = $this->create_signed_package();
        get_file_storage()->create_file_from_string([
            'contextid' => $this->signed_context()->id, 'component' => 'mod_scormmakercmi5', 'filearea' => 'content',
            'itemid' => $pkg->id, 'filepath' => '/', 'filename' => 'intruso.js',
        ], 'alert(1);');
        [$result] = $this->recheck($pkg);
        $this->assertSame('fileextra', $result->reason);
    }

    /**
     * A file replaced through the File API (another content, another contenthash): step 2, without reading bytes.
     */
    public function test_file_replaced_through_file_api(): void {
        $pkg = $this->create_signed_package();
        $file = $this->content_file($pkg, 'css/estilo.css');
        $record = ['contextid' => $file->get_contextid(), 'component' => 'mod_scormmakercmi5', 'filearea' => 'content',
            'itemid' => $pkg->id, 'filepath' => '/css/', 'filename' => 'estilo.css'];
        $file->delete();
        get_file_storage()->create_file_from_string($record, 'body { display: none; }');
        // Step 2 alone catches it, even for a package too large for the synchronous byte check.
        [$result] = $this->recheck($pkg, 1);
        $this->assertSame('filechanged', $result->reason);
    }

    /**
     * Bytes changed directly in the filedir: the contenthash in the database is the same, so only step 3 sees it.
     */
    public function test_bytes_tampered_in_filedir(): void {
        $pkg = $this->create_signed_package();
        $this->tamper_filedir($this->content_file($pkg, 'css/estilo.css'), 'body { color: red; }');
        [$result, $row] = $this->recheck($pkg);
        $this->assertSame('filechanged', $result->reason);
        $this->assertEquals(self::NOW, $row->timefullrecheck);
    }

    /**
     * A package file whose bytes the file system cannot read is a failure (never verified).
     */
    public function test_unreadable_content(): void {
        $pkg = $this->create_signed_package();
        $file = $this->content_file($pkg, 'css/estilo.css');
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($file, true);
        unlink($path);
        // The file system warns when it cannot open the deleted file; that warning is the condition under test, so it
        // is swallowed here (PHPUnit 9 and 11 differ on expecting warnings, hence a plain handler).
        set_error_handler(static function (): bool {
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            [$result] = $this->recheck($pkg);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(verification_result::STATUS_INVALID, $result->status);
        $this->assertSame('filemissing', $result->reason);
    }

    /**
     * Above recheckmaxmb: the full check is queued once (dedupe) and the result is the last full one.
     */
    public function test_large_package_is_deferred(): void {
        $pkg = $this->create_signed_package(['grande.bin' => str_repeat('x', 1100000)]);
        set_config('recheckmaxmb', 1, 'mod_scormmakercmi5');
        $this->tamper_filedir($this->content_file($pkg, 'css/estilo.css'), 'body { color: red; }');

        [$first, $row] = $this->recheck($pkg);
        [$second] = $this->recheck($pkg);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(recheck_package::class));
        // No full check since the import: the import (which hashed every byte) is the last full verification.
        foreach ([$first, $second] as $result) {
            $this->assertSame(verification_result::STATUS_VERIFIED, $result->status);
            $this->assertTrue($result->deferred);
            $this->assertEquals($pkg->timecreated, $result->timefullcheck);
        }
        $this->assertEquals(0, $row->timefullrecheck);

        // After a full check found the change, the deferred recheck shows it until a full check passes again.
        $task = new recheck_package();
        $task->set_custom_data((object)['pkgid' => (int)$pkg->id]);
        $task->execute();
        [$third] = $this->recheck($pkg);
        $this->assertSame('filechanged', $third->reason);
        $this->assertTrue($third->deferred);
    }

    /**
     * Final review #38: above recheckmaxmb, a byte check recorded less than recheckminhours ago is reused: opening the
     * report again does not queue another full hash of the package. Once it is older, the next opening queues one.
     */
    public function test_large_package_recent_full_check_is_not_queued_again(): void {
        global $DB;
        $clock = \core\di::get(\core\clock::class);
        $pkg = $this->create_signed_package(['grande.bin' => str_repeat('x', 1100000)]);
        set_config('recheckmaxmb', 1, 'mod_scormmakercmi5');
        set_config('recheckminhours', 24, 'mod_scormmakercmi5');

        $this->recheck($pkg);
        $tasks = \core\task\manager::get_adhoc_tasks(recheck_package::class);
        $this->assertCount(1, $tasks);
        reset($tasks)->execute();
        $DB->delete_records('task_adhoc');
        $this->assertEquals(self::NOW, $DB->get_field('scormmakercmi5_pkg', 'timefullrecheck', ['id' => $pkg->id]));

        $clock->bump(23 * 3600);
        [$result] = $this->recheck($pkg);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(recheck_package::class), 'checked 23 h ago');
        $this->assertTrue($result->deferred);
        $this->assertEquals(self::NOW, $result->timefullcheck);
        $this->assertSame(verification_result::STATUS_VERIFIED, $result->status);

        $clock->bump(3600);
        $this->recheck($pkg);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(recheck_package::class), 'checked 24 h ago');
    }

    /**
     * evaluate() is the read-only recheck behind the report: same verdict as recheck(), yet it writes nothing to the
     * package row and queues nothing, so opening a report (a GET) changes no state.
     */
    public function test_evaluate_writes_nothing_and_queues_nothing(): void {
        global $DB;
        $pkg = $this->create_signed_package(['grande.bin' => str_repeat('x', 1100000)]);
        $this->tamper_filedir($this->content_file($pkg, 'css/estilo.css'), 'body { color: red; }');
        $before = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);

        $deferred = verifier::evaluate($pkg, keyring::from_config(), 1);
        $full = verifier::evaluate($pkg, keyring::from_config());
        $this->assertTrue($deferred->deferred);
        $this->assertSame(verification_result::STATUS_VERIFIED, $deferred->status);
        $this->assertSame('filechanged', $full->reason, 'the byte check still runs, in memory');
        $this->assertEquals($before, $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(recheck_package::class));

        // The persisting recheck gives the same verdict and then records it.
        $recorded = verifier::recheck($pkg, keyring::from_config());
        $this->assertSame($full->status, $recorded->status);
        $this->assertSame($full->reason, $recorded->reason);
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('invalid', $row->recheckstatus);
    }

    /**
     * The date and the result of the "last full check" are written only when step 3 hashed the bytes: a step-2
     * failure leaves them alone, and a step-1 failure still hashes (the byte state is refreshed).
     */
    public function test_full_check_recorded_only_when_bytes_hashed(): void {
        global $DB;
        $pkg = $this->create_signed_package();
        $this->content_file($pkg, 'css/estilo.css')->delete();
        [$result, $row] = $this->recheck($pkg);
        $this->assertSame('filemissing', $result->reason);
        $this->assertEquals(0, $row->timefullrecheck);
        $this->assertNull($row->fullrecheckstatus);

        $other = $this->create_signed_package();
        set_config('revokedkids', 'sm-test-1', 'mod_scormmakercmi5');
        [$result, $row] = $this->recheck($other);
        $this->assertSame('revokedkid', $result->reason);
        $this->assertEquals(self::NOW, $row->timefullrecheck);
        $this->assertSame('verified', $row->fullrecheckstatus, 'the bytes were hashed and matched');
        $this->assertNull($row->fullrecheckreason);
        $this->assertSame('revokedkid', $DB->get_field('scormmakercmi5_pkg', 'recheckreason', ['id' => $other->id]));
    }

    /**
     * I-1 of the review: bytes changed in the filedir of a large package, found by the task; then the key is revoked,
     * the task runs again, and the key is trusted again. The report must keep showing the byte failure, with the date
     * of a check that really hashed the bytes; and a later passing full check clears it.
     */
    public function test_byte_failure_survives_a_revoke_and_unrevoke(): void {
        $clock = \core\di::get(\core\clock::class);
        $pkg = $this->create_signed_package(['grande.bin' => str_repeat('x', 1100000)]);
        set_config('recheckmaxmb', 1, 'mod_scormmakercmi5');
        $css = $this->content_file($pkg, 'css/estilo.css');
        $original = $css->get_content();
        $this->tamper_filedir($css, 'body { color: red; }');
        $task = new recheck_package();
        $task->set_custom_data((object)['pkgid' => (int)$pkg->id]);

        $task->execute();                                   // T1: bytes hashed, filechanged.
        $this->assertSame('filechanged', $this->recheck($pkg)[0]->reason);

        $clock->bump(60);
        set_config('revokedkids', 'sm-test-1', 'mod_scormmakercmi5');
        $this->assertSame('revokedkid', $this->recheck($pkg)[0]->reason);
        $clock->bump(60);
        $task->execute();                                   // T2: step 1 fails, the bytes are hashed anyway.
        [, $row] = $this->recheck($pkg);
        $this->assertSame('invalid', $row->fullrecheckstatus);
        $this->assertSame('filechanged', $row->fullrecheckreason);
        $t2 = (int)$row->timefullrecheck;
        $this->assertEquals(self::NOW + 120, $t2);

        $clock->bump(60);
        unset_config('revokedkids', 'mod_scormmakercmi5');
        [$result] = $this->recheck($pkg);
        $this->assertSame(verification_result::STATUS_INVALID, $result->status, 'never verified with tampered bytes');
        $this->assertSame('filechanged', $result->reason);
        $this->assertTrue($result->deferred);
        $this->assertSame($t2, $result->timefullcheck);

        // The bytes are restored and a full check passes: verified again, dated by that check.
        $this->tamper_filedir($css, $original);
        $clock->bump(60);
        $task->execute();
        [$result] = $this->recheck($pkg);
        $this->assertSame(verification_result::STATUS_VERIFIED, $result->status);
        $this->assertSame(self::NOW + 240, $result->timefullcheck);
    }

    /**
     * An opening that read the row before a byte check was recorded does not overwrite the newer result.
     */
    public function test_stale_write_is_skipped(): void {
        global $DB;
        $pkg = $this->create_signed_package();
        $stale = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $DB->update_record('scormmakercmi5_pkg', (object)['id' => $pkg->id, 'recheckstatus' => 'invalid',
            'recheckreason' => 'filechanged', 'fullrecheckstatus' => 'invalid', 'fullrecheckreason' => 'filechanged',
            'timefullrecheck' => self::NOW, 'timerecheck' => self::NOW]);
        $record = new \ReflectionMethod(verifier::class, 'record');
        $record->invoke(null, $stale, 'verified', null, self::NOW + 5, (int)$stale->timefullrecheck);
        $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $this->assertSame('filechanged', $row->recheckreason);

        // An older full write never replaces a newer one either.
        $full = new \ReflectionMethod(verifier::class, 'record_full');
        $full->invoke(null, $stale, 'verified', null, null, self::NOW - 10);
        $this->assertSame('invalid', $DB->get_field('scormmakercmi5_pkg', 'fullrecheckstatus', ['id' => $pkg->id]));
    }

    /**
     * An unsigned package stays unsigned; nothing is queued.
     */
    public function test_unsigned(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id]);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        [$result, $row] = $this->recheck($pkg, 1);
        $this->assertSame(verification_result::STATUS_UNSIGNED, $result->status);
        $this->assertSame('unsigned', $row->recheckstatus);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(recheck_package::class));
    }

    /**
     * A package invalid at import because a file differed from the manifest never becomes verified at recheck:
     * the stored file map is compared with the signed manifest too.
     */
    public function test_invalid_at_import_stays_invalid(): void {
        global $DB;
        $kp = builder::test_keypair();
        set_config('trustedkeys', 'sm-test-1=' . $kp['public'], 'mod_scormmakercmi5');
        $entries = builder::sign(builder::minimal_entries(), $kp, [], static function (array $e): array {
            $e['index.html'] = '<!doctype html><title>Outro</title>';
            return $e;
        });
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $course->id, 'package_entries' => $entries]);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $this->assertSame('filechanged', $pkg->sigreason);
        [$result] = $this->recheck($pkg);
        $this->assertSame('filechanged', $result->reason);
    }
}
