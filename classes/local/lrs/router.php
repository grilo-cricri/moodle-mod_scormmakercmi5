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
 * The router of lrs.php/<resource> (design §2.4, §7.1).
 *
 * Order: resource (404) → method (405 + Allow; HEAD answers as GET without the body) → X-Experience-API-Version
 * 1.0.x (400) → session token, or the reporting credential on GET statements (401) → handler. `about` needs neither
 * the version nor a token. Every response carries X-Experience-API-Version: 1.0.3 and Cache-Control: no-store, is
 * JSON or empty, and never HTML. CORS (class cors) exists only for an AU outside the package: OPTIONS from the
 * origin of such an AU answers 204 with its headers (any other OPTIONS is routed as before); after the token, the
 * session's AU must match the Origin of the request (no Origin or the Moodle origin: nothing changes, no header;
 * another outside origin: 403 before the handler runs), and the answer then carries the headers, errors of the
 * handler included. The token stays the authorisation; the origin is checked on top of it. A handler receives
 * (request, ?session_context) — GET statements also the user of the reporting credential — and returns a response;
 * an lrs_exception becomes its JSON error, anything else a fixed 500 body.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class router {
    /** @var string The xAPI version this LRS speaks. */
    public const VERSION = '1.0.3';

    /** @var array resource => [method => [class, static method]]. Tasks 12, 15 and 16 add theirs. */
    public const ROUTES = [
        'about' => ['GET' => [self::class, 'get_about']],
        'agents' => ['GET' => [self::class, 'get_agents']],
        'activities/state' => [
            'GET' => [state_store::class, 'get_state'],
            'PUT' => [state_store::class, 'put_state'],
            'POST' => [state_store::class, 'post_state'],
            'DELETE' => [state_store::class, 'delete_state'],
        ],
        'agents/profile' => [
            'GET' => [agent_profile::class, 'get_profile'],
            'PUT' => [agent_profile::class, 'reject_write'],
            'POST' => [agent_profile::class, 'reject_write'],
            'DELETE' => [agent_profile::class, 'reject_write'],
        ],
        'activities/profile' => ['GET' => [activity_profile::class, 'get_profile']],
        'statements' => [
            'GET' => [statement_query::class, 'get_statements'],
            'PUT' => [statement_store::class, 'put_statement'],
            'POST' => [statement_store::class, 'post_statements'],
        ],
    ];

    /** @var string[] Resources that need neither a token nor the version header (xAPI 1.0.3 part 3 §2.2). */
    private const PUBLIC_RESOURCES = ['about'];

    /**
     * @var string[] Resources whose GET (and HEAD) also accept the reporting credential (design §7.3); their handler
     *     receives (request, ?session_context, ?int report user id). No other resource or method ever does.
     */
    private const REPORT_RESOURCES = ['statements'];

    /**
     * @var string Body of an internal error, written by hand: the last-resort path must not call get_string, which
     * may be what failed.
     */
    public const INTERNAL_BODY = '{"error":"err_internal","message":"Internal error of the LRS.","rule":null}';

    /** @var array Test seam: extra routes, resource => [method => callable]. */
    private static array $testroutes = [];

    /**
     * Handles the current HTTP request and sends the response (lrs.php).
     *
     * @param string $pathinfo Path after lrs.php.
     */
    public static function handle(string $pathinfo): void {
        $fallback = new response(
            500,
            self::INTERNAL_BODY,
            ['Content-Type' => 'application/json', 'X-Experience-API-Version' => self::VERSION]
        );
        endpoint::serve(function () use ($pathinfo): response {
            // The Origin header of the real request: the form fields of the alternate syntax never replace it.
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : null;
            try {
                $request = request::from_globals($pathinfo);
            } catch (lrs_exception $e) {
                return self::finish(response::error($e));
            }
            return self::dispatch($request, $origin);
        }, $fallback, 'lrs');
    }

    /**
     * Answers a request. Never throws.
     *
     * @param request $r Request.
     * @param string|null $origin Origin header of the HTTP request, null when absent.
     * @return response
     */
    public static function dispatch(request $r, ?string $origin = null): response {
        try {
            $preflight = $r->method === 'OPTIONS' ? cors::preflight($origin) : null;
            $response = $preflight ?? self::route($r, $origin);
        } catch (lrs_exception $e) {
            $response = response::error($e);
        } catch (\Throwable $e) {
            endpoint::log_failure('lrs', $e);
            $response = new response(500, self::INTERNAL_BODY, ['Content-Type' => 'application/json']);
        }
        return self::finish($response);
    }

    /**
     * GET about (xAPI 1.0.3 part 3 §2.2).
     *
     * @param request $r Request.
     * @param session_context|null $ctx Always null (no authorisation).
     * @return response
     */
    public static function get_about(request $r, ?session_context $ctx): response {
        return response::json(200, ['version' => [self::VERSION]]);
    }

    /**
     * GET agents?agent= (xAPI 1.0.3 part 3 §2.5.1): the Person of the learner of the session, and no one else.
     *
     * @param request $r Request.
     * @param session_context|null $ctx Session of the token.
     * @return response
     * @throws lrs_exception 400 without a valid agent, 403 for another agent.
     */
    public static function get_agents(request $r, ?session_context $ctx): response {
        $agent = self::json_object_param($r, 'agent');
        $ctx->assert_scope($agent, null, null);
        return response::json(200, ['objectType' => 'Person', 'account' => [$ctx->actor['account']]]);
    }

    /**
     * A required query parameter holding a JSON object (agent, ...).
     *
     * @param request $r Request.
     * @param string $name Parameter name.
     * @return array
     * @throws lrs_exception 400 err_lrsparam.
     */
    public static function json_object_param(request $r, string $name): array {
        $value = $r->query[$name] ?? null;
        $decoded = is_string($value) ? json_decode($value, true, 16) : null;
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new lrs_exception(400, 'err_lrsparam', $name);
        }
        return $decoded;
    }

    /**
     * Finds the handler, checks the version and the token, and calls it.
     *
     * @param request $r Request.
     * @param string|null $origin Origin header of the HTTP request.
     * @return response
     */
    private static function route(request $r, ?string $origin): response {
        $methods = (self::$testroutes[$r->resource] ?? null) ?? (self::ROUTES[$r->resource] ?? null);
        if ($methods === null) {
            throw new lrs_exception(404, 'err_lrsnotfound');
        }
        $head = $r->method === 'HEAD' && isset($methods['GET']);
        $handler = $methods[$head ? 'GET' : $r->method] ?? null;
        if ($handler === null) {
            $allow = array_keys($methods);
            if (isset($methods['GET'])) {
                $allow[] = 'HEAD';
            }
            throw new lrs_exception(405, 'err_lrsmethod', $r->method, null, ['Allow' => implode(', ', $allow)]);
        }

        $ctx = null;
        $reportuserid = null;
        $reportable = ($head || $r->method === 'GET') && in_array($r->resource, self::REPORT_RESOURCES, true)
            && !isset(self::$testroutes[$r->resource]);
        if (!in_array($r->resource, self::PUBLIC_RESOURCES, true) && !isset(self::$testroutes[$r->resource])) {
            if (!preg_match('/^1\.0(\.\d+)?$/', trim($r->headers['x-experience-api-version'] ?? ''))) {
                throw new lrs_exception(400, 'err_lrsversion');
            }
            if ($reportable) {
                $reportuserid = auth::report_user_from_request($r);
            }
            if ($reportuserid === null) {
                $ctx = auth::session_from_request($r);
            }
        }
        // After the token, before the handler: an outside origin that is not the one of the session's AU is refused.
        $cors = $ctx ? cors::for_au($ctx->au, $origin) : [];
        try {
            $response = $reportable ? call_user_func($handler, $r, $ctx, $reportuserid) : call_user_func($handler, $r, $ctx);
        } catch (lrs_exception $e) {
            $response = response::error($e);
        }
        $response = $response->with_headers($cors);
        return $head ? $response->without_body() : $response;
    }

    /**
     * Adds the headers every LRS response carries.
     *
     * @param response $response Response.
     * @return response
     */
    private static function finish(response $response): response {
        return $response->with_headers(['X-Experience-API-Version' => self::VERSION]);
    }
}
