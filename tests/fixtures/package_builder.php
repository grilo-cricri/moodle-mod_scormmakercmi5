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
 * Builds cmi5 packages (zip files) for the tests.
 *
 * Load with require_once(core_component::get_component_directory('mod_scormmakercmi5')
 * . '/tests/fixtures/package_builder.php').
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_scormmakercmi5\local\signature\canonical_json;
use mod_scormmakercmi5\local\signature\verification_result;

/**
 * Test package builder: zip files, minimal entries, cmi5.xml documents and signatures.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_scormmakercmi5_test_package_builder {
    /** @var string Namespace of the cmi5 course structure. */
    public const CMI5_NS = 'https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd';

    /**
     * Writes a zip file.
     *
     * Entries whose path ends with "/" become directory entries. Options:
     * - symlinks: path => target; each becomes an entry whose Unix external attribute is S_IFLNK 0777;
     * - patch: search => replace (same length) applied to the bytes of the final zip, for names that
     *   ZipArchive cannot write (for example a NUL byte).
     *
     * @param array $entries path => content.
     * @param array $options See above.
     * @return string Path of the zip, inside a request directory.
     */
    public static function zip(array $entries, array $options = []): string {
        $path = make_request_directory() . '/package.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \coding_exception('Cannot create ' . $path);
        }
        foreach ($entries as $name => $content) {
            $name = (string)$name;
            if (substr($name, -1) === '/') {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, (string)$content);
            }
        }
        foreach ($options['symlinks'] ?? [] as $name => $target) {
            $zip->addFromString((string)$name, (string)$target);
            $zip->setExternalAttributesName((string)$name, \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        if (!$zip->close()) {
            throw new \coding_exception('Cannot write ' . $path);
        }
        if (!empty($options['patch'])) {
            $bytes = file_get_contents($path);
            foreach ($options['patch'] as $search => $replace) {
                if (strlen((string)$search) !== strlen((string)$replace)) {
                    throw new \coding_exception('Patch must keep the length: ' . $search);
                }
                $bytes = str_replace((string)$search, (string)$replace, $bytes);
            }
            file_put_contents($path, $bytes);
        }
        return $path;
    }

    /**
     * A fixed Ed25519 test key pair (seed of 32 bytes 0x01). Never a real SCORM Maker key.
     *
     * @return array ['kid' => 'sm-test-1', 'public' => base64 public key, 'secret' => raw secret key].
     */
    public static function test_keypair(): array {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
        return [
            'kid' => 'sm-test-1',
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * Signs package entries the way SCORM Maker does (docs/xapi/assinatura.md, design §5.1).
     *
     * The manifest lists every file entry (directory entries, ending in "/", are left out) as
     * "sha256:<hex>"; its canonical form is signed with the key pair and the signature file is
     * added at verification_result::SIGNATURE_PATH.
     *
     * @param array $entries path => content.
     * @param array $keypair ['kid' => …, 'secret' => raw secret key], as test_keypair() returns.
     * @param array $manifestoverrides Manifest fields to replace or add before signing (arquivos as path => hash).
     * @param callable|null $tamper Receives the signed entries and returns the tampered ones.
     * @return array path => content, including the signature file.
     */
    public static function sign(
        array $entries,
        array $keypair,
        array $manifestoverrides = [],
        ?callable $tamper = null
    ): array {
        $files = [];
        foreach ($entries as $name => $content) {
            if (substr((string)$name, -1) !== '/') {
                $files[(string)$name] = 'sha256:' . hash('sha256', (string)$content);
            }
        }
        $manifest = array_merge([
            'arquivos' => $files,
            'cursoId' => '3f2b8c1e-8d4a-4c7e-9b1a-0a1b2c3d4e5f',
            'donoId' => '7',
            'formato' => 1,
            'gerador' => 'SCORM Maker 1.0.0',
            'kid' => $keypair['kid'],
            'tipoDePacote' => 'cmi5',
            'versao' => '2026-09-30T12:00:00Z#0a1b2c3d',
        ], $manifestoverrides);
        $manifest = (object)$manifest;
        if (is_array($manifest->arquivos)) {
            $manifest->arquivos = (object)$manifest->arquivos;
        }
        $canonical = canonical_json::encode($manifest);
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, $keypair['secret']));
        $entries[verification_result::SIGNATURE_PATH] = json_encode(
            ['manifesto' => $manifest, 'assinatura' => $signature],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        return $tamper ? $tamper($entries) : $entries;
    }

    /**
     * The entries of the smallest valid package, with overrides merged in.
     *
     * @param array $overrides path => content (replaces or adds).
     * @return array path => content.
     */
    public static function minimal_entries(array $overrides = []): array {
        return array_merge([
            'cmi5.xml' => self::cmi5_xml(),
            'index.html' => '<!doctype html><title>AU</title>',
        ], $overrides);
    }

    /**
     * A cmi5.xml document that validates against CourseStructure.xsd.
     *
     * Each AU accepts id, url, title, moveOn, masteryScore, launchMethod, launchParameters,
     * entitlementKey, activityType. A block is ['id' => …, 'title' => …, 'children' => [AUs/blocks]]
     * (an item with 'children' is a block). $course accepts id, title, description, blocks; when
     * blocks is given it replaces $aus as the top-level children.
     *
     * @param array $aus Top-level AUs.
     * @param array $course Course attributes.
     * @return string XML.
     */
    public static function cmi5_xml(
        array $aus = [['id' => 'https://example.com/au/1', 'url' => 'index.html']],
        array $course = []
    ): string {
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::CMI5_NS, 'courseStructure');
        $doc->appendChild($root);
        $el = self::element($doc, 'course');
        $el->setAttribute('id', $course['id'] ?? 'https://example.com/course');
        $title = $course['title'] ?? 'Course';
        self::texts($doc, $el, $title, $course['description'] ?? $title);
        $root->appendChild($el);
        foreach ($course['blocks'] ?? $aus as $item) {
            self::item($doc, $root, $item);
        }
        return $doc->saveXML();
    }

    /**
     * Appends an AU or a block (an item with 'children').
     *
     * @param \DOMDocument $doc Document.
     * @param \DOMElement $parent Parent element.
     * @param array $item AU or block.
     */
    private static function item(\DOMDocument $doc, \DOMElement $parent, array $item): void {
        if (array_key_exists('children', $item)) {
            $el = self::element($doc, 'block');
            $el->setAttribute('id', $item['id'] ?? 'https://example.com/block/1');
            $title = $item['title'] ?? 'Block';
            self::texts($doc, $el, $title, $item['description'] ?? $title);
            $parent->appendChild($el);
            foreach ($item['children'] as $child) {
                self::item($doc, $el, $child);
            }
            return;
        }
        $el = self::element($doc, 'au');
        $el->setAttribute('id', $item['id'] ?? 'https://example.com/au/1');
        foreach (['moveOn', 'masteryScore', 'launchMethod', 'activityType'] as $attr) {
            if (isset($item[$attr])) {
                $el->setAttribute($attr, (string)$item[$attr]);
            }
        }
        $title = $item['title'] ?? 'AU';
        self::texts($doc, $el, $title, $item['description'] ?? $title);
        $url = self::element($doc, 'url');
        $url->appendChild($doc->createTextNode($item['url'] ?? 'index.html'));
        $el->appendChild($url);
        foreach (['launchParameters', 'entitlementKey'] as $name) {
            if (isset($item[$name])) {
                $child = self::element($doc, $name);
                $child->appendChild($doc->createTextNode((string)$item[$name]));
                $el->appendChild($child);
            }
        }
        $parent->appendChild($el);
    }

    /**
     * Appends the mandatory title and description, each with one en-US langstring.
     *
     * @param \DOMDocument $doc Document.
     * @param \DOMElement $parent Course, block or AU element.
     * @param string $title Title.
     * @param string $description Description.
     */
    private static function texts(\DOMDocument $doc, \DOMElement $parent, string $title, string $description): void {
        foreach (['title' => $title, 'description' => $description] as $name => $text) {
            $el = self::element($doc, $name);
            $lang = self::element($doc, 'langstring');
            $lang->setAttribute('lang', 'en-US');
            $lang->appendChild($doc->createTextNode($text));
            $el->appendChild($lang);
            $parent->appendChild($el);
        }
    }

    /**
     * Creates an element in the cmi5 namespace.
     *
     * @param \DOMDocument $doc Document.
     * @param string $name Local name.
     * @return \DOMElement
     */
    private static function element(\DOMDocument $doc, string $name): \DOMElement {
        return $doc->createElementNS(self::CMI5_NS, $name);
    }
}
