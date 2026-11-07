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
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\zip_inspector::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\zip_listing::class)]
/**
 * Tests for the zip inspection that runs before any extraction (design §4.1 step 2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\package\zip_inspector
 * @covers     \mod_scormmakercmi5\local\package\zip_listing
 */
final class zip_inspector_test extends \advanced_testcase {
    /** @var int Generous entry limit. */
    private const ENTRIES = 20000;

    /** @var int Generous size limit. */
    private const BYTES = 1073741824;

    /**
     * Runs the inspection and returns the moodle_exception it throws.
     *
     * @param string $path Zip path.
     * @param int $maxentries Entry limit.
     * @param int $maxbytes Size limit.
     * @return \moodle_exception
     */
    private function refused(string $path, int $maxentries = self::ENTRIES, int $maxbytes = self::BYTES): \moodle_exception {
        try {
            zip_inspector::inspect($path, $maxentries, $maxbytes);
        } catch (\moodle_exception $e) {
            $this->assertSame('mod_scormmakercmi5', $e->module);
            return $e;
        }
        $this->fail('The zip was accepted.');
    }

    /**
     * A valid zip lists its files in zip order, without directories, and the uncompressed total.
     */
    public function test_valid(): void {
        $xml = builder::cmi5_xml();
        $html = '<!doctype html><title>B</title>';
        $path = builder::zip(['cmi5.xml' => $xml, 'a/' => '', 'a/b.html' => $html]);
        $listing = zip_inspector::inspect($path, self::ENTRIES, self::BYTES);
        $this->assertInstanceOf(zip_listing::class, $listing);
        $this->assertSame(['cmi5.xml', 'a/b.html'], $listing->files);
        $this->assertSame(strlen($xml) + strlen($html), $listing->totalbytes);
    }

    /**
     * Entry names that must be refused as unsafe paths.
     *
     * @return array
     */
    public static function unsafe_path_provider(): array {
        return [
            'parent' => ['../evil.txt'],
            'inner parent' => ['a/../../x.txt'],
            'absolute' => ['/abs.txt'],
            'backslash' => ['a\\b.txt'],
            'drive letter' => ['C:/x.txt'],
        ];
    }

    /**
     * Traversal, absolute, backslash and drive-letter names are refused with the entry name.
     *
     * @dataProvider unsafe_path_provider
     * @param string $name Entry name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe_path_provider')]
    public function test_unsafe_path(string $name): void {
        $e = $this->refused(builder::zip(['cmi5.xml' => builder::cmi5_xml(), $name => 'x']));
        $this->assertSame('err_zippath', $e->errorcode);
        $this->assertSame($name, $e->a);
    }

    /**
     * A NUL byte in a name is refused.
     */
    public function test_nul_byte(): void {
        $path = builder::zip(['cmi5.xml' => builder::cmi5_xml(), 'aXb.txt' => 'x'], ['patch' => ['aXb' => "a\0b"]]);
        $this->assertStringContainsString("a\0b.txt", file_get_contents($path));
        $e = $this->refused($path);
        $this->assertSame('err_zippath', $e->errorcode);
        $this->assertSame('a\0b.txt', $e->a);
    }

    /**
     * A symbolic link entry is refused.
     */
    public function test_symlink(): void {
        $path = builder::zip(['cmi5.xml' => builder::cmi5_xml()], ['symlinks' => ['link' => '/etc/passwd']]);
        $e = $this->refused($path);
        $this->assertSame('err_zipsymlink', $e->errorcode);
        $this->assertSame('link', $e->a);
    }

    /**
     * Two names that differ only in case are duplicates.
     */
    public function test_duplicate_ignoring_case(): void {
        $e = $this->refused(builder::zip(['Index.html' => 'a', 'index.html' => 'b']));
        $this->assertSame('err_zipduplicate', $e->errorcode);
        $this->assertSame('index.html', $e->a);
    }

    /**
     * More entries than the limit are refused, with the limit in the message.
     */
    public function test_too_many_entries(): void {
        $entries = [];
        for ($i = 1; $i <= 6; $i++) {
            $entries["f{$i}.txt"] = 'x';
        }
        $e = $this->refused(builder::zip($entries), 5);
        $this->assertSame('err_zipentries', $e->errorcode);
        $this->assertSame(5, $e->a);
    }

