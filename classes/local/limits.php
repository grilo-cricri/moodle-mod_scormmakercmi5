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

/**
 * Configurable limits (design §7.4, NF6), read from the plugin settings.
 *
 * A setting that is missing, not a whole number or not positive falls back to its default.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class limits {
    /** @var int Bytes in a kilobyte. */
    private const KB = 1024;

    /** @var int Bytes in a megabyte. */
    private const MB = 1048576;

    /**
     * Maximum number of entries in a package zip.
     *
     * @return int
     */
    public static function max_entries(): int {
        return self::positive('maxentries', 20000);
    }

    /**
     * Maximum sum of the uncompressed sizes of a package zip, in bytes.
     *
     * @return int
     */
    public static function max_unzipped_bytes(): int {
        return self::positive('maxunzippedmb', 1024) * self::MB;
    }

    /**
     * Maximum size of an LRS request body, in bytes.
     *
     * @return int
     */
    public static function max_body_bytes(): int {
        return self::positive('maxbodykb', 1024) * self::KB;
    }

    /**
     * Maximum number of statements in one POST.
     *
     * @return int
     */
    public static function max_batch(): int {
        return self::positive('maxbatch', 100);
    }

    /**
     * Maximum size of one serialised statement, in bytes.
     *
     * @return int
     */
    public static function max_statement_bytes(): int {
        return self::positive('maxstatementkb', 64) * self::KB;
    }

    /**
     * Maximum number of statements stored per registration.
     *
     * @return int
     */
    public static function max_statements_per_reg(): int {
        return self::positive('maxstatementsperreg', 10000);
    }

    /**
     * Maximum number of analysis statements (block summaries and decisions, subproject D) stored per registration.
     * They do not count in max_statements_per_reg(); above this one they are skipped without an error.
     *
     * @return int
     */
    public static function max_analysis_per_reg(): int {
        return self::positive('maxanalysisperreg', 20000);
    }

    /**
     * Whether a registration reached maxstatementsperreg: the AU's statements are refused from now on (403), until
     * the attempt is deleted (final review N-2).
     *
     * @param int $statementcount reg.statementcount.
     * @return bool
     */
    public static function statement_limit_reached(int $statementcount): bool {
        return $statementcount >= self::max_statements_per_reg();
    }

    /**
     * Maximum size of one State API document, in bytes.
     *
     * @return int
     */
    public static function max_state_bytes(): int {
        return self::positive('maxstatekb', 1024) * self::KB;
    }

    /**
     * Maximum number of State API documents the AU may create per learner, activity and registration (LMS.LaunchData
     * not counted). Replacing or merging into an existing document is always allowed.
     *
     * @return int
     */
    public static function max_state_docs(): int {
        return self::positive('maxstatedocs', 100);
    }

    /**
     * Seconds without activity before a session is abandoned and its token expires.
     *
     * @return int
     */
    public static function session_timeout_seconds(): int {
        return self::positive('sessiontimeout', 8 * 3600);
    }

    /**
     * Largest package, in bytes, whose full SHA-256 recheck runs synchronously.
     *
     * @return int
     */
    public static function recheck_max_bytes(): int {
        return self::positive('recheckmaxmb', 100) * self::MB;
    }

    /**
     * Least time, in seconds, between two background full rechecks of a package above recheck_max_bytes() queued by
     * openings of the rich report.
     *
     * @return int
     */
    public static function recheck_min_interval_seconds(): int {
        return self::positive('recheckminhours', 24) * 3600;
    }

    /**
     * Whether "initialized" requires the AU to have read the learner preferences first.
     *
     * @return bool
     */
    public static function require_prefs_read(): bool {
        $value = get_config('mod_scormmakercmi5', 'requireprefsread');
        if ($value === false || $value === null || $value === '') {
            return true;
        }
        return (bool)(int)$value;
    }

    /**
     * Reads a positive whole-number setting.
     *
     * @param string $name Setting name.
     * @param int $default Default value.
     * @return int
     */
    private static function positive(string $name, int $default): int {
        $value = get_config('mod_scormmakercmi5', $name);
        if (!is_string($value) && !is_int($value)) {
            return $default;
        }
        $value = trim((string)$value);
        if (!preg_match('/^\d{1,9}$/', $value) || (int)$value <= 0) {
            return $default;
        }
        return (int)$value;
    }
}
