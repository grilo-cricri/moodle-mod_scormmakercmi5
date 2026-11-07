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
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\signature\verifier::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\signature\verification_result::class)]
/**
 * Tests for the SCORM Maker signature check at import (design §5.1).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\signature\verifier
 * @covers     \mod_scormmakercmi5\local\signature\verification_result
 */
final class verifier_test extends \advanced_testcase {
    /** @var array Test key pair. */
    private array $kp;

    /**
     * Trusts the test key only.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->kp = builder::test_keypair();
        set_config('trustedkeys', 'sm-test-1=' . $this->kp['public'], 'mod_scormmakercmi5');
        unset_config('revokedkids', 'mod_scormmakercmi5');
        unset_config('accepthmlkey', 'mod_scormmakercmi5');
    }

    /**
     * Zips the entries, extracts them with the File API and verifies the extracted tree.
     *
     * @param array $entries path => content.
     * @param keyring|null $keyring Keyring to verify with; the one of the site when null.
     * @return verification_result
     */
    private function verify(array $entries, ?keyring $keyring = null): verification_result {
        $zip = builder::zip($entries);
        $dir = make_request_directory();
        $packer = get_file_packer('application/zip');
        $this->assertNotFalse($packer->extract_to_pathname($zip, $dir));
        return verifier::verify_at_import($dir, verifier::hash_files($dir), $keyring ?? keyring::from_config());
    }

