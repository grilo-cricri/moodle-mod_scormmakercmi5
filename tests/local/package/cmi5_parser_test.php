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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\cmi5_parser::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\course_structure::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\au_definition::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\block_definition::class)]
/**
 * Tests for the cmi5.xml validation (design §4.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\package\cmi5_parser
 * @covers     \mod_scormmakercmi5\local\package\course_structure
 * @covers     \mod_scormmakercmi5\local\package\au_definition
 * @covers     \mod_scormmakercmi5\local\package\block_definition
 */
final class cmi5_parser_test extends \advanced_testcase {
    /** @var string The cmi5 namespace. */
    private const NS = builder::CMI5_NS;

    /**
     * Parses and returns the moodle_exception thrown.
     *
     * @param string $xml Document.
     * @param array $files Zip file list.
     * @return \moodle_exception
     */
    private function refused(string $xml, array $files = ['cmi5.xml', 'index.html']): \moodle_exception {
        try {
            cmi5_parser::parse($xml, $files);
        } catch (\moodle_exception $e) {
            $this->assertSame('mod_scormmakercmi5', $e->module);
            return $e;
        }
        $this->fail('The document was accepted.');
    }

    /**
     * A course with objectives, given as raw XML around a list of AU bodies.
     *
     * @param string $objectives Content of the course objectives element ('' = none).
     * @param string $au Complete au element.
     * @return string
     */
    private function raw(string $objectives, string $au): string {
        $obj = $objectives === '' ? '' : '<objectives>' . $objectives . '</objectives>';
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<courseStructure xmlns="' . self::NS . '">' . "\n"
            . '<course id="https://example.com/course"><title><langstring lang="en-US">C</langstring></title>'
            . '<description><langstring lang="en-US">C</langstring></description></course>' . "\n"
            . $obj . "\n" . $au . "\n</courseStructure>";
    }

    /**
     * Returns an objective definition.
     *
     * @param string $id Objective id.
     * @return string
     */
    private function objective(string $id): string {
        return '<objective id="' . $id . '"><title><langstring>O</langstring></title>'
            . '<description><langstring>O</langstring></description></objective>';
    }

    public function test_minimal_au(): void {
        $course = cmi5_parser::parse(builder::cmi5_xml(), ['cmi5.xml', 'index.html']);
        $this->assertSame('https://example.com/course', $course->publisherid);
        $this->assertSame(['en-US' => 'Course'], $course->title);
        $this->assertSame(['en-US' => 'Course'], $course->description);
        $this->assertCount(1, $course->children);
        $au = $course->children[0];
        $this->assertInstanceOf(au_definition::class, $au);
        $this->assertSame('https://example.com/au/1', $au->publisherid);
        $this->assertSame('NotApplicable', $au->moveon);
        $this->assertSame('AnyWindow', $au->launchmethod);
        $this->assertNull($au->masteryscore);
        $this->assertNull($au->launchparameters);
        $this->assertNull($au->entitlementkey);
        $this->assertNull($au->activitytype);
        $this->assertSame('index.html', $au->url);
        $this->assertSame('index.html', $au->urlpath);
        $this->assertSame(1, $au->depth);
        $this->assertSame([], $course->all_blocks());
    }

