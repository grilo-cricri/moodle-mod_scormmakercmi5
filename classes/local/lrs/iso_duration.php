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

/**
 * ISO 8601:2004 4.4.3.2 durations of xAPI results (xAPI 1.0.3 Part Two §4.6, cmi5 §9.5.4) as milliseconds.
 *
 * Grammar: PnW, or PnYnMnDTnHnMnS with any non-empty subset of the components; only the lowest-order component
 * present may have a decimal fraction ("." or ","). Every such duration is valid (is_valid). Years and months have
 * no fixed length, so a duration where they are not zero has no milliseconds: to_ms gives null ("unknown"), and
 * so does a duration beyond the INT(15) columns. Callers store null and never refuse the statement for it.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class iso_duration {
    /** @var string One component value: digits with an optional fraction. */
    private const N = '(\d+(?:[.,]\d+)?)';

    /** @var string PnW, or PnYnMnDTnHnMnS; the D modifier refuses a trailing newline. */
    private const PATTERN = '/^P(?:' . self::N . 'W|(?:' . self::N . 'Y)?(?:' . self::N . 'M)?(?:' . self::N . 'D)?'
        . '(?:T(?:' . self::N . 'H)?(?:' . self::N . 'M)?(?:' . self::N . 'S)?)?)$/D';

    /** @var int[] Milliseconds of each component group of PATTERN (1 = weeks … 7 = seconds; 0 = years/months). */
    private const UNIT_MS = [1 => 604800000, 2 => 0, 3 => 0, 4 => 86400000, 5 => 3600000, 6 => 60000, 7 => 1000];

    /** @var int Largest value the INT(15) duration columns hold. */
    private const MAX_MS = 999999999999999;

    /**
     * Whether a text is an ISO 8601 duration of the 4.4.3.2 format.
     *
     * @param string $d Duration.
     * @return bool
     */
    public static function is_valid(string $d): bool {
        return self::components($d) !== null;
    }

    /**
     * Milliseconds of a duration, or null when it has no fixed length (years or months not zero) or is too long.
     *
     * @param string $d Duration.
     * @return int|null
     * @throws \InvalidArgumentException When it is not an ISO 8601 duration.
     */
    public static function to_ms(string $d): ?int {
        $components = self::components($d);
        if ($components === null) {
            throw new \InvalidArgumentException('Not an ISO 8601 duration');
        }
        $total = 0.0;
        foreach ($components as $group => $value) {
            $unit = self::UNIT_MS[$group];
            if ($unit === 0) {
                if ((float)str_replace(',', '.', $value) !== 0.0) {
                    return null;
                }
                continue;
            }
            [$int, $fraction] = array_pad(preg_split('/[.,]/', $value), 2, '');
            $total += (float)$int * $unit + ($fraction === '' ? 0 : (float)('0.' . $fraction) * $unit);
        }
        $total = round($total);
        return $total > self::MAX_MS ? null : (int)$total;
    }

    /**
     * The components present, group number => value text; null when the text does not follow the format.
     *
     * @param string $d Duration.
     * @return array|null
     */
    private static function components(string $d): ?array {
        if (!preg_match(self::PATTERN, $d, $m) || $d === 'P' || str_ends_with($d, 'T')) {
            return null;
        }
        $present = [];
        for ($group = 1; $group <= 7; $group++) {
            if (($m[$group] ?? '') !== '') {
                $present[$group] = $m[$group];
            }
        }
        $values = array_values($present);
        array_pop($values);
        foreach ($values as $value) {
            if (strpbrk($value, '.,') !== false) {
                return null;
            }
        }
        return $present;
    }

    /**
     * Shortest duration of milliseconds, with hours as the largest unit ("PT1H2M3.5S"); negative gives "PT0S".
     *
     * @param int $ms Milliseconds.
     * @return string
     */
    public static function from_ms(int $ms): string {
        $ms = max(0, $ms);
        $hours = intdiv($ms, 3600000);
        $minutes = intdiv($ms % 3600000, 60000);
        $seconds = intdiv($ms % 60000, 1000);
        $rest = $ms % 1000;
        $out = 'PT';
        if ($hours) {
            $out .= $hours . 'H';
        }
        if ($minutes) {
            $out .= $minutes . 'M';
        }
        if ($seconds || $rest || $out === 'PT') {
            $out .= $seconds . ($rest ? rtrim(sprintf('.%03d', $rest), '0') : '') . 'S';
        }
        return $out;
    }
}
