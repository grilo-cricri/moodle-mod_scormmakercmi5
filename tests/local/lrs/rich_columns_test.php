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

namespace mod_scormmakercmi5\local\lrs;

use mod_scormmakercmi5\local\cmi5_vocab as vocab;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\statement_store::class)]
/**
 * Tests for the SCORM Maker profile 2 columns (subproject D, design §3.9).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\statement_store
 */
final class rich_columns_test extends \basic_testcase {
    /** @var string SHA-256 of backend/publicacoes/tests/contrato-dados-ricos.json in the SCORM Maker repository. */
    private const CONTRACT_SHA256 = 'f763370c63fccb236d554637c9f4fc6798f8f43a22d438425d70f7f263b2df59';

    /**
     * Path of the copy of the shared contract.
     *
     * @return string
     */
    public static function contract_path(): string {
        return \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contrato-dados-ricos.json';
    }

    /**
     * The copy is byte-for-byte the one of SCORM Maker.
     */
    public function test_contract_copy_matches_scorm_maker(): void {
        $this->assertSame(
            self::CONTRACT_SHA256,
            hash_file('sha256', self::contract_path()),
            'update the copy together with SCORM Maker (backend/publicacoes/tests/contrato-dados-ricos.json)'
        );
    }

    /**
     * A statement of the profile 2.
     *
     * @param string $object Object IRI.
     * @param array $extensions Extra context extensions.
     * @return array
     */
    private static function st(string $object, array $extensions = []): array {
        return [
            'object' => ['objectType' => 'Activity', 'id' => $object],
            'context' => [
                'contextActivities' => ['category' => [['id' => vocab::SM_PROFILE]]],
                'extensions' => $extensions + [
                    vocab::SM_EXT_VERSION => 'v#1', vocab::SM_EXT_PROFILEVER => 2, vocab::SM_EXT_BLOCKTYPE => 'video',
                ],
            ],
        ];
    }

    /**
     * A block summary: kind bloco, item and block are the block id.
     */
    public function test_block_summary(): void {
        $c = statement_store::sm_columns(self::st('https://scormmaker.com.br/xapi/curso/c1/bloco/v1'));
        $this->assertSame(['smprofile' => 1, 'smversion' => 'v#1', 'smkind' => 'bloco', 'smitem' => 'v1',
            'smblock' => 'v1', 'smblocktype' => 'video', 'smprofilever' => 2], (array)$c);
    }

    /**
     * A decision: kind item, item = item id, block = block id.
     */
    public function test_item_decision(): void {
        $c = statement_store::sm_columns(self::st('https://scormmaker.com.br/xapi/curso/c1/bloco/f1/item/k1'));
        $this->assertSame(['item', 'k1', 'f1'], [$c->smkind, $c->smitem, $c->smblock]);
    }

    /**
     * The lessons and questions of C keep working, without block columns.
     */
    public function test_lesson_keeps_the_c_columns(): void {
        $c = statement_store::sm_columns(self::st('https://scormmaker.com.br/xapi/curso/c1/licao/l1'));
        $this->assertSame(['licao', 'l1', null], [$c->smkind, $c->smitem, $c->smblock]);
    }

