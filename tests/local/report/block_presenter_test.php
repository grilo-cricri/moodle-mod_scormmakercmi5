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

namespace mod_scormmakercmi5\local\report;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contract.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\block_presenter::class)]
/**
 * Tests for the presentation of the block activity report (subproject D).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\report\block_presenter
 */
final class block_presenter_test extends \advanced_testcase {
    /** @var array The case of the contract. */
    private array $case;

    /** @var array The structure of the contract as the import stores it (only media/m1.jpg is in the package). */
    private array $structure;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $path = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contrato-dados-ricos.json';
        $this->case = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['casos'][0];
        $this->structure = \mod_scormmakercmi5\local\package\analysis_structure::from_json(
            json_encode($this->case['estrutura']),
            ['media/m1.jpg']
        );
    }

    /**
     * A template of the plugin rendered with a context.
     *
     * @param string $name Template name.
     * @param array $context Context.
     * @return string
     */
    private function render(string $name, array $context): string {
        $page = new \moodle_page();
        $page->set_url('/');
        $page->set_context(\context_system::instance());
        return $page->get_renderer('mod_scormmakercmi5')->render_from_template('mod_scormmakercmi5/' . $name, $context);
    }

    /**
     * Blocks in the order of the structure; the block nobody reached is listed as not reached.
     */
    public function test_order_and_not_reached(): void {
        $ctx = block_presenter::activity($this->structure, $this->case['atividade'], null);
        $blocks = $ctx['lessons'][0]['blocks'];
        $this->assertSame(['Vídeo 1', 'Abas 1', 'Marcadores 1', 'Cartões 1', 'Cenário 1', 'Texto 1', 'Tour 1',
            '=HYPERLINK("https://exemplo.com")'], array_column($blocks, 'label'));
        $this->assertTrue($blocks[7]['notreached']);
        $this->assertSame(67, $blocks[1]['reachedpercent']);
    }

    /**
     * Items of the structure with no data appear with zero; cards sorted hardest first; media bands labelled.
     */
    public function test_sections(): void {
        $ctx = block_presenter::activity($this->structure, $this->case['atividade'], null);
        $blocks = $ctx['lessons'][0]['blocks'];
        $this->assertSame([2, 1, 0], array_column($blocks[1]['items'], 'opened'));
        $this->assertSame(['Cartão 1', 'Cartão 2'], array_column($blocks[3]['cards'], 'label'));
        $this->assertSame([100, 33, 33, 67], array_column($blocks[0]['media']['bands'], 'retention'));
        $this->assertSame([['option' => 'Final feliz', 'count' => 1]], $blocks[4]['scenario']['endings']);
        // B chose "Ignorar" in Cena 1, which leads to Cena 2, and reached no ending: the class stopped in Cena 2.
        $this->assertSame([['label' => 'Cena 2', 'count' => 1]], $blocks[4]['scenario']['dropoff']);
        $discovered = [['label' => 'Ponto 1', 'count' => 1], ['label' => 'Ponto 2', 'count' => 0]];
        $this->assertSame($discovered, $blocks[6]['discovered']);
    }

    /**
     * Map: one cell per clicked cell, with the image only when the URL callback gives one.
     */
    public function test_map(): void {
        $withimage = block_presenter::activity(
            $this->structure,
            $this->case['atividade'],
            static fn(string $path): string => 'https://moodle.test/pluginfile.php/' . $path
        );
        $map = $withimage['lessons'][0]['blocks'][2]['maps'][0];
        $this->assertSame('https://moodle.test/pluginfile.php/media/m1.jpg', $map['imageurl']);
        $this->assertSame(
            [['row' => 3, 'col' => 4, 'count' => 3], ['row' => 6, 'col' => 6, 'count' => 1]],
            array_map(static fn(array $c): array => ['row' => $c['row'], 'col' => $c['col'], 'count' => $c['count']], $map['cells'])
        );
        $tour = $withimage['lessons'][0]['blocks'][6]['maps'];
        $this->assertSame('Sala', $tour[0]['scene']);
        $this->assertNull($tour[0]['imageurl'], 'the scene image is not in the package');
    }

    /**
     * The learner summary: blocks reached with time and media, decisions with readable answers — nothing else.
     */
    public function test_learner(): void {
        $ctx = block_presenter::learner($this->structure, $this->case['porAluno']['B']);
        $labels = array_column($ctx['blocks'], 'label');
        $this->assertSame(['Vídeo 1', 'Abas 1', 'Marcadores 1', 'Cartões 1', 'Cenário 1'], $labels);
        $this->assertSame('50%', $ctx['blocks'][0]['watched']);
        $this->assertSame(["Didn't know", 'Ignorar'], array_column($ctx['decisions'], 'answer'));
        $this->assertArrayNotHasKey('cells', $ctx['blocks'][2]);
    }

    /**
     * Texts from the package are escaped by the template.
     */
    public function test_template_escapes_package_text(): void {
        $html = $this->render('report_blocks', block_presenter::activity($this->structure, $this->case['atividade'], null));
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    /**
     * The heat map is an image with a name and a description, next to its table; the labels are numbered once.
     */
    public function test_template_map_is_accessible(): void {
        $html = $this->render('report_blocks', block_presenter::activity($this->structure, $this->case['atividade'], null));
        $this->assertSame(2, substr_count($html, 'role="img"'), 'one image per map (markers and the tour scene)');
        $this->assertMatchesRegularExpression('~<svg[^>]+aria-labelledby="([^"]+)"[^>]+aria-describedby="([^"]+)"~', $html);
        preg_match_all('~\sid="([^"]+)"~', $html, $ids);
        $this->assertSame(array_unique($ids[1]), $ids[1], 'no repeated id');
        $this->assertStringContainsString('Row 3, column 4: 3 clicks', $html);
        $this->assertStringContainsString('<th scope="row">Row 3, column 4</th>', $html);
    }

    /**
     * The template of a learner shows only the summary: no map, no sequence.
     */
    public function test_template_learner_has_no_fine_data(): void {
        $html = $this->render('report_blocks_user', block_presenter::learner($this->structure, $this->case['porAluno']['A']));
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('Row ', $html);
        $this->assertStringContainsString('Final feliz', $html);
        $this->assertStringContainsString('Knew', $html);
    }

    /**
     * Ids made only of digits (case decimos-exatos): PHP gives the array keys back as ints, and the casts to string are
     * what keeps the labels of the package (not the ids) on the page.
     */
    public function test_numeric_ids_keep_the_labels(): void {
        $path = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contrato-dados-ricos.json';
        $cases = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['casos'];
        $case = array_values(array_filter($cases, static fn(array $c): bool => $c['nome'] === 'decimos-exatos'))[0];
        $structure = \mod_scormmakercmi5\local\package\analysis_structure::from_json(json_encode($case['estrutura']), []);
        $blocks = block_presenter::activity($structure, $case['atividade'], null)['lessons'][0]['blocks'];
        $this->assertSame(['Vídeo 123', 'Cartões 456'], array_column($blocks, 'label'));
        $this->assertSame(['Cartão 42'], array_column($blocks[1]['cards'], 'label'));
    }

    /**
     * A scenario reached with no choice yet shows a notice, not a "Choices per scene" table with no rows.
     */
    public function test_scenario_without_choices_has_no_empty_table(): void {
        $aggregates = $this->case['atividade'];
        $aggregates['blocos']['c1']['escolhas'] = [];
        $ctx = block_presenter::activity($this->structure, $aggregates, null);
        $scenario = $ctx['lessons'][0]['blocks'][4]['scenario'];
        $this->assertFalse($scenario['haschoices']);
        $html = $this->render('report_blocks', $ctx);
        $this->assertStringNotContainsString(get_string('richscenario', 'mod_scormmakercmi5') . '</caption>', $html);
        $this->assertStringContainsString(get_string('richscenarionochoices', 'mod_scormmakercmi5'), $html);
        $full = $this->render('report_blocks', block_presenter::activity($this->structure, $this->case['atividade'], null));
        $this->assertStringContainsString(get_string('richscenario', 'mod_scormmakercmi5') . '</caption>', $full);
    }

    /**
     * Cards tied on the percentage are ordered by label in code point order (strcmp): "10" before "9", never the
     * numeric order of PHP's <=> on numeric strings.
     */
    public function test_card_ties_by_code_point(): void {
        $structure = $this->structure;
        $structure['blocos']['f1']['itens'][0]['rotulo'] = '9';
        $structure['blocos']['f1']['itens'][1]['rotulo'] = '10';
        $aggregates = $this->case['atividade'];
        foreach ($aggregates['blocos']['f1']['sei'] as &$card) {
            $card['percentualSei'] = 50;
        }
        unset($card);
        $blocks = block_presenter::activity($structure, $aggregates, null)['lessons'][0]['blocks'];
        $this->assertSame(['10', '9'], array_column($blocks[3]['cards'], 'label'));
    }

    /**
     * The structure and the activity of a contract case, as the import stores the structure.
     *
     * @param string $name Nome of the case.
     * @return array [structure, case].
     */
    private static function nested_case(string $name): array {
        $case = \mod_scormmakercmi5_test_contract::case_named($name);
        $structure = \mod_scormmakercmi5\local\package\analysis_structure::from_json(
            \mod_scormmakercmi5_test_contract::encode($case['estrutura']),
            []
        );
        return [$structure, $case];
    }

    /**
     * Nested blocks come right after their parent, labelled "Parent › Child" and marked nested.
     */
    public function test_nested_blocks_follow_their_parent(): void {
        [$structure, $case] = self::nested_case('blocos-aninhados');
        $blocks = block_presenter::activity($structure, $case['atividade'], null)['lessons'][0]['blocks'];
        $this->assertSame(['Mapa 1', 'Mapa 1 › Vídeo dentro', 'Mapa 1 › Cartões dentro'], array_column($blocks, 'label'));
        $this->assertSame([false, true, true], array_column($blocks, 'nested'));
        $this->assertSame([100, 100, 50], array_column($blocks, 'reachedpercent'), 'the child keeps its own numbers');
        $this->assertTrue($blocks[1]['hasmedia']);
        $this->assertSame(['Cartão 1'], array_column($blocks[2]['cards'], 'label'));
    }

    /**
     * Keys of blocos out of order, lessons out of id order, an empty child label: the order is the one of licoes and
     * filhos, and the child with no label shows its id after the parent.
     */
    public function test_nested_blocks_out_of_order(): void {
        [$structure, $case] = self::nested_case('aninhados-fora-de-ordem');
        $lessons = block_presenter::activity($structure, $case['atividade'], null)['lessons'];
        $this->assertSame(['Tour 2', 'Tour 2 › Texto dentro'], array_column($lessons[0]['blocks'], 'label'));
        $this->assertSame(
            ['Mapa 2', 'Mapa 2 › vv2', 'Mapa 2 › Cartões dentro 2', 'Texto 2'],
            array_column($lessons[1]['blocks'], 'label')
        );
        $this->assertSame([false, true, true, false], array_column($lessons[1]['blocks'], 'nested'));
        $this->assertSame([33, 33, 67, 33], array_column($lessons[1]['blocks'], 'reachedpercent'));
    }

    /**
     * The learner summary lists the nested blocks reached, with the same label.
     */
    public function test_learner_nested_blocks(): void {
        [$structure, $case] = self::nested_case('blocos-aninhados');
        $ctx = block_presenter::learner($structure, $case['porAluno']['A']);
        $this->assertSame(
            ['Mapa 1', 'Mapa 1 › Vídeo dentro', 'Mapa 1 › Cartões dentro'],
            array_column($ctx['blocks'], 'label')
        );
        $this->assertSame(['Mapa 1 › Cartões dentro'], array_column($ctx['decisions'], 'block'));
    }

    /**
     * The row of a nested block has the nested class (indented) and the "Parent › Child" text; top-level rows do not.
     */
    public function test_template_nested_row(): void {
        [$structure, $case] = self::nested_case('blocos-aninhados');
        $html = $this->render('report_blocks', block_presenter::activity($structure, $case['atividade'], null));
        $this->assertSame(2, substr_count($html, '<tr class="mod-scormmakercmi5-nested">'));
        $this->assertStringContainsString(
            '<tr class="mod-scormmakercmi5-nested">' . "\n" . '                <th scope="row">Mapa 1 › Vídeo dentro</th>',
            $html
        );
        $this->assertMatchesRegularExpression('~<tr>\s*<th scope="row">Mapa 1</th>~', $html);
    }

    /**
     * The item column says "seen or opened": the item already on screen when the block became visible counts without
     * being opened (final wave ruling), the same text as the hosted report.
     */
    public function test_item_column_says_seen_or_opened(): void {
        $html = $this->render('report_blocks', block_presenter::activity($this->structure, $this->case['atividade'], null));
        $this->assertStringContainsString('Items seen or opened</caption>', $html);
        $this->assertStringContainsString('<th scope="col">Seen or opened by</th>', $html);
    }

    /**
     * A version with nested blocks says, once, that the time of a block includes the time its nested content was open
     * (the parent stays visible under the modal); a version without nested blocks does not.
     */
    public function test_nested_time_hint_only_with_nested_blocks(): void {
        $hint = get_string('richblocksnestedtime', 'mod_scormmakercmi5');
        [$structure, $case] = self::nested_case('blocos-aninhados');
        $ctx = block_presenter::activity($structure, $case['atividade'], null);
        $this->assertTrue($ctx['hasnested']);
        $this->assertSame(1, substr_count($this->render('report_blocks', $ctx), $hint));
        $ctx = block_presenter::activity($this->structure, $this->case['atividade'], null);
        $this->assertFalse($ctx['hasnested']);
        $this->assertStringNotContainsString($hint, $this->render('report_blocks', $ctx));
    }
}
