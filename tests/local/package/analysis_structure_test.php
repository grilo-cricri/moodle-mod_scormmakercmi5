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
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contract.php');

use mod_scormmakercmi5_test_contract as contract;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\analysis_structure::class)]
/**
 * Tests for the analysis structure of a SCORM Maker package (subproject D).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\package\analysis_structure
 */
final class analysis_structure_test extends \advanced_testcase {
    /**
     * The structure of the shared contract.
     *
     * @return array
     */
    private static function contract_structure(): array {
        return contract::data()['casos'][0]['estrutura'];
    }

    /**
     * Path of the copy of the contract.
     *
     * @return string
     */
    private static function contract_path(): string {
        return contract::path();
    }

    /**
     * A case of the contract by its nome.
     *
     * @param string $name Nome.
     * @return array
     */
    private static function case_named(string $name): array {
        return contract::case_named($name);
    }

    /**
     * The structure of the contract is valid; images present in the package stay, the others become null.
     */
    public function test_contract_structure(): void {
        $s = analysis_structure::from_json(json_encode(self::contract_structure()), ['media/m1.jpg', 'index.html']);
        $this->assertNotNull($s);
        $this->assertSame(['v1', 't1', 'm1', 'f1', 'c1', 'x1', 'tv1', 'b9'], $s['licoes'][0]['blocos']);
        $this->assertSame('media/m1.jpg', $s['blocos']['m1']['imagem']);
        $this->assertNull($s['blocos']['tv1']['cenas'][0]['imagem'], 'media/tA.jpg is not in the package');
        $this->assertSame('<img src=x onerror=alert(1)>', $s['blocos']['t1']['itens'][2]['rotulo'], 'kept raw; escaped on output');
        $this->assertSame('=HYPERLINK("https://exemplo.com")', $s['blocos']['b9']['rotulo']);
    }

    /**
     * Invalid structures.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        $ok = self::contract_structure();
        $changes = [
            'version' => ['v', 3],
            'version as string' => ['v', '1'],
            'course id with a slash' => ['courseId', 'c/1'],
            'type' => ['blocos.v1.tipo', 'vi deo'],
            'key differs from id' => ['blocos.v1.id', 'outro'],
            'unknown lesson' => ['blocos.v1.licaoId', 'lx'],
            'unknown block' => ['licoes.0.blocos.8', 'zz'],
            'block listed twice' => ['licoes.0.blocos.8', 'v1'],
            'order' => ['blocos.v1.ordem', -1],
            'order past 2^31-1' => ['blocos.v1.ordem', 2147483648],
            'order not an integer' => ['blocos.v1.ordem', 1.5],
            'order a boolean' => ['blocos.v1.ordem', true],
            'wide item' => ['blocos.t1.itens.0.id', str_repeat('x', 65)],
            'item id with a space' => ['blocos.t1.itens.0.id', 'a 1'],
            'item id with a query' => ['blocos.t1.itens.0.id', 'a?1'],
            'label wider than the exporter writes' => ['blocos.t1.itens.0.rotulo', str_repeat('é', 81)],
            'lesson title too wide' => ['licoes.0.titulo', str_repeat('t', 81)],
            'flag' => ['blocos.v1.midia', 'sim'],
            'items not a list' => ['blocos.t1.itens', ['a' => ['id' => 'a', 'rotulo' => 'a']]],
            'too many items' => ['blocos.t1.itens', array_fill(0, 201, ['id' => 'a', 'rotulo' => 'a'])],
            'too many scenes' => ['blocos.tv1.cenas', array_fill(0, 201, ['id' => 'c', 'titulo' => 'c', 'imagem' => null])],
            'scene without title' => ['blocos.tv1.cenas.0.titulo', null],
            'lessons not a list' => ['licoes', ['x' => ['id' => 'l1', 'titulo' => 'L', 'blocos' => []]]],
        ];
        $cases = [];
        foreach ($changes as $name => [$path, $value]) {
            $s = $ok;
            $ref = &$s;
            foreach (explode('.', $path) as $key) {
                $ref = &$ref[$key];
            }
            $ref = $value;
            unset($ref);
            $cases[$name] = [$s];
        }
        // A block of the structure that no lesson lists.
        $s = $ok;
        array_pop($s['licoes'][0]['blocos']);
        $cases['block not listed'] = [$s];
        // A lesson lists a block of another lesson.
        $s = $ok;
        $s['licoes'][] = ['id' => 'l2', 'titulo' => 'L2', 'blocos' => []];
        $s['blocos']['b9']['licaoId'] = 'l2';
        $cases['block listed by another lesson'] = [$s];
        return $cases;
    }

    /**
     * Any other violation drops the whole structure.
     *
     * @dataProvider invalid_provider
     * @param array $structure Structure.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid(array $structure): void {
        $this->assertNull(analysis_structure::from_json(json_encode($structure), ['media/m1.jpg']));
    }

    /**
     * The limits of the exporter (labels of 80 characters, 200 items and 200 scenes per block) are accepted.
     */
    public function test_exporter_limits(): void {
        $s = self::contract_structure();
        $s['blocos']['t1']['itens'] = array_map(
            static fn(int $i): array => ['id' => '~a' . $i, 'rotulo' => str_repeat('é', 80)],
            range(1, 200)
        );
        $s['blocos']['tv1']['cenas'] = array_map(
            static fn(int $i): array => ['id' => 's' . $i, 'titulo' => str_repeat('t', 80), 'imagem' => 'media/m1.jpg'],
            range(1, 200)
        );
        $out = analysis_structure::from_json(json_encode($s), ['media/m1.jpg']);
        $this->assertNotNull($out);
        $this->assertCount(200, $out['blocos']['t1']['itens']);
        $this->assertSame('media/m1.jpg', $out['blocos']['tv1']['cenas'][199]['imagem']);
    }

