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
use mod_scormmakercmi5\event\attempt_deleted;
use mod_scormmakercmi5\event\au_waived;
use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\grading\grade_calculator;
use mod_scormmakercmi5\local\lrs\endpoint;
use mod_scormmakercmi5\local\lrs\statement_store;

/**
 * What a teacher does to the attempt of a learner: waive an AU (R4.8, cmi5 §9.3.7) and delete the attempt (design
 * §2.2, §11); and the course reset, which deletes every attempt of an instance with the same cascade.
 *
 * Both actions change the registration under its lock ('reg<id>', the one of the launch and of the ingestion) and in
 * one transaction; the gradebook and the completion are updated after the commit, each step in its own try, as the
 * ingestion does (design §6.6): a failure there is logged and repaired at the next view or launch of the learner
 * (grade_calculator::repair_user(), custom_completion::repair_user()).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_manager {
    /** @var string Capability of both actions (design §9). */
    public const CAPABILITY = 'mod/scormmakercmi5:manageattempts';

    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /** @var int Seconds to wait for the registration lock. Protected so a test can make it 0. */
    protected static int $locktimeout = 5;

    /** @var int Seconds the Privacy API waits for the registration lock before deleting without it. */
    protected static int $privacylocktimeout = 60;

    /**
     * Waives an AU of a learner (R4.8): the LMS writes "waived" (cmi5 §9.3.7) with a synthetic session of its own,
     * marks the regau waived with the reason and evaluates the satisfaction with that same session, so the
     * "satisfied" of the blocks and of the course it completes carry the session id of the waiver (§9.3.9: one
     * trigger outside a launch, one session). At most one waived per registration and AU (§9.3.7), and never for an
     * AU already satisfied, which has met its moveOn and has nothing to waive.
     *
     * A learner who never launched gets the registration created now.
     *
     * @param \stdClass $instance Instance row.
     * @param \cm_info $cm Course module of the instance.
     * @param int $userid Learner.
     * @param int $auid AU of the package version of the registration.
     * @param string $reason One of cmi5_vocab::WAIVE_REASONS (§9.5.5.2).
     * @throws \required_capability_exception Without manageattempts.
     * @throws \invalid_parameter_exception A reason outside the cmi5 vocabulary.
     * @throws \moodle_exception err_notlearner, err_usernotingroup, err_aunotinpkg, err_alreadywaived,
     *     err_ausatisfied, err_attemptbusy.
     */
    public static function waive(\stdClass $instance, \cm_info $cm, int $userid, int $auid, string $reason): void {
        global $DB;
        $context = self::check_access($instance, $cm, $userid);
        if (!in_array($reason, vocab::WAIVE_REASONS, true)) {
            throw new \invalid_parameter_exception('The waive reason must be one of the cmi5 vocabulary (§9.5.5.2)');
        }
        if (!is_enrolled($context, $userid, 'mod/scormmakercmi5:view', true)) {
            throw self::error('err_notlearner');
        }
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $auid]);
        if (!$au || !$DB->record_exists('scormmakercmi5_pkg', ['id' => $au->pkgid, 'scormmakercmi5id' => $instance->id])) {
            throw self::error('err_aunotinpkg');
        }
        $reg = launcher::get_or_create_registration($instance, $userid);
        if ((int)$au->pkgid !== (int)$reg->pkgid) {
            throw self::error('err_aunotinpkg');
        }

        $lock = launcher::registration_lock((int)$reg->id, static::$locktimeout);
        if (!$lock) {
            throw self::error('err_attemptbusy');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $reg->id], '*', MUST_EXIST);
                $regau = $DB->get_record('scormmakercmi5_regau', ['regid' => $reg->id, 'auid' => $au->id], '*', MUST_EXIST);
                if (!empty($regau->waived)) {
                    throw self::error('err_alreadywaived');
                }
                if (!empty($regau->satisfied) || satisfaction::is_au_satisfied((string)$au->moveon, $regau)) {
                    throw self::error('err_ausatisfied');
                }
                $wassatisfied = (int)$reg->satisfied === 1;
                $session = launcher::create_synthetic_session($reg, (int)$au->id);
                $statement = lms_statements::waived($reg, $au, $session, launcher::context_template($au, $session), $reason);
                statement_store::insert_lms($statement, $reg, (int)$au->id, (int)$session->id);
                $DB->update_record('scormmakercmi5_regau', (object)[
                    'id' => $regau->id,
                    'waived' => 1,
                    'waivedreason' => $reason,
                ]);
                satisfaction::evaluate($reg, $session);
                $satisfied = !$wassatisfied && (int)$reg->satisfied === 1;
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }

        au_waived::create([
            'objectid' => $regau->id,
            'context' => $context,
            'relateduserid' => $userid,
            'other' => ['auid' => (int)$au->id, 'reason' => $reason],
        ])->trigger();

        // After the commit (design §6.6). A waiver has no score, but the grade is recomputed from regau all the same,
        // which also repairs one left behind.
        try {
            grade_calculator::update_user($instance, $userid);
        } catch (\Throwable $e) {
            endpoint::log_failure('waive grade', $e);
        }
        if ($satisfied) {
            try {
                custom_completion::update_user($instance, $userid);
            } catch (\Throwable $e) {
                endpoint::log_failure('waive completion', $e);
            }
        }
    }

    /**
     * Deletes the attempt of a learner (design §2.2, §11): the registration with its regau, regblock, sessions,
     * statements and State, so an open session of the learner is refused (401) from the commit on; the Agent Profile
     * belongs to the learner, not to the registration, and stays. The grade goes back to null and the completion is
     * recomputed. The next launch creates a new registration on the current package version.
     *
     * @param \stdClass $instance Instance row.
     * @param \cm_info $cm Course module of the instance.
     * @param int $userid Learner.
     * @throws \required_capability_exception Without manageattempts.
     * @throws \moodle_exception err_usernotingroup, err_noattempt, err_attemptbusy.
     */
    public static function delete_attempt(\stdClass $instance, \cm_info $cm, int $userid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $context = self::check_access($instance, $cm, $userid);
        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $userid]);
        if (!$reg) {
            throw self::error('err_noattempt');
        }
        self::delete_registration($reg);

        $event = attempt_deleted::create([
            'objectid' => $reg->id,
            'context' => $context,
            'relateduserid' => $userid,
        ]);
        $event->add_record_snapshot('scormmakercmi5_reg', $reg);
        $event->trigger();

        try {
            grade_calculator::update_user($instance, $userid);
        } catch (\Throwable $e) {
            endpoint::log_failure('delete grade', $e);
        }
        try {
            custom_completion::update_user($instance, $userid, COMPLETION_INCOMPLETE);
        } catch (\Throwable $e) {
            endpoint::log_failure('delete completion', $e);
        }
    }

    /**
     * Course reset: deletes every attempt of an instance with the cascade of delete_attempt() and recomputes the
     * completion of the learners whose course was satisfied. No capability per learner: the reset itself requires
     * moodle/course:reset. The gradebook is reset by the caller (scormmakercmi5_reset_gradebook()).
     *
     * @param \stdClass $instance Instance row.
     * @return int Registrations not deleted because another request held their lock (0 when all were deleted).
     */
    public static function reset_instance(\stdClass $instance): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $busy = 0;
        $satisfied = [];
        $regs = $DB->get_records('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id], 'id');
        foreach ($regs as $reg) {
            try {
                self::delete_registration($reg);
            } catch (\moodle_exception $e) {
                if ($e->errorcode !== 'err_attemptbusy' || $e->module !== self::COMPONENT) {
                    throw $e;
                }
                $busy++;
                continue;
            }
            if ((int)$reg->satisfied === 1) {
                $satisfied[] = (int)$reg->userid;
            }
        }
        foreach ($satisfied as $userid) {
            try {
                custom_completion::update_user($instance, $userid, COMPLETION_INCOMPLETE);
            } catch (\Throwable $e) {
                endpoint::log_failure('reset completion', $e);
            }
        }
        return $busy;
    }

    /**
     * The cascade shared by delete_attempt() and reset_instance() (and, through purge_registration(), the Privacy API
     * provider), under the registration lock and in one transaction: stmt → state → session → regblock → regau → reg
     * (children first, design §11). Deleting the sessions revokes their tokens: an ingestion waiting for the lock reads
     * the session again and gets 401. The gradebook and the completion are left to the caller.
     *
     * @param \stdClass $reg Registration (id, scormmakercmi5id, userid).
     * @throws \moodle_exception err_attemptbusy.
     */
    public static function delete_registration(\stdClass $reg): void {
        $lock = launcher::registration_lock((int)$reg->id, static::$locktimeout);
        if (!$lock) {
            throw self::error('err_attemptbusy');
        }
        try {
            self::cascade($reg);
        } finally {
            $lock->release();
        }
    }

    /**
     * The same cascade for the Privacy API, which must never leave the data behind (design §11): core catches what a
     * provider throws, mails the DPO and closes the request as done, so a busy lock cannot be an error here. The lock
     * is awaited for $privacylocktimeout seconds; if it still does not come (an ingestion or a launch stuck on it),
     * the tokens of the sessions are revoked first, in a write of their own, so the AU gets 401 from then on, and the
     * cascade runs without the lock: privacy wins over the batch in flight. A statement that batch commits after the
     * cascade has the userid of the learner, and the caller sweeps statements and State by (instance, userid).
     *
     * @param \stdClass $reg Registration (id, scormmakercmi5id, userid).
     */
    public static function purge_registration(\stdClass $reg): void {
        global $DB;
        $lock = launcher::registration_lock((int)$reg->id, static::$privacylocktimeout);
        if (!$lock) {
            $DB->set_field('scormmakercmi5_session', 'tokenhash', null, ['regid' => $reg->id]);
            $DB->set_field('scormmakercmi5_session', 'fetchkeyhash', null, ['regid' => $reg->id]);
            $DB->set_field('scormmakercmi5_session', 'tokenexpires', 0, ['regid' => $reg->id]);
            self::cascade($reg);
            return;
        }
        try {
            self::cascade($reg);
        } finally {
            $lock->release();
        }
    }

    /**
     * stmt → state → session → regblock → regau → reg, in one transaction.
     *
     * @param \stdClass $reg Registration (id, scormmakercmi5id, userid).
     */
    private static function cascade(\stdClass $reg): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $byreg = ['regid' => $reg->id];
            $DB->delete_records('scormmakercmi5_stmt', $byreg);
            // State is keyed by learner and instance; a document sent without a registration has regid 0.
            $DB->delete_records(
                'scormmakercmi5_state',
                ['scormmakercmi5id' => $reg->scormmakercmi5id, 'userid' => $reg->userid]
            );
            $DB->delete_records('scormmakercmi5_session', $byreg);
            $DB->delete_records('scormmakercmi5_regblock', $byreg);
            $DB->delete_records('scormmakercmi5_regau', $byreg);
            $DB->delete_records('scormmakercmi5_reg', ['id' => $reg->id]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Where waive.php and attempt_delete.php go back to, after the action or a cancel: the learner's detail in the
     * report (design §2.2), where the links to these pages are.
     *
     * @param \cm_info $cm Course module.
     * @param int $userid Learner.
     * @return \moodle_url
     */
    public static function return_url(\cm_info $cm, int $userid): \moodle_url {
        return new \moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id, 'mode' => 'user', 'userid' => $userid]);
    }

    /**
     * Whether a user is a learner of the activity: they have an attempt, or they are actively enrolled with view.
     * The pages that show a learner by id check it before printing the name, so an id of any other user of the site
     * reveals nothing.
     *
     * @param \stdClass $instance Instance row.
     * @param \context_module $context Context of the module.
     * @param int $userid User.
     * @return bool
     */
    public static function is_learner(\stdClass $instance, \context_module $context, int $userid): bool {
        global $DB;
        return $DB->record_exists('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $userid])
            || is_enrolled($context, $userid, 'mod/scormmakercmi5:view', true);
    }

    /**
     * The checks of both actions, also run by their pages before showing anything: the course module is the one of the
     * instance, the current user has manageattempts there and, in separate groups without accessallgroups, shares a
     * group with the learner.
     *
     * @param \stdClass $instance Instance row.
     * @param \cm_info $cm Course module.
     * @param int $userid Learner.
     * @return \context_module
     * @throws \required_capability_exception Without manageattempts.
     * @throws \moodle_exception err_usernotingroup.
     */
    public static function check_access(\stdClass $instance, \cm_info $cm, int $userid): \context_module {
        if ((int)$cm->instance !== (int)$instance->id || $cm->modname !== 'scormmakercmi5') {
            throw new \coding_exception('The course module is not the one of the instance');
        }
        $context = \context_module::instance($cm->id);
        require_capability(self::CAPABILITY, $context);
        if (!groups_user_groups_visible($cm->get_course(), $userid, $cm)) {
            throw self::error('err_usernotingroup');
        }
        return $context;
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
