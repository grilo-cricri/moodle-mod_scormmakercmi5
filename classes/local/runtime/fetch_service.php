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

namespace mod_scormmakercmi5\local\runtime;

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\lrs\cors;
use mod_scormmakercmi5\local\lrs\endpoint;
use mod_scormmakercmi5\local\lrs\lrs_exception;
use mod_scormmakercmi5\local\lrs\response;
use mod_scormmakercmi5\local\lrs\xapi_time;

/**
 * The cmi5 fetch URL (design §6.2; cmi5 §8.2; R4.3, R4.7): trades the one-time key of a launch for the auth token.
 *
 * Only POST is accepted (§8.2.1). Every answer to a POST is 200 + application/json: the token once, then an error
 * with the codes of §8.2.3.2. The key and the token are compared and stored only as sha256 and never logged.
 *
 * Two fetches at once (two tabs, a retry of the AU) are serialised by the lock 'fetch<sessionid>': the second sees
 * fetchused = 1 and gets error 1, and a lock that does not come in time gives error 3 — never two tokens. A relaunch
 * or the cleanup task abandoning the session at the same moment hold another lock, so the state is read again after
 * the write: a session no longer launched loses the token it was just given.
 *
 * CORS (class cors), only for an AU outside the package: OPTIONS from its origin answers 204; a POST from its origin
 * gets the headers once the key names a session of that AU; a POST from another outside origin gets error 2 and the
 * key is not spent. The key stays the authorisation; the origin is checked on top of it.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fetch_service {
    /** @var string cmi5 §8.2.3.2: already in use or expired. */
    public const ERROR_EXPIRED = '1';

    /** @var string cmi5 §8.2.3.2: general security error. */
    public const ERROR_SECURITY = '2';

    /** @var string cmi5 §8.2.3.2: general application error. */
    public const ERROR_INTERNAL = '3';

    /**
     * @var string Body of error 3 when even the handler failed, written by hand: the last-resort path of fetch.php
     * must not call get_string. The text is the one of cmi5 §8.2.3.2.
     */
    public const FALLBACK_BODY = '{"error-code":"3","error-text":"General Application Error"}';

    /** @var string Component and lock type. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /** @var \Closure|null Test seam: runs under the lock after the checks, just before the token is written. */
    private static ?\Closure $beforewrite = null;

    /**
     * Handles a request to fetch.php.
     *
     * @param string $method HTTP method.
     * @param string $key The one-time key (parameter k), already cleaned.
     * @param int $locktimeout Seconds to wait for the session lock.
     * @param string|null $origin Origin header of the HTTP request, null when absent.
     * @return response
     */
    public static function handle(string $method, string $key, int $locktimeout = 5, ?string $origin = null): response {
        if ($method === 'OPTIONS') {
            $preflight = cors::preflight($origin);
            if ($preflight !== null) {
                return $preflight;
            }
        }
        if ($method !== 'POST') {
            // Cmi5 §8.2.1: "HTTP GET is not allowed". Nothing is looked up, so the key is not spent.
            return response::json(405, self::error_body(self::ERROR_INTERNAL, 'fetcherror_method'), ['Allow' => 'POST']);
        }
        try {
            return self::fetch($key, $locktimeout, $origin);
        } catch (\Throwable $e) {
            // The exception may carry SQL parameters (hashes): only its class and place are logged, nothing shown.
            endpoint::log_failure('fetch', $e);
            return self::error(self::ERROR_INTERNAL, 'fetcherror_internal');
        }
    }

    /**
     * The fetch of a POST.
     *
     * @param string $key One-time key.
     * @param int $locktimeout Seconds to wait for the session lock.
     * @param string|null $origin Origin header.
     * @return response
     */
    private static function fetch(string $key, int $locktimeout, ?string $origin): response {
        global $DB;
        if ($key === '') {
            return self::error(self::ERROR_SECURITY, 'fetcherror_security');
        }
        $session = $DB->get_record('scormmakercmi5_session', ['fetchkeyhash' => hash('sha256', $key)]);
        if (!$session || (int)$session->kind !== launcher::KIND_LAUNCH) {
            return self::error(self::ERROR_SECURITY, 'fetcherror_security');
        }
        // The origin of the request against the AU of the session, before anything is spent.
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], 'id, auorigin');
        try {
            $cors = cors::for_au($au ?: (object)['auorigin' => null], $origin);
        } catch (lrs_exception $e) {
            return self::error(self::ERROR_SECURITY, 'fetcherror_security');
        }
        return self::fetch_session($session, $locktimeout)->with_headers($cors);
    }

    /**
     * The fetch of a POST once the key named a launch session and the origin was accepted.
     *
     * @param \stdClass $session Session row of the key.
     * @param int $locktimeout Seconds to wait for the session lock.
     * @return response
     */
    private static function fetch_session(\stdClass $session, int $locktimeout): response {
        global $DB;
        // Access first (R4.7): a learner who lost it gets the security error whatever the state of the session.
        if (!self::has_access($session)) {
            return self::error(self::ERROR_SECURITY, 'fetcherror_security');
        }

        $lock = \core\lock\lock_config::get_lock_factory(self::COMPONENT)->get_lock('fetch' . $session->id, $locktimeout);
        if (!$lock) {
            return self::error(self::ERROR_INTERNAL, 'fetcherror_internal');
        }
        try {
            $session = $DB->get_record('scormmakercmi5_session', ['id' => $session->id], '*', MUST_EXIST);
            if ((int)$session->fetchused !== 0) {
                return self::error(self::ERROR_EXPIRED, 'fetcherror_alreadyused');
            }
            if ((int)$session->status !== launcher::STATUS_LAUNCHED) {
                return self::error(self::ERROR_EXPIRED, 'fetcherror_expired');
            }
            if (self::$beforewrite) {
                (self::$beforewrite)($session);
            }

            $token = base64_encode(random_bytes(32));
            $now = \core\di::get(\core\clock::class)->time();
            $DB->update_record('scormmakercmi5_session', (object)[
                'id' => $session->id,
                'tokenhash' => hash('sha256', $token),
                'fetchused' => 1,
                'tokenexpires' => $now + limits::session_timeout_seconds(),
                'lastactivityms' => xapi_time::now_ms(),
            ]);

            // A relaunch or the cleanup task may have abandoned the session between the read and the write.
            $status = (int)$DB->get_field('scormmakercmi5_session', 'status', ['id' => $session->id], MUST_EXIST);
            if ($status !== launcher::STATUS_LAUNCHED) {
                $DB->set_field('scormmakercmi5_session', 'tokenexpires', 0, ['id' => $session->id]);
                return self::error(self::ERROR_EXPIRED, 'fetcherror_expired');
            }
            return response::json(200, ['auth-token' => $token]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the learner of a session may still take the activity (R4.7), per access_check::user_can_launch().
     *
     * @param \stdClass $session Session row.
     * @return bool
     */
    private static function has_access(\stdClass $session): bool {
        global $DB;
        $instanceid = $DB->get_field('scormmakercmi5_reg', 'scormmakercmi5id', ['id' => $session->regid]);
        $courseid = $instanceid ? $DB->get_field('scormmakercmi5', 'course', ['id' => $instanceid]) : false;
        $course = $courseid ? $DB->get_record('course', ['id' => $courseid]) : false;
        if (!$course) {
            return false;
        }
        $userid = (int)$session->userid;
        $cm = get_fast_modinfo($course, $userid)->instances['scormmakercmi5'][$instanceid] ?? null;
        return $cm && access_check::user_can_launch($course, $cm, $userid);
    }

    /**
     * A cmi5 fetch error (always HTTP 200, §8.2.1).
     *
     * @param string $code Error code of §8.2.3.2.
     * @param string $identifier String id of the error text.
     * @return response
     */
    private static function error(string $code, string $identifier): response {
        return response::json(200, self::error_body($code, $identifier));
    }

    /**
     * The body of a fetch error (§8.2.3.1).
     *
     * @param string $code Error code.
     * @param string $identifier String id of the error text.
     * @return array
     */
    private static function error_body(string $code, string $identifier): array {
        return ['error-code' => $code, 'error-text' => get_string($identifier, self::COMPONENT)];
    }
}
