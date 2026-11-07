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
use mod_scormmakercmi5\local\package\course_structure;

/**
 * xAPI Agent Profile documents in scormmakercmi5_agentprof (design §3.11); only the LMS writes.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_profile {
    /** @var string[] Values of audioPreference (cmi5 §11.2). */
    private const AUDIO_VALUES = ['on', 'off'];

    /** @var string audioPreference written when the learner has none (cmi5 11.0.0.0-5 requires the property). */
    private const AUDIO_DEFAULT = 'on';

    /** @var \Closure|null Runs just before the insert; a test uses it to create the row first (the race). */
    private static ?\Closure $beforeinsert = null;

    /**
     * Writes cmi5LearnerPreferences (cmi5 §11) before a launch: languagePreference from the Moodle language as an
     * RFC 5646 tag, and audioPreference, which the document MUST carry (11.0.0.0-5): an "on"/"off" already there
     * is kept, otherwise "on" (§11.2; Moodle has no such preference, and audio on is what an AU does by default).
     *
     * The row is per user across activities, so two first launches of different activities (each under its own
     * registration lock) can both find no row; the unique index (userid, profileid) refuses the second insert,
     * which then reads the row of the first and updates it.
     *
     * @param int $userid Learner.
     * @param string $moodlelang Moodle language code (pt_br, en, es_mx…).
     */
    public static function ensure_learner_preferences(int $userid, string $moodlelang): void {
        self::write_preferences($userid, self::language_tag($moodlelang), true);
    }

    /**
     * GET agents/profile?agent=[&profileId=][&since=] (xAPI 1.0.3 part 3 §2.6): one document, or the list of ids.
     *
     * Reading cmi5LearnerPreferences marks the session prefsread (cmi5 §11, rule 11.0.0.0-3 checked on initialized).
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response
     * @throws lrs_exception 400 bad parameters, 403 another agent, 404 missing document.
     */
    public static function get_profile(request $r, session_context $ctx): response {
        global $DB;
        $ctx->assert_scope(router::json_object_param($r, 'agent'), null, null);
        $userid = (int)$ctx->session->userid;
        $profileid = documents::string_param($r, 'profileId', false, documents::MAX_PROFILEID);
        if ($profileid === null) {
            $since = documents::since_param($r);
            $select = 'userid = :userid';
            $params = ['userid' => $userid];
            if ($since !== null) {
                $select .= ' AND updatedms > :since';
                $params['since'] = $since;
            }
            $ids = $DB->get_fieldset_select('scormmakercmi5_agentprof', 'profileid', $select, $params);
            return response::json(200, array_values($ids));
        }

        $row = $DB->get_record('scormmakercmi5_agentprof', ['userid' => $userid, 'profileid' => $profileid]);
        if (!$row) {
            throw new lrs_exception(404, 'err_lrsnodoc');
        }
        if ($profileid === vocab::PREFS_ID && (int)$ctx->session->prefsread !== 1) {
            $DB->set_field('scormmakercmi5_session', 'prefsread', 1, ['id' => $ctx->session->id]);
            $ctx->session->prefsread = 1;
        }
        return documents::document_response($row->content, state_store::JSON, $row->etag, (int)$row->updatedms);
    }

    /**
     * PUT/POST/DELETE agents/profile: refused with 403, after the agent is checked (cmi5 §11: the LMS may ignore
     * the AU's changes to the preferences by returning 403; only the LMS writes this resource).
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response Never returns.
     * @throws lrs_exception 400 without agent, 403 always.
     */
    public static function reject_write(request $r, session_context $ctx): response {
        $ctx->assert_scope(router::json_object_param($r, 'agent'), null, null);
        throw new lrs_exception(403, 'err_lrsreadonly');
    }

    /**
     * Creates or updates the preferences row.
     *
     * @param int $userid Learner.
     * @param string $language languagePreference.
     * @param bool $retry Whether a refused insert is retried once as an update.
     */
    private static function write_preferences(int $userid, string $language, bool $retry): void {
        global $DB;
        $key = ['userid' => $userid, 'profileid' => vocab::PREFS_ID];
        $existing = $DB->get_record('scormmakercmi5_agentprof', $key);
        $prefs = ['languagePreference' => $language, 'audioPreference' => self::AUDIO_DEFAULT];
        if ($existing) {
            $old = json_decode($existing->content, true);
            if (is_array($old) && in_array($old['audioPreference'] ?? null, self::AUDIO_VALUES, true)) {
                $prefs['audioPreference'] = $old['audioPreference'];
            }
        }
        $content = json_encode($prefs, statement_store::JSON_FLAGS);
        if ($existing && $existing->content === $content) {
            return;
        }
        $row = (object)($key + ['content' => $content, 'etag' => sha1($content), 'updatedms' => xapi_time::now_ms()]);
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('scormmakercmi5_agentprof', $row);
            return;
        }
        if (self::$beforeinsert) {
            (self::$beforeinsert)();
        }
        try {
            $DB->insert_record('scormmakercmi5_agentprof', $row);
        } catch (\dml_write_exception $e) {
            if (!$retry || !$DB->record_exists('scormmakercmi5_agentprof', $key)) {
                throw $e;
            }
            self::write_preferences($userid, $language, false);
        }
    }

    /**
     * RFC 5646 tag of a Moodle language: language and region only ("pt_br" → "pt-BR"); a further part of the
     * code names a Moodle variant pack ("en_us_k12"), not a subtag, and is dropped.
     *
     * @param string $moodlelang Moodle language code.
     * @return string
     */
    private static function language_tag(string $moodlelang): string {
        $parts = explode('_', $moodlelang);
        return course_structure::rfc5646(implode('_', array_slice($parts, 0, 2)));
    }
}