    /**
     * The exporter cuts labels with String.slice (UTF-16 units) and JSON.stringify escapes a lone surrogate: it
     * becomes U+FFFD instead of refusing the structure. An escaped backslash before "u" is left alone.
     */
    public function test_lone_surrogate_of_a_cut_label(): void {
        $s = self::contract_structure();
        $s['blocos']['t1']['itens'][0]['rotulo'] = 'SURROGATE';
        $s['blocos']['t1']['itens'][1]['rotulo'] = 'ESCAPED';
        $json = str_replace(
            ['"SURROGATE"', '"ESCAPED"'],
            ['"Aba \ud83d"', '"C:\\\\ud83d \ud83d\ude00 \udc00x"'],
            json_encode($s)
        );
        $out = analysis_structure::from_json($json, []);
        $this->assertNotNull($out);
        $this->assertSame("Aba \u{FFFD}", $out['blocos']['t1']['itens'][0]['rotulo']);
        $this->assertSame("C:\\ud83d \u{1F600} \u{FFFD}x", $out['blocos']['t1']['itens'][1]['rotulo']);
    }

    /**
     * Not JSON, too deep or an image outside media/ (path traversal) are refused or neutralised.
     */
    public function test_untrusted_input(): void {
        $this->assertNull(analysis_structure::from_json('{', []));
        $this->assertNull(analysis_structure::from_json('[]', []));
        $this->assertNull(analysis_structure::from_json("\xC3\x28", []));
        $this->assertNull(analysis_structure::from_json(str_repeat('[', 40) . str_repeat(']', 40), []));
        $s = self::contract_structure();
        $s['blocos']['m1']['imagem'] = '../../config.php';
        $this->assertNull(analysis_structure::from_json(json_encode($s), ['../../config.php'])['blocos']['m1']['imagem']);
        foreach (['https://example.com/m1.jpg', 'media/../cmi5.xml', 'media/sub/m1.jpg', 'javascript:alert(1)', 7] as $bad) {
            $s['blocos']['m1']['imagem'] = $bad;
            $out = analysis_structure::from_json(json_encode($s), [(string)$bad, 'media/m1.jpg']);
            $this->assertNotNull($out, (string)$bad);
            $this->assertNull($out['blocos']['m1']['imagem'], (string)$bad);
            $this->assertSame('Marcadores 1', $out['blocos']['m1']['rotulo'], 'only the image drops');
        }
    }

