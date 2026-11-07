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

namespace mod_scormmakercmi5\local;

use mod_scormmakercmi5\local\cmi5_vocab as vocab;

/**
 * The limits of the values a learner sends in a block summary of the activity analysis (subproject D, audit M-D1).
 *
 * The same limits as the hosted course of SCORM Maker (contract tests/fixtures/contrato-dados-ricos.json, case
 * "valores-fora-do-limite"). The LRS refuses a block summary outside them with a 400, and the block aggregator ignores
 * a stored one entirely (rows stored before the limits existed): the report never allocates from a learner's numbers.
 *
 * Checked over the extensions in object form (json_decode(…, false)), so a JSON object and a list never get mixed up
 * and the size is measured on what was sent:
 * - the compact JSON (unescaped slashes and Unicode, zero fraction kept) of the object of the extensions resumo-do-bloco,
 *   video and mapa-de-calor that are present, in that order, keyed by their IRIs: at most 8192 bytes;
 * - resumo-do-bloco (an object): cliques and aberturas integers 0..100000; itensVistos a list of at most 500 strings;
 *   itensTotal an integer 0..500;
 * - video (an object): duracaoMidia a number, 0 < d <= 86400 s, or 0 (unknown duration, streaming) only when
 *   trechosAssistidos is absent or empty; trechosAssistidos a list of at most 40 segments, each a list [ini, fim] of
 *   numbers with 0 <= ini <= fim <= duracaoMidia; pulos and pausas integers 0..100000;
 * - mapa-de-calor (an object): grade 10; celulas (and each scene of cenas, an object of objects) cell keys "l,c" with
 *   0 <= l, c <= 9 and counts integers 0..100000 (an empty list [] is taken as an empty map).
 * An integer is a JSON number with no fractional part (3 or 3.0). A field that is absent is not checked (except
 * duracaoMidia and grade, which a video and a map must have). Any other field is not checked.
 *
 * Final wave W2a (final review M-1, M-2, M-3, M-6), the same rules as the hosted course (contract limites.regra):
 * - video.percentualAssistido, when present, is a finite number 0..100 (not necessarily whole);
 * - result.duration of a summary, when present, is only PT<n>S or PT<n>.<d>S (ASCII digits, a decimal point) up to
 *   86400 s, and its milliseconds come from the decimal text, never a float (duration_ms());
 * - a summary or a decision of the analysis has a timestamp and is at most 16384 bytes as a whole (statement_store
 *   checks these two, they are not in the result).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class analysis_bounds {
    /** @var int Longest media, seconds. */
    public const MAX_MEDIA_SECONDS = 86400;

    /** @var int Segments of one summary (the cap of the player, MAXIMO_DE_TRECHOS). */
    public const MAX_SEGMENTS = 40;

    /** @var int Largest count (cliques, aberturas, pulos, pausas, heatmap cells). */
    public const MAX_COUNT = 100000;

    /** @var int Largest itensVistos and itensTotal. */
    public const MAX_ITEMS = 500;

    /** @var int Side of the heatmap grid. */
    public const GRID = 10;

    /** @var int Largest JSON of the summary extensions together, bytes. */
    public const MAX_EXTENSION_BYTES = 8192;

    /** @var int Longest answer of a decision (result.response), in characters. */
    public const MAX_RESPONSE = 500;

    /** @var int Largest percentualAssistido. */
    public const MAX_PERCENT = 100;

    /** @var int Longest result.duration of a block summary, seconds. */
    public const MAX_SUMMARY_SECONDS = 86400;

    /** @var int Largest summary or decision of the analysis, the whole statement as sent, bytes. */
    public const MAX_STATEMENT_BYTES = 16384;

    /** @var string The only form of the duration of a block summary (the one the player writes, duracaoIso). */
    private const SUMMARY_DURATION = '/^PT([0-9]+)(?:\.([0-9]+))?S$/D';

    /** @var int JSON flags of the size measure. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @var string Cell key of the heatmap. */
    private const CELL = '/^[0-9],[0-9]$/D';

    /**
     * The limit a decision of the analysis breaks, or null: result.response, when it is text, has at most 500
     * characters (Unicode code points, as core_text::strlen counts them; not bytes).
     *
     * @param mixed $result result of the decision, in object form.
     * @return string|null "response", for the error message.
     */
    public static function decision_violation(mixed $result): ?string {
        $response = $result instanceof \stdClass ? ($result->response ?? null) : null;
        return is_string($response) && \core_text::strlen($response) > self::MAX_RESPONSE ? 'response' : null;
    }

    /**
     * Milliseconds of the result.duration of a block summary, or null when it does not follow the rule: PT<n>S or
     * PT<n>.<d>S up to 86400 s. Computed on the decimal text, never as a float: whole seconds * 1000, plus the first
     * three decimals, plus 1 when the fourth decimal is 5 or more (half up: PT1.0005S is 1001 ms). The hosted course
     * computes the same (publicacoes/lrs.py, duracao_do_resumo_ms), so the time column is identical on both sides.
     *
     * @param mixed $value result.duration.
     * @return int|null
     */
    public static function duration_ms(mixed $value): ?int {
        if (!is_string($value) || !preg_match(self::SUMMARY_DURATION, $value, $m)) {
            return null;
        }
        $whole = ltrim($m[1], '0');
        $fraction = $m[2] ?? '';
        if (strlen($whole) > 5 || (int)$whole > self::MAX_SUMMARY_SECONDS) {
            return null;
        }
        if ((int)$whole === self::MAX_SUMMARY_SECONDS && trim($fraction, '0') !== '') {
            return null;
        }
        $ms = (int)$whole * 1000 + (int)str_pad(substr($fraction, 0, 3), 3, '0');
        return $ms + (strlen($fraction) > 3 && $fraction[3] >= '5' ? 1 : 0);
    }

    /**
     * The first limit the result of a block summary breaks: the duration, when present (duration_ms()), then the
     * extensions (violation()). The ingestion and the aggregator both use it: what the LRS refuses, the report
     * ignores when it was stored before.
     *
     * @param mixed $result result of the summary, in object form.
     * @return string|null "duration" or the field of violation().
     */
    public static function summary_violation(mixed $result): ?string {
        if (!$result instanceof \stdClass) {
            return null;
        }
        if (property_exists($result, 'duration') && self::duration_ms($result->duration) === null) {
            return 'duration';
        }
        return self::violation($result->extensions ?? null);
    }

    /**
     * The first limit a block summary breaks, or null when it is inside every limit.
     *
     * @param mixed $extensions result.extensions of the summary, in object form (anything else: no extensions).
     * @return string|null The field outside its limit (e.g. "video.duracaoMidia"), for the error message.
     */
    public static function violation(mixed $extensions): ?string {
        if (!$extensions instanceof \stdClass) {
            return null;
        }
        $parts = [];
        foreach ([vocab::SM_EXT_SUMMARY, vocab::SM_EXT_VIDEO, vocab::SM_EXT_HEATMAP] as $iri) {
            if (property_exists($extensions, $iri)) {
                $parts[$iri] = $extensions->{$iri};
            }
        }
        if ($parts === []) {
            return null;
        }
        $json = json_encode((object)$parts, self::JSON_FLAGS);
        if ($json === false || strlen($json) > self::MAX_EXTENSION_BYTES) {
            return 'bytes';
        }
        if (array_key_exists(vocab::SM_EXT_SUMMARY, $parts)) {
            $bad = self::summary($parts[vocab::SM_EXT_SUMMARY]);
            if ($bad !== null) {
                return rtrim('resumo-do-bloco.' . $bad, '.');
            }
        }
        if (array_key_exists(vocab::SM_EXT_VIDEO, $parts)) {
            $bad = self::video($parts[vocab::SM_EXT_VIDEO]);
            if ($bad !== null) {
                return rtrim('video.' . $bad, '.');
            }
        }
        if (array_key_exists(vocab::SM_EXT_HEATMAP, $parts)) {
            $bad = self::heatmap($parts[vocab::SM_EXT_HEATMAP]);
            if ($bad !== null) {
                return rtrim('mapa-de-calor.' . $bad, '.');
            }
        }
        return null;
    }

    /**
     * The resumo-do-bloco extension.
     *
     * @param mixed $s Value.
     * @return string|null Field outside its limit.
     */
    private static function summary(mixed $s): ?string {
        if (!$s instanceof \stdClass) {
            return '';
        }
        foreach (['cliques', 'aberturas'] as $field) {
            if (property_exists($s, $field) && !self::count($s->{$field}, self::MAX_COUNT)) {
                return $field;
            }
        }
        if (property_exists($s, 'itensVistos')) {
            $items = $s->itensVistos;
            if (!is_array($items) || count($items) > self::MAX_ITEMS) {
                return 'itensVistos';
            }
            foreach ($items as $item) {
                if (!is_string($item)) {
                    return 'itensVistos';
                }
            }
        }
        if (property_exists($s, 'itensTotal') && !self::count($s->itensTotal, self::MAX_ITEMS)) {
            return 'itensTotal';
        }
        return null;
    }

    /**
     * The video extension.
     *
     * @param mixed $v Value.
     * @return string|null Field outside its limit.
     */
    private static function video(mixed $v): ?string {
        if (!$v instanceof \stdClass) {
            return '';
        }
        $d = $v->duracaoMidia ?? null;
        if (!self::number($d) || $d < 0 || $d > self::MAX_MEDIA_SECONDS) {
            return 'duracaoMidia';
        }
        // 0 is a media whose duration the player never learned (Infinity or NaN while streaming): only with no segment.
        if ($d == 0 && ($v->trechosAssistidos ?? []) !== []) {
            return 'duracaoMidia';
        }
        if (property_exists($v, 'trechosAssistidos')) {
            $segments = $v->trechosAssistidos;
            if (!is_array($segments) || count($segments) > self::MAX_SEGMENTS) {
                return 'trechosAssistidos';
            }
            foreach ($segments as $s) {
                if (
                    !is_array($s) || count($s) !== 2 || !self::number($s[0]) || !self::number($s[1])
                        || $s[0] < 0 || $s[0] > $s[1] || $s[1] > $d
                ) {
                    return 'trechosAssistidos';
                }
            }
        }
        foreach (['pulos', 'pausas'] as $field) {
            if (property_exists($v, $field) && !self::count($v->{$field}, self::MAX_COUNT)) {
                return $field;
            }
        }
        if (property_exists($v, 'percentualAssistido')) {
            $p = $v->percentualAssistido;
            if (!self::number($p) || $p < 0 || $p > self::MAX_PERCENT) {
                return 'percentualAssistido';
            }
        }
        return null;
    }

    /**
     * The mapa-de-calor extension.
     *
     * @param mixed $m Value.
     * @return string|null Field outside its limit.
     */
    private static function heatmap(mixed $m): ?string {
        if (!$m instanceof \stdClass) {
            return '';
        }
        $grade = $m->grade ?? null;
        if (!self::number($grade) || $grade != self::GRID) {
            return 'grade';
        }
        if (property_exists($m, 'celulas') && !self::cells($m->celulas)) {
            return 'celulas';
        }
        if (property_exists($m, 'cenas') && $m->cenas !== []) {
            if (!$m->cenas instanceof \stdClass) {
                return 'cenas';
            }
            foreach (get_object_vars($m->cenas) as $cells) {
                if (!self::cells($cells)) {
                    return 'cenas';
                }
            }
        }
        return null;
    }

    /**
     * Cells of a heatmap: an object "l,c" => count (an empty list is an empty map).
     *
     * @param mixed $cells Value.
     * @return bool
     */
    private static function cells(mixed $cells): bool {
        if ($cells === []) {
            return true;
        }
        if (!$cells instanceof \stdClass) {
            return false;
        }
        foreach (get_object_vars($cells) as $key => $n) {
            if (!preg_match(self::CELL, (string)$key) || !self::count($n, self::MAX_COUNT)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a decoded JSON value is a finite number.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function number(mixed $value): bool {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /**
     * Whether a decoded JSON value is an integer from 0 to $max (3.0 is the integer 3).
     *
     * @param mixed $value Value.
     * @param int $max Largest value.
     * @return bool
     */
    private static function count(mixed $value, int $max): bool {
        return self::number($value) && floor($value) == $value && $value >= 0 && $value <= $max;
    }
}
