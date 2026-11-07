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
use mod_scormmakercmi5\local\runtime\launcher;

/**
 * The cmi5 rules the LMS applies to the statements an AU sends (design §6.3, §6.4; R5.2, R5.3).
 *
 * Input: statements that already passed statement_validator::validate() and were normalized by
 * statement_validator::normalize() (every contextActivities value is a list). The batch is simulated in order over
 * a clone of the state, so [initialized, experienced, terminated] is judged statement by statement (the batch
 * refusal 9.3.0.0-4 of the reference LMS is a detail of its implementation, design §6.4). The first violation
 * refuses the whole batch (R5.5) with 403 err_cmi5rule and the violated requirement in "rule".
 *
 * Pure: no database access. check_statement() is the step function (state, statement) -> (kind, new state);
 * the ingestion (Task 15) runs it under the registration lock and applies the resulting state.
 *
 * Order of the checks (the first failing one names the rule): 1 id, 2 timestamp, 3 actor, 4 registration,
 * 5 contextTemplate, 7 LMS-only verbs, 6 classification, 8 object, 9 session order (with 11.0.0.0-3 on initialized),
 * 17 launchMode, 10 session duplicates, 11 registration, 12 score, 13 masteryScore, 14 success/completion,
 * 15 duration, 16 moveOn category, 18 progress. The order in which statements are judged is the order of the
 * batch, not of the timestamps (§9.3 orders by timestamp; the AU sends in order and the timestamps come from
 * its clock).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cmi5_rules {
    /** @var int cmi5 not-allowed statement (§7.1.3): never stored. */
    public const KIND_NOTALLOWED = 0;

    /** @var int cmi5 allowed statement (§7.1.3). */
    public const KIND_ALLOWED = statement_store::KIND_ALLOWED;

    /** @var int cmi5 defined statement (§7.1.3). */
    public const KIND_DEFINED = statement_store::KIND_DEFINED;

    /** @var string[] Verbs defined by cmi5 (§9.3.1–§9.3.9). */
    private const CMI5_VERBS = [
        vocab::VERB_LAUNCHED, vocab::VERB_INITIALIZED, vocab::VERB_COMPLETED, vocab::VERB_PASSED, vocab::VERB_FAILED,
        vocab::VERB_ABANDONED, vocab::VERB_WAIVED, vocab::VERB_TERMINATED, vocab::VERB_SATISFIED,
    ];

    /** @var string[] Verbs only the LMS uses (§9.3.1, §9.3.6, §9.3.7, §9.3.9) and voided (the LRS does not void). */
    private const LMS_VERBS = [
        vocab::VERB_LAUNCHED, vocab::VERB_ABANDONED, vocab::VERB_WAIVED, vocab::VERB_SATISFIED, vocab::VERB_VOIDED,
    ];

    /** @var string[] Defined verbs that must carry a duration (§9.5.4.1). */
    private const DURATION_VERBS = [vocab::VERB_TERMINATED, vocab::VERB_COMPLETED, vocab::VERB_PASSED, vocab::VERB_FAILED];

    /** @var string[] Verbs that record satisfaction, forbidden in Browse and Review (§10.2.2). */
    private const JUDGED_VERBS = [vocab::VERB_COMPLETED, vocab::VERB_PASSED, vocab::VERB_FAILED];

    /**
     * The type of a statement (§7.1.3): the cmi5 category with a cmi5 verb is defined, the cmi5 category with any
     * other verb is not allowed, no cmi5 category is allowed.
     *
     * @param array $statement Normalized statement.
     * @return int self::KIND_*.
     */
    public static function classify(array $statement): int {
        if (!self::has_category($statement, vocab::CAT_CMI5)) {
            return self::KIND_ALLOWED;
        }
        return in_array($statement['verb']['id'] ?? null, self::CMI5_VERBS, true) ? self::KIND_DEFINED
            : self::KIND_NOTALLOWED;
    }

    /**
     * Simulates a batch in order over a clone of the state.
     *
     * @param array $statements Normalized statements.
     * @param rules_state $state State before the batch (not changed).
     * @return array ['kinds' => int[] (one per statement), 'state' => rules_state after the batch].
     * @throws lrs_exception 403 err_cmi5rule at the first violation.
     */
    public static function check_batch(array $statements, rules_state $state): array {
        $kinds = [];
        $current = clone $state;
        foreach (array_values($statements) as $i => $statement) {
            try {
                [$kinds[], $current] = self::check_statement($statement, $current);
            } catch (lrs_exception $e) {
                // Same rule, with the position of the statement in the batch as the detail of the message.
                throw new lrs_exception(403, 'err_cmi5rule', (string)($i + 1), $e->rule);
            }
        }
        return ['kinds' => $kinds, 'state' => $current];
    }

    /**
     * Judges one statement against a state: pure step function of the batch.
     *
     * @param array $statement Normalized statement.
     * @param rules_state $state State before the statement (not changed).
     * @return array [int kind, rules_state state after the statement].
     * @throws lrs_exception 403 err_cmi5rule.
     */
    public static function check_statement(array $statement, rules_state $state): array {
        // Rule 1 (§9.1, 9.1.0.0-1): the AU assigns the id (its UUID format is checked by the validator).
        if (!isset($statement['id']) || !is_string($statement['id'])) {
            self::refuse('9.1.0.0-1');
        }
        // Rule 2 (§9.7, 9.7.0.0-1/-2): timestamp present, in UTC ("Z" or "+00:00"; "-00:00" is an unknown offset).
        if (
            !isset($statement['timestamp']) || !is_string($statement['timestamp'])
            || !xapi_time::is_utc($statement['timestamp'])
        ) {
            self::refuse('9.7.0.0-1');
        }
        // Rule 3 (§9.2, 9.2.0.0-2/-3; R5.2): the Agent of the session, identified by its account only.
        if (!self::is_session_actor($statement['actor'] ?? null, $state->actoraccount)) {
            self::refuse('9.2.0.0-2');
        }
        // Rule 4 (§9.6, §9.6.1): context present with the registration of the session.
        $context = $statement['context'] ?? null;
        if (
            !is_array($context) || !is_string($context['registration'] ?? null)
            || strtolower($context['registration']) !== strtolower($state->registration)
        ) {
            self::refuse('9.6.1');
        }
        // Rule 5 (§10.2.1, §9.6.3.1, §9.6.2.3, 9.6.3.1-4): the contextTemplate is kept unchanged, for defined
        // and allowed statements alike (§7.1.3 "cmi5 allowed – any verb and cmi5 context template").
        if (
            ($context['extensions'][vocab::EXT_SESSIONID] ?? null) !== $state->sessionuuid
            || !self::list_has_id($context['contextActivities']['grouping'] ?? [], $state->publisherid)
        ) {
            self::refuse('9.6.3.1-4');
        }
        $verb = $statement['verb']['id'] ?? '';
        // Rule 7 (§9.3.1, §9.3.6, §9.3.7, §9.3.9; design §6.4): LMS verbs and voided never come from the AU.
        if (in_array($verb, self::LMS_VERBS, true)) {
            self::refuse('9.3');
        }
        // Rule 6 (§7.1.3): the cmi5 category with a verb that is not a cmi5 verb is not allowed.
        $kind = self::classify($statement);
        if ($kind === self::KIND_NOTALLOWED) {
            self::refuse('7.1.3');
        }
        $defined = $kind === self::KIND_DEFINED;
        // Rule 8 (§9.4, 8.1.5.0-6): a defined statement is about the AU, whose id is the activityId of the launch.
        $object = is_array($statement['object'] ?? null) ? $statement['object'] : [];
        $isau = ($object['objectType'] ?? 'Activity') === 'Activity' && ($object['id'] ?? null) === $state->activityid;
        if ($defined && !$isau) {
            self::refuse('8.1.5.0-6');
        }

        $next = clone $state;
        self::check_order($verb, $defined, $next);
        $result = is_array($statement['result'] ?? null) ? $statement['result'] : [];
        if ($defined) {
            self::check_defined($verb, $statement, $result, $next);
        }
        // Rule 16 (§9.6.2.2): the moveOn category on exactly the defined statements with success or completion;
        // "Other statements MUST NOT include this activity" covers allowed statements and terminated.
        $needsmoveon = $defined && (array_key_exists('success', $result) || array_key_exists('completion', $result));
        if ($needsmoveon !== self::has_category($statement, vocab::CAT_MOVEON)) {
            self::refuse('9.6.2.2');
        }
        // Rule 18 (§9.5.5.1): progress, when present, is a whole number from 0 to 100. JSON has one number type,
        // so 50.0 is the integer 50.
        $extensions = is_array($result['extensions'] ?? null) ? $result['extensions'] : [];
        if (array_key_exists(vocab::EXT_PROGRESS, $extensions)) {
            $progress = $extensions[vocab::EXT_PROGRESS];
            $whole = is_int($progress) || (is_float($progress) && floor($progress) === $progress);
            if (!$whole || $progress < 0 || $progress > 100) {
                self::refuse('9.5.5.1');
            }
        }

        if ($defined) {
            $next->sessionverbs[$verb] = true;
            $next->regcompleted = $next->regcompleted || $verb === vocab::VERB_COMPLETED;
            $next->regpassed = $next->regpassed || $verb === vocab::VERB_PASSED;
            $next->regfailed = $next->regfailed || $verb === vocab::VERB_FAILED;
        }
        return [$kind, $next];
    }

    /**
     * Rule 9 and the transitions of the session (§6.3): the first statement is initialized, once; nothing after
     * terminated; allowed statements only between the two.
     *
     * @param string $verb Verb IRI.
     * @param bool $defined The statement is cmi5 defined.
     * @param rules_state $state State, changed on initialized/terminated.
     * @throws lrs_exception
     */
    private static function check_order(string $verb, bool $defined, rules_state $state): void {
        $isinit = $defined && $verb === vocab::VERB_INITIALIZED;
        if ($state->sessionstatus === launcher::STATUS_LAUNCHED) {
            if (!$defined) {
                // Requirement 8.1.2.0-5, §7.1.3: allowed statements only after initialized.
                self::refuse('8.1.2.0-5');
            }
            if (!$isinit) {
                // Section 9.3.2, 9.3.2.0-3: initialized is the first statement of the session.
                self::refuse('9.3.2.0-3');
            }
            if ($state->requireprefsread && !$state->prefsread) {
                // Section 11, 11.0.0.0-3: the AU reads cmi5LearnerPreferences before initialized (design §6.3).
                self::refuse('11.0.0.0-3');
            }
            $state->sessionstatus = launcher::STATUS_INITIALIZED;
            return;
        }
        if ($state->sessionstatus !== launcher::STATUS_INITIALIZED || $isinit) {
            // Sections 9.3.8 and 9.3.6, 9.3.0.0-5: nothing after terminated or abandoned; section 9.3.2: initialized only once.
            self::refuse('9.3.0.0-5');
        }
        if ($defined && $verb === vocab::VERB_TERMINATED) {
            $state->sessionstatus = launcher::STATUS_TERMINATED;
        }
    }

    /**
     * Rules 17, 10–15 of a cmi5 defined statement.
     *
     * @param string $verb Verb IRI.
     * @param array $statement Statement.
     * @param array $result Its result ([] when absent).
     * @param rules_state $state State (sessionverbs and reg* hold what came before this statement).
     * @throws lrs_exception
     */
    private static function check_defined(string $verb, array $statement, array $result, rules_state $state): void {
        $passed = $verb === vocab::VERB_PASSED;
        $failed = $verb === vocab::VERB_FAILED;
        // Rule 17 (§10.2.2): Browse and Review send only initialized and terminated.
        if ($state->launchmode !== 'Normal' && in_array($verb, self::JUDGED_VERBS, true)) {
            self::refuse('10.2.2');
        }
        // Rule 10 (§9.3, 9.3.0.0-2/-3): no defined verb twice in the session, at most one of {passed, failed}.
        $judged = isset($state->sessionverbs[vocab::VERB_PASSED]) || isset($state->sessionverbs[vocab::VERB_FAILED]);
        if (isset($state->sessionverbs[$verb]) || (($passed || $failed) && $judged)) {
            self::refuse('9.3.0.0-2');
        }
        // Rule 11 (§9.3, §9.3.3, §9.3.4): per registration and AU, one completed, one passed, no failed after passed.
        if (($verb === vocab::VERB_COMPLETED && $state->regcompleted) || (($passed || $failed) && $state->regpassed)) {
            self::refuse('9.3');
        }
        // Rule 12 (§9.5.1): score only in passed/failed; scaled 0..1; raw needs min and max.
        $score = $result['score'] ?? null;
        if ($score !== null) {
            if (!$passed && !$failed) {
                self::refuse('9.5.1');
            }
            if (
                (isset($score['scaled']) && ($score['scaled'] < 0 || $score['scaled'] > 1))
                || (isset($score['raw']) && (!isset($score['min']) || !isset($score['max'])))
            ) {
                self::refuse('9.5.1');
            }
        }
        // Rule 13 (§9.3.4, §9.3.5, §9.5.1, §9.6.3.2): with a masteryScore, a passed/failed WITH a score carries the
        // masteryscore extension ("for passed or failed statements it makes based on the masteryScore"; CATAPULT
        // 9.6.3.2-2 (d) "... but include a score"). A scoreless passed/failed needs no extension (LTS 001-essentials,
        // 005-2-invalid-au). When the extension is present it equals the masteryScore, and a scaled score agrees
        // with it (passed ≥ mastery, failed < mastery).
        if (($passed || $failed) && $state->masteryscore !== null) {
            $mastery = $state->masteryscore;
            $extensions = is_array($statement['context']['extensions'] ?? null) ? $statement['context']['extensions'] : [];
            $hasext = array_key_exists(vocab::EXT_MASTERYSCORE, $extensions);
            $ext = $extensions[vocab::EXT_MASTERYSCORE] ?? null;
            $badext = $hasext && ((!is_int($ext) && !is_float($ext)) || abs($ext - $mastery) > 0.00005);
            if (($score !== null && !$hasext) || $badext) {
                self::refuse('9.3.4');
            }
            $scaled = $score['scaled'] ?? null;
            if ($scaled !== null && ($passed ? $scaled < $mastery : $scaled >= $mastery)) {
                self::refuse('9.3.4');
            }
        }
        // Rule 14 (§9.5.2, §9.5.3): success true in passed, false in failed, absent elsewhere; completion true in
        // completed, absent elsewhere. A null success/completion never reaches here: the validator refuses a
        // non-boolean with 400 (otherwise it would pass this rule and fail rule 16, which tests key presence).
        $success = array_key_exists('success', $result) ? $result['success'] : null;
        $completion = array_key_exists('completion', $result) ? $result['completion'] : null;
        $wantsuccess = $passed ? true : ($failed ? false : null);
        $wantcompletion = $verb === vocab::VERB_COMPLETED ? true : null;
        if ($success !== $wantsuccess || $completion !== $wantcompletion) {
            self::refuse('9.5.2');
        }
        // Rule 15 (§9.5.4.1): terminated, completed, passed and failed carry a valid ISO 8601 duration.
        if (
            in_array($verb, self::DURATION_VERBS, true)
            && (!is_string($result['duration'] ?? null) || !iso_duration::is_valid($result['duration']))
        ) {
            self::refuse('9.5.4.1');
        }
    }

    /**
     * Whether an actor is the Agent of the session: objectType Agent (or absent), the account as the only
     * identifier, equal to the account of the session.
     *
     * @param mixed $actor Actor of the statement.
     * @param array $account Account of the session.
     * @return bool
     */
    private static function is_session_actor(mixed $actor, array $account): bool {
        if (!is_array($actor) || ($actor['objectType'] ?? 'Agent') !== 'Agent') {
            return false;
        }
        foreach (['mbox', 'mbox_sha1sum', 'openid'] as $ifi) {
            if (array_key_exists($ifi, $actor)) {
                return false;
            }
        }
        $given = $actor['account'] ?? null;
        return is_array($given) && $account !== []
            && ($given['homePage'] ?? null) === ($account['homePage'] ?? null)
            && ($given['name'] ?? null) === ($account['name'] ?? null);
    }

    /**
     * Whether a category activity is in the context of a statement.
     *
     * @param array $statement Normalized statement.
     * @param string $id Category IRI.
     * @return bool
     */
    private static function has_category(array $statement, string $id): bool {
        return self::list_has_id($statement['context']['contextActivities']['category'] ?? [], $id);
    }

    /**
     * Whether a normalized contextActivities list has an activity with an id.
     *
     * @param mixed $list List of activities.
     * @param string $id Activity id.
     * @return bool
     */
    private static function list_has_id(mixed $list, string $id): bool {
        if (!is_array($list) || $id === '') {
            return false;
        }
        foreach ($list as $activity) {
            if (is_array($activity) && ($activity['id'] ?? null) === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Throws the 403 of a violated rule.
     *
     * @param string $rule cmi5 requirement or section.
     * @return never
     * @throws lrs_exception
     */
    private static function refuse(string $rule): never {
        throw new lrs_exception(403, 'err_cmi5rule', '1', $rule);
    }
}