    /**
     * Unknown keys are left out of the stored structure.
     */
    public function test_unknown_keys_dropped(): void {
        $s = self::contract_structure();
        $s['extra'] = 'x';
        $s['blocos']['v1']['html'] = '<script>';
        $out = analysis_structure::from_json(json_encode($s), []);
        $this->assertArrayNotHasKey('extra', $out);
        $this->assertArrayNotHasKey('html', $out['blocos']['v1']);
    }

    /**
     * from_dir reads the file of the extracted package; absent file or too big gives null.
     */
    public function test_from_dir(): void {
        $dir = make_request_directory();
        $this->assertNull(analysis_structure::from_dir($dir, []));
        mkdir($dir . '/analise');
        file_put_contents($dir . '/analise/estrutura.json', json_encode(self::contract_structure()));
        $this->assertNull(analysis_structure::from_dir($dir, ['media/m1.jpg' => 'y']), 'not a file of the package');
        $json = analysis_structure::from_dir($dir, ['analise/estrutura.json' => 'x', 'media/m1.jpg' => 'y']);
        $this->assertSame('media/m1.jpg', analysis_structure::decode($json)['blocos']['m1']['imagem']);
        $this->assertSame(analysis_structure::from_json($json, ['media/m1.jpg']), analysis_structure::decode($json), 'normalised');
        file_put_contents($dir . '/analise/estrutura.json', str_repeat(' ', analysis_structure::MAX_BYTES + 1));
        $this->assertNull(analysis_structure::from_dir($dir, ['analise/estrutura.json' => 'x']));
    }

    /**
     * Blocks stay a JSON object when stored, even with no block or with numeric ids.
     */
    public function test_stored_blocks_stay_an_object(): void {
        $s = ['v' => 1, 'courseId' => 'c1', 'licoes' => [['id' => 'l1', 'titulo' => 'L', 'blocos' => ['0']]], 'blocos' => [
            '0' => ['id' => '0', 'tipo' => 'text', 'rotulo' => 'T', 'licaoId' => 'l1', 'ordem' => 1, 'itens' => [],
                'imagem' => null, 'cenas' => [], 'midia' => false, 'espacial' => false],
        ]];
        $s['blocos'] = (object)$s['blocos'];
        $encoded = analysis_structure::encode(analysis_structure::from_json(json_encode((object)$s), []));
        $this->assertStringContainsString('"blocos":{"0":', $encoded);
        $empty = analysis_structure::from_json('{"v":1,"courseId":"c1","licoes":[],"blocos":{}}', []);
        $this->assertNotNull($empty);
        $this->assertStringContainsString('"blocos":{}', analysis_structure::encode($empty));
    }

    /**
     * decode() reads what was stored, and nothing else.
     */
    public function test_decode(): void {
        $this->assertNull(analysis_structure::decode(null));
        $this->assertNull(analysis_structure::decode(''));
        $this->assertNull(analysis_structure::decode('"x"'));
        $this->assertSame(['v' => 1], analysis_structure::decode('{"v":1}'));
    }

    /**
     * Limits and ids equal to the contract.
     */
    public function test_limits_of_the_contract(): void {
        $c = json_decode(file_get_contents(self::contract_path()), true, 512, JSON_THROW_ON_ERROR)['limitesDaEstrutura'];
        $this->assertSame($c['filhosPorBloco'], analysis_structure::MAX_CHILDREN);
        $this->assertSame($c['bytes'], analysis_structure::MAX_BYTES);
        foreach ($c['id']['aceitos'] as $id) {
            $this->assertTrue(analysis_structure::valid_id($id), $id);
        }
        foreach ($c['id']['recusados'] as $id) {
            $this->assertFalse(analysis_structure::valid_id($id), json_encode($id));
        }
    }

