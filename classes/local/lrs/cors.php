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

use mod_scormmakercmi5\local\package\cmi5_parser;

/**
 * CORS of lrs.php and fetch.php for an AU outside the package: only the exact origin of the AU (never "*", never an
 * echo of whatever Origin came), Vary: Origin, no credentials (the token goes in Authorization, no cookie is used).
 * Same origin as Moodle, or no Origin: no header, nothing changes.
 *
 * This only adds to the authorisation, never replaces it: a preflight answers nothing but the headers, and an
 * authenticated answer is checked after the token, with the AU of that token's session.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cors {
    /** @var string Methods of the LRS. */
    private const METHODS = 'GET, PUT, POST, DELETE';

    /** @var string Request headers an AU sends. */
    private const HEADERS = 'Authorization, Content-Type, X-Experience-API-Version, If-Match, If-None-Match';

    /** @var string Response headers the AU may read. */
    private const EXPOSE = 'X-Experience-API-Version, X-Experience-API-Consistent-Through, ETag, Last-Modified, '
        . 'Content-Type';

    /**
     * Answer to a preflight, or null when the origin is not, exactly, the one of an AU of this site.
     *
     * @param string|null $origin Origin header.
     * @return response|null
     */
    public static function preflight(?string $origin): ?response {
        global $DB;
        if (!self::canonical($origin)) {
            return null;
        }
        // Case-sensitive comparison in the database too: the collation of the site must not widen the match.
        $known = $DB->record_exists_select(
            'scormmakercmi5_au',
            $DB->sql_equal('auorigin', ':origin', true),
            ['origin' => $origin]
        );
        if (!$known) {
            return null;
        }
        return new response(204, '', [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => self::METHODS,
            'Access-Control-Allow-Headers' => self::HEADERS,
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ]);
    }

    /**
     * Headers of an authenticated answer for the AU of the session.
     *
     * @param \stdClass $au AU row of the session (auorigin is used).
     * @param string|null $origin Origin header.
     * @return array Header name => value; [] without Origin or from the origin of Moodle.
     * @throws lrs_exception 403 err_corsorigin for an outside origin that is not the one of the AU.
     */
    public static function for_au(\stdClass $au, ?string $origin): array {
        global $CFG;
        if ($origin === null || $origin === '' || self::same_origin($origin, $CFG->wwwroot)) {
            return [];
        }
        $auorigin = $au->auorigin ?? null;
        // The value "null" is what a browser sends for a same-origin no-cors POST (a form, sendBeacon) when the site sets
        // Referrer-Policy: no-referrer. For an AU of the package it is no outside origin, so it is treated as absent
        // (no header is ever sent for it); an AU outside the package still needs its own origin.
        if ($origin === 'null' && ($auorigin === null || $auorigin === '')) {
            return [];
        }
        if ($auorigin === null || $auorigin === '' || $origin !== $auorigin) {
            throw new lrs_exception(403, 'err_corsorigin');
        }
        return ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Expose-Headers' => self::EXPOSE, 'Vary' => 'Origin'];
    }

    /**
     * Whether an Origin header is an https origin in the exact form an AU origin is stored (and a browser sends it).
     *
     * @param string|null $origin Origin header.
     * @return bool
     */
    private static function canonical(?string $origin): bool {
        if ($origin === null || $origin === '' || strlen($origin) > 255 || !str_starts_with($origin, 'https://')) {
            return false;
        }
        try {
            return cmi5_parser::external_origin($origin) === $origin;
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * Whether an origin is the one of a URL (scheme and host ignoring case, default port left out).
     *
     * @param string $origin Origin.
     * @param string $url URL.
     * @return bool
     */
    private static function same_origin(string $origin, string $url): bool {
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        $default = ['https' => 443, 'http' => 80][$scheme] ?? null;
        $port = isset($p['port']) && (int)$p['port'] !== $default ? ':' . (int)$p['port'] : '';
        return $origin === $scheme . '://' . strtolower($p['host']) . $port;
    }
}
