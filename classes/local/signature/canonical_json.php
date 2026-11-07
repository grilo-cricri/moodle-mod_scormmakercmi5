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

/**
 * Canonical JSON form of the signed manifest (design §5.2).
 *
 * Same contract as backend/courses/assinatura.py and src/export/assinatura/jsonCanonico.ts in SCORM Maker; the
 * shared vectors are tests/fixtures/vetores_json_canonico.json. The format has only strings, integers, lists and
 * objects: object keys sorted by their UTF-8 bytes, no whitespace, strings escaping only backslash, double quote
 * and U+0000..U+001F (as lower-case \u00xx).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class canonical_json {
    /**
     * Encodes a value decoded with json_decode($raw, false).
     *
     * @param mixed $value stdClass (object), list (array), int or string.
     * @return string
     * @throws \InvalidArgumentException For float (including integers above PHP_INT_MAX), bool, null, associative
     *     arrays, other objects and strings that are not valid UTF-8.
     */
    public static function encode(mixed $value): string {
        if (is_string($value)) {
            return self::string($value);
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new \InvalidArgumentException('canonical JSON: associative array (decode objects as stdClass)');
            }
            return '[' . implode(',', array_map([self::class, 'encode'], $value)) . ']';
        }
        if ($value instanceof \stdClass) {
            $pairs = [];
            foreach (get_object_vars($value) as $key => $item) {
                // PHP turns the key "1" into the integer 1; the canonical form needs it back as the string it was.
                $pairs[] = [(string)$key, $item];
            }
            usort($pairs, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
            $parts = [];
            foreach ($pairs as [$key, $item]) {
                $parts[] = self::string($key) . ':' . self::encode($item);
            }
            return '{' . implode(',', $parts) . '}';
        }
        throw new \InvalidArgumentException('canonical JSON: unsupported ' . get_debug_type($value));
    }

    /**
     * Quotes a string: escapes only backslash, double quote and control characters below U+0020.
     *
     * @param string $value UTF-8 string.
     * @return string
     * @throws \InvalidArgumentException When the string is not valid UTF-8.
     */
    private static function string(string $value): string {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('canonical JSON: string is not valid UTF-8');
        }
        $escaped = preg_replace_callback(
            '/[\\\\"\x00-\x1F]/',
            static function (array $match): string {
                $char = $match[0];
                if ($char === '\\' || $char === '"') {
                    return '\\' . $char;
                }
                return sprintf('\\u%04x', ord($char));
            },
            $value
        );
        return '"' . $escaped . '"';
    }
}