    /**
     * The entry limit is inclusive.
     */
    public function test_entries_at_limit(): void {
        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entries["f{$i}.txt"] = 'x';
        }
        $this->assertCount(5, zip_inspector::inspect(builder::zip($entries), 5, self::BYTES)->files);
    }

    /**
     * A zip bomb is refused from the declared sizes, without extracting anything.
     */
    public function test_zip_bomb(): void {
        $path = builder::zip(['cmi5.xml' => builder::cmi5_xml(), 'zeros.bin' => str_repeat("\0", 2 * 1048576)]);
        $this->assertLessThan(1048576, filesize($path));
        $before = scandir(dirname($path));
        $e = $this->refused($path, self::ENTRIES, 1048576);
        $this->assertSame('err_zipsize', $e->errorcode);
        $this->assertSame(1, $e->a);
        $this->assertSame($before, scandir(dirname($path)));
    }

    /**
     * A zip64 archive is accepted (conformance package 102-zip64.zip, requirement 14.0.0.0-1).
     */
    public function test_zip64(): void {
        $path = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/zip/zip64.zip';
        $bytes = file_get_contents($path);
        // Zip64 end of central directory record and locator are present.
        $this->assertStringContainsString("PK\x06\x06", $bytes);
        $this->assertStringContainsString("PK\x06\x07", $bytes);
        $listing = zip_inspector::inspect($path, self::ENTRIES, self::BYTES);
        $this->assertSame(['cmi5.xml', 'index.html'], $listing->files);
        $this->assertGreaterThan(0, $listing->totalbytes);
    }

    /**
     * The NUL check also reads the central directory of a zip64 archive.
     */
    public function test_zip64_nul_byte(): void {
        $source = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/zip/zip64.zip';
        $path = make_request_directory() . '/zip64nul.zip';
        file_put_contents($path, str_replace('index.html', "index\0html", file_get_contents($source)));
        $e = $this->refused($path);
        $this->assertSame('err_zippath', $e->errorcode);
        $this->assertSame('index\0html', $e->a);
    }

    /**
     * A file that is not a zip is refused (requirement 14.1.0.0-1).
     */
    public function test_not_a_zip(): void {
        $path = make_request_directory() . '/hello.zip';
        file_put_contents($path, 'hello');
        $e = $this->refused($path);
        $this->assertSame('err_notzip', $e->errorcode);
    }

    /**
     * An empty file is refused as not a zip.
     */
    public function test_empty_file(): void {
        $path = make_request_directory() . '/empty.zip';
        touch($path);
        $e = $this->refused($path);
        $this->assertSame('err_notzip', $e->errorcode);
    }

    /**
     * A missing file is refused as not a zip.
     */
    public function test_missing_file(): void {
        $e = $this->refused(make_request_directory() . '/nothing.zip');
        $this->assertSame('err_notzip', $e->errorcode);
    }

    /**
     * The builder writes cmi5.xml with blocks and AU attributes in schema order.
     */
    public function test_builder_cmi5_xml(): void {
        $xml = builder::cmi5_xml([], ['id' => 'https://example.com/c', 'title' => 'C', 'blocks' => [
            ['id' => 'https://example.com/b', 'title' => 'B', 'children' => [
                ['id' => 'https://example.com/au/2', 'url' => 'a.html', 'moveOn' => 'Passed', 'masteryScore' => '0.8',
                    'launchMethod' => 'OwnWindow', 'launchParameters' => 'p=1', 'entitlementKey' => 'k',
                    'activityType' => 'https://example.com/t'],
            ]],
        ]]);
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
        $au = $doc->getElementsByTagName('au')->item(0);
        $this->assertSame('block', $au->parentNode->localName);
        $this->assertSame('Passed', $au->getAttribute('moveOn'));
        $names = [];
        foreach ($au->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $names[] = $child->localName;
            }
        }
        $this->assertSame(['title', 'description', 'url', 'launchParameters', 'entitlementKey'], $names);
    }

    /**
     * Pairs whose second name the extractor would rewrite (and then drop as a collision), in both orders.
     *
     * @return array
     */
    public static function rewritten_pair_provider(): array {
        return [
            'control character, after' => ['cmi5.xml', "cmi5.xml\x01", 'cmi5.xml?'],
            'control character, before' => ["cmi5.xml\x01", 'cmi5.xml', 'cmi5.xml?'],
            'double slash, after' => ['a/b.html', 'a//b.html', 'a//b.html'],
            'double slash, before' => ['a//b.html', 'a/b.html', 'a//b.html'],
            'dot segment, after' => ['x.html', './x.html', './x.html'],
            'dot segment, before' => ['./x.html', 'x.html', './x.html'],
            'inner dot segment' => ['a/y.html', 'a/./y.html', 'a/./y.html'],
        ];
    }

    /**
     * A name the File API extractor would write differently is refused, whatever the entry order.
     *
     * @dataProvider rewritten_pair_provider
     * @param string $first First entry.
     * @param string $second Second entry.
     * @param string $shown Name shown in the message.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rewritten_pair_provider')]
    public function test_rewritten_name_is_refused(string $first, string $second, string $shown): void {
        $e = $this->refused(builder::zip([$first => 'FIRST', $second => 'SECOND']));
        $this->assertSame('err_zippath', $e->errorcode);
        $this->assertSame($shown, $e->a);
    }

    /**
     * A character that clean_param(PARAM_PATH) strips is refused on its own too.
     */
    public function test_stripped_character(): void {
        $e = $this->refused(builder::zip(['ab:c.html' => 'x']));
        $this->assertSame('err_zippath', $e->errorcode);
        $this->assertSame('ab:c.html', $e->a);
    }

    /**
     * The name in the message is sanitised: no HTML, bounded length.
     */
    public function test_message_name_is_sanitised(): void {
        $e = $this->refused(builder::zip(['<img src=x onerror=alert(1)>/../z' => 'x']));
        $this->assertSame('err_zippath', $e->errorcode);
        $this->assertSame('?img src=x onerror=alert(1)?/../z', $e->a);
        $e = $this->refused(builder::zip([str_repeat('a', 300) . '/../z' => 'x']));
        $this->assertSame(str_repeat('a', 200) . '...', $e->a);
    }

    /**
     * Pairs where one name is a file and also a folder, in both orders.
     *
     * @return array
     */
    public static function file_folder_provider(): array {
        return [
            'file first' => [['a' => 'x', 'a/b.html' => 'y']],
            'folder first' => [['a/b.html' => 'y', 'a' => 'x']],
            'case differs' => [['A' => 'x', 'a/b.html' => 'y']],
            'deep' => [['a/b' => 'x', 'a/b/c/d.html' => 'y']],
        ];
    }

    /**
     * A file whose name is also a folder cannot be extracted and is refused.
     *
     * @dataProvider file_folder_provider
     * @param array $entries Zip entries.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('file_folder_provider')]
    public function test_file_and_folder_collision(array $entries): void {
        $e = $this->refused(builder::zip($entries));
        $this->assertSame('err_zipfiledir', $e->errorcode);
    }

    /**
     * An explicit folder entry after its files is not a duplicate.
     */
    public function test_folder_entry_after_its_files(): void {
        $listing = zip_inspector::inspect(builder::zip(['a/b.html' => 'x', 'a/' => '']), self::ENTRIES, self::BYTES);
        $this->assertSame(['a/b.html'], $listing->files);
    }

    /**
     * Operating system files that the extractor skips are not listed.
     */
    public function test_system_files_not_listed(): void {
        $path = builder::zip(['cmi5.xml' => 'x', '__MACOSX/._cmi5.xml' => 'y', 'a/.DS_Store' => 'z']);
        $this->assertSame(['cmi5.xml'], zip_inspector::inspect($path, self::ENTRIES, self::BYTES)->files);
    }

    /**
     * The listing is exactly what the File API extracts.
     */
    public function test_listing_matches_extraction(): void {
        $path = builder::zip(['cmi5.xml' => 'x', 'a/' => '', 'a/b c.html' => 'y', 'img/é.png' => 'z', '.DS_Store' => 'w']);
        $listing = zip_inspector::inspect($path, self::ENTRIES, self::BYTES);
        $dir = make_request_directory();
        $result = get_file_packer('application/zip')->extract_to_pathname($path, $dir);
        $extracted = [];
        foreach ($result as $name => $status) {
            $this->assertTrue($status, $name);
            if (substr($name, -1) !== '/') {
                $extracted[] = $name;
            }
        }
        $this->assertSame($listing->files, $extracted);
    }

    /**
     * Malformed end of central directory records are refused as not a zip.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        return [
            'truncated in the central directory' => ['truncate'],
            'entry count patched' => ['count'],
            'central directory offset patched' => ['offset'],
            'end record cut' => ['eocd'],
        ];
    }

    /**
     * A damaged central directory is refused, never half read.
     *
     * @dataProvider malformed_provider
     * @param string $damage Kind of damage.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed_provider')]
    public function test_malformed_central_directory(string $damage): void {
        $path = builder::zip(['cmi5.xml' => builder::cmi5_xml(), 'index.html' => 'x']);
        $bytes = file_get_contents($path);
        $eocd = strrpos($bytes, "PK\x05\x06");
        $cd = unpack('V', substr($bytes, $eocd + 16, 4))[1];
        switch ($damage) {
            case 'truncate':
                $bytes = substr($bytes, 0, $cd + 30);
                break;
            case 'count':
                $bytes = substr_replace($bytes, pack('v', 3), $eocd + 8, 2);
                $bytes = substr_replace($bytes, pack('v', 3), $eocd + 10, 2);
                break;
            case 'offset':
                $bytes = substr_replace($bytes, pack('V', $cd + 7), $eocd + 16, 4);
                break;
            case 'eocd':
                $bytes = substr($bytes, 0, $eocd + 12);
                break;
        }
        file_put_contents($path, $bytes);
        $e = $this->refused($path);
        $this->assertSame('err_notzip', $e->errorcode);
    }
}
