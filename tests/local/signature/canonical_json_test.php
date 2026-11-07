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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\signature\canonical_json::class)]
/**
 * Tests for the canonical JSON form (design §5.2), driven by the vectors shared with the SCORM Maker backend.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\signature\canonical_json
 */
final class canonical_json_test extends \basic_testcase {
    /** @var string SHA-256 of backend/courses/tests/vetores_json_canonico.json in the SCORM Maker repository. */
    private const VECTORS_SHA256 = 'a0ba272c2a01fd0630700935c4bcd48037bb1d6182881b95bc46dfcb88693138';

    /**
     * Path of the copy of the shared vectors.
     *
     * @return string
     */
    private static function vectors_path(): string {
        return \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/vetores_json_canonico.json';
    }

    /**
     * The copy is byte-for-byte the backend file.
     */
    public function test_copy_matches_backend(): void {
        $this->assertSame(
            self::VECTORS_SHA256,
            hash_file('sha256', self::vectors_path()),
            'update the copy together with the backend (backend/courses/tests/vetores_json_canonico.json)'
        );
    }

    /**
     * One dataset per shared vector.
     *
     * @return array
     */
    public static function vectors_provider(): array {
        $vectors = json_decode(file_get_contents(self::vectors_path()), false, 512, JSON_THROW_ON_ERROR);
        $datasets = [];
        foreach ($vectors as $vector) {
            $datasets[$vector->nome] = [$vector->entrada, $vector->saida];
        }
        return $datasets;
    }

    /**
     * Each shared vector encodes to its expected output.
     *
     * @dataProvider vectors_provider
     * @param mixed $input Decoded input.
     * @param string $expected Expected canonical form.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('vectors_provider')]
    public function test_vectors(mixed $input, string $expected): void {
        $this->assertSame($expected, canonical_json::encode($input));
    }

    /**
     * Extra cases that depend on how PHP decodes JSON.
     *
     * @return array
     */
    public static function extra_provider(): array {
        return [
            'numeric key stays a string' => ['{"1":"a","b":"c"}', '{"1":"a","b":"c"}'],
            'numeric keys ordered as bytes' => ['{"10":1,"9":2,"-1":3}', '{"-1":3,"10":1,"9":2}'],
            'empty list and empty object differ' => ['{"a":[],"b":{}}', '{"a":[],"b":{}}'],
            'top-level list' => ['[1,"x",{"b":2,"a":1}]', '[1,"x",{"a":1,"b":2}]'],
            'DEL is not escaped' => ["{\"s\":\"a\u{7f}\"}", "{\"s\":\"a\u{7f}\"}"],
            'escaped slash decodes raw' => ['{"s":"a\/b"}', '{"s":"a/b"}'],
            'control in key is escaped' => ['{"a\u001fb":1}', '{"a\\u001fb":1}'],
            'largest integer' => ['9223372036854775807', '9223372036854775807'],
        ];
    }

    /**
     * Extra cases encode as expected.
     *
     * @dataProvider extra_provider
     * @param string $raw Raw JSON.
     * @param string $expected Expected canonical form.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('extra_provider')]
    public function test_extra(string $raw, string $expected): void {
        $this->assertSame($expected, canonical_json::encode(json_decode($raw, false, 512, JSON_THROW_ON_ERROR)));
    }

    /**
     * Values outside the format.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'float' => ['1.5'],
            'float with zero fraction' => ['{"a":1.0}'],
            'true' => ['true'],
            'false in a list' => ['[false]'],
            'null' => ['{"a":null}'],
            'integer above PHP_INT_MAX' => ['9223372036854775808'],
        ];
    }

    /**
     * Values outside the format are refused.
     *
     * @dataProvider refused_provider
     * @param string $raw Raw JSON.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused_provider')]
    public function test_refused(string $raw): void {
        $this->expectException(\InvalidArgumentException::class);
        canonical_json::encode(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * PHP values that json_decode never produces are refused too.
     */
    public function test_refuses_non_json_values(): void {
        foreach ([['a' => 1], [1 => 'x'], new \DateTime(), "\xff", ["\xc3"], (object)["\xff" => 1]] as $value) {
            try {
                canonical_json::encode($value);
                $this->fail('accepted ' . var_export($value, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