    /**
     * Rewrites the signature file through a callback on its decoded JSON (objects as stdClass).
     *
     * @param callable $change Receives the decoded document by reference-like object and may change it.
     * @return callable Tamper callback for builder::sign().
     */
    private static function rewrite(callable $change): callable {
        return static function (array $entries) use ($change): array {
            $doc = json_decode($entries[verification_result::SIGNATURE_PATH], false, 16, JSON_THROW_ON_ERROR);
            $change($doc);
            $entries[verification_result::SIGNATURE_PATH] = json_encode(
                $doc,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
            return $entries;
        };
    }

    /**
     * Asserts an invalid result with a reason.
     *
     * @param string $reason Expected reason.
     * @param verification_result $result Result.
     */
    private function assert_invalid(string $reason, verification_result $result): void {
        $this->assertSame([verification_result::STATUS_INVALID, $reason], [$result->status, $result->reason]);
    }

    /**
     * Constants of the result.
     */
    public function test_constants(): void {
        $this->assertSame('verified', verification_result::STATUS_VERIFIED);
        $this->assertSame('unsigned', verification_result::STATUS_UNSIGNED);
        $this->assertSame('invalid', verification_result::STATUS_INVALID);
        $this->assertSame('META-INF/scormmaker-assinatura.json', verification_result::SIGNATURE_PATH);
    }

    /**
     * No signature file: unsigned, no reason.
     */
    public function test_unsigned(): void {
        $result = $this->verify(builder::minimal_entries());
        $this->assertSame('unsigned', $result->status);
        $this->assertNull($result->reason);
        $this->assertNull($result->kid);
        $this->assertNull($result->manifest);
    }

    /**
     * A valid signature: verified, with the manifest and its canonical form.
     */
    public function test_verified(): void {
        $result = $this->verify(builder::sign(builder::minimal_entries(['a/b.html' => 'b']), $this->kp));
        $this->assertSame('verified', $result->status);
        $this->assertNull($result->reason);
        $this->assertSame('sm-test-1', $result->kid);
        $this->assertInstanceOf(\stdClass::class, $result->manifest);
        $this->assertSame(canonical_json::encode($result->manifest), $result->canonicalmanifest);
        $this->assertSame(64, strlen(base64_decode($result->signature, true)));
        $this->assertSame('cmi5', $result->manifest->tipoDePacote);
    }

    /**
     * The per-file hashes are streamed and keyed by the relative path with "/".
     */
    public function test_hash_files(): void {
        $dir = make_request_directory();
        mkdir($dir . '/a/b', 0777, true);
        file_put_contents($dir . '/x.txt', 'x');
        file_put_contents($dir . '/a/b/y.txt', str_repeat('y', 3 * 1048576 + 5));
        $hashes = verifier::hash_files($dir);
        ksort($hashes);
        $this->assertSame([
            'a/b/y.txt' => hash('sha256', str_repeat('y', 3 * 1048576 + 5)),
            'x.txt' => hash('sha256', 'x'),
        ], $hashes);
    }

    /**
     * A directory or file that cannot be read gives null, not an exception.
     */
    public function test_hash_files_unreadable(): void {
        $dir = make_request_directory();
        $this->assertNull(verifier::hash_files($dir . '/nao-existe'));
        $this->assertNull(verifier::hash_file($dir . '/nao-existe.txt'));
        $this->assertNull(verifier::hash_file($dir));
        file_put_contents($dir . '/vazio.txt', '');
        $this->assertSame(hash('sha256', ''), verifier::hash_file($dir . '/vazio.txt'));
        $this->assertSame(['vazio.txt' => hash('sha256', '')], verifier::hash_files($dir));
    }

    /**
     * Contract: a package signed by the real SCORM Maker backend code (courses/assinatura.py) and zipped with the
     * exporter's JSZip, including non-ASCII paths and directory entries (see tests/fixtures/README.md).
     */
    public function test_backend_signed_package(): void {
        $fixtures = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/zip';
        set_config('trustedkeys', trim(file_get_contents($fixtures . '/assinado_backend.pub')), 'mod_scormmakercmi5');
        $zip = $fixtures . '/assinado_backend.zip';
        // The importer inspects before extracting; the exporter's zip (with directory entries) must pass.
        \mod_scormmakercmi5\local\package\zip_inspector::inspect($zip, 20000, 1073741824);
        $dir = make_request_directory();
        $this->assertNotFalse(get_file_packer('application/zip')->extract_to_pathname($zip, $dir));
        $result = verifier::verify_at_import($dir, verifier::hash_files($dir), keyring::from_config());
        $this->assertSame(['verified', null], [$result->status, $result->reason]);
        $this->assertSame('sm-contract-1', $result->kid);
        $this->assertSame('42', $result->manifest->donoId);
        $this->assertObjectHasProperty("li\u{00E7}\u{00E3}o.html", $result->manifest->arquivos);
        $this->assertObjectHasProperty("media/imagem \u{00E9} boa.txt", $result->manifest->arquivos);

        // Without the key, the same package is unknownkid: the fixture key is not trusted by default.
        unset_config('trustedkeys', 'mod_scormmakercmi5');
        $result = verifier::verify_at_import($dir, verifier::hash_files($dir), keyring::from_config());
        $this->assertSame(['invalid', 'unknownkid'], [$result->status, $result->reason]);
    }

    /**
     * Signature files that are not the expected strict JSON are malformed.
     *
     * @dataProvider malformed_provider
     * @param string $content Content of the signature file.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_provider')]
    public function test_malformed_file(string $content): void {
        $entries = builder::minimal_entries([verification_result::SIGNATURE_PATH => $content]);
        $this->assert_invalid('malformed', $this->verify($entries));
    }

    /**
     * Malformed signature files.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        return [
            'broken json' => ['{"manifesto": '],
            'over 2 MB' => ['"' . str_repeat('a', 2 * 1048576) . '"'],
            'depth 17' => [str_repeat('[', 17) . str_repeat(']', 17)],
            'not an object' => ['[]'],
            'empty' => [''],
        ];
    }

    /**
     * Manifests with a wrong field are malformed.
     *
     * @dataProvider manifest_provider
     * @param array $overrides Manifest overrides.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('manifest_provider')]
    public function test_malformed_manifest(array $overrides): void {
        $this->assert_invalid('malformed', $this->verify(builder::sign(builder::minimal_entries(), $this->kp, $overrides)));
    }

    /**
     * Manifest overrides that make it malformed.
     *
     * @return array
     */
    public static function manifest_provider(): array {
        return [
            'formato 2' => [['formato' => 2]],
            'formato as string' => [['formato' => '1']],
            'tincan' => [['tipoDePacote' => 'tincan']],
            'scorm' => [['tipoDePacote' => 'scorm']],
            'upper-case hash' => [['arquivos' => ['index.html' => 'SHA256:' . str_repeat('ABCD', 16)]]],
            'short hash' => [['arquivos' => ['index.html' => 'sha256:abc']]],
            'no files' => [['arquivos' => []]],
            'empty kid' => [['kid' => '']],
            'number cursoId' => [['cursoId' => 5]],
            'extra field' => [['extra' => 'x']],
        ];
    }

    /**
     * A missing manifest field or extra top-level field is malformed.
     */
    public function test_malformed_structure(): void {
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], self::rewrite(static function (\stdClass $doc): void {
            unset($doc->manifesto->donoId);
        }));
        $this->assert_invalid('malformed', $this->verify($entries));
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], self::rewrite(static function (\stdClass $doc): void {
            $doc->outro = 1;
        }));
        $this->assert_invalid('malformed', $this->verify($entries));
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], self::rewrite(static function (\stdClass $doc): void {
            $doc->assinatura = 12;
        }));
        $this->assert_invalid('malformed', $this->verify($entries));
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], self::rewrite(static function (\stdClass $doc): void {
            $doc->manifesto->formato = 1.0;
        }));
        $this->assert_invalid('malformed', $this->verify($entries));
    }

    /**
     * Kid not in the keyring.
     */
    public function test_unknown_kid(): void {
        $kp = ['kid' => 'nao-existe'] + $this->kp;
        $result = $this->verify(builder::sign(builder::minimal_entries(), $kp));
        $this->assert_invalid('unknownkid', $result);
        $this->assertSame('nao-existe', $result->kid);
    }

    /**
     * Revoked kid.
     */
    public function test_revoked_kid(): void {
        set_config('revokedkids', 'sm-test-1', 'mod_scormmakercmi5');
        $this->assert_invalid('revokedkid', $this->verify(builder::sign(builder::minimal_entries(), $this->kp)));
    }

    /**
     * HML kid while the HML key is turned off.
     */
    public function test_hml_disabled(): void {
        set_config('trustedkeys', 'sm-hml-test=' . $this->kp['public'], 'mod_scormmakercmi5');
        set_config('accepthmlkey', 0, 'mod_scormmakercmi5');
        $kp = ['kid' => 'sm-hml-test'] + $this->kp;
        $this->assert_invalid('hmldisabled', $this->verify(builder::sign(builder::minimal_entries(), $kp)));
        // Unset (never saved) counts as off, like the default of the setting (0.3.1).
        unset_config('accepthmlkey', 'mod_scormmakercmi5');
        $this->assert_invalid('hmldisabled', $this->verify(builder::sign(builder::minimal_entries(), $kp)));
        set_config('accepthmlkey', 1, 'mod_scormmakercmi5');
        $this->assertSame('verified', $this->verify(builder::sign(builder::minimal_entries(), $kp))->status);
    }

    /**
     * A package signed by the PROD kid verifies with the HML key off. The PROD private key is never available to the
     * tests, so the test key pair stands in for it under the PROD kid; with the real embedded keys the same package
     * fails, which shows the embedded PROD key is the one checked.
     */
    public function test_prod_kid_verifies_with_the_hml_key_off(): void {
        set_config('accepthmlkey', 0, 'mod_scormmakercmi5');
        $kp = ['kid' => 'sm-prod-2026-10'] + $this->kp;
        $entries = builder::sign(builder::minimal_entries(), $kp);
        $vector = new keyring(['sm-prod-2026-10' => $this->kp['public']], [], [], [], false);
        $result = $this->verify($entries, $vector);
        $this->assertSame('verified', $result->status);
        $this->assertSame('sm-prod-2026-10', $result->kid);

        // The site keyring has the real PROD key: trusted, but this signature is not its.
        $this->assertSame('trusted', keyring::from_config()->status_of('sm-prod-2026-10'));
        $this->assert_invalid('badsignature', $this->verify($entries));
    }

    /**
     * Signature of 63 bytes, or not base64.
     */
    public function test_signature_length(): void {
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], self::rewrite(static function (\stdClass $doc): void {
            $doc->assinatura = base64_encode(substr(base64_decode($doc->assinatura, true), 0, 63));
        }));
        $this->assert_invalid('badsignature', $this->verify($entries));
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], self::rewrite(static function (\stdClass $doc): void {
            $doc->assinatura = '*' . substr($doc->assinatura, 1);
        }));
        $this->assert_invalid('badsignature', $this->verify($entries));
    }

    /**
     * Signed by another key under the same kid.
     */
    public function test_other_key(): void {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x02", 32));
        $kp = ['kid' => 'sm-test-1', 'secret' => sodium_crypto_sign_secretkey($pair)];
        $this->assert_invalid('badsignature', $this->verify(builder::sign(builder::minimal_entries(), $kp)));
    }

    /**
     * cursoId changed after signing.
     */
    public function test_manifest_changed(): void {
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], self::rewrite(static function (\stdClass $doc): void {
            $doc->manifesto->cursoId = '00000000-0000-0000-0000-000000000000';
        }));
        $this->assert_invalid('badsignature', $this->verify($entries));
    }

    /**
     * Contents of index.html changed after signing.
     */
    public function test_file_changed(): void {
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], static function (array $entries): array {
            $entries['index.html'] .= ' ';
            return $entries;
        });
        $result = $this->verify($entries);
        $this->assert_invalid('filechanged', $result);
        $this->assertSame('sm-test-1', $result->kid);
        $this->assertNotNull($result->manifest);
    }

    /**
     * A file that is not in the manifest.
     */
    public function test_file_extra(): void {
        $entries = builder::sign(builder::minimal_entries(), $this->kp, [], static function (array $entries): array {
            $entries['extra.js'] = 'x';
            return $entries;
        });
        $this->assert_invalid('fileextra', $this->verify($entries));
    }

    /**
     * A file of the manifest missing from the package.
     */
    public function test_file_missing(): void {
        $entries = builder::sign(builder::minimal_entries(['a/b.html' => 'b']), $this->kp, [], static function (array $e): array {
            unset($e['a/b.html']);
            return $e;
        });
        $this->assert_invalid('filemissing', $this->verify($entries));
    }

    /**
     * A non-ASCII path (NFC) on both sides is verified.
     */
    public function test_non_ascii_path(): void {
        $name = "li\u{00E7}\u{00E3}o.html";
        $this->assertTrue(\Normalizer::isNormalized($name, \Normalizer::FORM_C));
        $result = $this->verify(builder::sign(builder::minimal_entries([$name => 'aula']), $this->kp));
        $this->assertSame('verified', $result->status);
        $this->assertObjectHasProperty($name, $result->manifest->arquivos);
    }

    /**
     * The same path in NFD in the zip does not match the NFC one of the manifest (bytes compared).
     */
    public function test_path_bytes(): void {
        $nfc = "li\u{00E7}\u{00E3}o.html";
        $nfd = \Normalizer::normalize($nfc, \Normalizer::FORM_D);
        $move = static function (array $e) use ($nfc, $nfd): array {
            $e[$nfd] = $e[$nfc];
            unset($e[$nfc]);
            return $e;
        };
        $entries = builder::sign(builder::minimal_entries([$nfc => 'aula']), $this->kp, [], $move);
        $this->assert_invalid('fileextra', $this->verify($entries));
    }

    /**
     * Changed and extra at the same time: changed wins (order of §5.1 step 5); extra wins over missing.
     */
    public function test_order(): void {
        $entries = builder::sign(builder::minimal_entries(['a.html' => 'a']), $this->kp, [], static function (array $e): array {
            $e['index.html'] .= ' ';
            $e['extra.js'] = 'x';
            unset($e['a.html']);
            return $e;
        });
        $this->assert_invalid('filechanged', $this->verify($entries));
        $entries = builder::sign(builder::minimal_entries(['a.html' => 'a']), $this->kp, [], static function (array $e): array {
            $e['extra.js'] = 'x';
            unset($e['a.html']);
            return $e;
        });
        $this->assert_invalid('fileextra', $this->verify($entries));
    }

    /**
     * The filehashes argument is what counts; the verifier does not reread the file bytes.
     */
    public function test_uses_given_hashes(): void {
        $zip = builder::zip(builder::sign(builder::minimal_entries(), $this->kp));
        $dir = make_request_directory();
        get_file_packer('application/zip')->extract_to_pathname($zip, $dir);
        $hashes = verifier::hash_files($dir);
        $hashes['index.html'] = str_repeat('0', 64);
        $this->assert_invalid('filechanged', verifier::verify_at_import($dir, $hashes, keyring::from_config()));
    }

    /**
     * Every status and reason has a string in the plugin.
     */
    public function test_strings(): void {
        $string = [];
        include(\core_component::get_component_directory('mod_scormmakercmi5') . '/lang/pt_br/scormmakercmi5.php');
        $this->assertSame('SCORM Maker verificado', $string['sigstatus_verified']);
        $sm = get_string_manager();
        foreach (['verified', 'unsigned', 'invalid'] as $status) {
            $this->assertTrue($sm->string_exists('sigstatus_' . $status, 'mod_scormmakercmi5'));
        }
        $this->assertSame(
            ['malformed', 'unknownkid', 'revokedkid', 'hmldisabled', 'badsignature', 'filechanged', 'fileextra', 'filemissing'],
            verification_result::REASONS
        );
        foreach (verification_result::REASONS as $reason) {
            $this->assertTrue($sm->string_exists('sigreason_' . $reason, 'mod_scormmakercmi5'));
        }
    }
}
