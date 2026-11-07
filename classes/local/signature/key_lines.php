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
 * Line parsing of the trusted keys and revoked kids settings (design §5.3).
 *
 * Shared by the admin settings (validation) and the keyring (runtime); plain PHP, no admin library.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class key_lines {
    /** @var string Allowed key identifier. */
    public const KID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    /** @var int Size of an Ed25519 public key, in bytes. */
    public const KEY_BYTES = 32;

    /**
     * Whether a base64 text is the strict, canonical encoding of exactly 32 bytes.
     *
     * @param string $b64 Base64 text.
     * @return bool
     */
    public static function is_public_key(string $b64): bool {
        $bytes = base64_decode($b64, true);
        return $bytes !== false && strlen($bytes) === self::KEY_BYTES && base64_encode($bytes) === $b64;
    }

    /**
     * Reads "kid=base64" lines. Blank lines are ignored and spaces around each part trimmed. Bad lines, kids of
     * embedded keys and every line of a repeated kid are left out of the keys.
     *
     * @param string $raw Text of the setting.
     * @return array ['keys' => kid => base64, 'error' => null or ['key' => lang string key, 'line' => int]]
     *     where error is the first bad line.
     */
    public static function trusted(string $raw): array {
        $keys = [];
        $repeated = [];
        $error = null;
        foreach (self::lines($raw) as $number => $line) {
            $lineerror = null;
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                $lineerror = 'err_trustedkeys_line';
            } else {
                $kid = trim($parts[0]);
                $key = trim($parts[1]);
                if (!preg_match(self::KID_PATTERN, $kid)) {
                    $lineerror = 'err_trustedkeys_kid';
                } else if (array_key_exists($kid, keyring::EMBEDDED)) {
                    $lineerror = 'err_trustedkeys_embedded';
                } else if (!self::is_public_key($key)) {
                    $lineerror = 'err_trustedkeys_key';
                } else if (array_key_exists($kid, $keys) || isset($repeated[$kid])) {
                    $lineerror = 'err_trustedkeys_dup';
                    $repeated[$kid] = true;
                    unset($keys[$kid]);
                } else {
                    $keys[$kid] = $key;
                }
            }
            if ($lineerror !== null && $error === null) {
                $error = ['key' => $lineerror, 'line' => $number];
            }
        }
        return ['keys' => $keys, 'error' => $error];
    }

    /**
     * Reads one kid per line. Blank lines are ignored, bad lines left out and repetitions merged.
     *
     * @param string $raw Text of the setting.
     * @return array ['kids' => string[], 'error' => null or ['key' => lang string key, 'line' => int]]
     */
    public static function revoked(string $raw): array {
        $kids = [];
        $error = null;
        foreach (self::lines($raw) as $number => $line) {
            if (preg_match(self::KID_PATTERN, $line)) {
                $kids[$line] = $line;
            } else if ($error === null) {
                $error = ['key' => 'err_revokedkids_kid', 'line' => $number];
            }
        }
        return ['kids' => array_values($kids), 'error' => $error];
    }

    /**
     * Non-blank trimmed lines, keyed by their 1-based line number.
     *
     * @param string $raw Text.
     * @return array
     */
    private static function lines(string $raw): array {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $index => $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[$index + 1] = $line;
            }
        }
        return $lines;
    }
}
