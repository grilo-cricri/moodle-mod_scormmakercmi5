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
 * xAPI timestamps (ISO 8601 date-time) as milliseconds since the epoch, UTC (cmi5 §9.7).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class xapi_time {
    /** @var string Extended ISO 8601 date-time; the offset is optional (xAPI: "SHOULD include"); D refuses a trailing newline. */
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:[.,](\d+))?(Z|[+-]\d{2}(?::?\d{2})?)?$/iD';

    /**
     * Milliseconds of a timestamp; a fraction below the millisecond is truncated; no offset means UTC.
     *
     * @param string $iso Timestamp.
     * @return int
     * @throws \InvalidArgumentException When it is not a valid date-time.
     */
    public static function to_ms(string $iso): int {
        [$fields, $offsetms] = self::parse($iso);
        [$year, $month, $day, $hour, $minute, $second, $fraction] = $fields;
        $date = new \DateTimeImmutable(
            sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            new \DateTimeZone('UTC')
        );
        $ms = (int)substr(str_pad($fraction, 3, '0'), 0, 3);
        return $date->getTimestamp() * 1000 + $ms - $offsetms;
    }

    /**
     * Whether a timestamp is valid and in UTC: "Z" or a zero offset (not "-00:00", which means unknown offset).
     *
     * @param string $iso Timestamp.
     * @return bool
     */
    public static function is_utc(string $iso): bool {
        try {
            [, $offsetms, $zone] = self::parse($iso);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
        return $zone !== '' && $offsetms === 0 && $zone[0] !== '-';
    }

    /**
     * Timestamp text of milliseconds: UTC with milliseconds and "Z".
     *
     * @param int $ms Milliseconds since the epoch.
     * @return string
     */
    public static function from_ms(int $ms): string {
        $seconds = intdiv($ms, 1000);
        $rest = $ms % 1000;
        if ($rest < 0) {
            $seconds--;
            $rest += 1000;
        }
        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $rest);
    }

    /**
     * Now in milliseconds, from the clock of the dependency container (tests can freeze it).
     *
     * @return int
     */
    public static function now_ms(): int {
        $now = \core\di::get(\core\clock::class)->now();
        return $now->getTimestamp() * 1000 + intdiv((int)$now->format('u'), 1000);
    }

    /**
     * Splits and checks a timestamp.
     *
     * @param string $iso Timestamp.
     * @return array [[year, month, day, hour, minute, second, fraction digits], offset in ms, zone text].
     * @throws \InvalidArgumentException
     */
    private static function parse(string $iso): array {
        if (!preg_match(self::PATTERN, $iso, $m)) {
            throw new \InvalidArgumentException('Not an ISO 8601 date-time');
        }
        [, $year, $month, $day, $hour, $minute, $second] = array_map('intval', array_slice($m, 0, 7));
        $fraction = $m[7] ?? '';
        $zone = strtoupper($m[8] ?? '');
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            throw new \InvalidArgumentException('Date or time out of range');
        }
        $offsetms = 0;
        if ($zone !== '' && $zone !== 'Z') {
            $digits = str_replace(':', '', substr($zone, 1));
            $offhours = (int)substr($digits, 0, 2);
            $offminutes = (int)substr($digits, 2, 2);
            if ($offhours > 23 || $offminutes > 59) {
                throw new \InvalidArgumentException('Offset out of range');
            }
            $offsetms = ($offhours * 60 + $offminutes) * 60000 * ($zone[0] === '-' ? -1 : 1);
        }
        return [[$year, $month, $day, $hour, $minute, $second, $fraction], $offsetms, $zone];
    }
}
