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

use mod_scormmakercmi5\local\package\analysis_structure;

/**
 * Template contexts of the block activity report (subproject D): the aggregates of block_report laid over the
 * analysis structure of the package — blocks in their order, zero for what nobody reached, labels from the package.
 *
 * Texts are raw: the Mustache templates escape them ({{ }}, never {{{ }}}).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_presenter {
    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /**
     * Readable answer of a decision.
     *
     * @param string $option Option as sent by the package.
     * @return string
     */
    private static function answer(string $option): string {
        $known = ['sei' => 'richanswer_sei', 'não sei' => 'richanswer_naosei', 'descoberto' => 'richanswer_descoberto'];
        return isset($known[$option]) ? get_string($known[$option], self::COMPONENT) : $option;
    }

    /**
     * Label of an item of a block (the id when the package left it empty, or for an id outside the structure); the
     * report and block_export share it.
     *
     * @param array $block Block of the structure.
     * @param string $id Item id.
     * @return string
     */
    public static function item_label(array $block, string $id): string {
        foreach ($block['itens'] as $item) {
            if ((string)$item['id'] === $id) {
                return $item['rotulo'] !== '' ? $item['rotulo'] : $id;
            }
        }
        return $id;
    }

    /**
     * Cells of a heat map for the SVG (10×10 viewBox of 100) and its table.
     *
     * @param array $cells "l,c" => count.
     * @return array
     */
    private static function cells(array $cells): array {
        $max = max(1, ...array_values($cells ?: [1]));
        return array_map(static fn(array $c): array => $c + [
            'x' => ($c['col'] - 1) * 10, 'y' => ($c['row'] - 1) * 10,
            'opacity' => format_float(0.25 + 0.6 * $c['count'] / $max, 2, false),
            'summary' => get_string('richmapcellsummary', self::COMPONENT, (object)$c),
        ], self::grid_cells($cells));
    }

    /**
     * The cells of a heat map as the report and the detailed export show them: 1-based row and column, only the keys
     * "l,c" with one digit on each side (10×10 grid; any other key is left out), by row then column.
     *
     * @param array $cells "l,c" => count.
     * @return array[] [row, col, count] each.
     */
    public static function grid_cells(array $cells): array {
        $out = [];
        foreach ($cells as $key => $count) {
            if (preg_match('~^(\d),(\d)$~D', (string)$key, $m)) {
                $out[] = ['row' => (int)$m[1] + 1, 'col' => (int)$m[2] + 1, 'count' => (int)$count];
            }
        }
        usort($out, static fn(array $a, array $b): int => [$a['row'], $a['col']] <=> [$b['row'], $b['col']]);
        return $out;
    }

    /**
     * One heat map: the image (when the package has it) under the cells of its 10×10 grid.
     *
     * @param string|null $scene Title of the scene (tour), null for the image of the block.
     * @param string|null $url URL of the image, null when it is not in the package.
     * @param array $cells "l,c" => count.
     * @param int $number Number of the map on the page (ids of its title and description).
     * @return array
     */
    private static function map(?string $scene, ?string $url, array $cells, int $number): array {
        // The title of the scene goes into the string here, never through the str helper of the template.
        return [
            'scene' => $scene,
            'scenetitle' => $scene === null ? null : get_string('richmapscene', self::COMPONENT, $scene),
            'imageurl' => $url,
            'hasimage' => $url !== null,
            'cells' => self::cells($cells),
            'domid' => 'map' . $number,
        ];
    }

    /**
     * A label of the package, or the id when the package left it empty; the report and block_export share it.
     *
     * @param string $label Label.
     * @param string|int $id Id (an id made only of digits comes back from a PHP array key as an int).
     * @return string
     */
    public static function label(string $label, string|int $id): string {
        return $label !== '' ? $label : (string)$id;
    }

    /**
     * The label of a block in the report: a nested block is "Parent › Child" (U+203A, as the hosted report and the
     * spreadsheet of SCORM Maker), so the text says where it is even without the indent.
     *
     * @param array $structure Analysis structure.
     * @param string $blockid Block id.
     * @param string|null $parentid Id of its parent, null for a top-level block.
     * @return string
     */
    public static function block_label(array $structure, string $blockid, ?string $parentid): string {
        $b = $structure['blocos'][$blockid];
        $label = self::label($b['rotulo'], $b['id']);
        if ($parentid === null) {
            return $label;
        }
        $p = $structure['blocos'][$parentid];
        return self::label($p['rotulo'], $p['id']) . ' › ' . $label;
    }

    /**
     * A time in milliseconds as text, '-' when there is none.
     *
     * @param int|float $ms Milliseconds.
     * @return string
     */
    private static function duration(int|float $ms): string {
        return $ms > 0 ? format_time(max(1, (int)round($ms / 1000))) : '-';
    }

    /**
     * A whole percentage as text in the language of the user ("67%").
     *
     * @param int|float $value Percentage.
     * @return string
     */
    private static function percent(int|float $value): string {
        return get_string('percents', 'moodle', (int)$value);
    }

    /**
     * The media part of a block: retention, skips and stops per band of the class.
     *
     * @param array $m "midia" of the aggregate.
     * @return array
     */
    private static function media(array $m): array {
        $bands = [];
        $end = (int)ceil($m['duracaoMidia']);
        foreach ($m['retencao'] as $k => $retention) {
            $from = $k * $m['faixaSegundos'];
            $range = (object)['from' => $from, 'to' => min($from + $m['faixaSegundos'], $end)];
            $bands[] = [
                'label' => get_string('richmediabandlabel', self::COMPONENT, $range),
                'retention' => (int)$retention,
                'skipped' => (int)$m['pulados'][$k],
                'dropped' => (int)$m['abandonos'][$k],
            ];
        }
        $average = self::percent($m['percentualMedioAssistido']);
        return ['bands' => $bands, 'average' => get_string('richmediaaverage', self::COMPONENT, $average)];
    }

    /**
     * The scenario part of a block: choices per scene, endings, and the scene where learners stopped.
     *
     * @param array $b Block of the structure.
     * @param array $a Aggregate of the block.
     * @return array
     */
    private static function scenario(array $b, array $a): array {
        $choices = [];
        foreach ($b['itens'] as $item) {
            foreach ($a['escolhas'][$item['id']] ?? [] as $option => $count) {
                $choices[] = ['label' => self::label($item['rotulo'], $item['id']), 'option' => (string)$option,
                    'count' => (int)$count];
            }
        }
        $endings = [];
        foreach ($a['finais'] as $option => $count) {
            $endings[] = ['option' => (string)$option, 'count' => (int)$count];
        }
        $dropoff = [];
        foreach ($a['abandonoPorCena'] as $id => $count) {
            $dropoff[] = ['label' => self::item_label($b, (string)$id), 'count' => (int)$count];
        }
        // A scenario reached with no choice yet: a notice, never a table with headers and no rows.
        return ['choices' => $choices, 'endings' => $endings, 'dropoff' => $dropoff, 'haschoices' => (bool)$choices,
            'hasendings' => (bool)$endings, 'hasdropoff' => (bool)$dropoff];
    }

    /**
     * The self-assessment of a block, hardest card first.
     *
     * @param array $b Block of the structure.
     * @param array $sei "sei" of the aggregate.
     * @return array
     */
    private static function cards(array $b, array $sei): array {
        $cards = [];
        foreach ($sei as $id => $k) {
            $cards[] = ['label' => self::item_label($b, (string)$id), 'know' => (int)$k['sei'],
                'dontknow' => (int)$k['naoSei'], 'percentvalue' => (int)$k['percentualSei'],
                'percent' => self::percent($k['percentualSei'])];
        }
        // Ties by label in code point order (strcmp): <=> would compare "10" and "9" as numbers.
        usort($cards, static fn(array $x, array $y): int =>
            ($x['percentvalue'] <=> $y['percentvalue']) ?: strcmp($x['label'], $y['label']));
        return $cards;
    }

    /**
     * Context of the activity of the class.
     *
     * @param array $structure Analysis structure of the package.
     * @param array $aggregates block_report::activity().
     * @param callable|null $imageurl fn(string media path): ?string, the URL of a package image (null = no image).
     * @return array
     */
    public static function activity(array $structure, array $aggregates, ?callable $imageurl): array {
        $lessons = [];
        $mapnumber = 0;
        foreach ($structure['licoes'] as $lesson) {
            $blocks = [];
            foreach (analysis_structure::lesson_blocks($structure, $lesson) as [$blockid, $parentid]) {
                $b = $structure['blocos'][$blockid];
                $a = $aggregates['blocos'][$blockid] ?? null;
                $reached = (int)($a['alcancaram'] ?? 0);
                $entry = [
                    'label' => self::block_label($structure, $blockid, $parentid),
                    'nested' => $parentid !== null,
                    'notreached' => $reached === 0,
                    'reachedpercent' => (int)($a['percentualAlcance'] ?? 0),
                    'reached' => self::percent($a['percentualAlcance'] ?? 0),
                    'learners' => $reached,
                    'time' => self::duration($a['tempoMedioMs'] ?? 0),
                    'interactions' => format_float((float)($a['interacoesPorAluno'] ?? 0), 1),
                ];
                // Cards and scenes have tables of their own; a block nobody reached gets no table of zeros.
                if ($b['itens'] && $a !== null && !isset($a['sei']) && !isset($a['escolhas'])) {
                    $entry['items'] = array_map(static fn(array $item): array => [
                        'label' => self::label($item['rotulo'], $item['id']),
                        'opened' => (int)($a['itens'][$item['id']]['abriram'] ?? 0),
                        'percent' => self::percent($a['itens'][$item['id']]['percentual'] ?? 0),
                    ], $b['itens']);
                    $entry['hasitems'] = true;
                }
                if (isset($a['midia'])) {
                    $entry['media'] = self::media($a['midia']);
                    $entry['hasmedia'] = true;
                }
                if (isset($a['mapa'])) {
                    $maps = [];
                    if ($a['mapa']['celulas'] || !$b['cenas']) {
                        $url = $b['imagem'] !== null && $imageurl ? $imageurl($b['imagem']) : null;
                        $maps[] = self::map(null, $url, $a['mapa']['celulas'], ++$mapnumber);
                    }
                    foreach ($b['cenas'] as $scene) {
                        $cells = $a['mapa']['cenas'][(string)$scene['id']] ?? [];
                        if ($cells) {
                            $url = $scene['imagem'] !== null && $imageurl ? $imageurl($scene['imagem']) : null;
                            $maps[] = self::map($scene['titulo'], $url, $cells, ++$mapnumber);
                        }
                    }
                    $entry['maps'] = $maps;
                    $entry['hasmaps'] = (bool)$maps;
                }
                if (isset($a['sei'])) {
                    $entry['cards'] = self::cards($b, $a['sei']);
                    $entry['hascards'] = true;
                }
                if (isset($a['escolhas'])) {
                    $entry['scenario'] = self::scenario($b, $a);
                    $entry['hasscenario'] = true;
                }
                if (isset($a['descobertos'])) {
                    $entry['discovered'] = array_map(static fn(array $item): array => [
                        'label' => self::label($item['rotulo'], $item['id']),
                        'count' => (int)($a['descobertos'][$item['id']] ?? 0),
                    ], $b['itens']);
                    $entry['hasdiscovered'] = true;
                }
                $blocks[] = $entry;
            }
            $lessons[] = ['title' => $lesson['titulo'], 'blocks' => $blocks];
        }
        // The time of a parent includes the time its nested content was open (it stays visible under the modal): one
        // line of help when the version has a nested row.
        $hasnested = false;
        foreach ($lessons as $lesson) {
            $hasnested = $hasnested || in_array(true, array_column($lesson['blocks'], 'nested'), true);
        }
        return ['learners' => (int)$aggregates['alunos'], 'lessons' => $lessons, 'haslessons' => (bool)$lessons,
            'hasnested' => $hasnested];
    }

    /**
     * Context of the summary of one learner: blocks reached, time, media watched, decisions. No sequence, no cells.
     *
     * @param array $structure Analysis structure.
     * @param array $summary block_report::learner().
     * @return array
     */
    public static function learner(array $structure, array $summary): array {
        $blocks = [];
        $decisions = [];
        $types = ['escolha' => 'richdecisiontype_escolha', 'final' => 'richdecisiontype_final',
            'sei' => 'richdecisiontype_sei', 'descoberto' => 'richdecisiontype_descoberto'];
        foreach ($structure['licoes'] as $lesson) {
            foreach (analysis_structure::lesson_blocks($structure, $lesson) as [$blockid, $parentid]) {
                $b = $structure['blocos'][$blockid];
                $label = self::block_label($structure, $blockid, $parentid);
                if (isset($summary['blocos'][$blockid])) {
                    $s = $summary['blocos'][$blockid];
                    $blocks[] = [
                        'label' => $label,
                        'lesson' => $lesson['titulo'],
                        'time' => self::duration($s['tempoMs']),
                        'watched' => isset($s['percentualAssistido']) ? self::percent($s['percentualAssistido']) : '-',
                    ];
                }
                foreach ($b['itens'] as $item) {
                    $d = $summary['decisoes'][$blockid][$item['id']] ?? null;
                    if ($d) {
                        $decisions[] = [
                            'block' => $label,
                            'item' => self::label($item['rotulo'], $item['id']),
                            'type' => isset($types[$d['tipo']]) ? get_string($types[$d['tipo']], self::COMPONENT) : $d['tipo'],
                            'answer' => self::answer((string)$d['opcao']),
                        ];
                    }
                }
            }
        }
        return ['blocks' => $blocks, 'hasblocks' => (bool)$blocks, 'decisions' => $decisions,
            'hasdecisions' => (bool)$decisions];
    }
}
