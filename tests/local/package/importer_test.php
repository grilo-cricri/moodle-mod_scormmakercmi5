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

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5\local\signature\canonical_json;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\inspected_package::class)]
/**
 * Tests for the package importer (design §4.1, §3.2–§3.4, §3.13).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\package\importer
 * @covers     \mod_scormmakercmi5\local\package\inspected_package
 */
final class importer_test extends \advanced_testcase {
    /** @var string Pattern of a generated AU activity id. */
    private const AU_IRI = '#/mod/scormmakercmi5/xapi/au/[0-9a-f-]{36}$#';

    /** @var string Pattern of a generated block IRI. */
    private const BLOCK_IRI = '#/mod/scormmakercmi5/xapi/block/[0-9a-f-]{36}$#';

    /** @var string[] Tables the importer writes. */
    private const TABLES = ['scormmakercmi5_pkg', 'scormmakercmi5_block', 'scormmakercmi5_au', 'scormmakercmi5_pkgfile'];

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Instance row. */
    private \stdClass $instance;

    /** @var \context_module Module context. */
    private \context_module $context;

    /**
     * Creates a course with one instance and trusts the test key.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Off by default on a new site: the tests of external AUs opt in, as an administrator would.
        set_config('allowexternalau', 1, 'mod_scormmakercmi5');
        set_config('trustedkeys', 'sm-test-1=' . builder::test_keypair()['public'], 'mod_scormmakercmi5');
        $this->course = $this->getDataGenerator()->create_course();
        $id = $DB->insert_record('scormmakercmi5', (object)[
            'course' => $this->course->id,
            'name' => 'cmi5',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $cmid = add_course_module((object)[
            'course' => $this->course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'scormmakercmi5']),
            'instance' => $id,
            'section' => 0,
        ]);
        course_add_cm_to_section($this->course, $cmid, 0);
        $this->instance = $DB->get_record('scormmakercmi5', ['id' => $id]);
        $this->context = \context_module::instance($cmid);
    }

    /**
     * Inspects and stores a zip of the entries.
     *
     * @param array $entries path => content.
     * @param importer|null $importer Importer to use.
     * @return \stdClass The pkg row.
     */
    private function import(array $entries, ?importer $importer = null): \stdClass {
        $zip = builder::zip($entries);
        $importer = $importer ?? new importer();
        $pkg = $importer->inspect_file($zip, 1 << 30);
        return $importer->store($pkg, $this->instance, $this->context, $zip, 'curso.zip');
    }

    /**
     * Entries in the request storage directory, to detect temporary residue.
     *
     * @return string[]
     */
    private static function request_dirs(): array {
        $list = scandir(get_request_storage_directory());
        sort($list);
        return $list;
    }

