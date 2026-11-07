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
 * Reads the contract shared with SCORM Maker (tests/fixtures/contrato-dados-ricos.json).
 *
 * Load with require_once(core_component::get_component_directory('mod_scormmakercmi5')
 * . '/tests/fixtures/contract.php').
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The contract, its cases by name and the expansion of its generated structures.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mod_scormmakercmi5_test_contract {
    /**
     * Flags of the bytes rule of limitesDaEstrutura: the size of the front's JSON.stringify.
     */
    public const BYTES_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;

    /**
     * Path of the copy of the contract.
     *
     * @return string
     */
    public static function path(): string {
        return core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contrato-dados-ricos.json';
    }

    /**
     * The whole contract.
     *
     * @return array
     */
    public static function data(): array {
        return json_decode(file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A case of the contract by its nome.
     *
     * @param string $name Nome of the case.
     * @return array
     */
    public static function case_named(string $name): array {
        foreach (self::data()['casos'] as $case) {
            if ($case['nome'] === $name) {
                return $case;
            }
        }
        throw new coding_exception('no contract case ' . $name);
    }

    /**
     * The JSON text of an example of limitesDaEstrutura.estruturas as the file of a package would carry it: the
     * contract decoded without assoc (an object stays a stdClass, so {} and [] stay distinct, and {"0": ...} stays an
     * object), written back by the bytes rule. A generated example is expanded first.
     *
     * @param string $kind aceitas or recusadas.
     * @param int $n Index of the example.
     * @return string
     */
    public static function example_json(string $kind, int $n): string {
        $example = json_decode(file_get_contents(self::path()), false, 512, JSON_THROW_ON_ERROR)
            ->limitesDaEstrutura->estruturas->{$kind}[$n];
        if (property_exists($example, 'estrutura')) {
            return json_encode($example->estrutura, self::BYTES_FLAGS | JSON_THROW_ON_ERROR);
        }
        return self::encode(self::structure_of(self::data()['limitesDaEstrutura']['estruturas'][$kind][$n]));
    }

    /**
     * The structure of an example of limitesDaEstrutura.estruturas: its estrutura, or the expansion of its gerar
     * (rule at the end of limitesDaEstrutura.regra).
     *
     * @param array $example Example.
     * @return array
     */
    public static function structure_of(array $example): array {
        if (array_key_exists('estrutura', $example)) {
            return $example['estrutura'];
        }
        $g = $example['gerar'];
        $total = $g['licoes'] * $g['blocosPorLicao'];
        $lessons = [];
        $blocks = [];
        $n = 0;
        for ($l = 1; $l <= $g['licoes']; $l++) {
            $ids = [];
            for ($o = 1; $o <= $g['blocosPorLicao']; $o++) {
                $n++;
                $id = 'b' . $n;
                $label = $n === $total && array_key_exists('rotuloDoUltimo', $g) ? $g['rotuloDoUltimo'] : $g['rotulo'];
                $blocks[$id] = ['id' => $id, 'tipo' => 'text', 'rotulo' => $label, 'licaoId' => 'l' . $l, 'ordem' => $o,
                    'itens' => [], 'imagem' => null, 'cenas' => [], 'midia' => false, 'espacial' => false];
                $ids[] = $id;
            }
            $lessons[] = ['id' => 'l' . $l, 'titulo' => $g['titulo'], 'blocos' => $ids];
        }
        return ['v' => 1, 'courseId' => 'c1', 'licoes' => $lessons, 'blocos' => (object)$blocks];
    }

    /**
     * A value ready for assertSame by value (regraDaPlanilha: 20 = 20.0, text never equals a number): every int and
     * float becomes a float, and the keys of each map are sorted (by code point: strcmp), since key order carries no
     * meaning in an aggregate. Lists keep their order.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    public static function by_value(mixed $value): mixed {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = array_map([self::class, 'by_value'], $value);
        if (!array_is_list($out)) {
            uksort($out, static fn($a, $b): int => strcmp((string)$a, (string)$b));
        }
        return $out;
    }

    /**
     * A structure as the file of the package carries it (the bytes rule).
     *
     * @param array $structure Structure (blocos may already be an object; json_decode turns {} into []).
     * @return string
     */
    public static function encode(array $structure): string {
        if (is_array($structure['blocos'] ?? null)) {
            $structure['blocos'] = (object)$structure['blocos'];
        }
        return json_encode($structure, self::BYTES_FLAGS | JSON_THROW_ON_ERROR);
    }
}
