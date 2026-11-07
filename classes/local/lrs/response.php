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
 * An HTTP response of a cookieless endpoint (fetch.php, lrs.php; design §2.4): status, headers and body, never HTML.
 *
 * Every response carries Cache-Control: no-store (it may hold a token or a learner's data),
 * X-Content-Type-Options: nosniff and a Content-Security-Policy that sandboxes it with no source allowed: a document
 * the AU stored as text/html (State API) never runs script on the Moodle origin, even when a cross-site form reaches
 * it through the alternate request syntax (security review item A-1). A response without a Content-Type is
 * sent as text/plain.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class response {
    /** @var int JSON flags of every body. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var string Content-Security-Policy of every response: nothing loads, nothing frames it, no script runs. */
    public const CSP = "default-src 'none'; frame-ancestors 'none'; sandbox";

    /** @var int HTTP status. */
    public readonly int $status;

    /** @var string Body. */
    public readonly string $body;

    /** @var array Header name => value; always has Cache-Control. */
    public readonly array $headers;

    /**
     * Constructor.
     *
     * @param int $status HTTP status.
     * @param string $body Body.
     * @param array $headers Header name => value; the given ones win over the defaults.
     */
    public function __construct(int $status, string $body = '', array $headers = []) {
        $this->status = $status;
        $this->body = $body;
        $this->headers = array_merge([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::CSP,
        ], $headers);
    }

    /**
     * A JSON response.
     *
     * @param int $status HTTP status.
     * @param mixed $data Data to encode.
     * @param array $headers Extra headers.
     * @return response
     */
    public static function json(int $status, mixed $data, array $headers = []): response {
        return new self($status, json_encode($data, self::JSON_FLAGS), ['Content-Type' => 'application/json'] + $headers);
    }

    /**
     * The JSON response of an LRS error (design §2.4): {"error": code, "message": text, "rule": cmi5 requirement or null}.
     *
     * @param lrs_exception $e The error.
     * @return response
     */
    public static function error(lrs_exception $e): response {
        return self::json(
            $e->httpstatus,
            ['error' => $e->errorcode, 'message' => $e->getMessage(), 'rule' => $e->rule],
            $e->headers
        );
    }

    /**
     * The same response with headers added or replaced.
     *
     * @param array $headers Header name => value; they win over the current ones.
     * @return response
     */
    public function with_headers(array $headers): response {
        return new self($this->status, $this->body, array_merge($this->headers, $headers));
    }

    /**
     * The same response without its body (HEAD), headers kept.
     *
     * @return response
     */
    public function without_body(): response {
        return new self($this->status, '', $this->headers);
    }

    /**
     * Sends the status, the headers and the body.
     */
    public function send(): void {
        if (!headers_sent()) {
            http_response_code($this->status);
            $headers = $this->headers + ['Content-Type' => 'text/plain; charset=utf-8'];
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
