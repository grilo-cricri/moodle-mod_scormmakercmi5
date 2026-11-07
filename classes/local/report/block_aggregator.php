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

use mod_scormmakercmi5\local\analysis_bounds;
use mod_scormmakercmi5\local\cmi5_vocab as vocab;

/**
 * The aggregates of the block activity report (subproject D), the same as SCORM Maker computes for its hosted course
 * (contract tests/fixtures/contrato-dados-ricos.json, rules 1–8 of its Task 4). Pure: rows in, arrays out.
 *
 * The rows are read twice, in the order regid, timestampms, id. The first pass only finds the duration of each media
 * (the largest any learner reported, which the bands depend on). The second folds the rows as they come: what belongs
 * to one learner (items seen, segments, last decisions) is kept only until the next learner starts, then added to the
 * counters of the block, the segments as per-band increments (covering, skipped, dropped) and one sum of the %. What
 * stays in memory for the whole class is O(bands + blocks + items): never a learner's segments, never a payload. The
 * one thing that grows with the class is the answers of the decisions (each learner's last answer per item, at most
 * 500 characters each).
 *
 * Ids (blocks, items, scenes) are text. A PHP array turns an all-digits key ("123") into an integer, and that cannot
 * be avoided: so every id that comes from a key is cast back to string before it is used again, and a caller that
 * compares the keys of the result (array_keys) or encodes a map to JSON must cast them too — a map whose only key is
 * "0" would otherwise go out of json_encode as a list.
 *
 * Media arithmetic is done in exact tenths of a second (the player sends seconds with one decimal): a band is covered
 * when the overlap is at least min(1 s, band length), and 1.3 → 2.3 is one second, not the 0.9999999999999998 of the
 * float subtraction.
 *
 * A block summary outside the limits of analysis_bounds (stored before the LRS refused them, audit M-D1) is ignored
 * entirely (its interactions, items, media and map), never clamped piecemeal, the same rule as the hosted course:
 * the number of bands then never exceeds 86400 / 5, a segment never ends past 86400 s, and a heatmap grid has at most
 * 100 cells. The time and the reach of the block still come from SQL (the indexed columns), as for any summary. The
 * segments of a learner are kept as one integer each (start * 2^20 + end, in tenths, both below 2^20 thanks to that
 * ceiling) and merged as they grow, so thousands of summaries of one learner cost the size of their union.
 *
 * Only the blocks, items and scenes of the verified analysis structure of the package count (block_report passes it):
 * a learner can store thousands of distinct ids, and none of them is kept unless the package has it. A media of
 * unknown duration (duracaoMidia 0, streaming) counts for reach, time and interactions and stays out of the media.
 * A heatmap counts only on a block the structure marks as spatial (final review M-10), as a video only on a media
 * block. "Last" is the largest (timestampms, id): the hosted course orders by the timestamp truncated to the
 * millisecond, then id, so both sides break a tie inside one millisecond the same way (M-3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_aggregator {
    /** @var int Width of a media band, seconds. */
    public const BAND = 5;

    /** @var int Tenths of a second per second. */
    private const TENTHS = 10;

    /** @var int Packs a segment [start, end] in tenths into one integer: start * SHIFT + end (end < SHIFT). */
    private const SHIFT = 1 << 20;

    /** @var int Unmerged segments of one learner in one block before they are merged into their union. */
    private const MERGE_AT = 1024;

    /** @var string Decision of a flashcard (the course sends the Portuguese values "sei" / "não sei"). */
    private const KNOW = 'sei';

    /** @var string Choice of a branching scenario. */
    private const CHOICE = 'escolha';

    /** @var string Ending of a branching scenario. */
    private const ENDING = 'final';

    /** @var string Point found in a virtual tour. */
    private const FOUND = 'descoberto';

    /**
     * Rounded percentage, half up: floor((200n + d) / 2d).
     *
     * @param int $n Part.
     * @param int $d Whole.
     * @return int
     */
    public static function pct(int $n, int $d): int {
        return $d === 0 ? 0 : intdiv(200 * $n + $d, 2 * $d);
    }

    /**
     * Rounded mean, half up.
     *
     * @param int $sum Sum.
     * @param int $d Count.
     * @return int
     */
    public static function mean(int $sum, int $d): int {
        return $d === 0 ? 0 : intdiv(2 * $sum + $d, 2 * $d);
    }

    /**
     * Mean with one decimal, half up.
     *
     * @param int $total Total.
     * @param int $d Count.
     * @return float
     */
    public static function tenth(int $total, int $d): float {
        return $d === 0 ? 0.0 : intdiv(20 * $total + $d, 2 * $d) / 10;
    }

    /**
     * Aggregates of the class (contract: "atividade").
     *
     * @param int $learners Registrations with any statement of the selection.
     * @param array $groups smblock => ['alcancaram' => int, 'tempo' => int] from SQL.
     * @param array|\Closure $rows Rows (id, regid, smkind, smblock, smitem, smblocktype, timestampms, payload), ordered
     *     by regid, timestampms, id: an array, or a closure that gives a fresh iterable of them each time it is called
     *     (they are read twice).
     * @param array|null $structure Verified analysis structure of the package (block_report::structure()): only its
     *     blocks, items and scenes count. Null only for the unit tests of the arithmetic (no filter).
     * @return array ['alunos' => int, 'blocos' => [smblock => aggregate]].
     */
    public static function activity(int $learners, array $groups, array|\Closure $rows, ?array $structure = null): array {
        $known = self::known($structure);
        $open = $rows instanceof \Closure ? $rows : static fn(): array => $rows;
        // First pass: the duration of each media, so that every learner is folded into the bands at once.
        $durations = [];
        foreach ($open() as $row) {
            if ($row->smkind !== 'bloco') {
                continue;
            }
            $fact = self::fact($row, $known);
            if ($fact !== null && $fact['video'] !== null) {
                $durations[$fact['block']] = max($durations[$fact['block']] ?? 0, $fact['video']['duration']);
            }
        }
        $blocks = [];
        $current = null;
        $state = [];
        foreach ($open() as $row) {
            $fact = self::fact($row, $known);
            if ($fact === null) {
                continue;
            }
            if ($fact['reg'] !== $current) {
                self::fold_learner($blocks, $state, $durations);
                $current = $fact['reg'];
                $state = [];
            }
            $block = $fact['block'];
            $blocks[$block] ??= self::new_block();
            $state[$block] ??= self::new_learner();
            self::take($blocks[$block], $state[$block], $fact);
        }
        self::fold_learner($blocks, $state, $durations);

        $out = ['alunos' => $learners, 'blocos' => []];
        foreach ($blocks as $block => $b) {
            $reached = (int)($groups[$block]['alcancaram'] ?? 0);
            $agg = [
                'tipo' => $b['type'] ?? $b['decisiontype'],
                'alcancaram' => $reached,
                'percentualAlcance' => self::pct($reached, $learners),
                'tempoMedioMs' => self::mean((int)($groups[$block]['tempo'] ?? 0), $reached),
                'interacoesPorAluno' => self::tenth($b['interactions'], $reached),
                'itens' => [],
            ];
            foreach ($b['items'] as $item => $n) {
                $agg['itens'][$item] = ['abriram' => $n, 'percentual' => self::pct($n, $reached)];
            }
            $duration = (int)($durations[$block] ?? 0);
            if ($duration > 0) {
                $agg['midia'] = self::media($duration, $b, $reached);
            }
            if ($b['cells'] !== null) {
                $agg['mapa'] = ['grade' => 10, 'celulas' => $b['cells'], 'cenas' => $b['scenes']];
            }
            if ($b['know']) {
                foreach ($b['know'] as $item => $k) {
                    $b['know'][$item]['percentualSei'] = self::pct($k['sei'], $k['sei'] + $k['naoSei']);
                }
                $agg['sei'] = $b['know'];
            }
            if ($b['scenario']) {
                $agg['escolhas'] = $b['choices'];
                $agg['finais'] = $b['endings'];
                $agg['abandonoPorCena'] = $b['dropoff'];
            }
            if ($b['found']) {
                $agg['descobertos'] = $b['found'];
            }
            $out['blocos'][$block] = $agg;
        }
        return $out;
    }

    /**
     * Summary of one learner (contract: "porAluno"): blocks reached with time and media watched, last decisions.
     *
     * @param array $groups smblock => ['tempo' => int] of this registration, from SQL.
     * @param iterable $rows Rows of this registration, ordered by timestampms, id.
     * @param array|null $structure Verified analysis structure (see activity()).
     * @return array ['blocos' => [smblock => ['tempoMs' => int, 'percentualAssistido'?: int]], 'decisoes' => [...]].
     */
    public static function learner(array $groups, iterable $rows, ?array $structure = null): array {
        $known = self::known($structure);
        $blocks = [];
        $decisions = [];
        foreach ($rows as $row) {
            $fact = self::fact($row, $known);
            if ($fact === null) {
                continue;
            }
            $block = $fact['block'];
            if ($fact['summary']) {
                $blocks[$block] ??= ['duration' => 0, 'segments' => [], 'merged' => 0, 'pct' => null];
                $video = $fact['video'];
                if ($video !== null) {
                    $blocks[$block]['duration'] = max($blocks[$block]['duration'], $video['duration']);
                    self::gather($blocks[$block]['segments'], $blocks[$block]['merged'], $video['segments']);
                    $blocks[$block]['pct'] = max($blocks[$block]['pct'] ?? 0, $video['pct'] ?? 0);
                }
            } else {
                $decisions[$block][$fact['item']] = ['tipo' => $fact['kind'], 'opcao' => $fact['option']];
            }
        }
        $out = ['blocos' => [], 'decisoes' => $decisions];
        foreach ($blocks as $block => $b) {
            $entry = ['tempoMs' => (int)($groups[$block]['tempo'] ?? 0)];
            if ($b['duration'] > 0) {
                [$covered] = self::coverage(self::union($b['segments']), $b['duration']);
                $entry['percentualAssistido'] = max(self::watched($covered, $b['duration']), $b['pct'] ?? 0);
            }
            $out['blocos'][$block] = $entry;
        }
        return $out;
    }

    /**
     * The ids of the verified structure: block id => ['items' => id => true, 'scenes' => id => true, 'media' => bool,
     * 'spatial' => bool].
     * The one index of the structure of the report (block_report filters its SQL groups with it too).
     *
     * @param array|null $structure Analysis structure (analysis_structure::decode()).
     * @return array|null Null: no structure, no filter.
     */
    public static function known(?array $structure): ?array {
        if ($structure === null) {
            return null;
        }
        $ids = static function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $entry) {
                if (is_array($entry) && is_scalar($entry['id'] ?? null)) {
                    $out[(string)$entry['id']] = true;
                }
            }
            return $out;
        };
        $known = [];
        foreach (is_array($structure['blocos'] ?? null) ? $structure['blocos'] : [] as $key => $block) {
            if (is_array($block)) {
                $id = is_scalar($block['id'] ?? null) ? $block['id'] : $key;
                $known[(string)$id] = ['items' => $ids($block['itens'] ?? null), 'scenes' => $ids($block['cenas'] ?? null),
                    'media' => !empty($block['midia']), 'spatial' => !empty($block['espacial'])];
            }
        }
        return $known;
    }

    /**
     * What the aggregates read from one row (the payload is decoded here and dropped).
     *
     * A block outside the structure drops the row before its payload is decoded; an item seen, a scene of the map and
     * a destination scene outside their block are left out, and so is a decision on an item outside it or of a kind
     * the report does not know, or with an answer longer than analysis_bounds::MAX_RESPONSE: what the aggregates keep
     * is bounded by the structure, whatever was stored. A media of unknown duration (0), or a video extension on a
     * block the structure does not mark as media, gives no video: the summary stays out of the media math. A heatmap
     * on a block the structure does not mark as spatial gives no map.
     *
     * @param \stdClass $row Row.
     * @param array|null $known known() of the structure (null: no filter).
     * @return array|null reg, block, type, summary (bool); for a summary: interactions, items, video, map; for a
     *     decision: item, kind, option, dest. Null when the row is ignored: unreadable payload, summary outside the
     *     limits of analysis_bounds (its result: duration and extensions), or ids outside the structure.
     */
    private static function fact(\stdClass $row, ?array $known): ?array {
        $block = (string)$row->smblock;
        if ($known !== null && !isset($known[$block])) {
            return null;
        }
        $st = json_decode((string)$row->payload, true);
        if (!is_array($st)) {
            return null;
        }
        $result = is_array($st['result'] ?? null) ? $st['result'] : [];
        $ext = is_array($result['extensions'] ?? null) ? $result['extensions'] : [];
        if ($row->smkind === 'bloco') {
            // The limits are checked over the object form, as at the ingestion (a JSON object is never a list there).
            $object = json_decode((string)$row->payload, false);
            if (analysis_bounds::summary_violation($object->result ?? null) !== null) {
                return null;
            }
        }
        $fact = [
            'reg' => (int)$row->regid,
            'block' => $block,
            'type' => $row->smblocktype === null ? null : (string)$row->smblocktype,
            'summary' => $row->smkind === 'bloco',
        ];
        if ($fact['summary']) {
            $summary = is_array($ext[vocab::SM_EXT_SUMMARY] ?? null) ? $ext[vocab::SM_EXT_SUMMARY] : [];
            $items = [];
            foreach (is_array($summary['itensVistos'] ?? null) ? $summary['itensVistos'] : [] as $item) {
                if (is_scalar($item) && ($known === null || isset($known[$block]['items'][(string)$item]))) {
                    $items[] = (string)$item;
                }
            }
            $fact['interactions'] = self::count_value($summary['cliques'] ?? 0) + self::count_value($summary['aberturas'] ?? 0);
            $fact['items'] = $items;
            $video = self::video($ext[vocab::SM_EXT_VIDEO] ?? null);
            $media = $known === null || $known[$block]['media'];
            $fact['video'] = $media && $video !== null && $video['duration'] > 0 ? $video : null;
            $spatial = $known === null || $known[$block]['spatial'];
            $map = $spatial && is_array($ext[vocab::SM_EXT_HEATMAP] ?? null) ? $ext[vocab::SM_EXT_HEATMAP] : null;
            if ($map !== null && $known !== null && is_array($map['cenas'] ?? null)) {
                $map['cenas'] = array_intersect_key($map['cenas'], $known[$block]['scenes']);
            }
            $fact['map'] = $map;
            return $fact;
        }
        $item = (string)$row->smitem;
        $context = is_array($st['context']['extensions'] ?? null) ? $st['context']['extensions'] : [];
        $kind = $context[vocab::SM_EXT_DECISIONTYPE] ?? '';
        $response = $result['response'] ?? null;
        if (
            ($known !== null && !isset($known[$block]['items'][$item]))
                || !in_array($kind, [self::KNOW, self::CHOICE, self::ENDING, self::FOUND], true)
                || (is_string($response) && \core_text::strlen($response) > analysis_bounds::MAX_RESPONSE)
        ) {
            return null;
        }
        $dest = $ext[vocab::SM_EXT_DESTSCENE] ?? null;
        $fact['item'] = $item;
        $fact['kind'] = $kind;
        $fact['option'] = is_scalar($response) ? (string)$response : '';
        $fact['dest'] = is_string($dest) && $dest !== '' && ($known === null || isset($known[$block]['items'][$dest]))
            ? $dest : null;
        return $fact;
    }

    /**
     * The video extension of a summary in tenths of a second.
     *
     * @param mixed $video Decoded extension.
     * @return array|null duration (tenths), segments (packed start * SHIFT + end, in tenths, unmerged), pct (?int,
     *     the percentualAssistido the player computed from the original segments).
     */
    private static function video(mixed $video): ?array {
        if (!is_array($video)) {
            return null;
        }
        $segments = [];
        foreach (is_array($video['trechosAssistidos'] ?? null) ? $video['trechosAssistidos'] : [] as $s) {
            if (is_array($s) && count($s) === 2 && self::finite($s[0] ?? null) && self::finite($s[1] ?? null)) {
                $a = (int)round((float)$s[0] * self::TENTHS);
                $b = (int)round((float)$s[1] * self::TENTHS);
                if ($b > $a && $a >= 0 && $b < self::SHIFT) {
                    $segments[] = $a * self::SHIFT + $b;
                }
            }
        }
        $duration = $video['duracaoMidia'] ?? 0;
        $pct = $video['percentualAssistido'] ?? null;
        return [
            'duration' => self::finite($duration) ? max(0, (int)round((float)$duration * self::TENTHS)) : 0,
            'segments' => $segments,
            'pct' => self::finite($pct) ? max(0, min(100, (int)floor((float)$pct + 0.5))) : null,
        ];
    }

    /**
     * Whether a decoded JSON value is a finite number.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function finite(mixed $value): bool {
        return (is_int($value) || is_float($value)) && is_finite((float)$value);
    }

    /**
     * A count from the summary (non-negative integer; anything else is 0).
     *
     * @param mixed $value Value.
     * @return int
     */
    private static function count_value(mixed $value): int {
        return self::finite($value) ? max(0, (int)$value) : 0;
    }

    /**
     * Empty state of a block of the class.
     *
     * @return array
     */
    private static function new_block(): array {
        return [
            'type' => null, 'decisiontype' => null, 'interactions' => 0, 'items' => [], 'covering' => [], 'skipped' => [],
            'dropped' => [], 'sumpct' => 0, 'cells' => null, 'scenes' => [], 'know' => [], 'scenario' => false,
            'choices' => [], 'endings' => [], 'dropoff' => [], 'found' => [],
        ];
    }

    /**
     * Empty state of one learner in one block.
     *
     * @return array
     */
    private static function new_learner(): array {
        return ['items' => [], 'video' => false, 'segments' => [], 'merged' => 0, 'pct' => null, 'last' => [],
            'choice' => null, 'ending' => null];
    }

    /**
     * Adds one row to the block (what is summed over every statement) and to the learner (what is per learner).
     *
     * @param array $b State of the block.
     * @param array $l State of the learner in the block.
     * @param array $fact The row, from fact().
     */
    private static function take(array &$b, array &$l, array $fact): void {
        if (!$fact['summary']) {
            $b['decisiontype'] ??= $fact['type'];
            $l['last'][$fact['item']][$fact['kind']] = $fact['option'];
            if ($fact['kind'] === self::CHOICE) {
                $l['choice'] = $fact['dest'] ?? $fact['item'];
            } else if ($fact['kind'] === self::ENDING) {
                $l['ending'] = $fact['option'];
            }
            return;
        }
        $b['type'] ??= $fact['type'];
        $b['interactions'] += $fact['interactions'];
        foreach ($fact['items'] as $item) {
            $l['items'][$item] = true;
        }
        $video = $fact['video'];
        if ($video !== null) {
            $l['video'] = true;
            self::gather($l['segments'], $l['merged'], $video['segments']);
            if ($video['pct'] !== null) {
                $l['pct'] = max($l['pct'] ?? 0, $video['pct']);
            }
        }
        $map = $fact['map'];
        if ($map !== null) {
            $b['cells'] ??= [];
            foreach (is_array($map['celulas'] ?? null) ? $map['celulas'] : [] as $cell => $n) {
                $b['cells'][(string)$cell] = ($b['cells'][(string)$cell] ?? 0) + self::count_value($n);
            }
            foreach (is_array($map['cenas'] ?? null) ? $map['cenas'] : [] as $scene => $cells) {
                foreach (is_array($cells) ? $cells : [] as $cell => $n) {
                    $b['scenes'][(string)$scene][(string)$cell] = ($b['scenes'][(string)$scene][(string)$cell] ?? 0)
                        + self::count_value($n);
                }
            }
        }
    }

    /**
     * Adds what one learner did in each block to the counts of the blocks (distinct learners, last decisions, the
     * bands of the media); the state of the learner is then dropped.
     *
     * @param array $blocks States of the blocks.
     * @param array $state smblock => state of the learner.
     * @param int[] $durations smblock => duration of the media, tenths (first pass).
     */
    private static function fold_learner(array &$blocks, array $state, array $durations): void {
        foreach ($state as $block => $l) {
            $b = &$blocks[$block];
            foreach (array_keys($l['items']) as $item) {
                $item = (string)$item;
                $b['items'][$item] = ($b['items'][$item] ?? 0) + 1;
            }
            $duration = (int)($durations[$block] ?? 0);
            if ($l['video'] && $duration > 0) {
                self::fold_media($b, self::union($l['segments']), $l['pct'], $duration);
            }
            foreach ($l['last'] as $item => $kinds) {
                $item = (string)$item;
                foreach ($kinds as $kind => $option) {
                    $kind = (string)$kind;
                    if ($kind === self::KNOW) {
                        $b['know'][$item] ??= ['sei' => 0, 'naoSei' => 0];
                        $b['know'][$item][$option === 'sei' ? 'sei' : 'naoSei']++;
                    } else if ($kind === self::CHOICE) {
                        $b['scenario'] = true;
                        $b['choices'][$item][$option] = ($b['choices'][$item][$option] ?? 0) + 1;
                    } else if ($kind === self::ENDING) {
                        $b['scenario'] = true;
                    } else if ($kind === self::FOUND) {
                        $b['found'][$item] = ($b['found'][$item] ?? 0) + 1;
                    }
                }
            }
            if ($l['ending'] !== null) {
                $b['endings'][$l['ending']] = ($b['endings'][$l['ending']] ?? 0) + 1;
            } else if ($l['choice'] !== null) {
                $b['dropoff'][$l['choice']] = ($b['dropoff'][$l['choice']] ?? 0) + 1;
            }
            unset($b);
        }
    }

    /**
     * Adds segments to those of one learner, merging them into their union once the unmerged part grows: the list
     * then never holds much more than twice the union.
     *
     * @param int[] $segments Packed segments so far (updated).
     * @param int $merged Size of the list after the last merge (updated).
     * @param int[] $new Packed segments to add.
     */
    private static function gather(array &$segments, int &$merged, array $new): void {
        array_push($segments, ...$new);
        if (count($segments) > max(self::MERGE_AT, 2 * $merged)) {
            $segments = self::union($segments);
            $merged = count($segments);
        }
    }

    /**
     * The union of segments: merged intervals, sorted (touching intervals merge; gaps are never filled).
     *
     * @param int[] $segments Packed segments (start * SHIFT + end, tenths): sorting the integers sorts by start, end.
     * @return int[] Packed, sorted, disjoint.
     */
    private static function union(array $segments): array {
        sort($segments, SORT_NUMERIC);
        $out = [];
        $last = -1;
        $end = 0;
        foreach ($segments as $code) {
            $a = intdiv($code, self::SHIFT);
            $b = $code % self::SHIFT;
            if ($last >= 0 && $a <= $end) {
                $end = max($end, $b);
                $out[$last] = intdiv($out[$last], self::SHIFT) * self::SHIFT + $end;
            } else {
                $out[] = $code;
                $last++;
                $end = $b;
            }
        }
        return $out;
    }

    /**
     * Coverage of one learner: tenths watched inside the media and which bands are covered.
     *
     * @param int[] $union Merged segments, packed (tenths).
     * @param int $duration Media duration, tenths.
     * @return array [int tenths covered, bool[] covered per band].
     */
    private static function coverage(array $union, int $duration): array {
        $width = self::BAND * self::TENTHS;
        $bands = intdiv($duration + $width - 1, $width);
        $overlap = array_fill(0, $bands, 0);
        $covered = 0;
        foreach ($union as $code) {
            $a = intdiv($code, self::SHIFT);
            $b = min($code % self::SHIFT, $duration);
            if ($b <= $a) {
                continue;
            }
            $covered += $b - $a;
            for ($k = intdiv($a, $width); $k < $bands && $k * $width < $b; $k++) {
                $overlap[$k] += min($b, ($k + 1) * $width) - max($a, $k * $width);
            }
        }
        $is = [];
        foreach ($overlap as $k => $o) {
            $length = min(($k + 1) * $width, $duration) - $k * $width;
            $is[] = $o >= min(self::TENTHS, $length);
        }
        return [$covered, $is];
    }

    /**
     * Percentage of the media covered by the union of the segments: min(100, floor(100·covered/duration + 0.5)).
     *
     * @param int $covered Tenths covered.
     * @param int $duration Duration, tenths.
     * @return int
     */
    private static function watched(int $covered, int $duration): int {
        return $duration > 0 ? min(100, intdiv(200 * $covered + $duration, 2 * $duration)) : 0;
    }

    /**
     * Folds the media of one learner into the counters of the block (rule 5): the bands they covered, those they
     * skipped before their last covered band, that band as a drop when it is not the last one, and their % — the
     * larger of the union of their segments and the best % their statements reported.
     *
     * @param array $b State of the block (covering, skipped, dropped, sumpct; updated).
     * @param int[] $union Merged segments of the learner, packed (tenths).
     * @param int|null $pct Best % the statements of the learner reported.
     * @param int $duration Duration of the media, tenths.
     */
    private static function fold_media(array &$b, array $union, ?int $pct, int $duration): void {
        [$covered, $is] = self::coverage($union, $duration);
        $bands = count($is);
        if ($b['covering'] === []) {
            $b['covering'] = array_fill(0, $bands, 0);
            $b['skipped'] = array_fill(0, $bands, 0);
            $b['dropped'] = array_fill(0, $bands, 0);
        }
        $b['sumpct'] += max(self::watched($covered, $duration), $pct ?? 0);
        $lastcovered = -1;
        foreach ($is as $k => $yes) {
            if ($yes) {
                $b['covering'][$k]++;
                $lastcovered = $k;
            }
        }
        for ($k = 0; $k < $lastcovered; $k++) {
            if (!$is[$k]) {
                $b['skipped'][$k]++;
            }
        }
        if ($lastcovered >= 0 && $lastcovered < $bands - 1) {
            $b['dropped'][$lastcovered]++;
        }
    }

    /**
     * The media part of a block aggregate (rule 5), from the counters folded learner by learner.
     *
     * @param int $duration Largest duration reported, tenths.
     * @param array $b State of the block.
     * @param int $reached Learners who reached the block.
     * @return array
     */
    private static function media(int $duration, array $b, int $reached): array {
        return [
            'duracaoMidia' => $duration % self::TENTHS === 0 ? intdiv($duration, self::TENTHS) : $duration / self::TENTHS,
            'faixaSegundos' => self::BAND,
            'retencao' => array_map(static fn(int $n): int => self::pct($n, $reached), $b['covering']),
            'pulados' => $b['skipped'],
            'abandonos' => $b['dropped'],
            'percentualMedioAssistido' => self::mean($b['sumpct'], $reached),
        ];
    }
}
