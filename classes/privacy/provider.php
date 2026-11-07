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

namespace mod_scormmakercmi5\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_scormmakercmi5\completion\custom_completion;
use mod_scormmakercmi5\local\lrs\endpoint;
use mod_scormmakercmi5\local\package\course_structure;
use mod_scormmakercmi5\local\runtime\attempt_manager;

/**
 * Privacy API provider for mod_scormmakercmi5 (design §11, NF2).
 *
 * Two contexts hold personal data. The module context: the registration of a learner with its AU and block status,
 * sessions, statements and State, found by reg.userid and, for a State document written after its registration was
 * deleted (design §6.5), by state.userid; and the package versions a user uploaded (pkg.usermodified). The user
 * context: the Agent Profile (learner preferences), which belongs to the learner and not to an activity.
 *
 * Export goes under [activity name] (the registration, its blocks, the uploaded packages and the data that no AU
 * claims) and [activity name, AU] (status, sessions without the token hashes, statements with the stored payload,
 * State). Deletion reuses the cascade of "Delete attempt" (attempt_manager::purge_registration(): stmt → state →
 * session → regblock → regau → reg in one transaction), then sweeps State and statements of the user left without a
 * registration, recomputes the grade (back to null) and the completion. The package versions are kept, because
 * registrations depend on them: usermodified becomes 0.
 *
 * Core catches whatever a provider throws, mails the DPO and closes the request as done, with no retry; so a busy
 * registration never stops the deletion: purge_registration() waits for the lock and, if it does not come, revokes the
 * tokens and deletes without it. Every user of the list is processed and the uploader is always cleared before an
 * unexpected error (a database failure) is rethrown, which then at least reaches the DPO.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /** @var array<string, string[]> Tables with personal data and the fields declared for each. */
    private const TABLES = [
        // Who uploaded each package version; deletion sets usermodified to 0 and keeps the package.
        'scormmakercmi5_pkg' => [
            'usermodified', 'timecreated',
        ],
        'scormmakercmi5_reg' => [
            'userid', 'registration', 'satisfied', 'timesatisfied', 'grade', 'timecreated', 'timemodified',
        ],
        'scormmakercmi5_regau' => [
            'regid', 'completed', 'passed', 'failed', 'waived', 'waivedreason', 'satisfied',
            'scorehighest', 'scorelast', 'timecompleted', 'timepassed', 'timesatisfied',
        ],
        'scormmakercmi5_regblock' => [
            'regid', 'blockid', 'timesatisfied',
        ],
        // The fetch key and token hashes are declared but never exported.
        'scormmakercmi5_session' => [
            'userid', 'sessionuuid', 'launchmode', 'status', 'fetchkeyhash', 'tokenhash',
            'timelaunchedms', 'timeinitializedms', 'lastactivityms', 'timeendedms', 'durationms',
        ],
        'scormmakercmi5_stmt' => [
            'userid', 'statementid', 'verb', 'objectid', 'timestampms', 'success', 'completion',
            'scorescaled', 'durationms', 'payload',
        ],
        'scormmakercmi5_state' => [
            'userid', 'stateid', 'content', 'updatedms',
        ],
        'scormmakercmi5_agentprof' => [
            'userid', 'profileid', 'content', 'updatedms',
        ],
    ];

    /** @var string[] Session status names by value (launcher::STATUS_*). */
    private const SESSION_STATUS = ['launched', 'initialized', 'terminated', 'abandoned'];

    /** @var string Session fields exported: everything but the hashes of the fetch key and of the token. */
    private const SESSION_FIELDS = 'id, auid, sessionuuid, kind, launchmode, status, timelaunchedms, timeinitializedms, '
        . 'lastactivityms, timeendedms, durationms';

    /**
     * Describes the personal data stored by the plugin.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        foreach (self::TABLES as $table => $fields) {
            $fieldstrings = [];
            foreach ($fields as $field) {
                $fieldstrings[$field] = "privacy:metadata:{$table}:{$field}";
            }
            $collection->add_database_table($table, $fieldstrings, "privacy:metadata:{$table}");
        }
        // An AU outside the package (absolute https url) gets the actor (the user id, no name) and the registration in
        // its launch URL, and a token that reads and writes the statements, State and Agent Profile of the learner.
        $collection->add_external_location_link('externalau', [
            'actor' => 'privacy:metadata:externalau:actor',
            'registration' => 'privacy:metadata:externalau:registration',
            'statements' => 'privacy:metadata:externalau:statements',
            'state' => 'privacy:metadata:externalau:state',
            'agentprofile' => 'privacy:metadata:externalau:agentprofile',
        ], 'privacy:metadata:externalau');
        return $collection;
    }

    /**
     * The contexts with data of a user: the modules where they have a registration, a State document or an uploaded
     * package version, and their own user context when they have an Agent Profile.
     *
     * @param int $userid User.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = ['modlevel' => CONTEXT_MODULE, 'modname' => 'scormmakercmi5', 'userid' => $userid];
        $base = "SELECT ctx.id
                   FROM {context} ctx
                   JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modlevel
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname";
        $sources = ['scormmakercmi5_reg' => 'userid', 'scormmakercmi5_state' => 'userid', 'scormmakercmi5_pkg' => 'usermodified'];
        foreach ($sources as $table => $field) {
            $sql = "$base
                   JOIN {{$table}} x ON x.scormmakercmi5id = cm.instance
                  WHERE x.$field = :userid";
            $contextlist->add_from_sql($sql, $params);
        }
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {scormmakercmi5_agentprof} a ON a.userid = ctx.instanceid AND ctx.contextlevel = :userlevel
              WHERE a.userid = :userid",
            ['userlevel' => CONTEXT_USER, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * The users with data in a context.
     *
     * @param userlist $userlist The list to fill; its context is the one asked about.
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            if ($DB->record_exists('scormmakercmi5_agentprof', ['userid' => $context->instanceid])) {
                $userlist->add_user((int)$context->instanceid);
            }
            return;
        }
        $instance = self::instance_of($context);
        if (!$instance) {
            return;
        }
        $params = ['instanceid' => $instance->id];
        foreach (['scormmakercmi5_reg', 'scormmakercmi5_state'] as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}} WHERE scormmakercmi5id = :instanceid", $params);
        }
        $userlist->add_from_sql(
            'usermodified',
            'SELECT usermodified FROM {scormmakercmi5_pkg} WHERE scormmakercmi5id = :instanceid AND usermodified > 0',
            $params
        );
    }

    /**
     * Exports the data of the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts of one user.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $user = $contextlist->get_user();
        $userid = (int)$user->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user) {
                if ((int)$context->instanceid === $userid) {
                    self::export_preferences($context, $userid);
                }
                continue;
            }
            $instance = self::instance_of($context);
            if ($instance) {
                self::export_activity($context, $instance, $user);
            }
        }
    }

    /**
     * Deletes the data of every user in a context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_user) {
            $DB->delete_records('scormmakercmi5_agentprof', ['userid' => $context->instanceid]);
            return;
        }
        $instance = self::instance_of($context);
        if (!$instance) {
            return;
        }
        $params = ['scormmakercmi5id' => $instance->id];
        $userids = array_unique(array_merge(
            $DB->get_fieldset_select('scormmakercmi5_reg', 'userid', 'scormmakercmi5id = :scormmakercmi5id', $params),
            $DB->get_fieldset_select('scormmakercmi5_state', 'userid', 'scormmakercmi5id = :scormmakercmi5id', $params)
        ));
        $failure = null;
        foreach ($userids as $userid) {
            try {
                self::delete_learner($instance, (int)$userid);
            } catch (\Throwable $e) {
                $failure = $failure ?? $e;
            }
        }
        $DB->set_field_select('scormmakercmi5_pkg', 'usermodified', 0, 'scormmakercmi5id = :scormmakercmi5id', $params);
        if ($failure) {
            throw $failure;
        }
    }

    /**
     * Deletes the data of one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts of one user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_users_in_context($context, [$userid]);
        }
    }

    /**
     * Deletes everything of one user in every activity and their Agent Profile, with no request in hand: the contexts
     * found by get_contexts_for_userid() go through the same deletion as delete_data_for_user(), except that grade and
     * completion are not recomputed. Made for an account that was just deleted (\core\event\user_deleted fires after
     * the user row is marked deleted and after delete_user() removed their grades and completion), so recomputing
     * would insert fresh gradebook and completion rows for the deleted account. The Agent Profile is also removed by
     * user id, as a safety net when its user context is already gone. The package versions the user uploaded stay,
     * with usermodified = 0, as in any deletion. It waits for a registration lock like the privacy deletion does
     * (up to the privacy lock timeout). Called from a transaction, a failure of the cascade rolls that transaction
     * back; the user_deleted observer is not internal, so it runs after the account deletion has committed.
     *
     * @param int $userid User.
     */
    public static function delete_all_data_of_user(int $userid): void {
        global $DB;
        $contextids = array_map('intval', self::get_contexts_for_userid($userid)->get_contextids());
        try {
            $failure = null;
            foreach ($contextids as $contextid) {
                $context = \context::instance_by_id($contextid, IGNORE_MISSING);
                if (!$context) {
                    continue;
                }
                try {
                    self::delete_users_in_context($context, [$userid], false);
                } catch (\Throwable $e) {
                    $failure = $failure ?? $e;
                }
            }
            if ($failure) {
                throw $failure;
            }
        } finally {
            $DB->delete_records('scormmakercmi5_agentprof', ['userid' => $userid]);
        }
    }

    /**
     * Deletes the data of several users in one context.
     *
     * @param approved_userlist $userlist Approved users of one context.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        self::delete_users_in_context($userlist->get_context(), array_map('intval', $userlist->get_userids()));
    }

    /**
     * Deletes the data of the users in a context: the Agent Profile of the owner of a user context; the learner data
     * and the uploader of the package versions of a module.
     *
     * @param \context $context Context.
     * @param int[] $userids Users.
     * @param bool $recompute Whether to recompute grade and completion (false for a deleted account).
     */
    private static function delete_users_in_context(\context $context, array $userids, bool $recompute = true): void {
        global $DB;
        if ($context instanceof \context_user) {
            if (in_array((int)$context->instanceid, $userids, true)) {
                $DB->delete_records('scormmakercmi5_agentprof', ['userid' => $context->instanceid]);
            }
            return;
        }
        $instance = self::instance_of($context);
        if (!$instance) {
            return;
        }
        $failure = null;
        foreach ($userids as $userid) {
            $uploader = ['scormmakercmi5id' => $instance->id, 'usermodified' => $userid];
            $DB->set_field('scormmakercmi5_pkg', 'usermodified', 0, $uploader);
            try {
                self::delete_learner($instance, $userid, $recompute);
            } catch (\Throwable $e) {
                $failure = $failure ?? $e;
            }
        }
        if ($failure) {
            throw $failure;
        }
    }

    /**
     * Deletes the learner data of a user in an instance: the registration with the cascade of "Delete attempt", then
     * State and statements left without a registration (design §6.5); the grade goes back to null and the completion
     * is recomputed, after the commit and each in its own try, as delete_attempt() does (a failure there is repaired
     * by grade_calculator::repair_user() and custom_completion::repair_user() at the next view or launch).
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid User.
     * @param bool $recompute Whether to recompute grade and completion (false for a deleted account, whose grades
     *     and completion core already removed).
     */
    private static function delete_learner(\stdClass $instance, int $userid, bool $recompute = true): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $key = ['scormmakercmi5id' => $instance->id, 'userid' => $userid];
        $reg = $DB->get_record('scormmakercmi5_reg', $key);
        if ($reg) {
            attempt_manager::purge_registration($reg);
        }
        $DB->delete_records('scormmakercmi5_state', $key);
        $DB->delete_records('scormmakercmi5_stmt', $key);
        if (!$reg || !$recompute) {
            return;
        }
        try {
            scormmakercmi5_update_grades($instance, $userid);
        } catch (\Throwable $e) {
            endpoint::log_failure('privacy grade', $e);
        }
        try {
            custom_completion::update_user($instance, $userid, COMPLETION_INCOMPLETE);
        } catch (\Throwable $e) {
            endpoint::log_failure('privacy completion', $e);
        }
    }

    /**
     * Exports the Agent Profile documents of a user in their user context.
     *
     * @param \context_user $context User context.
     * @param int $userid User.
     */
    private static function export_preferences(\context_user $context, int $userid): void {
        global $DB;
        $rows = $DB->get_records('scormmakercmi5_agentprof', ['userid' => $userid], 'profileid, id');
        if (!$rows) {
            return;
        }
        $profiles = [];
        foreach ($rows as $row) {
            $profiles[] = [
                'profileid' => $row->profileid,
                'content' => self::document($row->content),
                'updated' => self::datetime_ms((int)$row->updatedms),
            ];
        }
        writer::with_context($context)->export_data(
            [get_string('privacy:path:preferences', self::COMPONENT)],
            (object)['profiles' => $profiles]
        );
    }

    /**
     * Exports the data of a user in an activity.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $instance Instance row.
     * @param \stdClass $user User.
     */
    private static function export_activity(\context_module $context, \stdClass $instance, \stdClass $user): void {
        global $DB;
        $userid = (int)$user->id;
        $key = ['scormmakercmi5id' => $instance->id, 'userid' => $userid];
        $reg = $DB->get_record('scormmakercmi5_reg', $key);
        $states = $DB->get_records('scormmakercmi5_state', $key, 'id');
        $uploader = ['scormmakercmi5id' => $instance->id, 'usermodified' => $userid];
        $packages = $DB->get_records('scormmakercmi5_pkg', $uploader, 'versionnum', 'id, versionnum, filename, timecreated');
        if (!$reg && !$states && !$packages) {
            return;
        }

        $writer = writer::with_context($context);
        $writer->export_data([], helper::get_context_data($context, $user));
        helper::export_context_files($context, $user);
        $name = format_string($instance->name, true, ['context' => $context]);

        if ($packages) {
            $list = [];
            foreach ($packages as $pkg) {
                $list[] = [
                    'version' => (int)$pkg->versionnum,
                    'filename' => $pkg->filename,
                    'timecreated' => transform::datetime((int)$pkg->timecreated),
                ];
            }
            $writer->export_data([$name, get_string('privacy:path:packages', self::COMPONENT)], (object)['packages' => $list]);
        }

        // AU of each piece of data, by id and by the hash of its activity id (the key of the State documents).
        $aus = $DB->get_records_sql(
            "SELECT au.id, au.pkgid, au.lmsactivityid, au.title, au.sortorder, p.versionnum
               FROM {scormmakercmi5_au} au
               JOIN {scormmakercmi5_pkg} p ON p.id = au.pkgid
              WHERE p.scormmakercmi5id = :instanceid
           ORDER BY p.versionnum, au.sortorder",
            ['instanceid' => $instance->id]
        );
        // A new version inherits the activity id of an AU (design §3.4): the State goes to the AU of the version of the
        // registration, where its statements are, or else to the first version that has it.
        $byhash = [];
        foreach ($aus as $au) {
            $hash = sha1($au->lmsactivityid);
            if (!isset($byhash[$hash]) || ($reg && (int)$au->pkgid === (int)$reg->pkgid)) {
                $byhash[$hash] = (int)$au->id;
            }
        }
        $perau = [];
        $root = [];
        if ($reg) {
            foreach (self::regau_status((int)$reg->id) as $auid => $status) {
                $perau[$auid]['status'] = $status;
            }
            foreach (self::sessions((int)$reg->id) as $auid => $sessions) {
                $perau[$auid]['sessions'] = $sessions;
            }
        }
        // The statements are read per AU when that AU is written, not all at once (memory of a long history).
        $stmtauids = $DB->get_fieldset_sql(
            'SELECT DISTINCT auid FROM {scormmakercmi5_stmt} WHERE scormmakercmi5id = :scormmakercmi5id AND userid = :userid',
            $key
        );
        foreach ($stmtauids as $auid) {
            $perau[(int)$auid]['statements'] = [];
        }
        foreach ($states as $state) {
            $auid = $byhash[$state->activityhash] ?? 0;
            if ($auid) {
                $perau[$auid]['states'][] = self::state($state);
            } else {
                $root['states'][] = self::state($state);
            }
        }

        if ($reg) {
            $root = self::registration($reg) + $root;
        }
        $names = self::au_names(array_intersect_key($aus, $perau));
        foreach ($perau as $auid => $data) {
            if (isset($data['statements'])) {
                $data['statements'] = self::statements($key, $auid);
            }
            if (!isset($names[$auid])) {
                // Data of an AU no longer in the activity stays with the registration.
                foreach ($data as $kind => $items) {
                    $root[$kind] = array_merge($root[$kind] ?? [], $kind === 'status' ? [$items] : $items);
                }
                continue;
            }
            $writer->export_data([$name, $names[$auid]], (object)$data);
        }
        if ($root) {
            $writer->export_data([$name], (object)$root);
        }
    }

    /**
     * The registration fields exported, with the satisfied blocks.
     *
     * @param \stdClass $reg Registration row.
     * @return array
     */
    private static function registration(\stdClass $reg): array {
        global $DB;
        $blocks = [];
        $rows = $DB->get_records_sql(
            "SELECT rb.id, rb.timesatisfied, b.title, b.publisherid
               FROM {scormmakercmi5_regblock} rb
               JOIN {scormmakercmi5_block} b ON b.id = rb.blockid
              WHERE rb.regid = :regid
           ORDER BY rb.id",
            ['regid' => $reg->id]
        );
        foreach ($rows as $row) {
            $blocks[] = [
                'block' => self::title((string)$row->title),
                'publisherid' => $row->publisherid,
                'timesatisfied' => self::datetime((int)$row->timesatisfied),
            ];
        }
        return [
            'registration' => $reg->registration,
            'packageversion' => (int)$DB->get_field('scormmakercmi5_pkg', 'versionnum', ['id' => $reg->pkgid]),
            'satisfied' => transform::yesno((int)$reg->satisfied === 1),
            'timesatisfied' => self::datetime((int)$reg->timesatisfied),
            'grade' => $reg->grade === null ? null : (float)$reg->grade,
            'timecreated' => self::datetime((int)$reg->timecreated),
            'timemodified' => self::datetime((int)$reg->timemodified),
            'blocks' => $blocks,
        ];
    }

    /**
     * The AU status of a registration.
     *
     * @param int $regid Registration.
     * @return array auid => status.
     */
    private static function regau_status(int $regid): array {
        global $DB;
        $result = [];
        foreach ($DB->get_records('scormmakercmi5_regau', ['regid' => $regid], 'id') as $row) {
            $result[(int)$row->auid] = [
                'completed' => transform::yesno((int)$row->completed === 1),
                'passed' => transform::yesno((int)$row->passed === 1),
                'failed' => transform::yesno((int)$row->failed === 1),
                'waived' => transform::yesno((int)$row->waived === 1),
                'waivedreason' => $row->waivedreason,
                'satisfied' => transform::yesno((int)$row->satisfied === 1),
                'scorehighest' => $row->scorehighest === null ? null : (float)$row->scorehighest,
                'scorelast' => $row->scorelast === null ? null : (float)$row->scorelast,
                'timecompleted' => self::datetime((int)$row->timecompleted),
                'timepassed' => self::datetime((int)$row->timepassed),
                'timesatisfied' => self::datetime((int)$row->timesatisfied),
            ];
        }
        return $result;
    }

    /**
     * The sessions of a registration, without the hashes of the fetch key and of the token.
     *
     * @param int $regid Registration.
     * @return array auid => list of sessions.
     */
    private static function sessions(int $regid): array {
        global $DB;
        $result = [];
        $rows = $DB->get_records('scormmakercmi5_session', ['regid' => $regid], 'timelaunchedms, id', self::SESSION_FIELDS);
        foreach ($rows as $row) {
            $result[(int)$row->auid][] = [
                'sessionuuid' => $row->sessionuuid,
                'synthetic' => transform::yesno((int)$row->kind === 1),
                'launchmode' => $row->launchmode,
                'status' => self::SESSION_STATUS[(int)$row->status] ?? (string)$row->status,
                'timelaunched' => self::datetime_ms((int)$row->timelaunchedms),
                'timeinitialized' => self::datetime_ms((int)$row->timeinitializedms),
                'lastactivity' => self::datetime_ms((int)$row->lastactivityms),
                'timeended' => self::datetime_ms((int)$row->timeendedms),
                'durationms' => $row->durationms === null ? null : (int)$row->durationms,
            ];
        }
        return $result;
    }

    /**
     * The statements of a user in an instance about one AU, in stored order.
     *
     * @param array $key scormmakercmi5id and userid.
     * @param int $auid AU.
     * @return array
     */
    private static function statements(array $key, int $auid): array {
        global $DB;
        $result = [];
        $rs = $DB->get_recordset('scormmakercmi5_stmt', $key + ['auid' => $auid], 'storedms, id');
        foreach ($rs as $row) {
            $result[] = self::statement($row);
        }
        $rs->close();
        return $result;
    }

    /**
     * One statement: the indexed fields and the payload as stored (and as the GET statements returns it).
     *
     * @param \stdClass $row Statement row.
     * @return array
     */
    private static function statement(\stdClass $row): array {
        return [
            'statementid' => $row->statementid,
            'verb' => $row->verb,
            'objectid' => $row->objectid,
            'timestamp' => self::datetime_ms((int)$row->timestampms),
            'stored' => self::datetime_ms((int)$row->storedms),
            'success' => $row->success === null ? null : transform::yesno((int)$row->success === 1),
            'completion' => $row->completion === null ? null : transform::yesno((int)$row->completion === 1),
            'scorescaled' => $row->scorescaled === null ? null : (float)$row->scorescaled,
            'durationms' => $row->durationms === null ? null : (int)$row->durationms,
            'payload' => self::document($row->payload),
        ];
    }

    /**
     * One State document.
     *
     * @param \stdClass $row State row.
     * @return array
     */
    private static function state(\stdClass $row): array {
        return [
            'stateid' => $row->stateid,
            'registration' => $row->registration,
            'contenttype' => $row->contenttype,
            // A document that is not UTF-8 is stored, and exported, in base64.
            'base64' => transform::yesno((int)$row->isbase64 === 1),
            'content' => (int)$row->isbase64 === 1 ? $row->content : self::document($row->content),
            'updated' => self::datetime_ms((int)$row->updatedms),
        ];
    }

    /**
     * A JSON document decoded with its objects kept as objects (so {} stays {} in the export), or the text itself
     * when it is not JSON.
     *
     * @param string $content Content.
     * @return mixed
     */
    private static function document(string $content): mixed {
        try {
            return json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $content;
        }
    }

    /**
     * The folder name of each AU: its title in the current language, followed by its id when two AUs share it.
     *
     * @param \stdClass[] $aus AU rows by id.
     * @return array auid => name.
     */
    private static function au_names(array $aus): array {
        $names = [];
        foreach ($aus as $au) {
            $names[(int)$au->id] = self::title((string)$au->title);
        }
        $counts = array_count_values($names);
        foreach ($names as $auid => $title) {
            if ($title === '' || $counts[$title] > 1) {
                $names[$auid] = trim($title . ' (' . $auid . ')');
            }
        }
        return $names;
    }

    /**
     * A title stored as JSON {lang: text}, in the current language.
     *
     * @param string $json Title.
     * @return string
     */
    private static function title(string $json): string {
        $titles = json_decode($json, true);
        return trim(course_structure::pick(is_array($titles) ? $titles : [], current_language()));
    }

    /**
     * A time in seconds, null when not set.
     *
     * @param int $time Seconds.
     * @return string|null
     */
    private static function datetime(int $time): ?string {
        return $time > 0 ? transform::datetime($time) : null;
    }

    /**
     * A time in milliseconds, null when not set.
     *
     * @param int $ms Milliseconds.
     * @return string|null
     */
    private static function datetime_ms(int $ms): ?string {
        return $ms > 0 ? transform::datetime(intdiv($ms, 1000)) : null;
    }

    /**
     * The instance of a module context of this plugin.
     *
     * @param \context $context Context.
     * @return \stdClass|null Instance row, null for any other context.
     */
    private static function instance_of(\context $context): ?\stdClass {
        global $DB;
        if (!$context instanceof \context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('scormmakercmi5', $context->instanceid);
        if (!$cm) {
            return null;
        }
        $instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance]);
        return $instance ?: null;
    }
}