    /**
     * Runs a failing import and checks the error code, that nothing was created and no temp residue is left.
     *
     * @param string $code Expected error code.
     * @param callable $run The failing call.
     * @return \moodle_exception
     */
    private function assert_refused(string $code, callable $run): \moodle_exception {
        global $DB;
        $before = self::request_dirs();
        try {
            $run();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode, $e->getMessage());
            if (str_starts_with($code, 'err_')) {
                $this->assertSame('mod_scormmakercmi5', $e->module);
            }
        }
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $fs = get_file_storage();
        foreach (['content', 'package'] as $area) {
            $this->assertSame([], $fs->get_area_files($this->context->id, 'mod_scormmakercmi5', $area, false), $area);
        }
        $this->assertSame($before, self::request_dirs(), 'temporary residue');
        return $e;
    }

    /**
     * The smallest package: one pkg, one AU, two files stored with their hashes.
     */
    public function test_minimal_package(): void {
        global $DB, $USER;
        $entries = builder::minimal_entries();
        $pkg = $this->import($entries);

        $this->assertSame(1, $DB->count_records('scormmakercmi5_pkg'));
        $this->assertEquals(1, $pkg->versionnum);
        $this->assertSame('unsigned', $pkg->sigstatus);
        $this->assertNull($pkg->sigreason);
        $this->assertNull($pkg->manifest);
        $this->assertEquals(2, $pkg->filecount);
        $this->assertEquals(strlen($entries['cmi5.xml']) + strlen($entries['index.html']), $pkg->totalbytes);
        $this->assertSame('https://example.com/course', $pkg->publishercourseid);
        $this->assertSame($entries['cmi5.xml'], $pkg->cmi5xml);
        $this->assertSame('curso.zip', $pkg->filename);
        $this->assertEquals($USER->id, $pkg->usermodified);
        $this->assertEquals($pkg, $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id]));

        $aus = $DB->get_records('scormmakercmi5_au', ['pkgid' => $pkg->id]);
        $this->assertCount(1, $aus);
        $au = reset($aus);
        $this->assertMatchesRegularExpression(self::AU_IRI, $au->lmsactivityid);
        $this->assertStringStartsWith((new \moodle_url('/mod/scormmakercmi5/xapi/au/'))->out(false), $au->lmsactivityid);
        $this->assertSame('https://example.com/au/1', $au->publisherid);
        $this->assertEquals(0, $au->blockid);
        $this->assertSame('index.html', $au->url);
        $this->assertSame('AnyWindow', $au->launchmethod);
        $this->assertSame('NotApplicable', $au->moveon);
        $this->assertNull($au->masteryscore);
        $this->assertSame(['en-US' => 'AU'], json_decode($au->title, true));

        $files = $DB->get_records_menu('scormmakercmi5_pkgfile', ['pkgid' => $pkg->id], 'path', 'path, sha256');
        $this->assertSame([
            'cmi5.xml' => hash('sha256', $entries['cmi5.xml']),
            'index.html' => hash('sha256', $entries['index.html']),
        ], $files);
        foreach ($DB->get_records('scormmakercmi5_pkgfile', ['pkgid' => $pkg->id]) as $row) {
            $this->assertSame(sha1($row->path), $row->pathhash);
            $this->assertSame(sha1($entries[$row->path]), $row->contenthash);
            $this->assertEquals(strlen($entries[$row->path]), $row->filesize);
        }

        $fs = get_file_storage();
        $content = $fs->get_area_files($this->context->id, 'mod_scormmakercmi5', 'content', $pkg->id, 'filepath, filename', false);
        $this->assertCount(2, $content);
        foreach ($content as $file) {
            $this->assertSame($entries[ltrim($file->get_filepath() . $file->get_filename(), '/')], $file->get_content());
        }
        $package = $fs->get_area_files($this->context->id, 'mod_scormmakercmi5', 'package', $pkg->id, 'id', false);
        $this->assertCount(1, $package);
        $package = reset($package);
        $this->assertSame('curso.zip', $package->get_filename());
        $this->assertSame($package->get_contenthash(), $pkg->zipcontenthash);
    }

    /**
     * Nested blocks: parent links, AU block ids and document order.
     */
    public function test_nested_blocks(): void {
        global $DB;
        $xml = builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/au/0', 'url' => 'index.html'],
            ['id' => 'https://example.com/b/1', 'title' => 'B1', 'children' => [
                ['id' => 'https://example.com/au/1', 'url' => 'index.html'],
                ['id' => 'https://example.com/b/2', 'title' => 'B2', 'children' => [
                    ['id' => 'https://example.com/au/2', 'url' => 'index.html'],
                ]],
                ['id' => 'https://example.com/au/3', 'url' => 'index.html'],
            ]],
            ['id' => 'https://example.com/au/4', 'url' => 'index.html'],
        ]]);
        $pkg = $this->import(builder::minimal_entries(['cmi5.xml' => $xml]));

        $blocks = $DB->get_records('scormmakercmi5_block', ['pkgid' => $pkg->id], 'sortorder');
        $this->assertCount(2, $blocks);
        $byid = [];
        foreach ($blocks as $block) {
            $byid[$block->publisherid] = $block;
            $this->assertMatchesRegularExpression(self::BLOCK_IRI, $block->lmsiri);
        }
        $b1 = $byid['https://example.com/b/1'];
        $b2 = $byid['https://example.com/b/2'];
        $this->assertEquals(0, $b1->parentid);
        $this->assertEquals($b1->id, $b2->parentid);
        $this->assertEquals([1, 2], [(int)$b1->depth, (int)$b2->depth]);
        $this->assertSame(['en-US' => 'B2'], json_decode($b2->title, true));
        $this->assertNotSame($b1->lmsiri, $b2->lmsiri);

        $aus = array_values($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkg->id], 'sortorder'));
        $this->assertSame(
            ['https://example.com/au/0', 'https://example.com/au/1', 'https://example.com/au/2', 'https://example.com/au/3',
                'https://example.com/au/4'],
            array_column($aus, 'publisherid')
        );
        $this->assertEquals([0, $b1->id, $b2->id, $b1->id, 0], array_map('intval', array_column($aus, 'blockid')));

        // One order across blocks and AUs: children of a parent sorted by sortorder follow the file.
        $order = [];
        foreach ($blocks as $block) {
            $order[(int)$block->sortorder] = $block->publisherid;
        }
        foreach ($aus as $au) {
            $order[(int)$au->sortorder] = $au->publisherid;
        }
        ksort($order);
        $this->assertSame([
            'https://example.com/au/0', 'https://example.com/b/1', 'https://example.com/au/1', 'https://example.com/b/2',
            'https://example.com/au/2', 'https://example.com/au/3', 'https://example.com/au/4',
        ], array_values($order));
    }

    /**
     * A package signed with a trusted key is verified and keeps the canonical manifest.
     */
    public function test_signed_valid(): void {
        $kp = builder::test_keypair();
        $entries = builder::sign(builder::minimal_entries(), $kp);
        $pkg = $this->import($entries);

        $this->assertSame('verified', $pkg->sigstatus);
        $this->assertNull($pkg->sigreason);
        $this->assertSame('sm-test-1', $pkg->sigkid);
        $manifest = json_decode($entries['META-INF/scormmaker-assinatura.json'])->manifesto;
        $this->assertSame(canonical_json::encode($manifest), $pkg->manifest);
        $this->assertSame(json_decode($entries['META-INF/scormmaker-assinatura.json'])->assinatura, $pkg->signature);
        $this->assertSame('3f2b8c1e-8d4a-4c7e-9b1a-0a1b2c3d4e5f', $pkg->smcourseid);
        $this->assertSame('2026-09-30T12:00:00Z#0a1b2c3d', $pkg->smversion);
        $this->assertEquals(3, $pkg->filecount);
    }

    /**
     * An AU outside the package (https url) is stored with its origin; the package is unsigned (reason external_au)
     * even with a valid signature of the zip, and has no analysis structure: the content is not in the zip.
     */
    public function test_external_au(): void {
        global $DB;
        $xml = builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html'],
            ['id' => 'https://example.com/au/2', 'url' => 'https://cursos.exemplo.com/au/index.html'],
        ]);
        $entries = builder::minimal_entries([
            'cmi5.xml' => $xml,
            analysis_structure::PATH => json_encode(self::contract_structure()),
            'media/m1.jpg' => 'x',
        ]);
        $pkg = $this->import(builder::sign($entries, builder::test_keypair()));

        $this->assertSame('unsigned', $pkg->sigstatus);
        $this->assertSame('external_au', $pkg->sigreason);
        $this->assertNull($pkg->richstructure);
        $this->assertNull($pkg->manifest);
        $this->assertNull($pkg->signature);
        $this->assertNull($pkg->sigkid);
        $aus = array_values($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkg->id], 'sortorder'));
        $this->assertNull($aus[0]->auorigin);
        $this->assertSame('https://cursos.exemplo.com', $aus[1]->auorigin);
        $this->assertSame('https://cursos.exemplo.com/au/index.html', $aus[1]->url);

        // Only the AU outside the package: no file of it is needed in the zip.
        $only = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html']]);
        $pkg = $this->import(['cmi5.xml' => $only]);
        $this->assertSame('unsigned', $pkg->sigstatus);
        $this->assertSame('external_au', $pkg->sigreason);
        $this->assertSame('https://cursos.exemplo.com', $DB->get_field('scormmakercmi5_au', 'auorigin', ['pkgid' => $pkg->id]));
    }

    /**
     * With allowexternalau off, a package with an AU outside it is refused at inspection, before anything is stored;
     * a package with every AU inside it still imports.
     */
    public function test_external_au_refused_when_disabled(): void {
        global $DB;
        // Off by default: also before any value is stored (no row in config_plugins).
        unset_config('allowexternalau', 'mod_scormmakercmi5');
        $this->assertFalse(importer::external_au_allowed());
        set_config('allowexternalau', 1, 'mod_scormmakercmi5');
        $this->assertTrue(importer::external_au_allowed());
        set_config('allowexternalau', 0, 'mod_scormmakercmi5');
        $this->assertFalse(importer::external_au_allowed());
        $before = $DB->count_records('scormmakercmi5_pkg');
        $only = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html']]);
        try {
            $this->import(['cmi5.xml' => $only]);
            $this->fail('imported');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_externalaudisabled', $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
        }
        $this->assertSame($before, $DB->count_records('scormmakercmi5_pkg'));
        $pkg = $this->import(builder::minimal_entries());
        $this->assertNull($DB->get_field('scormmakercmi5_au', 'auorigin', ['pkgid' => $pkg->id]));
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
     * The analysis structure (subproject D) is stored validated, with the images of the package only; a package
     * without it, or with an invalid one, is imported with richstructure NULL.
     */
    public function test_analysis_structure(): void {
        global $DB;
        $entries = builder::minimal_entries([
            analysis_structure::PATH => json_encode(self::contract_structure()),
            'media/m1.jpg' => 'x',
        ]);
        $pkg = $this->import(builder::sign($entries, builder::test_keypair()));
        $this->assertSame('verified', $pkg->sigstatus);
        $this->assertNotNull($pkg->richstructure);
        $stored = analysis_structure::decode($DB->get_field('scormmakercmi5_pkg', 'richstructure', ['id' => $pkg->id]));
        $this->assertSame('media/m1.jpg', $stored['blocos']['m1']['imagem']);
        $this->assertNull($stored['blocos']['tv1']['cenas'][0]['imagem']);

        $this->assertNull($this->import(builder::minimal_entries())->richstructure);
        $broken = $this->import(builder::minimal_entries([analysis_structure::PATH => '{"v":1']));
        $this->assertNull($broken->richstructure);
        $this->assertEquals(3, $broken->versionnum, 'the import goes on');
    }

    /**
     * A changed file makes the signature invalid, but the package is still imported (R2.3).
     */
    public function test_signed_file_changed(): void {
        global $DB;
        $entries = builder::sign(builder::minimal_entries(), builder::test_keypair(), [], static function (array $e): array {
            $e['index.html'] .= '<!-- changed -->';
            return $e;
        });
        $pkg = $this->import($entries);
        $this->assertSame(['invalid', 'filechanged'], [$pkg->sigstatus, $pkg->sigreason]);
        $this->assertSame('sm-test-1', $pkg->sigkid);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_au'));
    }

    /**
     * Manifest fields wider than their columns never block the import: they are left empty.
     */
    public function test_manifest_fields_too_wide(): void {
        $entries = builder::sign(builder::minimal_entries(), builder::test_keypair(), [
            'cursoId' => str_repeat('c', 37),
            'versao' => str_repeat('v', 65),
        ]);
        $pkg = $this->import($entries);
        $this->assertSame('verified', $pkg->sigstatus);
        $this->assertNull($pkg->smcourseid);
        $this->assertNull($pkg->smversion);

        // An unknown kid longer than the column: invalid, kid left empty, import goes on.
        $kp = builder::test_keypair();
        $kp['kid'] = str_repeat('k', 65);
        $pkg = $this->import(builder::sign(builder::minimal_entries(), $kp));
        $this->assertSame(['invalid', 'unknownkid'], [$pkg->sigstatus, $pkg->sigreason]);
        $this->assertNull($pkg->sigkid);
        $this->assertEquals(2, $pkg->versionnum);
    }

    /**
     * A zip larger than the limit is refused before it is opened, whatever its content.
     */
    public function test_too_large(): void {
        $zip = builder::zip(builder::minimal_entries());
        $e = $this->assert_refused('err_toolarge', fn() => (new importer())->inspect_file($zip, 10));
        $this->assertStringContainsString(display_size(10), $e->getMessage());

        $junk = make_request_directory() . '/junk.zip';
        file_put_contents($junk, str_repeat('x', 100));
        $this->assert_refused('err_toolarge', fn() => (new importer())->inspect_file($junk, 10));
        // Same file under the limit: now it is opened and refused as not a zip.
        $this->assert_refused('err_notzip', fn() => (new importer())->inspect_file($junk, 1000));
    }

    /**
     * cmi5.xml must be at the root.
     */
    public function test_cmi5xml_not_at_root(): void {
        $entries = builder::minimal_entries();
        $entries['sub/cmi5.xml'] = $entries['cmi5.xml'];
        unset($entries['cmi5.xml']);
        $zip = builder::zip($entries);
        $this->assert_refused('err_nocmi5xml', fn() => (new importer())->inspect_file($zip, 1 << 30));
    }

    /**
     * An invalid cmi5.xml creates nothing and leaves no temporary files.
     */
    public function test_invalid_cmi5xml(): void {
        $zip = builder::zip(builder::minimal_entries(['cmi5.xml' => '<courseStructure/>']));
        $this->assert_refused('err_xmlinvalid', fn() => (new importer())->inspect_file($zip, 1 << 30));
        $zip = builder::zip(builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'missing.html'],
        ])]));
        $this->assert_refused('err_aufilemissing', fn() => (new importer())->inspect_file($zip, 1 << 30));
    }

    /**
     * Unsafe zips (Task 2) are refused through the importer with no residue.
     */
    public function test_unsafe_zip(): void {
        $zip = builder::zip(builder::minimal_entries(['../evil.html' => 'x']));
        $this->assert_refused('err_zippath', fn() => (new importer())->inspect_file($zip, 1 << 30));
    }

    /**
     * Rewrites the declared uncompressed size of one entry, in its local header and in the central directory.
     *
     * @param string $zip Zip path.
     * @param string $name Entry name.
     * @param int $declared Size to declare.
     */
    private static function lie_about_size(string $zip, string $name, int $declared): void {
        $bytes = file_get_contents($zip);
        // Local headers: uncompressed size at 22, name length at 26, name at 30.
        // Central directory: uncompressed size at 24, name length at 28, name at 46.
        foreach (["PK\x03\x04" => [22, 26, 30], "PK\x01\x02" => [24, 28, 46]] as $signature => [$sizeat, $lenat, $nameat]) {
            $offset = 0;
            while (($offset = strpos($bytes, $signature, $offset)) !== false) {
                $len = unpack('v', substr($bytes, $offset + $lenat, 2))[1];
                if (substr($bytes, $offset + $nameat, $len) === $name) {
                    $bytes = substr_replace($bytes, pack('V', $declared), $offset + $sizeat, 4);
                }
                $offset += 4;
            }
        }
        file_put_contents($zip, $bytes);
    }

    /**
     * A zip whose declared sizes are smaller than the real content is refused, with no residue.
     */
    public function test_declared_size_lie(): void {
        $zip = builder::zip(builder::minimal_entries(['big.txt' => str_repeat('a', 5000)]));
        self::lie_about_size($zip, 'big.txt', 10);
        $this->assert_refused('err_zipextract', fn() => (new importer())->inspect_file($zip, 1 << 30));

        // Declared larger than the real content: also refused.
        $zip = builder::zip(builder::minimal_entries(['big.txt' => str_repeat('a', 5000)]));
        self::lie_about_size($zip, 'big.txt', 6000);
        $this->assert_refused('err_zipextract', fn() => (new importer())->inspect_file($zip, 1 << 30));
    }

    /**
     * Zip bomb: a 50 MB entry that declares 10 bytes (the zip is about 51 KB). Extraction stops at the declared
     * size + 1 byte instead of writing the 50 MB to disk.
     */
    public function test_zip_bomb_capped_during_extraction(): void {
        $entries = builder::minimal_entries(['big.txt' => str_repeat('a', 50 * 1048576)]);
        $zip = builder::zip($entries);
        unset($entries['big.txt']);
        $this->assertLessThan(100 * 1024, filesize($zip));
        self::lie_about_size($zip, 'big.txt', 10);
        $importer = new class extends importer {
            /** @var int[] Bytes written per entry. */
            public array $written = [];

            /**
             * Records what the real copy wrote.
             *
             * @param resource $in Entry stream.
             * @param resource $out Target file.
             * @param int $limit Most bytes to copy.
             * @return array [bytes, sha1].
             */
            protected function copy_entry($in, $out, int $limit): array {
                $result = parent::copy_entry($in, $out, $limit);
                $this->written[] = $result[0];
                return $result;
            }
        };
        $this->assert_refused('err_zipextract', fn() => $importer->inspect_file($zip, 1 << 30));
        $this->assertCount(3, $importer->written);
        $this->assertSame(11, end($importer->written));
        $this->assertLessThanOrEqual(strlen(implode('', $entries)) + 11, array_sum($importer->written));
    }

    /**
     * A cmi5.xml larger than the parser cap is refused by its size, before it is read into memory.
     */
    public function test_large_cmi5xml(): void {
        $xml = builder::cmi5_xml();
        $xml .= str_repeat(' ', cmi5_parser::MAX_BYTES + 1 - strlen($xml));
        $zip = builder::zip(builder::minimal_entries(['cmi5.xml' => $xml]));
        unset($xml);
        $importer = new class extends importer {
            /** @var bool Whether cmi5.xml was read. */
            public bool $read = false;

            /**
             * Records that cmi5.xml was read.
             *
             * @param string $path Path.
             * @return string|false
             */
            protected function read_cmi5xml(string $path): string|false {
                $this->read = true;
                return parent::read_cmi5xml($path);
            }
        };
        $this->assert_refused('err_xmlsize', fn() => $importer->inspect_file($zip, 1 << 30));
        $this->assertFalse($importer->read);
    }

    /**
     * Paths wider than the File API columns (filepath/filename 255) are refused before extraction.
     */
    public function test_path_too_long(): void {
        $zip = builder::zip(builder::minimal_entries([str_repeat('d', 254) . '/a.html' => 'x']));
        $this->assert_refused('err_pathtoolong', fn() => (new importer())->inspect_file($zip, 1 << 30));
        $zip = builder::zip(builder::minimal_entries([str_repeat('f', 256) => 'x']));
        $this->assert_refused('err_pathtoolong', fn() => (new importer())->inspect_file($zip, 1 << 30));
    }

    /**
     * A failure while storing rolls everything back, stored files included.
     */
    public function test_store_files_failure_rolls_back(): void {
        // The test would otherwise run inside the reset-by-rollback transaction, and the import's own
        // rollback would only be nested.
        $this->preventResetByRollback();
        $importer = new class extends importer {
            /**
             * Stores the files as usual, then fails.
             *
             * @param inspected_package $pkg Package.
             * @param \context_module $context Context.
             * @param int $pkgid Pkg id.
             * @param string $zippath Zip path.
             * @param string $filename File name.
             * @return array
             */
            protected function store_files(
                inspected_package $pkg,
                \context_module $context,
                int $pkgid,
                string $zippath,
                string $filename
            ): array {
                $stored = parent::store_files($pkg, $context, $pkgid, $zippath, $filename);
                $fs = get_file_storage();
                if (!$fs->get_area_files($context->id, 'mod_scormmakercmi5', 'content', $pkgid, 'id', false)) {
                    throw new \coding_exception('parent did not store the files');
                }
                throw new \moodle_exception('err_zipextract', 'mod_scormmakercmi5', '', 'simulated');
            }
        };
        $zip = builder::zip(builder::minimal_entries());
        $pkg = $importer->inspect_file($zip, 1 << 30);
        $this->assert_refused('err_zipextract', fn() => $importer->store($pkg, $this->instance, $this->context, $zip, 'x.zip'));
    }

    /**
     * A database error after the files were stored still leaves nothing behind. When the cleanup before the
     * rollback cannot run (simulated: the first delete_files() throws, as on a lost connection), the rollback drops
     * the files rows and the contents this import added are removed afterwards; shared contents stay.
     */
    public function test_database_error_rolls_back(): void {
        $this->preventResetByRollback();
        $importer = new class extends importer {
            /**
             * Stores the files as usual, then fails.
             *
             * @param inspected_package $pkg Package.
             * @param \context_module $context Context.
             * @param int $pkgid Pkg id.
             * @param string $zippath Zip path.
             * @param string $filename File name.
             * @return array
             */
            protected function store_files(
                inspected_package $pkg,
                \context_module $context,
                int $pkgid,
                string $zippath,
                string $filename
            ): array {
                global $DB;
                $stored = parent::store_files($pkg, $context, $pkgid, $zippath, $filename);
                // Same (instance, versionnum) as the row just inserted: unique index violation.
                $row = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkgid]);
                unset($row->id);
                $DB->insert_record('scormmakercmi5_pkg', $row);
                return $stored;
            }

            /** @var int Calls of delete_files(). */
            public int $deletecalls = 0;

            /**
             * Fails on the first call, the cleanup before the rollback.
             *
             * @param \context_module $context Context.
             * @param int $pkgid Pkg id.
             */
            protected function delete_files(\context_module $context, int $pkgid): void {
                if (++$this->deletecalls === 1) {
                    throw new \coding_exception('simulated lost connection');
                }
                parent::delete_files($context, $pkgid);
            }
        };
        // One content new to the file system and one already referenced by another file.
        $unique = 'unique ' . random_string(32);
        $shared = 'shared ' . random_string(32);
        $fs = get_file_storage();
        $fs->create_file_from_string(['contextid' => \context_system::instance()->id, 'component' => 'mod_scormmakercmi5',
            'filearea' => 'test', 'itemid' => 0, 'filepath' => '/', 'filename' => 'shared.txt'], $shared);
        $zip = builder::zip(builder::minimal_entries(['unique.txt' => $unique, 'shared.txt' => $shared]));
        $pkg = $importer->inspect_file($zip, 1 << 30);
        $this->assertSame(sha1($unique), $pkg->contenthashes['unique.txt']);
        $this->assert_refused('dmlwriteexception', function () use ($importer, $pkg, $zip): void {
            try {
                $importer->store($pkg, $this->instance, $this->context, $zip, 'x.zip');
            } finally {
                // The failed cleanup before the rollback is reported, not thrown.
                $this->assertDebuggingCalled('Cleanup of a failed package import did not complete');
            }
        });
        $this->assertSame(2, $importer->deletecalls);
        // Contents this import added to the file system are removed; shared ones stay.
        $filesystem = $fs->get_file_system();
        $this->assertFalse($filesystem->is_file_readable_remotely_by_hash(sha1($unique)));
        $this->assertFalse($filesystem->is_file_readable_remotely_by_hash(sha1_file($zip)));
        $this->assertTrue($filesystem->is_file_readable_remotely_by_hash(sha1($shared)));
    }

    /**
     * A second import is version 2; an AU with the same publisher id keeps its activity id, a new AU gets a new one.
     */
    public function test_second_import_inherits_activity_id(): void {
        global $DB;
        $v1 = $this->import(builder::minimal_entries());
        $au1 = $DB->get_record('scormmakercmi5_au', ['pkgid' => $v1->id]);

        $xml = builder::cmi5_xml([
            ['id' => 'https://example.com/au/new', 'url' => 'index.html'],
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Renamed'],
        ]);
        $v2 = $this->import(builder::minimal_entries(['cmi5.xml' => $xml]));
        $this->assertEquals(2, $v2->versionnum);
        $aus = $DB->get_records_menu('scormmakercmi5_au', ['pkgid' => $v2->id], '', 'publisherid, lmsactivityid');
        $this->assertSame($au1->lmsactivityid, $aus['https://example.com/au/1']);
        $this->assertMatchesRegularExpression(self::AU_IRI, $aus['https://example.com/au/new']);
        $this->assertNotSame($au1->lmsactivityid, $aus['https://example.com/au/new']);

        // Files of each version live under their own item id.
        $fs = get_file_storage();
        $this->assertCount(2, $fs->get_area_files($this->context->id, 'mod_scormmakercmi5', 'content', $v1->id, 'id', false));
        $this->assertCount(2, $fs->get_area_files($this->context->id, 'mod_scormmakercmi5', 'content', $v2->id, 'id', false));
    }

    /**
     * An AU absent from the latest version but present in an older one keeps the older activity id.
     */
    public function test_inherits_from_older_version(): void {
        global $DB;
        $v1 = $this->import(builder::minimal_entries());
        $old = $DB->get_field('scormmakercmi5_au', 'lmsactivityid', ['pkgid' => $v1->id]);
        $this->import(builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml([
            ['id' => 'https://example.com/au/other', 'url' => 'index.html'],
        ])]));
        $v3 = $this->import(builder::minimal_entries());
        $this->assertEquals(3, $v3->versionnum);
        $this->assertSame($old, $DB->get_field('scormmakercmi5_au', 'lmsactivityid', ['pkgid' => $v3->id]));
    }

    /**
     * 1000 AUs are stored.
     */
    public function test_thousand_aus(): void {
        global $DB;
        $aus = [];
        for ($i = 0; $i < 1000; $i++) {
            $aus[] = ['id' => 'https://example.com/au/' . $i, 'url' => 'index.html'];
        }
        $pkg = $this->import(builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml($aus)]));
        $this->assertSame(1000, $DB->count_records('scormmakercmi5_au', ['pkgid' => $pkg->id]));
        $ids = $DB->get_fieldset_select('scormmakercmi5_au', 'lmsactivityid', 'pkgid = :pkgid', ['pkgid' => $pkg->id]);
        $this->assertSame(1000, count(array_unique($ids)));
        $last = $DB->get_records('scormmakercmi5_au', ['pkgid' => $pkg->id], 'sortorder DESC', 'publisherid', 0, 1);
        $this->assertSame('https://example.com/au/999', reset($last)->publisherid);
    }

    /**
     * A cmi5.xml whose value is placed in the element of the case.
     *
     * @param string $element course, block, au or activityType.
     * @param string $value Value.
     * @return string XML.
     */
    private static function xml_with(string $element, string $value): string {
        $au = ['id' => 'https://example.com/au/1', 'url' => 'index.html'];
        $course = [];
        if ($element === 'au@id') {
            $au['id'] = $value;
        } else if ($element === 'au@activityType') {
            $au['activityType'] = $value;
        } else if ($element === 'course@id') {
            $course['id'] = $value;
        }
        if ($element === 'block@id') {
            $course['blocks'] = [['id' => $value, 'children' => [$au]]];
        }
        return builder::cmi5_xml([$au], $course);
    }

    /**
     * Elements.
     *
     * @return array
     */
    public static function element_provider(): array {
        return [
            'au@id' => ['au@id'],
            'block@id' => ['block@id'],
            'course@id' => ['course@id'],
            'au@activityType' => ['au@activityType'],
        ];
    }

    /**
     * Ids and activityType wider than their 1333-character columns refuse the package, naming the element (§3.13).
     *
     * @dataProvider element_provider
     * @param string $element Element.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('element_provider')]
    public function test_id_too_long(string $element): void {
        global $DB;
        $prefix = 'https://example.com/';
        $zip = builder::zip(builder::minimal_entries([
            'cmi5.xml' => self::xml_with($element, $prefix . str_repeat('a', 1334 - strlen($prefix))),
        ]));
        $e = $this->assert_refused('err_idtoolong', fn() => (new importer())->inspect_file($zip, 1 << 30));
        $this->assertSame($element, $e->a);

        // 1333 characters, two bytes each: fits (the column counts characters).
        $value = $prefix . str_repeat("\u{00E9}", 1333 - strlen($prefix));
        $this->assertSame(1333, \core_text::strlen($value));
        $pkg = $this->import(builder::minimal_entries(['cmi5.xml' => self::xml_with($element, $value)]));
        $fields = [
            'au@id' => ['scormmakercmi5_au', 'publisherid'],
            'au@activityType' => ['scormmakercmi5_au', 'activitytype'],
            'block@id' => ['scormmakercmi5_block', 'publisherid'],
            'course@id' => ['scormmakercmi5_pkg', 'publishercourseid'],
        ];
        [$table, $field] = $fields[$element];
        $column = $table === 'scormmakercmi5_pkg' ? 'id' : 'pkgid';
        $this->assertSame($value, $DB->get_field($table, $field, [$column => $pkg->id]));
    }

    /**
     * Stores a draft file for the current user.
     *
     * @param string $zip Zip path.
     * @return int Draft item id.
     */
    private static function draft(string $zip): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'meu curso.zip',
        ], $zip);
        return $draftid;
    }

    /**
     * draft_to_temp copies the draft file; import_draft runs the whole flow with the course limit.
     */
    public function test_import_draft(): void {
        $zip = builder::zip(builder::minimal_entries());
        $draftid = self::draft($zip);
        $temp = importer::draft_to_temp($draftid);
        $this->assertSame('meu curso.zip', $temp['filename']);
        $this->assertFileEquals($zip, $temp['path']);

        $pkg = (new importer())->import_draft($draftid, $this->instance, $this->context, $this->course);
        $this->assertSame('meu curso.zip', $pkg->filename);
        $this->assertEquals(1, $pkg->versionnum);
    }

    /**
     * An empty draft area is err_nopackage; the course upload limit applies to import_draft.
     */
    public function test_import_draft_refusals(): void {
        $this->assert_refused('err_nopackage', fn() => importer::draft_to_temp(file_get_unused_draft_itemid()));

        $draftid = self::draft(builder::zip(builder::minimal_entries()));
        $this->course->maxbytes = 10;
        $this->assert_refused('err_toolarge', fn() => (new importer())->import_draft(
            $draftid,
            $this->instance,
            $this->context,
            $this->course
        ));
    }

    /**
     * The new error strings exist in both languages.
     */
    public function test_strings(): void {
        $sm = get_string_manager();
        $keys = ['err_idtoolong', 'err_nocmi5xml', 'err_nopackage', 'err_packageunreadable', 'err_pathtoolong',
            'err_toolarge', 'err_zipextract'];
        foreach ($keys as $key) {
            $this->assertTrue($sm->string_exists($key, 'mod_scormmakercmi5'), $key);
        }
        $string = [];
        include(\core_component::get_component_directory('mod_scormmakercmi5') . '/lang/pt_br/scormmakercmi5.php');
        $this->assertArrayHasKey('err_idtoolong', $string);
    }

    /**
     * A second import that read the version number before the first one committed hits the unique index: it fails
     * with err_importbusy, not a raw database error, and leaves nothing behind (review M5).
     */
    public function test_concurrent_version_number(): void {
        global $DB;
        $this->preventResetByRollback();
        $first = $this->import(builder::minimal_entries());
        $stale = new class extends importer {
            /**
             * The version number the other import already took.
             *
             * @param array $previous Pkg rows.
             * @return int
             */
            protected function next_versionnum(array $previous): int {
                return 1;
            }
        };
        $zip = builder::zip(builder::minimal_entries(['outro.html' => 'x']));
        $pkg = $stale->inspect_file($zip, 1 << 30);
        try {
            $stale->store($pkg, $this->instance, $this->context, $zip, 'curso.zip');
            $this->fail('Expected err_importbusy');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_importbusy', $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
        }
        $this->assertSame([(int)$first->id], array_map('intval', array_keys($DB->get_records('scormmakercmi5_pkg'))));
        $files = get_file_storage()->get_area_files($this->context->id, 'mod_scormmakercmi5', 'content', false, 'id', false);
        $this->assertCount(2, $files, 'only the files of the first import');
    }

    /**
     * While another request holds the import lock of the instance, store() gives up with err_importbusy.
     */
    public function test_import_lock_busy(): void {
        global $CFG, $DB;
        // The file lock factory refuses a second flock() of the same file even in one process; the database
        // factories are reentrant within one connection and could not show the contention here.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $timeout = new \ReflectionProperty(importer::class, 'locktimeout');
        $timeout->setValue(null, 0);
        $lock = \core\lock\lock_config::get_lock_factory('mod_scormmakercmi5')->get_lock('import' . $this->instance->id, 0);
        $zip = builder::zip(builder::minimal_entries());
        $importer = new importer();
        $pkg = $importer->inspect_file($zip, 1 << 30);
        try {
            $this->assertNotFalse($lock);
            $this->assert_refused('err_importbusy', fn() => $importer->store($pkg, $this->instance, $this->context, $zip, 'a.zip'));
        } finally {
            $lock->release();
            $timeout->setValue(null, 30);
        }
        $this->assertSame(1, (int)$this->import(builder::minimal_entries())->versionnum);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_pkg'));
    }
}