    public function test_nested_blocks_in_document_order(): void {
        $xml = builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/au/1', 'url' => 'index.html'],
            ['id' => 'https://example.com/block/1', 'children' => [
                ['id' => 'https://example.com/au/2', 'url' => 'index.html'],
                ['id' => 'https://example.com/block/2', 'children' => [
                    ['id' => 'https://example.com/au/3', 'url' => 'index.html'],
                ]],
            ]],
        ]]);
        $course = cmi5_parser::parse($xml, ['index.html']);
        $ids = array_map(fn($au) => $au->publisherid, $course->all_aus());
        $this->assertSame(['https://example.com/au/1', 'https://example.com/au/2', 'https://example.com/au/3'], $ids);
        $blocks = $course->all_blocks();
        $blockids = array_map(fn($b) => $b->publisherid, $blocks);
        $this->assertSame(['https://example.com/block/1', 'https://example.com/block/2'], $blockids);
        $this->assertInstanceOf(block_definition::class, $course->children[1]);
        $this->assertSame(1, $blocks[0]->depth);
        $this->assertSame(2, $blocks[1]->depth);
        $this->assertSame(2, $course->all_aus()[1]->depth);
        $this->assertSame(3, $course->all_aus()[2]->depth);
        $this->assertSame($blocks[1], $blocks[0]->children[1]);
    }

    public function test_all_au_fields(): void {
        $xml = builder::cmi5_xml([[
            'id' => 'https://example.com/au/1', 'url' => 'index.html', 'moveOn' => 'Passed',
            'masteryScore' => '0.8', 'launchMethod' => 'OwnWindow', 'launchParameters' => '{"a":1}',
            'entitlementKey' => 'key-1', 'activityType' => 'https://example.com/type',
        ]]);
        $au = cmi5_parser::parse($xml, ['index.html'])->all_aus()[0];
        $this->assertSame('Passed', $au->moveon);
        $this->assertSame(0.8, $au->masteryscore);
        $this->assertSame('OwnWindow', $au->launchmethod);
        $this->assertSame('{"a":1}', $au->launchparameters);
        $this->assertSame('key-1', $au->entitlementkey);
        $this->assertSame('https://example.com/type', $au->activitytype);
    }

    public function test_other_vocabulary_values(): void {
        foreach (['Completed', 'CompletedAndPassed', 'CompletedOrPassed', 'NotApplicable'] as $moveon) {
            $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'moveOn' => $moveon, 'masteryScore' => '1']]);
            $au = cmi5_parser::parse($xml, ['index.html'])->all_aus()[0];
            $this->assertSame($moveon, $au->moveon);
            $this->assertSame(1.0, $au->masteryscore);
        }
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'moveOn' => 'Sometimes']]);
        $this->assertSame('err_xmlinvalid', $this->refused($xml)->errorcode);
    }

    public function test_whitespace_is_trimmed(): void {
        $xml = builder::cmi5_xml([[
            'id' => '  https://example.com/au/1 ', 'url' => '  index.html ', 'moveOn' => ' Completed ',
            'launchMethod' => ' OwnWindow', 'title' => "  Hi\n", 'launchParameters' => ' p ',
        ]], ['id' => ' https://example.com/course ']);
        $course = cmi5_parser::parse($xml, ['index.html']);
        $au = $course->all_aus()[0];
        $this->assertSame('https://example.com/course', $course->publisherid);
        $this->assertSame('https://example.com/au/1', $au->publisherid);
        $this->assertSame('index.html', $au->url);
        $this->assertSame('index.html', $au->urlpath);
        $this->assertSame('Completed', $au->moveon);
        $this->assertSame('OwnWindow', $au->launchmethod);
        $this->assertSame(['en-US' => 'Hi'], $au->title);
        $this->assertSame('p', $au->launchparameters);
    }

    public function test_doctype_refused(): void {
        $xml = '<?xml version="1.0"?>' . "\n" . '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' . "\n"
            . '<courseStructure xmlns="' . self::NS . '"><course id="https://e/&e;"/></courseStructure>';
        $e = $this->refused($xml);
        $this->assertSame('err_doctype', $e->errorcode);
        $this->assertStringNotContainsString('passwd', $e->getMessage());
        $this->assertStringNotContainsString('passwd', (string)$e->a);
        $this->assertStringNotContainsString('passwd', (string)$e->debuginfo);
    }

    public function test_doctype_refused_in_utf16(): void {
        // The byte scan cannot see the declaration; the check after loading does.
        $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
            . '<courseStructure xmlns="' . self::NS . '"><course id="https://e/"/></courseStructure>';
        $utf16 = "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
        $this->assertSame('err_doctype', $this->refused($utf16)->errorcode);
        // An entity used in content is never expanded: the document is refused either way.
        $xml = str_replace('<course id="https://e/"/>', '<course id="https://e/">&e;</course>', $xml);
        $e = $this->refused("\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
        $this->assertContains($e->errorcode, ['err_doctype', 'err_xmlinvalid']);
        $this->assertStringNotContainsString('root:', $e->getMessage());
    }

    public function test_malformed_xml(): void {
        $e = $this->refused("<?xml version=\"1.0\"?>\n<courseStructure xmlns=\"" . self::NS . "\">\n<course>");
        $this->assertSame('err_xmlinvalid', $e->errorcode);
        $this->assertGreaterThan(0, $e->a->line);
        $this->assertSame('err_xmlinvalid', $this->refused('')->errorcode);
    }

    public function test_au_without_url(): void {
        $xml = preg_replace('~<url>[^<]*</url>~', '', builder::cmi5_xml());
        $e = $this->refused($xml);
        $this->assertSame('err_xmlinvalid', $e->errorcode);
        $this->assertGreaterThan(0, $e->a->line);
        $this->assertNotSame('', $e->a->message);
        // The namespace is shortened out of the libxml message.
        $this->assertStringNotContainsString('{' . self::NS . '}', $e->a->message);
    }

    public function test_wrong_namespace(): void {
        $xml = str_replace(self::NS, 'https://example.com/other', builder::cmi5_xml());
        $this->assertSame('err_xmlinvalid', $this->refused($xml)->errorcode);
    }

    public function test_size_limit(): void {
        $xml = builder::cmi5_xml() . '<!--' . str_repeat('x', cmi5_parser::MAX_BYTES) . '-->';
        $this->assertSame('err_xmlsize', $this->refused($xml)->errorcode);
    }

    public function test_iri_without_scheme(): void {
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1']], ['id' => 'abc']));
        $this->assertSame('err_iri', $e->errorcode);
        $this->assertSame('course', $e->a);
        $e = $this->refused(builder::cmi5_xml([['id' => 'au-1']]));
        $this->assertSame('err_iri', $e->errorcode);
        $this->assertSame('au', $e->a);
        $e = $this->refused(builder::cmi5_xml([], ['blocks' => [['id' => 'block-1', 'children' => [[]]]]]));
        $this->assertSame('err_iri', $e->errorcode);
        $this->assertSame('block', $e->a);
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/a b']]));
        $this->assertSame('err_iri', $e->errorcode);
        $e = $this->refused($this->raw($this->objective('obj1'), '<au id="https://example.com/au/1">'
            . '<title><langstring>A</langstring></title><description><langstring>A</langstring></description>'
            . '<url>index.html</url></au>'));
        $this->assertSame('err_iri', $e->errorcode);
        $this->assertSame('objective', $e->a);
    }

    public function test_duplicate_ids(): void {
        $e = $this->refused(builder::cmi5_xml([
            ['id' => 'https://example.com/au/1'], ['id' => ' https://example.com/au/1'],
        ]));
        $this->assertSame('err_duplicateid', $e->errorcode);
        $this->assertSame('https://example.com/au/1', $e->a);
        $e = $this->refused(builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/x', 'children' => [['id' => 'https://example.com/x']]],
        ]]));
        $this->assertSame('err_duplicateid', $e->errorcode);
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/course']]));
        $this->assertSame('err_duplicateid', $e->errorcode);
    }

    public function test_objective_references(): void {
        $au = fn(string $refs) => '<au id="https://example.com/au/1"><title><langstring>A</langstring></title>'
            . '<description><langstring>A</langstring></description><objectives>' . $refs . '</objectives>'
            . '<url>index.html</url></au>';
        $definition = $this->objective('https://example.com/obj/1');
        $ok = $this->raw($definition, $au('<objective idref=" https://example.com/obj/1 "/>'));
        $this->assertCount(1, cmi5_parser::parse($ok, ['index.html'])->all_aus());
        $e = $this->refused($this->raw($definition, $au('<objective idref="https://example.com/obj/2"/>')));
        $this->assertSame('err_objectiveref', $e->errorcode);
        $this->assertSame('https://example.com/obj/2', $e->a);
        $this->assertSame('err_objectiveref', $this->refused($this->raw('', $au('<objective/>')))->errorcode);
        $e = $this->refused($this->raw($this->objective('https://example.com/obj/1')
            . $this->objective('https://example.com/obj/1'), $au('<objective idref="https://example.com/obj/1"/>')));
        $this->assertSame('err_duplicateid', $e->errorcode);
    }

    /**
     * An https AU outside the package is accepted with its origin; other absolute forms are refused.
     */
    public function test_absolute_au_url(): void {
        $origin = cmi5_parser::external_origin('https://cursos.exemplo.com/au/index.html?x=1#y');
        $this->assertSame('https://cursos.exemplo.com', $origin);
        $this->assertSame('https://exemplo.com:8443', cmi5_parser::external_origin('https://exemplo.com:8443/a'));
        $this->assertNull(cmi5_parser::external_origin('index.html'));
        $refused = ['http://exemplo.com/a', '//exemplo.com/a', 'https://user@exemplo.com/a', 'javascript:alert(1)',
            'https:///semhost', 'https://exemplo.com/' . str_repeat('a', 2050)];
        foreach ($refused as $bad) {
            try {
                cmi5_parser::external_origin($bad);
                $this->fail($bad);
            } catch (\moodle_exception $e) {
                $this->assertSame('err_auabsoluteurl', $e->errorcode, $bad);
            }
        }
        try {
            cmi5_parser::external_origin('https://exemplo.com/a?endpoint=x');
            $this->fail('endpoint');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_aureservedparam', $e->errorcode);
            $this->assertSame('endpoint', $e->a);
        }
    }

    /**
     * The origin is the one a browser sends: scheme and host in lower case, the default port left out; a host or a
     * character a browser would read differently from parse_url() is refused.
     */
    public function test_absolute_au_url_origin_form(): void {
        $this->assertSame('https://cursos.exemplo.com', cmi5_parser::external_origin('HTTPS://Cursos.Exemplo.COM/a'));
        $this->assertSame('https://exemplo.com', cmi5_parser::external_origin('https://exemplo.com:443/a'));
        $this->assertSame('https://exemplo.com', cmi5_parser::external_origin('https://exemplo.com'));
        $this->assertSame('https://xn--exmplo-bva.com', cmi5_parser::external_origin('https://xn--exmplo-bva.com/a'));
        $this->assertSame('https://10.0.0.1:9000', cmi5_parser::external_origin('https://10.0.0.1:9000/a'));
        $this->assertSame('https://[::1]:8443', cmi5_parser::external_origin('https://[::1]:8443/a'));
        $this->assertSame('https://exemplo.com', cmi5_parser::external_origin('https://exemplo.com/a@b/c?d=e@f'));
        $refused = [
            'data:text/html,<p>x</p>', 'vbscript:x', 'ftp://exemplo.com/a', 'https:exemplo.com/a', 'https:/exemplo.com/a',
            'https://user:senha@exemplo.com/a', 'https://:senha@exemplo.com/a', 'https://exemplo.com:0/a',
            'https://exemplo.com:99999/a', 'https://exämplo.com/a', 'https://exemplo.com\\@outro.com/a',
            'https://exemplo.com\\.outro.com/a', 'https://exemplo.com/a b', "https://exemplo.com/a\tb", "https://exemplo.com/a\nb",
            'https://%65xemplo.com/a', 'https://exemplo.com/a"b', 'https://exemplo.com/a<b>', 'https://exe_mplo.com/a',
            'https://exemplo.com/á',
        ];
        foreach ($refused as $bad) {
            try {
                cmi5_parser::external_origin($bad);
                $this->fail($bad);
            } catch (\moodle_exception $e) {
                $this->assertSame('err_auabsoluteurl', $e->errorcode, $bad);
            }
        }
    }

    /**
     * The origin fits auorigin (255 characters): a longer host is refused with its own message (L-1 of the Task 26
     * review). A host a browser rewrites (one number, hexadecimal or octal parts, fewer or more than four parts, an
     * empty label, an IPv6 address not in its short form) would never match the Origin header: refused (L-2). A plain
     * IPv4 address and a short IPv6 address are kept.
     */
    public function test_absolute_au_url_host_rules(): void {
        $label = str_repeat('a', 61) . '.';
        $host247 = str_repeat($label, 3) . str_repeat('b', 247 - 3 * 62);
        $this->assertSame(247, strlen($host247));
        $this->assertSame('https://' . $host247, cmi5_parser::external_origin('https://' . $host247 . '/a'));
        $this->assertSame(255, strlen(cmi5_parser::external_origin('https://' . $host247 . '/a')));
        $host241 = substr($host247, 0, 241);
        $this->assertSame('https://' . $host241 . ':8443', cmi5_parser::external_origin('https://' . $host241 . ':8443/a'));
        $toolong = ['https://' . $host247 . 'c/a', 'https://' . $host241 . 'cc:8443/a', 'https://' . str_repeat($label, 5) . 'x/a'];
        foreach ($toolong as $long) {
            try {
                cmi5_parser::external_origin($long);
                $this->fail(strlen($long) . ' bytes');
            } catch (\moodle_exception $e) {
                $this->assertSame('err_auhosttoolong', $e->errorcode);
            }
        }

        $kept = [
            'https://192.0.2.10/a' => 'https://192.0.2.10', 'https://0.0.0.0/a' => 'https://0.0.0.0',
            'https://255.255.255.255:8443/a' => 'https://255.255.255.255:8443', 'https://[::1]/a' => 'https://[::1]',
            'https://[2001:db8::1]/a' => 'https://[2001:db8::1]', 'https://[2001:DB8::1]/a' => 'https://[2001:db8::1]',
            'https://[1:0:0:2::3]/a' => 'https://[1:0:0:2::3]', 'https://[::]/a' => 'https://[::]',
            'https://[1:2:3:4:5:6:7:8]/a' => 'https://[1:2:3:4:5:6:7:8]',
            'https://123a.exemplo.com/a' => 'https://123a.exemplo.com',
            'https://exemplo.123a/a' => 'https://exemplo.123a', 'https://exemplo.com./a' => 'https://exemplo.com.',
            'https://exemplo.0x1g/a' => 'https://exemplo.0x1g',
        ];
        foreach ($kept as $url => $origin) {
            $this->assertSame($origin, cmi5_parser::external_origin($url), $url);
        }
        $rewritten = ['https://0x7f.1/a', 'https://2130706433/a', 'https://127.1/a', 'https://0177.0.0.1/a', 'https://127.0.0.01/a',
            'https://256.0.0.1/a', 'https://1.2.3.4.5/a', 'https://127.0.0.1./a', 'https://exemplo.0x1/a', 'https://exemplo.123/a',
            'https://exemplo.0x/a', 'https://0X7F000001/a', 'https://a..b/a', 'https://[::ffff:127.0.0.1]/a',
            'https://[0:0:0:0:0:0:0:1]/a', 'https://[::01]/a', 'https://[1::2:0:0:0:3]/a', 'https://[1:0::2]/a',
            'https://[::1:2:3:4:5:6:7]/a', 'https://[1:2]/a', 'https://[:::1]/a'];
        foreach ($rewritten as $bad) {
            try {
                cmi5_parser::external_origin($bad);
                $this->fail($bad);
            } catch (\moodle_exception $e) {
                $this->assertSame('err_auhost', $e->errorcode, $bad);
            }
        }
    }

    /**
     * In cmi5.xml: the AU outside the package has no path in it and carries its origin; its query follows the rules
     * of a relative one (the launcher passes it on unchanged); http and "//host" are refused with the new text.
     */
    public function test_absolute_url_in_cmi5xml(): void {
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html?x=1#y']]);
        $au = cmi5_parser::parse($xml, ['cmi5.xml'])->all_aus()[0];
        $this->assertSame('https://cursos.exemplo.com/au/index.html?x=1#y', $au->url);
        $this->assertSame('', $au->urlpath);
        $this->assertSame('https://cursos.exemplo.com', $au->origin);
        $this->assertNull(cmi5_parser::parse(builder::cmi5_xml(), ['cmi5.xml', 'index.html'])->all_aus()[0]->origin);

        $auid = 'https://example.com/au/1';
        $e = $this->refused(builder::cmi5_xml([['id' => $auid, 'url' => 'https://exemplo.com/a?k=1&k=2']]));
        $this->assertSame('err_auquery', $e->errorcode);
        $e = $this->refused(builder::cmi5_xml([['id' => $auid, 'url' => 'https://exemplo.com/a?x=1&fetch=2']]));
        $this->assertSame('err_aureservedparam', $e->errorcode);
        foreach (['HTTP://x.example/au', '//x.example/au'] as $url) {
            $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => $url]]));
            $this->assertSame('err_auabsoluteurl', $e->errorcode, $url);
        }
        $this->assertSame('The URL of an AU must be a path inside the package or an https address (https only), with no user '
            . 'name or password; an international domain name goes in its xn-- form.', $e->getMessage());
        // The pt_br pack is not installed on the test site, so the plugin file is read directly.
        $string = [];
        include(\core_component::get_component_directory('mod_scormmakercmi5') . '/lang/pt_br/scormmakercmi5.php');
        $this->assertSame('A URL de um AU deve ser um caminho dentro do pacote ou um endereço https (só https), sem usuário '
            . 'nem senha; um domínio internacional vai na forma xn--.', $string['err_auabsoluteurl']);
    }

    public function test_missing_au_file(): void {
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'falta.html']]));
        $this->assertSame('err_aufilemissing', $e->errorcode);
        $this->assertSame('falta.html', $e->a);
        $this->assertStringContainsString('falta.html', $e->getMessage());
        // Case matters: the zip listing is compared byte by byte.
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'INDEX.html']]));
        $this->assertSame('err_aufilemissing', $e->errorcode);
        // A path that climbs out of the package never matches a file.
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => '../index.html']]));
        $this->assertSame('err_aufilemissing', $e->errorcode);
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => '?a=1']]));
        $this->assertSame('err_aufilemissing', $e->errorcode);
        // Markup in the name is neutralised in the message.
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'a%3Cb%3E.html']]));
        $this->assertSame('a?b?.html', $e->a);
    }

    public function test_reserved_query_parameters(): void {
        foreach (['endpoint', 'fetch', 'actor', 'registration', 'activityId'] as $name) {
            $url = 'index.html?x=1&' . $name . '=x';
            $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => $url]]));
            $this->assertSame('err_aureservedparam', $e->errorcode, $name);
            $this->assertSame($name, $e->a);
        }
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'index.html?endpoint']]));
        $this->assertSame('err_aureservedparam', $e->errorcode);
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'index.html?endpoints=1&myfetch=2']]);
        $this->assertCount(1, cmi5_parser::parse($xml, ['index.html'])->all_aus());
    }

    /**
     * A query the launcher could not pass on unchanged is refused: ";", empty, digit-only or duplicate names.
     */
    public function test_query_names_the_launcher_cannot_keep(): void {
        $refused = [
            'index.html?a=1;b=2' => ';',
            'index.html?0=x&k=1' => '0',
            'index.html?k=1&k=2' => 'k',
            'index.html?k=1&%6B=2' => 'k',
            'index.html?=x' => '=x',
        ];
        foreach ($refused as $url => $detail) {
            $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => $url]]));
            $this->assertSame('err_auquery', $e->errorcode, $url);
            $this->assertSame($detail, $e->a, $url);
        }
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'index.html?a=1&&b=%3B&k1=x#f;g']]);
        $this->assertCount(1, cmi5_parser::parse($xml, ['index.html'])->all_aus());
    }

    public function test_query_and_fragment_kept_out_of_path(): void {
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'index.html?a=1#f']]);
        $au = cmi5_parser::parse($xml, ['index.html'])->all_aus()[0];
        $this->assertSame('index.html', $au->urlpath);
        $this->assertStringContainsString('?a=1', $au->url);
        $this->assertSame('index.html?a=1#f', $au->url);
    }

    public function test_encoded_path(): void {
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'my%20page.html']]);
        $au = cmi5_parser::parse($xml, ['cmi5.xml', 'my page.html'])->all_aus()[0];
        $this->assertSame('my page.html', $au->urlpath);
        $this->assertSame('my%20page.html', $au->url);
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => './a/./b/../c%C3%A9.html']]);
        $au = cmi5_parser::parse($xml, ['a/cé.html'])->all_aus()[0];
        $this->assertSame('a/cé.html', $au->urlpath);
    }

    public function test_mastery_score_precision(): void {
        $e = $this->refused(builder::cmi5_xml([['id' => 'https://example.com/au/1', 'masteryScore' => '0.12345']]));
        $this->assertSame('err_masteryscore', $e->errorcode);
        $this->assertSame('0.12345', $e->a);
        foreach (['0.1234' => 0.1234, '0.80000' => 0.8, '0' => 0.0, '.5' => 0.5] as $value => $expected) {
            $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'masteryScore' => $value]]);
            $this->assertSame($expected, cmi5_parser::parse($xml, ['index.html'])->all_aus()[0]->masteryscore, $value);
        }
        $xml = builder::cmi5_xml([['id' => 'https://example.com/au/1', 'masteryScore' => '1.5']]);
        $this->assertSame('err_xmlinvalid', $this->refused($xml)->errorcode);
    }

    public function test_pick_language(): void {
        $two = '<langstring lang="en-US">Hello</langstring><langstring lang="pt-BR">Olá</langstring>';
        $xml = str_replace('<langstring lang="en-US">Course</langstring>', $two, builder::cmi5_xml());
        $course = cmi5_parser::parse($xml, ['index.html'], 'pt_br');
        $this->assertSame(['en-US' => 'Hello', 'pt-BR' => 'Olá'], $course->title);
        $this->assertSame('Olá', course_structure::pick($course->title, 'pt_br'));
        $this->assertSame('Hello', course_structure::pick($course->title, 'fr'));
        $this->assertSame('Hello', course_structure::pick($course->title, 'en'));
        $this->assertSame('Olá', course_structure::pick($course->title, 'pt'));
        $this->assertSame('Olá', course_structure::pick(['EN' => 'x', 'pt-br' => 'Olá'], 'pt_br'));
        $this->assertSame('', course_structure::pick([], 'pt_br'));
    }

    public function test_langstring_without_lang_and_duplicates(): void {
        $three = '<langstring>Plain</langstring><langstring lang="en-US">A</langstring><langstring lang="en-US">B</langstring>';
        $xml = str_replace('<langstring lang="en-US">Course</langstring>', $three, builder::cmi5_xml());
        $course = cmi5_parser::parse($xml, ['index.html']);
        $this->assertSame(['' => 'Plain', 'en-US' => 'A'], $course->title);
        $this->assertSame('Plain', course_structure::pick($course->title, 'de'));
    }

    public function test_foreign_extensions_are_ignored(): void {
        $ext = '<x:ext xmlns:x="https://example.com/ext" x:a="1"><x:y/></x:ext>';
        $xml = str_replace('<url>', $ext . '<url>', builder::cmi5_xml());
        $this->assertSame('err_xmlinvalid', $this->refused($xml)->errorcode, 'extension before url is out of sequence');
        $xml = str_replace('</au>', '<x:ext xmlns:x="https://example.com/ext" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            . 'xsi:schemaLocation="https://example.com/ext http://127.0.0.1:9/ext.xsd"/></au>', builder::cmi5_xml());
        $this->assertCount(1, cmi5_parser::parse($xml, ['index.html'])->all_aus());
    }

    public function test_entity_loader_restored(): void {
        cmi5_parser::parse(builder::cmi5_xml(), ['index.html']);
        $path = make_request_directory() . '/a.xml';
        file_put_contents($path, '<a/>');
        $doc = new \DOMDocument();
        $this->assertTrue($doc->load($path));
    }

    public function test_one_thousand_aus(): void {
        $aus = [];
        for ($i = 1; $i <= 1000; $i++) {
            $aus[] = ['id' => 'https://example.com/au/' . $i, 'url' => 'au' . $i . '/index.html'];
        }
        $files = array_map(fn($au) => $au['url'], $aus);
        $xml = builder::cmi5_xml($aus);
        $start = microtime(true);
        $course = cmi5_parser::parse($xml, $files);
        $this->assertCount(1000, $course->all_aus());
        $this->assertLessThan(5, microtime(true) - $start);
    }
}
