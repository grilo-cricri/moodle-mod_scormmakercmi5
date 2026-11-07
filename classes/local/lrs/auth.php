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

use mod_scormmakercmi5\local\runtime\access_check;
use mod_scormmakercmi5\local\runtime\actor;
use mod_scormmakercmi5\local\runtime\launcher;

/**
 * Authorisation of LRS requests (design §2.4, §6.3, §7.2 step 1; R5.2).
 *
 * The AU sends "Authorization: Basic <auth-token>" (cmi5 §8.2.1): the token is the exact string after the scheme,
 * standard base64 that is never decoded, looked up by its sha256. It is good only while its session is open —
 * launched or initialized — and before tokenexpires: a terminated or abandoned session refuses its token even if
 * the expiry is still ahead (a relaunch can abandon the session right after the fetch handed the token out).
 * The token and its hash are never logged or echoed. GET statements also accepts the read-only reporting credential
 * (report_user_from_request(), design §7.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class auth {
    /** @var string Short name of the external service of the reporting credential (db/services.php). */
    public const REPORT_SERVICE = 'mod_scormmakercmi5_lrs';

    /** @var string What the Basic credentials of the reporting credential decode to, before the token. */
    private const WSTOKEN_PREFIX = 'wstoken:';

    /**
     * The session of the token of a request; updates its lastactivityms (§6.3).
     *
     * @param request $r Request.
     * @return session_context
     * @throws lrs_exception 401 err_unauthorized; 403 err_lrsnoaccess when the activity is no longer available to the
     *     learner (suspended, unenrolled, without the view capability, hidden or behind an access restriction mid-session).
     */
    public static function session_from_request(request $r): session_context {
        global $DB;
        $token = self::basic_token($r->authorization);
        if ($token === null) {
            throw self::unauthorized();
        }
        $session = $DB->get_record('scormmakercmi5_session', ['tokenhash' => hash('sha256', $token)]);
        $now = \core\di::get(\core\clock::class)->time();
        $open = [launcher::STATUS_LAUNCHED, launcher::STATUS_INITIALIZED];
        if (
            !$session || (int)$session->kind !== launcher::KIND_LAUNCH || !in_array((int)$session->status, $open, true)
                || (int)$session->tokenexpires <= $now
        ) {
            throw self::unauthorized();
        }

        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid]);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid]);
        $instance = $reg ? $DB->get_record('scormmakercmi5', ['id' => $reg->scormmakercmi5id]) : false;
        if (!$reg || !$au || !$instance) {
            throw self::unauthorized();
        }
        $course = get_course($instance->course);
        $cm = get_fast_modinfo($course, (int)$session->userid)->instances['scormmakercmi5'][$instance->id] ?? null;
        if (!$cm) {
            throw self::unauthorized();
        }
        if (!access_check::user_can_launch($course, $cm, (int)$session->userid)) {
            // Suspended, unenrolled, hidden or outside an access restriction, or without the view capability, since
            // the fetch: the token is right, the access is gone. The same test the fetch URL applies.
            throw new lrs_exception(403, 'err_lrsnoaccess');
        }

        $session->lastactivityms = xapi_time::now_ms();
        $DB->set_field('scormmakercmi5_session', 'lastactivityms', $session->lastactivityms, ['id' => $session->id]);
        return new session_context(
            $session,
            $reg,
            $au,
            $instance,
            $cm,
            \context_module::instance($cm->id),
            actor::for_user((int)$session->userid)
        );
    }

    /**
     * The user of the read-only reporting credential (design §7.3; cmi5 4.2.0.0-2), or null when the request does not
     * carry one.
     *
     * The credential is "Basic base64(wstoken:<token>)": a permanent token of the service mod_scormmakercmi5_lrs. It is
     * validated as the web service servers of core do (webservice_server::authenticate_user and ::load_function_info):
     * web services enabled on the site; the service enabled; the token permanent, of this service, within validuntil
     * and its iprestriction; for a user-restricted service, the user listed with their own validuntil and
     * iprestriction; the service's required capability; the user neither deleted, unconfirmed, suspended, nologin nor
     * with an expired password, and allowed in during maintenance. Only its lastaccess is written; a refusal triggers
     * \core\event\webservice_login_failed (reason and token id, never the token). The protocol capability
     * webservice/<protocol>:use is not checked: this is not a core protocol. The router accepts
     * it only on GET statements; anywhere else the value is not a session token and gets the 401 of
     * session_from_request().
     *
     * @param request $r Request.
     * @return int|null User id; null when the Authorization is not a wstoken credential.
     * @throws lrs_exception 401 err_unauthorized for a wstoken credential that is not valid.
     */
    public static function report_user_from_request(request $r): ?int {
        global $CFG, $DB;
        $credentials = self::basic_token($r->authorization);
        $decoded = $credentials === null ? false : base64_decode($credentials, true);
        if ($decoded === false || strncmp($decoded, self::WSTOKEN_PREFIX, strlen(self::WSTOKEN_PREFIX)) !== 0) {
            return null;
        }
        require_once($CFG->dirroot . '/webservice/lib.php');
        $token = substr($decoded, strlen(self::WSTOKEN_PREFIX));
        if ($token === '' || empty($CFG->enablewebservices)) {
            throw self::report_refused($token === '' ? 'invalid_token' : 'webservices_disabled');
        }
        $service = $DB->get_record('external_services', ['shortname' => self::REPORT_SERVICE, 'component' => 'mod_scormmakercmi5']);
        if (!$service || empty($service->enabled)) {
            throw self::report_refused('service_disabled');
        }
        $record = $DB->get_record(
            'external_tokens',
            ['token' => $token, 'tokentype' => EXTERNAL_TOKEN_PERMANENT, 'externalserviceid' => $service->id]
        );
        $now = \core\di::get(\core\clock::class)->time();
        $ip = getremoteaddr();
        if (!$record) {
            throw self::report_refused('invalid_token');
        }
        if (!self::within($record, $now, $ip)) {
            throw self::report_refused('token_expired_or_ip_restricted', $record);
        }
        if (!empty($service->restrictedusers)) {
            $listed = $DB->get_record(
                'external_services_users',
                ['externalserviceid' => $service->id, 'userid' => $record->userid]
            );
            if (!$listed || !self::within($listed, $now, $ip)) {
                throw self::report_refused('user_not_authorised', $record);
            }
        }
        $user = $DB->get_record('user', ['id' => $record->userid]);
        if (!$user || !empty($user->deleted) || empty($user->confirmed) || !empty($user->suspended) || $user->auth === 'nologin') {
            throw self::report_refused('user_not_active', $record);
        }
        $plugin = get_auth_plugin($user->auth);
        $expires = !empty($plugin->config->expiration) && (int)$plugin->config->expiration === 1;
        if ($expires && (int)$plugin->password_expire($user->username) < 0) {
            throw self::report_refused('password_expired', $record);
        }
        $system = \context_system::instance();
        if (!empty($CFG->maintenance_enabled) && !has_capability('moodle/site:maintenanceaccess', $system, $user)) {
            throw self::report_refused('maintenance', $record);
        }
        $tokencontext = \context::instance_by_id((int)$record->contextid, IGNORE_MISSING) ?: $system;
        if (!empty($service->requiredcapability) && !has_capability($service->requiredcapability, $tokencontext, $user)) {
            throw self::report_refused('missing_capability', $record);
        }
        \webservice::update_token_lastaccess($record, $now);
        return (int)$user->id;
    }

    /**
     * The 401 of a refused reporting credential, audited as core audits a refused web service token: the event
     * webservice_login_failed with the reason and the token id when known — never the token itself.
     *
     * @param string $reason Why.
     * @param \stdClass|null $record Token row, when found.
     * @return lrs_exception
     */
    private static function report_refused(string $reason, ?\stdClass $record = null): lrs_exception {
        try {
            $other = ['method' => WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN, 'reason' => $reason];
            if ($record !== null) {
                $other['tokenid'] = (int)$record->id;
            }
            \core\event\webservice_login_failed::create(['other' => $other])->trigger();
        } catch (\Throwable $e) {
            // The audit trail must never turn a 401 into a 500.
            endpoint::log_failure('lrs report auth', $e);
        }
        return self::unauthorized();
    }

    /**
     * Whether a token or an authorised-user row is within its validuntil (0 or null = none) and its iprestriction.
     *
     * @param \stdClass $row Row of external_tokens or external_services_users.
     * @param int $now Now, seconds.
     * @param string $ip Remote address.
     * @return bool
     */
    private static function within(\stdClass $row, int $now, string $ip): bool {
        if (!empty($row->validuntil) && (int)$row->validuntil < $now) {
            return false;
        }
        return empty($row->iprestriction) || address_in_subnet($ip, $row->iprestriction);
    }

    /**
     * The credentials of a Basic Authorization value: the exact text after the scheme (case-insensitive), trimmed.
     *
     * @param string|null $authorization Authorization header.
     * @return string|null Null if not Basic or empty.
     */
    private static function basic_token(?string $authorization): ?string {
        if ($authorization === null || !preg_match('/^Basic\s+(\S+)\s*$/i', $authorization, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * The 401 of a missing or invalid token. No WWW-Authenticate header: a browser would open its login dialog
     * over the course for a request of the AU.
     *
     * @return lrs_exception
     */
    private static function unauthorized(): lrs_exception {
        return new lrs_exception(401, 'err_unauthorized');
    }
}