    /**
     * Too wide or wrong-typed values never reach the columns (design §3.13).
     */
    public function test_widths_and_types(): void {
        $wide = statement_store::sm_columns(self::st('https://scormmaker.com.br/xapi/curso/c1/bloco/' . str_repeat('x', 65)));
        $this->assertSame([0, null], [$wide->smprofile, $wide->smblock], 'a wide id drops the profile, like the item of C');
        $wideitem = statement_store::sm_columns(
            self::st('https://scormmaker.com.br/xapi/curso/c1/bloco/b1/item/' . str_repeat('i', 65))
        );
        $this->assertSame([0, null, null], [$wideitem->smprofile, $wideitem->smblock, $wideitem->smitem]);
        $edge = statement_store::sm_columns(self::st('https://scormmaker.com.br/xapi/curso/c1/bloco/' . str_repeat('é', 64)));
        $this->assertSame(str_repeat('é', 64), $edge->smblock, '64 characters, not bytes');
        $type = statement_store::sm_columns(self::st(
            'https://scormmaker.com.br/xapi/curso/c1/bloco/v1',
            [vocab::SM_EXT_BLOCKTYPE => str_repeat('t', 33), vocab::SM_EXT_PROFILEVER => true]
        ));
        $this->assertSame(['v1', null, null], [$type->smblock, $type->smblocktype, $type->smprofilever]);
        $type = statement_store::sm_columns(self::st(
            'https://scormmaker.com.br/xapi/curso/c1/bloco/v1',
            [vocab::SM_EXT_BLOCKTYPE => ['video'], vocab::SM_EXT_PROFILEVER => 2.0]
        ));
        $this->assertSame([null, null], [$type->smblocktype, $type->smprofilever]);
        $type = statement_store::sm_columns(self::st(
            'https://scormmaker.com.br/xapi/curso/c1/bloco/v1',
            [vocab::SM_EXT_BLOCKTYPE => '', vocab::SM_EXT_PROFILEVER => 100]
        ));
        $this->assertSame([null, null], [$type->smblocktype, $type->smprofilever]);
    }

    /**
     * Ids with whitespace or a line break, as in the hosted course (lrs.py, fullmatch with no whitespace).
     *
     * @return array
     */
    public static function whitespace_provider(): array {
        return [
            'trailing newline' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v1' . "\n"],
            'space in the block' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v 1'],
            'tab in the block' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v1' . "\t"],
            'newline in the item' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v1/item/k1' . "\n"],
            'space in the item' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v1/item/k 1'],
            'space in the course' => ['https://scormmaker.com.br/xapi/curso/c 1/bloco/v1'],
            'no-break space' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v' . "\u{00A0}" . '1'],
            'query' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v1?x=1'],
            'empty block' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/'],
            'empty item' => ['https://scormmaker.com.br/xapi/curso/c1/bloco/v1/item/'],
        ];
    }

    /**
     * An object outside the pattern is no block: the statement keeps the profile and the version only.
     *
     * @dataProvider whitespace_provider
     * @param string $object Object IRI.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('whitespace_provider')]
    public function test_outside_the_pattern_is_no_block(string $object): void {
        $c = statement_store::sm_columns(self::st($object));
        $this->assertSame([1, 'v#1', null, null, null, null], [$c->smprofile, $c->smversion, $c->smkind, $c->smitem,
            $c->smblock, $c->smblocktype]);
    }

    /**
     * Without the profile category nothing is extracted.
     */
    public function test_without_profile(): void {
        $st = self::st('https://scormmaker.com.br/xapi/curso/c1/bloco/v1');
        $st['context']['contextActivities']['category'] = [];
        $c = statement_store::sm_columns($st);
        $this->assertSame([0, null, null], [$c->smprofile, $c->smblock, $c->smprofilever]);
    }

    /**
     * The vocabulary of the profile 2, verbatim from the contract.
     */
    public function test_vocabulary(): void {
        $base = 'https://scormmaker.com.br/xapi/extensao/';
        $this->assertSame($base . 'versao-do-perfil', vocab::SM_EXT_PROFILEVER);
        $this->assertSame($base . 'tipo-de-bloco', vocab::SM_EXT_BLOCKTYPE);
        $this->assertSame($base . 'tipo-de-decisao', vocab::SM_EXT_DECISIONTYPE);
        $this->assertSame($base . 'cena-de-destino', vocab::SM_EXT_DESTSCENE);
        $this->assertSame($base . 'resumo-do-bloco', vocab::SM_EXT_SUMMARY);
        $this->assertSame($base . 'video', vocab::SM_EXT_VIDEO);
        $this->assertSame($base . 'mapa-de-calor', vocab::SM_EXT_HEATMAP);
        $this->assertSame('http://adlnet.gov/expapi/verbs/interacted', vocab::VERB_INTERACTED);
        $this->assertSame('http://id.tincanapi.com/verb/selected', vocab::VERB_SELECTED);
    }
}