    /**
     * PF-10: every limit the plugin implements equals the contract (versions, sizes, labels, children, id length).
     */
    public function test_every_limit_equals_the_contract(): void {
        $c = contract::data()['limitesDaEstrutura'];
        $this->assertSame($c['versoes'], analysis_structure::VERSIONS);
        $this->assertSame($c['licoes'], analysis_structure::MAX_LESSONS);
        $this->assertSame($c['blocos'], analysis_structure::MAX_BLOCKS);
        $this->assertSame($c['itens'], analysis_structure::MAX_ITEMS);
        $this->assertSame($c['cenas'], analysis_structure::MAX_SCENES);
        $this->assertSame($c['rotulo'], analysis_structure::MAX_LABEL);
        $this->assertSame($c['filhosPorBloco'], analysis_structure::MAX_CHILDREN);
        $this->assertSame(1, $c['niveisDeAninhamento'], 'one level: a child has no children');
        $this->assertSame($c['profundidade'], analysis_structure::MAX_DEPTH);
        $this->assertSame($c['ordem']['min'], analysis_structure::MIN_ORDER);
        $this->assertSame($c['ordem']['max'], analysis_structure::MAX_ORDER);
        $this->assertFalse(analysis_structure::valid_id(str_repeat('x', $c['id']['min'] - 1)));
        $this->assertTrue(analysis_structure::valid_id(str_repeat('😀', $c['id']['min'])));
        $this->assertTrue(analysis_structure::valid_id(str_repeat('😀', $c['id']['max'])));
        $this->assertFalse(analysis_structure::valid_id(str_repeat('😀', $c['id']['max'] + 1)));
        $this->assertFalse(analysis_structure::valid_id(7), 'an id is text');
    }

    /**
     * Both ends of every forbidden range of the contract are refused; the code point just outside a range is
     * accepted when no other range holds it.
     */
    public function test_forbidden_ranges_of_the_contract(): void {
        $ranges = contract::data()['limitesDaEstrutura']['id']['proibidos'];
        $forbidden = static function (int $cp) use ($ranges): bool {
            foreach ($ranges as [$from, $to]) {
                if ($cp >= $from && $cp <= $to) {
                    return true;
                }
            }
            return false;
        };
        foreach ($ranges as [$from, $to]) {
            foreach ([$from, $to] as $cp) {
                $this->assertFalse(analysis_structure::valid_id('a' . \core_text::code2utf8($cp) . 'b'), "U+{$cp}");
                $this->assertFalse(analysis_structure::valid_id(\core_text::code2utf8($cp)), "U+{$cp} alone");
            }
            foreach ([$from - 1, $to + 1] as $cp) {
                if ($cp >= 0 && !$forbidden($cp)) {
                    $this->assertTrue(analysis_structure::valid_id('a' . \core_text::code2utf8($cp) . 'b'), "U+{$cp}");
                }
            }
        }
    }

