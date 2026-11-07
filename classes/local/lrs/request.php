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

use mod_scormmakercmi5\local\limits;

/**
 * A request to the LRS (design §2.4, §7.1), after the xAPI alternate request syntax has been unwrapped.
 *
 * The query and the form are parsed here, not taken from $_GET/$_POST: PHP turns dots into underscores and brackets
 * into arrays, and every xAPI parameter is a plain string.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request {
    /** @var string[] Methods of the xAPI resources. */
    public const METHODS = ['GET', 'PUT', 'POST', 'DELETE'];

    /** @var string[] Headers the alternate syntax carries as form fields (xAPI 1.0.3 part 3 §1.3), lower case. */
    private const FORM_HEADERS = ['authorization', 'x-experience-api-version', 'content-type', 'content-length', 'if-match',
        'if-none-match'];

    /** @var string Content type of the alternate syntax. */
    private const FORM_TYPE = 'application/x-www-form-urlencoded';

    /** @var string HTTP method (upper case), the intended one for the alternate syntax. */
    public readonly string $method;

    /** @var string Resource path without leading or trailing slash, e.g. 'statements', 'activities/state'. */
    public readonly string $resource;

    /** @var array Query parameters, name => string. */
    public readonly array $query;

    /** @var array Headers, lower-case name => value. */
    public readonly array $headers;

    /** @var string Body (the document or the statements). */
    public readonly string $body;

    /** @var string|null Authorization header value, trimmed; null if none. */
    public readonly ?string $authorization;

    /**
     * Constructor (use build() or from_globals()).
     *
     * @param string $method Method.
     * @param string $resource Resource.
     * @param array $query Query.
     * @param array $headers Headers with lower-case names.
     * @param string $body Body.
     * @param string|null $authorization Authorization.
     */
    public function __construct(
        string $method,
        string $resource,
        array $query,
        array $headers,
        string $body,
        ?string $authorization
    ) {
        $this->method = $method;
        $this->resource = $resource;
        $this->query = $query;
        $this->headers = $headers;
        $this->body = $body;
        $this->authorization = $authorization;
    }

    /**
     * The request of the current HTTP call.
     *
     * The body is read with a ceiling (max_read_bytes()): a declared or actual body above it is refused with 413
     * before anything else, so no handler ever sees a truncated document.
     *
     * @param string $pathinfo Path after lrs.php (get_file_argument()).
     * @return request
     * @throws lrs_exception 413 for a body above the ceiling, 400 for a malformed alternate syntax.
     */
    public static function from_globals(string $pathinfo): request {
        $server = $_SERVER;
        $headers = self::headers_from_server($server);
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            foreach (is_array($all) ? $all : [] as $name => $value) {
                $headers[strtolower((string)$name)] = (string)$value;
            }
        }
        $max = self::max_read_bytes();
        if ((int)($headers['content-length'] ?? 0) > $max) {
            throw new lrs_exception(413, 'err_lrstoolarge');
        }
        $body = (string)file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($body) > $max) {
            throw new lrs_exception(413, 'err_lrstoolarge');
        }
        $query = self::parse_form((string)($server['QUERY_STRING'] ?? ''));
        return self::build((string)($server['REQUEST_METHOD'] ?? ''), $pathinfo, $query, $headers, $body, $server);
    }

    /**
     * Builds a request, unwrapping the alternate request syntax (xAPI 1.0.3 part 3 §1.3).
     *
     * With ?method=: the real request must be a form-encoded POST with no other query parameter; the method is the
     * one of the parameter, the header fields of the form replace the headers, 'content' is the body and every other
     * field is a query parameter. An Authorization of the real request is used when the form has none.
     *
     * @param string $method HTTP method.
     * @param string $pathinfo Path after lrs.php.
     * @param array $query Query parameters.
     * @param array $headers Headers (any case).
     * @param string $body Body.
     * @param array $server Server variables, for the Authorization header.
     * @return request
     * @throws lrs_exception 400 for a malformed alternate syntax.
     */
    public static function build(
        string $method,
        string $pathinfo,
        array $query,
        array $headers,
        string $body,
        array $server = []
    ): request {
        $method = strtoupper($method);
        $resource = trim($pathinfo, '/');
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string)$name)] = (string)$value;
        }
        $authorization = self::extract_authorization($server, $lower);
        if (!array_key_exists('method', $query)) {
            return new self($method, $resource, $query, $lower, $body, $authorization);
        }

        $intended = strtoupper((string)$query['method']);
        $type = strtolower(trim(explode(';', $lower['content-type'] ?? '')[0]));
        if ($method !== 'POST' || count($query) !== 1 || !in_array($intended, self::METHODS, true) || $type !== self::FORM_TYPE) {
            throw new lrs_exception(400, 'err_lrsaltsyntax');
        }
        unset($lower['content-type'], $lower['content-length']);
        $content = '';
        $params = [];
        foreach (self::parse_form($body) as $name => $value) {
            $key = strtolower($name);
            if (in_array($key, self::FORM_HEADERS, true)) {
                $lower[$key] = $value;
            } else if ($name === 'content') {
                $content = $value;
            } else {
                $params[$name] = $value;
            }
        }
        $formauth = trim($lower['authorization'] ?? '');
        return new self($intended, $resource, $params, $lower, $content, $formauth !== '' ? $formauth : $authorization);
    }

    /**
     * The Authorization header, wherever the server put it (design §2.4): PHP-FPM/CGI often drops it from
     * HTTP_AUTHORIZATION, a rewrite rule leaves it in REDIRECT_HTTP_AUTHORIZATION, and getallheaders() may still
     * have it, under any case.
     *
     * @param array $server Server variables.
     * @param array|null $allheaders getallheaders() (any case), or null.
     * @return string|null The trimmed value, null if absent or empty.
     */
    public static function extract_authorization(array $server, ?array $allheaders): ?string {
        $candidates = [$server['HTTP_AUTHORIZATION'] ?? null, $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null];
        foreach ($allheaders ?? [] as $name => $value) {
            if (strtolower((string)$name) === 'authorization') {
                $candidates[] = $value;
            }
        }
        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Parses a query string or a form body (application/x-www-form-urlencoded) into name => string, without PHP's
     * renaming of dots and brackets. The last of repeated names wins; empty pairs are skipped.
     *
     * @param string $encoded Encoded pairs.
     * @return array
     */
    public static function parse_form(string $encoded): array {
        $result = [];
        foreach (explode('&', $encoded) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $result[urldecode($parts[0])] = urldecode($parts[1] ?? '');
        }
        return $result;
    }

    /**
     * Refuses a body above the limit of a resource (design §7.2 step 2, §7.4). from_globals() only caps the read at
     * max_read_bytes(), three times the largest limit, so every handler that takes a body checks its own limit here,
     * on the declared Content-Length and on the bytes actually received (a chunked body declares none).
     *
     * @param int $bytes Limit in bytes (limits::max_state_bytes(), limits::max_body_bytes()...).
     * @throws lrs_exception 413 err_lrstoolarge.
     */
    public function assert_body_max(int $bytes): void {
        $declared = trim($this->headers['content-length'] ?? '');
        if (strlen($this->body) > $bytes || (ctype_digit($declared) && (int)$declared > $bytes)) {
            throw new lrs_exception(413, 'err_lrstoolarge');
        }
    }

    /**
     * The largest body the LRS reads: three times the largest document or batch limit (form encoding of the
     * alternate syntax can triple a body) plus room for the header fields of the form.
     *
     * @return int Bytes.
     */
    public static function max_read_bytes(): int {
        return 3 * max(limits::max_body_bytes(), limits::max_state_bytes()) + 8192;
    }

    /**
     * Headers from the server variables (HTTP_*, CONTENT_TYPE, CONTENT_LENGTH), lower-case with dashes.
     *
     * @param array $server Server variables.
     * @return array
     */
    private static function headers_from_server(array $server): array {
        $headers = [];
        foreach ($server as $name => $value) {
            $name = (string)$name;
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string)$value;
            } else if ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $name))] = (string)$value;
            }
        }
        return $headers;
    }
}
