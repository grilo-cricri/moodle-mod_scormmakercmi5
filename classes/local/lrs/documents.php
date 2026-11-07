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
 * What the xAPI Document resources share (State, Agent Profile, Activity Profile; xAPI 1.0.3 part 3 §2.3, §3.1):
 * parameters with their column widths (design §3.13), `since`, the concurrency headers and the document response.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class documents {
    /** @var int Largest profileId, the width of agentprof.profileid (design §3.13). */
    public const MAX_PROFILEID = 255;

    /**
     * A string query parameter. Absent → null (or 400 when required); present but empty, or longer than $maxchars
     * characters → 400, so a value wider than its column never reaches the database (design §3.13).
     *
     * @param request $r Request.
     * @param string $name Parameter name.
     * @param bool $required Whether it must be present.
     * @param int|null $maxchars Largest length in characters, none if null.
     * @return string|null
     * @throws lrs_exception 400 err_lrsparam naming the parameter.
     */
    public static function string_param(request $r, string $name, bool $required, ?int $maxchars = null): ?string {
        $value = $r->query[$name] ?? null;
        if ($value === null) {
            if ($required) {
                throw new lrs_exception(400, 'err_lrsparam', $name);
            }
            return null;
        }
        $value = (string)$value;
        if ($value === '' || ($maxchars !== null && \core_text::strlen($value) > $maxchars)) {
            throw new lrs_exception(400, 'err_lrsparam', $name);
        }
        return $value;
    }

    /**
     * The `since` parameter of a list request, in milliseconds; null if absent.
     *
     * @param request $r Request.
     * @return int|null
     * @throws lrs_exception 400 err_lrsparam for a value that is not an ISO 8601 date-time.
     */
    public static function since_param(request $r): ?int {
        $since = self::string_param($r, 'since', false);
        if ($since === null) {
            return null;
        }
        try {
            return xapi_time::to_ms($since);
        } catch (\InvalidArgumentException $e) {
            throw new lrs_exception(400, 'err_lrsparam', 'since');
        }
    }

    /**
     * Honours If-Match and If-None-Match when sent (xAPI 1.0.3 part 3 §3.1), without requiring them (design §7.1):
     * If-Match needs the document to exist with one of the listed ETags (or "*"); If-None-Match refuses an existing
     * document whose ETag is listed (or any, with "*").
     *
     * @param request $r Request.
     * @param string|null $etag ETag (sha1, unquoted) of the current document, null if there is none.
     * @throws lrs_exception 412 err_lrsprecondition.
     */
    public static function check_preconditions(request $r, ?string $etag): void {
        $ifmatch = trim($r->headers['if-match'] ?? '');
        if ($ifmatch !== '' && ($etag === null || !self::etag_listed($ifmatch, $etag, false))) {
            throw new lrs_exception(412, 'err_lrsprecondition');
        }
        $ifnonematch = trim($r->headers['if-none-match'] ?? '');
        if ($ifnonematch !== '' && $etag !== null && self::etag_listed($ifnonematch, $etag, true)) {
            throw new lrs_exception(412, 'err_lrsprecondition');
        }
    }

    /** @var string RFC 9110 §5.6.2 token. */
    private const TOKEN = '[!#$%&\'*+.^_\x60|~0-9A-Za-z-]+';

    /** @var string RFC 9110 §5.6.4 quoted-string (obs-text kept). */
    private const QUOTED = '"(?:[ \x21\x23-\x5B\x5D-\x7E\x80-\xFF]|\\\\[ \x21-\x7E\x80-\xFF])*"';

    /**
     * Whether a Content-Type follows the media type grammar (RFC 9110 §8.3.1): type "/" subtype, then parameters
     * (token "=" token or quoted-string) separated by ";" with optional spaces. No control character at all (not even
     * a tab): a line break would make PHP's header() drop the line and send the default text/html (A-1).
     *
     * @param string $type Content-Type.
     * @return bool
     */
    public static function is_media_type(string $type): bool {
        if (preg_match('/[\x00-\x1F\x7F]/', $type)) {
            return false;
        }
        $t = self::TOKEN;
        $pattern = '/^' . $t . '\/' . $t . '(?: *; *(?:' . $t . '=(?:' . $t . '|' . self::QUOTED . '))?)*$/D';
        return preg_match($pattern, $type) === 1;
    }

    /**
     * The 200 of one document, with its ETag (quoted sha1) and Last-Modified. Always an attachment, sandboxed by the
     * CSP of every response: the browser never renders it as a page of the Moodle origin (A-1). The stored type is
     * given back as it was written (xAPI 1.0.3 part 3 §3.1); one outside the grammar (written before it was checked)
     * goes out as application/octet-stream.
     *
     * @param string $content Bytes of the document.
     * @param string $contenttype Content-Type it was stored with.
     * @param string $etag sha1 of the content.
     * @param int $updatedms Time of the last change, ms.
     * @return response
     */
    public static function document_response(string $content, string $contenttype, string $etag, int $updatedms): response {
        return new response(200, $content, [
            'Content-Type' => self::is_media_type($contenttype) ? $contenttype : 'application/octet-stream',
            'Content-Disposition' => 'attachment',
            'ETag' => '"' . $etag . '"',
            'Last-Modified' => gmdate('D, d M Y H:i:s', intdiv($updatedms, 1000)) . ' GMT',
        ]);
    }

    /**
     * Whether an entity-tag list names an ETag. If-Match compares strongly (a weak tag never matches); If-None-Match
     * compares weakly (RFC 9110 §13.1).
     *
     * @param string $header Header value: "*" or a comma-separated list of entity tags.
     * @param string $etag Current ETag, unquoted.
     * @param bool $weak Whether weak tags match.
     * @return bool
     */
    private static function etag_listed(string $header, string $etag, bool $weak): bool {
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if ($tag === '*') {
                return true;
            }
            if (strncasecmp($tag, 'W/', 2) === 0) {
                if (!$weak) {
                    continue;
                }
                $tag = substr($tag, 2);
            }
            if (strtolower(trim($tag, '"')) === strtolower($etag)) {
                return true;
            }
        }
        return false;
    }
}
