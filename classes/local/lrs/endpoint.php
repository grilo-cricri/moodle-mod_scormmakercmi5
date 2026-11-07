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
 * What the cookieless endpoints (fetch.php, lrs.php; design §2.4) share after config.php: run the handler, and if
 * anything escapes it, log the failure and send a fixed body — never Moodle's HTML error page.
 *
 * The two defines and the config.php require stay in each script: they must run before the class loader exists.
 * The fallback response is built by the caller before anything runs, from a literal: the last-resort path calls no
 * get_string, no cache and no database, which are what may have failed.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class endpoint {
    /**
     * Runs a handler and sends its response, or the fallback if it throws.
     *
     * @param \Closure $handler Returns the response.
     * @param response $fallback Sent when the handler throws.
     * @param string $name Name of the endpoint in the log line.
     */
    public static function serve(\Closure $handler, response $fallback, string $name): void {
        try {
            $response = $handler();
        } catch (\Throwable $e) {
            self::log_failure($name, $e);
            $response = $fallback;
        }
        $response->send();
    }

    /**
     * Logs an internal failure of an endpoint: the class, the error code and the place only.
     *
     * The message and the trace are left out on purpose: a DML exception carries the SQL parameters (token and key
     * hashes), and a message may quote a header. The line goes through debugging(), which the endpoints send to the
     * PHP error log (NO_DEBUG_DISPLAY) when the site debugging level is NORMAL or above.
     *
     * @param string $name Name of the endpoint.
     * @param \Throwable $e The failure.
     */
    public static function log_failure(string $name, \Throwable $e): void {
        $code = $e instanceof \moodle_exception ? ' [' . $e->errorcode . ']' : '';
        try {
            debugging('mod_scormmakercmi5 ' . $name . ': ' . get_class($e) . $code . ' at ' . $e->getFile() . ':' .
                $e->getLine(), DEBUG_NORMAL);
        } catch (\Throwable $ignored) {
            // Logging must never turn a JSON answer into an HTML page.
            unset($ignored);
        }
    }
}
