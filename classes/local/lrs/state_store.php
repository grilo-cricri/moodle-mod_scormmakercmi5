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

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\limits;

/**
 * xAPI State API documents in scormmakercmi5_state (design §3.10, §7.1; xAPI 1.0.3 part 3 §2.3; R4.4).
 *
 * A document is keyed by the learner, the activity, the registration ('' without one) and the stateId. PUT replaces
 * it and does not require If-Match (the lrsql answered 409 to the second PUT and froze the resume of SCORM Maker
 * courses); POST merges JSON objects, the way the SCORM Maker AU writes. If-Match and If-None-Match are honoured when
 * sent. LMS.LaunchData belongs to the LMS: the AU reads it, even before initialized, and never writes or deletes it
 * (cmi5 §10.2.1). A body that is not UTF-8, or has a NUL byte, is stored as base64, so it survives a TEXT column.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state_store {
    /** @var string Content type of JSON documents. */
    public const JSON = 'application/json';

    /** @var string Content type of a document sent without one. */
    private const DEFAULT_TYPE = 'application/octet-stream';

    /** @var int Largest stateId, the width of state.stateid (design §3.10, §3.13). */
    private const MAX_STATEID = 254;

    /** @var int Largest Content-Type, the width of state.contenttype (design §3.13). */
    private const MAX_CONTENTTYPE = 100;

    /** @var string cmi5 rule of the protected LaunchData (§10.2.1: the AU MUST NOT modify or delete it). */
    private const LAUNCHDATA_RULE = '10.2.1.0-5';

    /**
     * GET activities/state: with stateId one document (200 with ETag and Last-Modified, or 404); without, the list
     * of stateIds (changed after `since`, if given).
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response
     * @throws lrs_exception 400 bad parameters, 403 another agent/activity/registration, 404 missing document.
     */
    public static function get_state(request $r, session_context $ctx): response {
        global $DB;
        $key = self::document_key($r, $ctx);
        $stateid = documents::string_param($r, 'stateId', false, self::MAX_STATEID);
        if ($stateid === null) {
            $since = documents::since_param($r);
            [$select, $params] = self::select_many($r, $ctx, $key);
            if ($since !== null) {
                $select .= ' AND updatedms > :since';
                $params['since'] = $since;
            }
            $ids = $DB->get_fieldset_select('scormmakercmi5_state', 'DISTINCT stateid', $select, $params);
            return response::json(200, array_values($ids));
        }
        $row = $DB->get_record('scormmakercmi5_state', $key + ['stateid' => $stateid]);
        if (!$row) {
            throw new lrs_exception(404, 'err_lrsnodoc');
        }
        return documents::document_response(self::content_of($row), $row->contenttype, $row->etag, (int)$row->updatedms);
    }

    /**
     * PUT activities/state?stateId=: stores or replaces the document; If-Match is honoured, never required.
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response 204.
     * @throws lrs_exception 400, 403 (also err_lrsstatelimit: too many documents), 412 or 413.
     */
    public static function put_state(request $r, session_context $ctx): response {
        [$key, $row, $type] = self::write_target($r, $ctx);
        self::store($key, $ctx, $r->body, $type, $row);
        return new response(204);
    }

    /**
     * POST activities/state?stateId=: merges the JSON object sent into the stored one (the properties sent win, one
     * level deep, xAPI 1.0.3 part 3 §2.3), or stores it when there is none. Both must be application/json objects.
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response 204.
     * @throws lrs_exception 400 (err_lrsmerge when a side is not a JSON object), 403, 412 or 413.
     */
    public static function post_state(request $r, session_context $ctx): response {
        [$key, $row, $type] = self::write_target($r, $ctx);
        $posted = self::json_object($type, $r->body);
        if ($row === null) {
            self::store($key, $ctx, $r->body, $type, null);
            return new response(204);
        }
        $merged = (array)self::json_object($row->contenttype, self::content_of($row));
        foreach ((array)$posted as $name => $value) {
            $merged[$name] = $value;
        }
        $content = json_encode((object)$merged, statement_store::JSON_FLAGS);
        if (strlen($content) > limits::max_state_bytes()) {
            throw new lrs_exception(413, 'err_lrstoolarge');
        }
        self::store($key, $ctx, $content, $type, $row);
        return new response(204);
    }

    /**
     * DELETE activities/state: with stateId that document (204 even if there was none; If-Match honoured); without,
     * every document of the learner, activity and registration except LMS.LaunchData.
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response 204.
     * @throws lrs_exception 400, 403 (LMS.LaunchData or scope) or 412.
     */
    public static function delete_state(request $r, session_context $ctx): response {
        global $DB;
        $key = self::document_key($r, $ctx);
        $stateid = documents::string_param($r, 'stateId', false, self::MAX_STATEID);
        if ($stateid === null) {
            [$select, $params] = self::select_many($r, $ctx, $key);
            $DB->delete_records_select('scormmakercmi5_state', $select . ' AND lmsowned = 0', $params);
            return new response(204);
        }
        $row = self::writable_row($key, $stateid);
        documents::check_preconditions($r, $row ? $row->etag : null);
        if ($row) {
            $DB->delete_records('scormmakercmi5_state', ['id' => $row->id]);
        }
        return new response(204);
    }

    /**
     * Creates or replaces the LMS.LaunchData document of an AU in a registration (cmi5 §10.1), owned by the LMS.
     *
     * The document is keyed like the State API reads it: the learner, sha1 of the activity id of the AU, the
     * registration and the state id.
     *
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU.
     * @param array $launchdata Document properties (cmi5 §10.2).
     * @param int $userid Learner.
     * @param int $instanceid Instance id.
     */
    public static function put_launchdata(\stdClass $reg, \stdClass $au, array $launchdata, int $userid, int $instanceid): void {
        global $DB;
        $content = json_encode($launchdata, statement_store::JSON_FLAGS);
        $key = [
            'userid' => $userid,
            'activityhash' => sha1($au->lmsactivityid),
            'registration' => $reg->registration,
            'stateid' => vocab::LAUNCHDATA_ID,
        ];
        $row = (object)($key + [
            'scormmakercmi5id' => $instanceid,
            'regid' => $reg->id,
            'contenttype' => self::JSON,
            'content' => $content,
            'isbase64' => 0,
            'etag' => sha1($content),
            'updatedms' => xapi_time::now_ms(),
            'lmsowned' => 1,
        ]);
        $id = $DB->get_field('scormmakercmi5_state', 'id', $key);
        if ($id) {
            $row->id = $id;
            $DB->update_record('scormmakercmi5_state', $row);
        } else {
            $DB->insert_record('scormmakercmi5_state', $row);
        }
    }

    /**
     * The key of the documents of a request (without stateId), after checking that activityId, agent and
     * registration are those of the session (R5.2).
     *
     * @param request $r Request.
     * @param session_context $ctx Session.
     * @return array userid, activityhash, registration ('' without one).
     * @throws lrs_exception 400 missing or invalid parameter, 403 another scope.
     */
    private static function document_key(request $r, session_context $ctx): array {
        $activityid = documents::string_param($r, 'activityId', true);
        $agent = router::json_object_param($r, 'agent');
        $registration = $r->query['registration'] ?? null;
        $ctx->assert_scope($agent, $activityid, $registration === null ? null : (string)$registration);
        return [
            'userid' => (int)$ctx->session->userid,
            'activityhash' => sha1($activityid),
            'registration' => $registration === null ? '' : $ctx->reg->registration,
        ];
    }

    /**
     * The documents of a multiple-document GET or DELETE (xAPI 1.0.3 part 3 §2.3). With registration, those of it;
     * without, those of every registration — which, since the token is good for one registration (R5.2), are the
     * documents of the session's registration plus those written without one.
     *
     * @param request $r Request.
     * @param session_context $ctx Session.
     * @param array $key Key of document_key().
     * @return array [SQL where, params].
     */
    private static function select_many(request $r, session_context $ctx, array $key): array {
        $select = 'userid = :userid AND activityhash = :activityhash';
        $params = ['userid' => $key['userid'], 'activityhash' => $key['activityhash']];
        if (array_key_exists('registration', $r->query)) {
            return [$select . ' AND registration = :registration', $params + ['registration' => $key['registration']]];
        }
        return [
            $select . ' AND (registration = :noreg OR registration = :sessionreg)',
            $params + ['noreg' => '', 'sessionreg' => $ctx->reg->registration],
        ];
    }

    /**
     * Everything a PUT or POST checks before writing: scope, stateId, LMS.LaunchData, size, Content-Type (width and
     * media type grammar, documents::is_media_type), and the
     * If-Match/If-None-Match preconditions against the stored document.
     *
     * @param request $r Request.
     * @param session_context $ctx Session.
     * @return array [key with stateid, stored row or null, Content-Type].
     * @throws lrs_exception 400, 403, 412 or 413.
     */
    private static function write_target(request $r, session_context $ctx): array {
        $key = self::document_key($r, $ctx);
        $stateid = documents::string_param($r, 'stateId', true, self::MAX_STATEID);
        $row = self::writable_row($key, $stateid);
        $r->assert_body_max(limits::max_state_bytes());
        $type = trim($r->headers['content-type'] ?? '');
        if ($type === '') {
            $type = self::DEFAULT_TYPE;
        }
        if (\core_text::strlen($type) > self::MAX_CONTENTTYPE || !documents::is_media_type($type)) {
            throw new lrs_exception(400, 'err_lrsheader', 'Content-Type');
        }
        documents::check_preconditions($r, $row ? $row->etag : null);
        return [$key + ['stateid' => $stateid], $row ?: null, $type];
    }

    /**
     * The stored document an AU request may change or delete.
     *
     * @param array $key Key without stateid.
     * @param string $stateid stateId.
     * @return \stdClass|false The row, false if there is none.
     * @throws lrs_exception 403 for LMS.LaunchData (cmi5 §10.2.1, rule 10.2.1.0-5).
     */
    private static function writable_row(array $key, string $stateid): \stdClass|false {
        global $DB;
        $row = $DB->get_record('scormmakercmi5_state', $key + ['stateid' => $stateid]);
        if ($stateid === vocab::LAUNCHDATA_ID || ($row && (int)$row->lmsowned === 1)) {
            throw new lrs_exception(403, 'err_lrslaunchdata', '', self::LAUNCHDATA_RULE);
        }
        return $row;
    }

    /**
     * A document as a JSON object, for a merge.
     *
     * @param string $type Its Content-Type.
     * @param string $content Its bytes.
     * @return \stdClass
     * @throws lrs_exception 400 err_lrsmerge when it is not application/json or not a JSON object.
     */
    private static function json_object(string $type, string $content): \stdClass {
        $mediatype = strtolower(trim(explode(';', $type)[0]));
        $decoded = $mediatype === self::JSON ? json_decode($content) : null;
        if (!$decoded instanceof \stdClass) {
            throw new lrs_exception(400, 'err_lrsmerge');
        }
        return $decoded;
    }

    /**
     * The bytes of a stored document.
     *
     * @param \stdClass $row State row.
     * @return string
     */
    private static function content_of(\stdClass $row): string {
        return (int)$row->isbase64 === 1 ? (string)base64_decode($row->content, true) : $row->content;
    }

    /**
     * Writes a document from the AU. A concurrent first write of the same key (the unique index refuses the second
     * insert) turns into an update of the row the other request created.
     *
     * @param array $key Key with stateid.
     * @param session_context $ctx Session.
     * @param string $content Bytes.
     * @param string $type Content-Type.
     * @param \stdClass|null $row Stored row, null if none was found.
     */
    private static function store(array $key, session_context $ctx, string $content, string $type, ?\stdClass $row): void {
        global $DB;
        $binary = !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0");
        $fields = [
            'contenttype' => $type,
            'content' => $binary ? base64_encode($content) : $content,
            'isbase64' => (int)$binary,
            'etag' => sha1($content),
            'updatedms' => xapi_time::now_ms(),
        ];
        if ($row) {
            $DB->update_record('scormmakercmi5_state', (object)(['id' => $row->id] + $fields));
            return;
        }
        // A new document counts against the quota of its scope (learner, activity, registration); a concurrent burst
        // may overshoot it by a few rows, which is harmless: the quota stops growth, it is not an exact ledger.
        $scope = array_diff_key($key, ['stateid' => true]) + ['lmsowned' => 0];
        if ($DB->count_records('scormmakercmi5_state', $scope) >= limits::max_state_docs()) {
            throw new lrs_exception(403, 'err_lrsstatelimit', (string)limits::max_state_docs(), 'limit');
        }
        $record = (object)($key + $fields + [
            'scormmakercmi5id' => $ctx->instance->id,
            'regid' => $key['registration'] === '' ? 0 : $ctx->reg->id,
            'lmsowned' => 0,
        ]);
        try {
            $DB->insert_record('scormmakercmi5_state', $record);
        } catch (\dml_write_exception $e) {
            $id = $DB->get_field('scormmakercmi5_state', 'id', $key);
            if (!$id) {
                throw $e;
            }
            $DB->update_record('scormmakercmi5_state', (object)(['id' => $id] + $fields));
        }
    }
}
