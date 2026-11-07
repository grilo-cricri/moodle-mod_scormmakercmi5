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

use mod_scormmakercmi5\completion\custom_completion;
use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\grading\grade_calculator;
use mod_scormmakercmi5\local\lrs\agent_profile;
use mod_scormmakercmi5\local\lrs\endpoint;
use mod_scormmakercmi5\local\lrs\state_store;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5\local\lrs\xapi_time;
use mod_scormmakercmi5\local\package\cmi5_parser;
use mod_scormmakercmi5\local\package\importer;

/**
 * Registration, launch and abandonment of AU sessions (design §6.1, §6.3; R4.1, R4.4, R4.5).
 *
 * Everything a launch writes (abandoned statements of open sessions, the new session, LMS.LaunchData,
 * cmi5LearnerPreferences and the launched statement) is written under the registration lock 'reg<id>' and in one
 * transaction, before the URL reaches the browser: the AU finds its launch data when it starts (cmi5 §10.1,
 * §11), and two tabs launching at once are serialised.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class launcher {
    /** @var int Session launched, before or after the fetch. */
    public const STATUS_LAUNCHED = 0;

    /** @var int The AU sent initialized. */
    public const STATUS_INITIALIZED = 1;

    /** @var int The AU sent terminated (or a synthetic session). */
    public const STATUS_TERMINATED = 2;

    /** @var int The LMS abandoned the session (relaunch or timeout). */
    public const STATUS_ABANDONED = 3;

    /** @var int Session of a launch. */
    public const KIND_LAUNCH = 0;

    /** @var int Synthetic session of LMS statements outside a launch (waived, satisfied; cmi5 §9.3.7, §9.3.9). */
    public const KIND_SYNTHETIC = 1;

    /** @var string[] Launch modes (cmi5 §10.2.2). */
    public const MODES = ['Normal', 'Browse', 'Review'];

    /** @var string Component and lock type. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /** @var int Seconds launch() waits for the registration lock. Protected so a test can make it 0. */
    protected static int $locktimeout = 5;

    /**
     * The registration of a learner in an instance, created on first use (R4.4).
     *
     * A new one is pinned to the current package version (R1.5), has a v4 UUID and one regau per AU of that
     * version, and its NotApplicable AUs are satisfied at once (satisfaction::initialize_registration). Two requests
     * creating it at once end with one row: the unique index (instance, user) refuses the
     * second insert, which then reads the row of the first.
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid Learner.
     * @return \stdClass The scormmakercmi5_reg row.
     */
    public static function get_or_create_registration(\stdClass $instance, int $userid): \stdClass {
        global $DB;
        $key = ['scormmakercmi5id' => (int)$instance->id, 'userid' => $userid];
        $reg = $DB->get_record('scormmakercmi5_reg', $key);
        if ($reg) {
            return $reg;
        }
        $pkgid = (int)$DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $instance->id], MUST_EXIST);
        $now = \core\di::get(\core\clock::class)->time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $regid = $DB->insert_record('scormmakercmi5_reg', (object)($key + [
                'pkgid' => $pkgid,
                'registration' => \core\uuid::generate(),
                'timecreated' => $now,
                'timemodified' => $now,
            ]));
        } catch (\dml_write_exception $e) {
            $transaction->allow_commit();
            return $DB->get_record('scormmakercmi5_reg', $key) ?: throw $e;
        }
        try {
            $rows = [];
            foreach ($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder', 'id') as $au) {
                $rows[] = ['regid' => $regid, 'auid' => $au->id];
            }
            $DB->insert_records('scormmakercmi5_regau', $rows);
            // NotApplicable AUs are satisfied from the start; the blocks and course they complete are written now
            // (design §6.1 step 2, R6.3), in this transaction, where no one else sees the registration yet.
            satisfaction::initialize_registration($DB->get_record('scormmakercmi5_reg', ['id' => $regid], '*', MUST_EXIST));
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return $DB->get_record('scormmakercmi5_reg', ['id' => $regid], '*', MUST_EXIST);
    }

    /**
     * Launches an AU for a learner (design §6.1 steps 2–8). Access (login, view, sesskey) is checked by the page.
     *
     * @param \cm_info $cm Course module of the instance.
     * @param \stdClass $instance Instance row.
     * @param int $auid AU id; it must be in the package version of the registration.
     * @param int $userid Learner.
     * @param string $mode Normal, Browse (needs browsemode) or Review (needs reviewmode or a satisfied AU).
     * @return launch_result
     * @throws \moodle_exception err_mode, err_aunotinpkg, err_externalauoff, err_locktimeout.
     */
    public static function launch(\cm_info $cm, \stdClass $instance, int $auid, int $userid, string $mode): launch_result {
        global $DB;
        if ((int)$cm->instance !== (int)$instance->id) {
            throw new \coding_exception('The course module is not the one of the instance');
        }
        if (!in_array($mode, self::MODES, true) || ($mode === 'Browse' && empty($instance->browsemode))) {
            throw self::error('err_mode');
        }
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $auid]);
        if (!$au || !$DB->record_exists('scormmakercmi5_pkg', ['id' => $au->pkgid, 'scormmakercmi5id' => $instance->id])) {
            throw self::error('err_aunotinpkg');
        }
        $reg = self::get_or_create_registration($instance, $userid);
        if ((int)$au->pkgid !== (int)$reg->pkgid) {
            throw self::error('err_aunotinpkg');
        }
        if (self::external_refused($au)) {
            throw self::error('err_externalauoff');
        }
        // A grade left behind by an update that failed after the commit (final review N-1). Never blocks the launch.
        try {
            grade_calculator::repair_user($instance, $userid);
        } catch (\Throwable $e) {
            endpoint::log_failure('launch grade', $e);
        }
        if ((int)$reg->satisfied === 1) {
            // Born satisfied (every AU NotApplicable, R6.3), or a completion update that failed after the commit of the
            // batch that satisfied it: the completion rule is met now (design §6.6, R6.2). Never blocks the launch.
            try {
                custom_completion::repair_user($cm, $userid);
            } catch (\Throwable $e) {
                endpoint::log_failure('launch completion', $e);
            }
        }

        $lock = self::registration_lock((int)$reg->id, static::$locktimeout);
        if (!$lock) {
            throw self::error('err_locktimeout');
        }
        try {
            $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $reg->id], '*', MUST_EXIST);
            if (
                $mode === 'Review' && empty($instance->reviewmode)
                    && !$DB->record_exists('scormmakercmi5_regau', ['regid' => $reg->id, 'auid' => $au->id, 'satisfied' => 1])
            ) {
                throw self::error('err_mode');
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = self::launch_locked($cm, $instance, $au, $reg, $userid, $mode);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Abandons an open session (design §6.1 step 3, cmi5 §9.3.6): status abandoned, token revoked and an
     * "abandoned" statement whose duration is the last statement of the AU in the session minus launched
     * (§9.5.4.2; zero when the AU sent nothing; a timestamp after $nowms counts as $nowms). A session that is no
     * longer open is left alone, so a session is never abandoned twice.
     *
     * The caller holds the registration lock and a transaction.
     *
     * @param \stdClass $session Session row, read under the lock.
     * @param int $nowms Time of the abandonment, milliseconds.
     */
    public static function abandon_session(\stdClass $session, int $nowms): void {
        global $DB;
        if (!in_array((int)$session->status, [self::STATUS_LAUNCHED, self::STATUS_INITIALIZED], true)) {
            return;
        }
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], '*', MUST_EXIST);
        $last = $DB->get_records(
            'scormmakercmi5_stmt',
            ['sessionid' => $session->id, 'source' => statement_store::SOURCE_AU],
            'timestampms DESC',
            'id, timestampms',
            0,
            1
        );
        // The timestamp comes from the AU's clock: one in the future never inflates the duration past now.
        $lastms = $last ? min((int)reset($last)->timestampms, $nowms) : (int)$session->timelaunchedms;
        $durationms = max(0, $lastms - (int)$session->timelaunchedms);

        $session->status = self::STATUS_ABANDONED;
        $session->tokenexpires = 0;
        $session->timeendedms = $nowms;
        $session->durationms = $durationms;
        $DB->update_record('scormmakercmi5_session', (object)[
            'id' => $session->id,
            'status' => $session->status,
            'tokenexpires' => 0,
            'timeendedms' => $nowms,
            'durationms' => $durationms,
        ]);
        $statement = lms_statements::abandoned($reg, $au, $session, self::context_template($au, $session), $durationms);
        $statement['timestamp'] = xapi_time::from_ms($nowms);
        statement_store::insert_lms($statement, $reg, (int)$au->id, (int)$session->id);
    }

    /**
     * A session for LMS statements outside a launch (waived, satisfied): terminated at once, without keys.
     *
     * @param \stdClass $reg Registration.
     * @param int $auid AU the statements are about.
     * @return \stdClass The session row.
     */
    public static function create_synthetic_session(\stdClass $reg, int $auid): \stdClass {
        global $DB;
        $nowms = xapi_time::now_ms();
        $session = (object)[
            'regid' => $reg->id,
            'auid' => $auid,
            'userid' => $reg->userid,
            'sessionuuid' => \core\uuid::generate(),
            'kind' => self::KIND_SYNTHETIC,
            'launchmode' => 'Normal',
            'status' => self::STATUS_TERMINATED,
            'fetchkeyhash' => null,
            'fetchused' => 0,
            'tokenhash' => null,
            'tokenexpires' => 0,
            'prefsread' => 0,
            'timelaunchedms' => $nowms,
            'timeinitializedms' => 0,
            'lastactivityms' => $nowms,
            'timeendedms' => $nowms,
            'durationms' => null,
        ];
        $session->id = $DB->insert_record('scormmakercmi5_session', $session);
        return $session;
    }

    /**
     * The contextTemplate of a session (cmi5 §10.2.1): session id extension and publisher id in grouping.
     *
     * @param \stdClass $au AU.
     * @param \stdClass $session Session.
     * @return array
     */
    public static function context_template(\stdClass $au, \stdClass $session): array {
        return [
            'contextActivities' => [
                'grouping' => [['objectType' => 'Activity', 'id' => $au->publisherid]],
            ],
            'extensions' => [vocab::EXT_SESSIONID => $session->sessionuuid],
        ];
    }

    /**
     * The registration lock ('reg<id>'), shared by the launch, the cleanup task and the statement ingestion.
     *
     * @param int $regid Registration id.
     * @param int $timeout Seconds to wait.
     * @return \core\lock\lock|false
     */
    public static function registration_lock(int $regid, int $timeout): \core\lock\lock|false {
        return \core\lock\lock_config::get_lock_factory(self::COMPONENT)->get_lock('reg' . $regid, $timeout);
    }

    /**
     * The writes of a launch, under the lock and in the transaction.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $instance Instance.
     * @param \stdClass $au AU.
     * @param \stdClass $reg Registration.
     * @param int $userid Learner.
     * @param string $mode Launch mode.
     * @return launch_result
     */
    private static function launch_locked(
        \cm_info $cm,
        \stdClass $instance,
        \stdClass $au,
        \stdClass $reg,
        int $userid,
        string $mode
    ): launch_result {
        global $DB;
        $nowms = xapi_time::now_ms();

        // Step 3: every active session of the registration is abandoned, of this AU or of another one (cmi5 §9.3.6:
        // "If an LMS launches an AU with the same registration as an active session, the previous session with that
        // registration MUST be abandoned"; a cmi5 registration covers the whole course, not one AU).
        [$insql, $params] = $DB->get_in_or_equal([self::STATUS_LAUNCHED, self::STATUS_INITIALIZED], SQL_PARAMS_NAMED);
        $open = $DB->get_records_select(
            'scormmakercmi5_session',
            "regid = :regid AND kind = :kind AND status {$insql}",
            $params + ['regid' => $reg->id, 'kind' => self::KIND_LAUNCH],
            'id'
        );
        foreach ($open as $old) {
            self::abandon_session($old, $nowms);
        }

        // Step 4: new session; the fetch key exists only in the URL, the table keeps its hash.
        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = (object)[
            'regid' => $reg->id,
            'auid' => $au->id,
            'userid' => $userid,
            'sessionuuid' => \core\uuid::generate(),
            'kind' => self::KIND_LAUNCH,
            'launchmode' => $mode,
            'status' => self::STATUS_LAUNCHED,
            'fetchkeyhash' => hash('sha256', $key),
            'fetchused' => 0,
            'tokenhash' => null,
            'tokenexpires' => 0,
            'prefsread' => 0,
            'timelaunchedms' => $nowms,
            'timeinitializedms' => 0,
            'lastactivityms' => $nowms,
            'timeendedms' => 0,
            'durationms' => null,
        ];
        $session->id = $DB->insert_record('scormmakercmi5_session', $session);

        // Step 8 first: the launched statement carries the launch URL.
        [$url, $launchurl] = self::au_url($cm, $au, [
            'endpoint' => (new \moodle_url('/mod/scormmakercmi5/lrs.php/'))->out(false),
            'fetch' => (new \moodle_url('/mod/scormmakercmi5/fetch.php', ['k' => $key]))->out(false),
            'actor' => json_encode(actor::for_user($userid), statement_store::JSON_FLAGS),
            'registration' => $reg->registration,
            'activityId' => $au->lmsactivityid,
        ]);

        // Step 5: LMS.LaunchData.
        $masteryscore = $au->masteryscore === null ? null : (float)$au->masteryscore;
        $template = self::context_template($au, $session);
        $launchdata = ['contextTemplate' => $template, 'launchMode' => $mode];
        if ($au->launchparameters !== null) {
            $launchdata['launchParameters'] = $au->launchparameters;
        }
        if ($masteryscore !== null) {
            $launchdata['masteryScore'] = $masteryscore;
        }
        $launchdata['moveOn'] = $au->moveon;
        $launchdata['returnURL'] = (new \moodle_url('/mod/scormmakercmi5/view.php', ['id' => $cm->id]))->out(false);
        if ($au->entitlementkey !== null) {
            $launchdata['entitlementKey'] = ['courseStructure' => $au->entitlementkey];
        }
        state_store::put_launchdata($reg, $au, $launchdata, $userid, (int)$instance->id);

        // Step 6: learner preferences.
        agent_profile::ensure_learner_preferences($userid, self::user_language($cm, $userid));

        // Step 7: launched.
        $statement = lms_statements::launched(
            $reg,
            $au,
            $session,
            $template,
            $mode,
            $launchurl,
            $au->moveon,
            $masteryscore,
            $au->launchparameters
        );
        statement_store::insert_lms($statement, $reg, (int)$au->id, (int)$session->id);

        return new launch_result($session, $url, $au->launchmethod);
    }

    /**
     * The URL of the AU (design §6.1 step 8) and the same URL without the cmi5 parameters (cmi5 §9.6.3.4).
     *
     * The url of cmi5.xml is taken apart, never concatenated: its path becomes the pluginfile path of the
     * package version (each segment encoded again, so a literal space works), its query parameters are kept in
     * order and the cmi5 parameters come after them, before the fragment, which is appended last. Duplicate
     * names of the original query keep the last value, and names made only of digits cannot be expressed by
     * moodle_url and are dropped.
     *
     * An AU outside the package (auorigin set) launches at its own https URL, taken apart the same way: scheme, host,
     * port and path as written, the query rebuilt parameter by parameter (each name and value encoded again by
     * moodle_url), the cmi5 parameters after it and the fragment last. The url must still give the stored origin.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $au AU.
     * @param array $cmi5params endpoint, fetch, actor, registration, activityId.
     * @return array [\moodle_url launch URL, string URL without the cmi5 parameters].
     */
    private static function au_url(\cm_info $cm, \stdClass $au, array $cmi5params): array {
        [$rest, $fragment] = array_pad(explode('#', $au->url, 2), 2, null);
        [$base, $query] = array_pad(explode('?', $rest, 2), 2, '');
        if (($au->auorigin ?? null) !== null && $au->auorigin !== '') {
            try {
                $origin = cmi5_parser::external_origin($au->url);
            } catch (\moodle_exception $e) {
                $origin = null;
            }
            if ($origin === null || $origin !== $au->auorigin) {
                throw new \coding_exception('The AU url does not give its stored origin');
            }
            $url = new \moodle_url($base);
        } else {
            $file = cmi5_parser::package_path($au->url);
            if ($file === null || $file === '') {
                throw new \coding_exception('The AU url was not checked at import');
            }
            $slash = strrpos($file, '/');
            $filepath = $slash === false ? '/' : '/' . substr($file, 0, $slash + 1);
            $filename = $slash === false ? $file : substr($file, $slash + 1);
            $url = \moodle_url::make_pluginfile_url(
                $cm->context->id,
                self::COMPONENT,
                'content',
                (int)$au->pkgid,
                $filepath,
                $filename
            );
        }
        $original = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            if ($name !== '' && !ctype_digit($name)) {
                $original[$name] = urldecode($value);
            }
        }
        $url->params($original);
        if ($fragment !== null) {
            $url->set_anchor($fragment);
        }
        $launchurl = $url->out(false);
        $url->params($cmi5params);
        return [$url, $launchurl];
    }

    /**
     * The language the learner sees the course in: forced by the course, else the one of the user, else the site.
     *
     * @param \cm_info $cm Course module.
     * @param int $userid Learner.
     * @return string Moodle language code.
     */
    private static function user_language(\cm_info $cm, int $userid): string {
        global $CFG, $DB;
        $course = $cm->get_course();
        if (!empty($course->lang)) {
            return $course->lang;
        }
        $lang = $DB->get_field('user', 'lang', ['id' => $userid]);
        return $lang ?: $CFG->lang;
    }

    /**
     * Whether an AU outside the package is not launched on this site (checked before anything is written): any of
     * them while allowexternalau is off (final review m-1: the admin turns it off to stop sending learners to such
     * sites, so the AUs already on the site stop too), an absolute url without a stored origin (restored while
     * allowexternalau was off), or an absolute url the current rules refuse (a host accepted by the first rule of
     * 0.3.0, such as 127.1). A url that gives another valid origin than the stored one is a changed row, left to
     * au_url().
     *
     * @param \stdClass $au AU row.
     * @return bool
     */
    private static function external_refused(\stdClass $au): bool {
        try {
            $origin = cmi5_parser::external_origin((string)$au->url);
        } catch (\moodle_exception $e) {
            return true;
        }
        if ($origin === null) {
            return false;
        }
        return (string)($au->auorigin ?? '') === '' || !importer::external_au_allowed();
    }

    /**
     * An error of this plugin.
     *
     * @param string $code String id.
     * @return \moodle_exception
     */
    private static function error(string $code): \moodle_exception {
        return new \moodle_exception($code, self::COMPONENT);
    }
}