    /**
     * The v2 structure of the contract case blocos-aninhados is kept, with pai and filhos.
     */
    public function test_v2_with_nested_blocks(): void {
        $case = self::case_named('blocos-aninhados');
        $json = json_encode($case['estrutura'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $out = analysis_structure::from_json($json, ['media/mp1.jpg']);
        $this->assertSame(2, $out['v']);
        $this->assertSame(['vn1', 'fn1'], $out['blocos']['mp1']['filhos']);
        $this->assertSame('mp1', $out['blocos']['vn1']['pai']);
        $this->assertArrayNotHasKey('pai', $out['blocos']['mp1']);
        $this->assertArrayNotHasKey('filhos', $out['blocos']['vn1']);
        $this->assertSame('media/mp1.jpg', $out['blocos']['mp1']['imagem']);
        $v1 = analysis_structure::from_json(json_encode(self::contract_structure()), []);
        $this->assertSame(1, $v1['v']);
        foreach ($v1['blocos'] as $b) {
            $this->assertArrayNotHasKey('pai', $b, 'a v1 structure is stored as before');
            $this->assertArrayNotHasKey('filhos', $b, 'a v1 structure is stored as before');
        }
    }

    /**
     * A child of a child, a child in another lesson, 51 children and filhos in v1 are refused; 50 children pass
     * (PF-13: the children are real blocks with pai, so only MAX_CHILDREN can refuse the 51).
     */
    public function test_v2_refusals(): void {
        $base = self::case_named('blocos-aninhados')['estrutura'];
        $bad = [];
        $s = $base;
        $s['blocos']['vn1']['filhos'] = ['fn1'];
        $bad[] = $s;
        $s = $base;
        $s['blocos']['vn1']['licaoId'] = 'l9';
        $bad[] = $s;
        $s = $base;
        $s['v'] = 1;
        $bad[] = $s;
        foreach ($bad as $n => $structure) {
            $this->assertNull(analysis_structure::from_json(json_encode($structure), ['media/mp1.jpg']), "case $n");
        }
        $children = static function (int $count) use ($base): array {
            $s = $base;
            $s['blocos']['mp1']['filhos'] = [];
            foreach (range(1, $count) as $i) {
                $s['blocos']["f$i"] = ['id' => "f$i", 'pai' => 'mp1'] + $base['blocos']['vn1'];
                $s['blocos']['mp1']['filhos'][] = "f$i";
            }
            unset($s['blocos']['vn1'], $s['blocos']['fn1']);
            return $s;
        };
        $fifty = analysis_structure::from_json(json_encode($children(50)), []);
        $this->assertNotNull($fifty);
        $this->assertCount(50, $fifty['blocos']['mp1']['filhos']);
        $this->assertNull(analysis_structure::from_json(json_encode($children(51)), []));
    }

    /**
     * Ids made only of digits come back from json_decode as int keys: the link is checked and stored as text.
     */
    public function test_numeric_ids_of_nested_blocks_stay_text(): void {
        $s = self::case_named('blocos-aninhados')['estrutura'];
        $json = str_replace(['"mp1"', '"vn1"', '"fn1"'], ['"7"', '"123"', '"0"'], json_encode($s));
        $out = analysis_structure::from_json($json, []);
        $this->assertNotNull($out);
        $this->assertSame(['123', '0'], $out['blocos'][7]['filhos']);
        $this->assertSame('7', $out['blocos'][123]['pai']);
        $this->assertSame('7', $out['blocos'][0]['pai']);
        $this->assertStringContainsString('"blocos":{"7":', analysis_structure::encode($out));
    }

    /**
     * Every structure example of the contract (limitesDaEstrutura.estruturas), generated ones included.
     *
     * @return array
     */
    public static function structure_example_provider(): array {
        $out = [];
        foreach (contract::data()['limitesDaEstrutura']['estruturas'] as $kind => $examples) {
            foreach ($examples as $n => $example) {
                $out[$kind . ' ' . $n . ': ' . ($example['nome'] ?? $example['motivo'])] = [$kind, $n];
            }
        }
        return $out;
    }

    /**
     * Accepted examples are kept (equal to esperada when it is given), refused ones give null. The file is measured
     * by the bytes rule, so a generated example measures exactly the bytes it declares.
     *
     * @dataProvider structure_example_provider
     * @param string $kind aceitas or recusadas.
     * @param int $n Index of the example.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('structure_example_provider')]
    public function test_structure_examples_of_the_contract(string $kind, int $n): void {
        $example = contract::data()['limitesDaEstrutura']['estruturas'][$kind][$n];
        // Raw JSON text: an assoc round-trip would erase the {} / [] difference the shape examples test.
        $json = contract::example_json($kind, $n);
        if (array_key_exists('gerar', $example) && array_key_exists('bytes', $example['gerar'])) {
            $this->assertSame($example['gerar']['bytes'], strlen($json), 'the expansion measures the declared bytes');
        }
        $out = analysis_structure::from_json($json, []);
        if ($kind === 'recusadas') {
            $this->assertNull($out);
            return;
        }
        $this->assertNotNull($out);
        if (array_key_exists('esperada', $example)) {
            $this->assertSame($example['esperada'], $out);
        }
    }

    /**
     * A label or title with U+0000 is kept with U+FFFD in its place; an id with it is refused.
     */
    public function test_nul_in_labels_becomes_replacement_character(): void {
        $s = self::contract_structure();
        $s['licoes'][0]['titulo'] = "L\u{0}";
        $s['blocos']['t1']['rotulo'] = "\u{0}A";
        $s['blocos']['t1']['itens'][0]['rotulo'] = "a\u{0}";
        $s['blocos']['tv1']['cenas'][0]['titulo'] = "S\u{0}\u{0}";
        $out = analysis_structure::from_json(json_encode($s), []);
        $this->assertSame("L\u{FFFD}", $out['licoes'][0]['titulo']);
        $this->assertSame("\u{FFFD}A", $out['blocos']['t1']['rotulo']);
        $this->assertSame("a\u{FFFD}", $out['blocos']['t1']['itens'][0]['rotulo']);
        $this->assertSame("S\u{FFFD}\u{FFFD}", $out['blocos']['tv1']['cenas'][0]['titulo']);
        $s['blocos']['t1']['itens'][0]['id'] = "a\u{0}";
        $this->assertNull(analysis_structure::from_json(json_encode($s), []));
    }

    /**
     * The JSON is measured as received: one byte over MAX_BYTES is refused.
     */
    public function test_bytes_as_received(): void {
        $json = contract::encode(self::contract_structure());
        $padding = analysis_structure::MAX_BYTES - strlen($json);
        $this->assertNotNull(analysis_structure::from_json($json . str_repeat(' ', $padding), []));
        $this->assertNull(analysis_structure::from_json($json . str_repeat(' ', $padding + 1), []));
    }

    /**
     * Blocks of a lesson in report order: each top-level block, then its children in the order of filhos.
     */
    public function test_lesson_blocks_in_order(): void {
        $s = analysis_structure::from_json(contract::encode(self::case_named('aninhados-fora-de-ordem')['estrutura']), []);
        $this->assertSame([['tv2', null], ['tx2', 'tv2']], analysis_structure::lesson_blocks($s, $s['licoes'][0]));
        $this->assertSame(
            [['mp2', null], ['vv2', 'mp2'], ['fv2', 'mp2'], ['x2', null]],
            analysis_structure::lesson_blocks($s, $s['licoes'][1])
        );
    }

    /**
     * Every example of the contract runs: 16 accepted and 52 rejected (the provider reads them all, so a new example
     * runs without a test change; this pins that none is lost).
     */
    public function test_example_counts(): void {
        $e = contract::data()['limitesDaEstrutura']['estruturas'];
        $this->assertCount(16, $e['aceitas']);
        $this->assertCount(52, $e['recusadas']);
        $this->assertCount(68, self::structure_example_provider());
    }

    /**
     * A list is a JSON array and a map a JSON object, never swapped, even when empty (contract regra).
     *
     * @return array
     */
    public static function shape_provider(): array {
        $json = contract::encode(contract::case_named('blocos-aninhados')['estrutura']);
        return [
            'filhos {}' => [str_replace('"filhos":["vn1","fn1"]', '"filhos":{}', $json), false],
            'filhos object with list keys' => [
                str_replace('"filhos":["vn1","fn1"]', '"filhos":{"0":"vn1","1":"fn1"}', $json),
                false,
            ],
            'itens {}' => [str_replace('"itens":[{"id":"pin1","rotulo":"Pino 1"}]', '"itens":{}', $json), false],
            'licoes[].blocos object' => [str_replace('"blocos":["mp1"]', '"blocos":{"0":"mp1"}', $json), false],
            'empty structure' => ['{"v":1,"courseId":"c1","licoes":[],"blocos":{}}', true],
            'licoes {}' => ['{"v":1,"courseId":"c1","licoes":{},"blocos":{}}', false],
            'blocos []' => ['{"v":1,"courseId":"c1","licoes":[],"blocos":[]}', false],
            'root a list' => ['[1]', false],
            'item a list' => [str_replace('[{"id":"pin1","rotulo":"Pino 1"}]', '[["pin1","Pino 1"]]', $json), false],
        ];
    }

    /**
     * Shapes are told apart on the raw JSON.
     *
     * @dataProvider shape_provider
     * @param string $json Structure.
     * @param bool $valid Whether it is accepted.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shape_provider')]
    public function test_shapes(string $json, bool $valid): void {
        $this->assertSame($valid, analysis_structure::from_json($json, []) !== null);
    }

    /**
     * Depth: 16 levels counting the root as 1; an unknown key counts too (and is then dropped).
     */
    public function test_depth(): void {
        $base = substr(contract::encode(self::contract_structure()), 0, -1);
        $nest = static fn(int $levels): string => str_repeat('[', $levels) . str_repeat(']', $levels);
        $ok = analysis_structure::from_json($base . ',"x":' . $nest(15) . '}', []);
        $this->assertNotNull($ok, '16 levels');
        $this->assertArrayNotHasKey('x', $ok);
        $this->assertNull(analysis_structure::from_json($base . ',"x":' . $nest(16) . '}', []), '17 levels');
    }
}
