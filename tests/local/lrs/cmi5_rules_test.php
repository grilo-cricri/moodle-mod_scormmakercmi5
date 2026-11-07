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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\runtime\launcher;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\cmi5_rules::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\rules_state::class)]
/**
 * Tests for the LMS-side cmi5 statement rules (design §6.3, §6.4; cmi5 spec §7.1.3, §9, §10, §11; R5.2, R5.3).
 *
 * Each refusal starts from a valid statement and breaks one rule; the test checks the exact "rule" of the 403.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\cmi5_rules
 * @covers     \mod_scormmakercmi5\local\lrs\rules_state
 */
final class cmi5_rules_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var string Registration. */
    private const REG = '1d6d4f4e-8a4b-4d6f-9d6c-2f7a3b4c5d6e';

    /** @var string Session id. */
    private const SESSION = '7c1f3e2a-9b4d-4e6f-8a1b-2c3d4e5f6a7b';

    /** @var string activityId given at launch. */
    private const ACTIVITY = 'https://lms.example/mod/scormmakercmi5/xapi/au/5f0e4c3b-1a2b-4c5d-8e9f-0a1b2c3d4e5f';

    /** @var string Publisher id of the AU. */
    private const PUBLISHER = 'https://example.com/au/1';

    /** @var string Home page of the learner account. */
    private const HOME = 'https://lms.example';

    /** @var string Base IRI of the ADL verbs. */
    private const ADL = 'http://adlnet.gov/expapi/verbs/';

    /**
     * A state of a session that was launched and whose AU read the learner preferences.
     *
     * @param array $props Properties to change.
     * @return rules_state
     */
    private static function state(array $props = []): rules_state {
        $state = new rules_state();
        $state->sessionstatus = launcher::STATUS_LAUNCHED;
        $state->launchmode = 'Normal';
        $state->moveon = 'CompletedOrPassed';
        $state->registration = self::REG;
        $state->sessionuuid = self::SESSION;
        $state->activityid = self::ACTIVITY;
        $state->publisherid = self::PUBLISHER;
        $state->actoraccount = ['homePage' => self::HOME, 'name' => '42'];
        $state->prefsread = true;
        $state->requireprefsread = true;
        foreach ($props as $name => $value) {
            $state->{$name} = $value;
        }
        return $state;
    }

    /**
     * A state after "initialized".
     *
     * @param array $props Properties to change.
     * @return rules_state
     */
    private static function initialized(array $props = []): rules_state {
        return self::state($props + [
            'sessionstatus' => launcher::STATUS_INITIALIZED,
            'sessionverbs' => [vocab::VERB_INITIALIZED => true],
        ]);
    }

    /**
     * A valid cmi5 defined statement, normalized, as the SCORM Maker sends it.
     *
     * @param string $verb Verb IRI.
     * @param float|null $mastery masteryScore to put in the context of passed/failed.
     * @return array
     */
    private static function defined(string $verb, ?float $mastery = null): array {
        $st = self::allowed($verb);
        $st['object'] = ['objectType' => 'Activity', 'id' => self::ACTIVITY];
        $st['context']['contextActivities']['category'] = [['objectType' => 'Activity', 'id' => vocab::CAT_CMI5]];
        $moveon = ['objectType' => 'Activity', 'id' => vocab::CAT_MOVEON];
        switch ($verb) {
            case vocab::VERB_COMPLETED:
                $st['result'] = ['completion' => true, 'duration' => 'PT5M'];
                $st['context']['contextActivities']['category'][] = $moveon;
                break;
            case vocab::VERB_PASSED:
            case vocab::VERB_FAILED:
                $passed = $verb === vocab::VERB_PASSED;
                $st['result'] = [
                    'success' => $passed,
                    'score' => ['raw' => $passed ? 90 : 50, 'min' => 0, 'max' => 100, 'scaled' => $passed ? 0.9 : 0.5],
                    'duration' => 'PT5M',
                ];
                $st['context']['contextActivities']['category'][] = $moveon;
                if ($mastery !== null) {
                    $st['context']['extensions'][vocab::EXT_MASTERYSCORE] = $mastery;
                }
                break;
            case vocab::VERB_TERMINATED:
                $st['result'] = ['duration' => 'PT10M'];
                break;
        }
        return $st;
    }

    /**
     * A valid cmi5 allowed statement (context template, no cmi5 category).
     *
     * @param string $verb Verb IRI.
     * @return array
     */
    private static function allowed(string $verb = self::ADL . 'experienced'): array {
        return [
            'id' => \core\uuid::generate(),
            'timestamp' => '2026-09-30T12:00:00.000Z',
            'actor' => ['objectType' => 'Agent', 'account' => ['homePage' => self::HOME, 'name' => '42']],
            'verb' => ['id' => $verb, 'display' => ['pt-BR' => 'verbo']],
            'object' => ['objectType' => 'Activity', 'id' => self::ACTIVITY . '/licao/1'],
            'context' => [
                'registration' => self::REG,
                'contextActivities' => ['grouping' => [['objectType' => 'Activity', 'id' => self::PUBLISHER]]],
                'extensions' => [vocab::EXT_SESSIONID => self::SESSION],
            ],
        ];
    }

    /**
     * Asserts that a batch is refused with 403 err_cmi5rule and a rule, and that the given state is not changed.
     *
     * @param string $rule Expected rule.
     * @param array $statements Batch.
     * @param rules_state $state State before the batch.
     */
    private function assert_rule(string $rule, array $statements, rules_state $state): void {
        $before = clone $state;
        try {
            cmi5_rules::check_batch($statements, $state);
        } catch (lrs_exception $e) {
            $this->assertSame(403, $e->httpstatus);
            $this->assertSame('err_cmi5rule', $e->errorcode);
            $this->assertSame($rule, $e->rule);
            $this->assertEquals($before, $state, 'The state of the caller must not change');
            return;
        }
        $this->fail("Expected rule $rule");
    }

    /**
     * Runs a batch that must be accepted.
     *
     * @param array $statements Batch.
     * @param rules_state $state State before.
     * @param int[] $kinds Expected kinds.
     * @return rules_state The state after.
     */
    private function assert_ok(array $statements, rules_state $state, array $kinds): rules_state {
        $result = cmi5_rules::check_batch($statements, $state);
        $this->assertSame($kinds, $result['kinds']);
        $this->assertNotSame($state, $result['state']);
        return $result['state'];
    }

    /**
     * Classification of §7.1.3.
     */
    public function test_classify(): void {
        $this->assertSame(cmi5_rules::KIND_DEFINED, cmi5_rules::classify(self::defined(vocab::VERB_COMPLETED)));
        $this->assertSame(cmi5_rules::KIND_ALLOWED, cmi5_rules::classify(self::allowed()));
        $this->assertSame(cmi5_rules::KIND_ALLOWED, cmi5_rules::classify(self::allowed(vocab::VERB_COMPLETED)));
        $st = self::defined(vocab::VERB_COMPLETED);
        $st['verb']['id'] = self::ADL . 'experienced';
        $this->assertSame(cmi5_rules::KIND_NOTALLOWED, cmi5_rules::classify($st));
        $this->assertSame(statement_store::KIND_ALLOWED, cmi5_rules::KIND_ALLOWED);
        $this->assertSame(statement_store::KIND_DEFINED, cmi5_rules::KIND_DEFINED);
    }

    /**
     * Rule 1: the AU gives every statement an id (§9.1, 9.1.0.0-1).
     */
    public function test_rule_1_id(): void {
        $st = self::allowed();
        unset($st['id']);
        $this->assert_rule('9.1.0.0-1', [$st], self::initialized());
    }

    /**
     * Rule 2: timestamp present and in UTC (§9.7, 9.7.0.0-1/-2).
     */
    public function test_rule_2_timestamp(): void {
        $st = self::allowed();
        unset($st['timestamp']);
        $this->assert_rule('9.7.0.0-1', [$st], self::initialized());
        $st = self::allowed();
        $st['timestamp'] = '2026-09-30T09:00:00-03:00';
        $this->assert_rule('9.7.0.0-1', [$st], self::initialized());
        $st = self::allowed();
        $st['timestamp'] = '2026-09-30T12:00:00-00:00';
        $this->assert_rule('9.7.0.0-1', [$st], self::initialized());
    }

    /**
     * Rule 3: the actor is the Agent of the session, by account only (§9.2, 9.2.0.0-2/-3; R5.2).
     */
    public function test_rule_3_actor(): void {
        $st = self::allowed();
        $st['actor']['account']['name'] = '43';
        $this->assert_rule('9.2.0.0-2', [$st], self::initialized());
        $st = self::allowed();
        $st['actor']['account']['homePage'] = 'https://other.example';
        $this->assert_rule('9.2.0.0-2', [$st], self::initialized());
        $st = self::allowed();
        $st['actor'] = ['objectType' => 'Agent', 'mbox' => 'mailto:learner@example.com'];
        $this->assert_rule('9.2.0.0-2', [$st], self::initialized());
        $st = self::allowed();
        $st['actor'] = ['objectType' => 'Group', 'account' => ['homePage' => self::HOME, 'name' => '42']];
        $this->assert_rule('9.2.0.0-2', [$st], self::initialized());
    }

    /**
     * Rule 4: context present, registration of the session (§9.6, §9.6.1).
     */
    public function test_rule_4_registration(): void {
        $st = self::allowed();
        unset($st['context']);
        $this->assert_rule('9.6.1', [$st], self::initialized());
        $st = self::allowed();
        $st['context']['registration'] = '2e7e5f5f-9b5c-4e7f-8e7d-3f8b4c5d6e7f';
        $this->assert_rule('9.6.1', [$st], self::initialized());
        $st = self::allowed();
        unset($st['context']['registration']);
        $this->assert_rule('9.6.1', [$st], self::initialized());
    }

    /**
     * Rule 5: the contextTemplate is kept: sessionid and publisher id in grouping (§10.2.1, §9.6.3.1, 9.6.3.1-4).
     */
    public function test_rule_5_context_template(): void {
        $st = self::allowed();
        $st['context']['extensions'][vocab::EXT_SESSIONID] = '8d2f4e3b-0c5e-4f7a-9b2c-3d4e5f6a7b8c';
        $this->assert_rule('9.6.3.1-4', [$st], self::initialized());
        $st = self::allowed();
        unset($st['context']['extensions']);
        $this->assert_rule('9.6.3.1-4', [$st], self::initialized());
        $st = self::defined(vocab::VERB_COMPLETED);
        unset($st['context']['contextActivities']['grouping']);
        $this->assert_rule('9.6.3.1-4', [$st], self::initialized());
        $st = self::allowed();
        $st['context']['contextActivities']['grouping'] = [['id' => 'https://example.com/au/2']];
        $this->assert_rule('9.6.3.1-4', [$st], self::initialized());
    }

    /**
     * Rule 6: the cmi5 category with a verb that is not a cmi5 verb is not allowed (§7.1.3).
     */
    public function test_rule_6_category(): void {
        $st = self::allowed();
        $st['context']['contextActivities']['category'] = [['id' => vocab::CAT_CMI5]];
        $this->assert_rule('7.1.3', [$st], self::initialized());
    }

    /**
     * Rule 7: verbs of the LMS and voided never come from the AU (§9.3.1, §9.3.6, §9.3.7, §9.3.9; design §6.4).
     */
    public function test_rule_7_lms_verbs(): void {
        $verbs = [vocab::VERB_SATISFIED, vocab::VERB_LAUNCHED, vocab::VERB_ABANDONED, vocab::VERB_WAIVED,
            vocab::VERB_VOIDED];
        foreach ($verbs as $verb) {
            $st = self::defined(vocab::VERB_TERMINATED);
            $st['verb']['id'] = $verb;
            $this->assert_rule('9.3', [$st], self::initialized());
            $st = self::allowed($verb);
            $this->assert_rule('9.3', [$st], self::initialized());
        }
    }

    /**
     * Rule 8: a defined statement is about the AU: object.id = activityId (§9.4, 8.1.5.0-6).
     */
    public function test_rule_8_object(): void {
        $st = self::defined(vocab::VERB_COMPLETED);
        $st['object']['id'] = self::ACTIVITY . '/licao/1';
        $this->assert_rule('8.1.5.0-6', [$st], self::initialized());
        $st = self::defined(vocab::VERB_COMPLETED);
        $st['object'] = ['objectType' => 'Agent', 'account' => ['homePage' => self::HOME, 'name' => '42']];
        $this->assert_rule('8.1.5.0-6', [$st], self::initialized());
    }

    /**
     * Rule 9a: no statement before "initialized" (8.1.2.0-5, §7.1.3).
     */
    public function test_rule_9a_before_initialized(): void {
        $this->assert_rule('8.1.2.0-5', [self::allowed()], self::state());
        $this->assert_rule('8.1.2.0-5', [self::allowed(vocab::VERB_INITIALIZED)], self::state());
    }

    /**
     * Rule 9b: "initialized" only once; nothing after "terminated" (§9.3.2, §9.3.8, 9.3.0.0-5).
     */
    public function test_rule_9b_once_and_after_terminated(): void {
        $init = self::defined(vocab::VERB_INITIALIZED);
        $this->assert_rule('9.3.0.0-5', [$init, self::defined(vocab::VERB_INITIALIZED)], self::state());
        $this->assert_rule('9.3.0.0-5', [self::defined(vocab::VERB_INITIALIZED)], self::initialized());
        $this->assert_rule('9.3.0.0-5', [$init, self::defined(vocab::VERB_TERMINATED), self::allowed()], self::state());
        $terminated = self::initialized(['sessionstatus' => launcher::STATUS_TERMINATED]);
        $this->assert_rule('9.3.0.0-5', [self::allowed()], $terminated);
        $abandoned = self::initialized(['sessionstatus' => launcher::STATUS_ABANDONED]);
        $this->assert_rule('9.3.0.0-5', [self::allowed()], $abandoned);
        $this->assert_rule('9.3.0.0-5', [self::defined(vocab::VERB_INITIALIZED)], $abandoned);
    }

    /**
     * Rule 9c: the first statement of the session is "initialized" (§9.3.2, 9.3.2.0-3).
     */
    public function test_rule_9c_first_is_initialized(): void {
        $this->assert_rule('9.3.2.0-3', [self::defined(vocab::VERB_COMPLETED)], self::state());
        $this->assert_rule('9.3.2.0-3', [self::defined(vocab::VERB_TERMINATED)], self::state());
    }

    /**
     * Rule 10: no defined verb twice in a session; at most one of passed/failed (§9.3, 9.3.0.0-2/-3).
     */
    public function test_rule_10_session_duplicates(): void {
        $this->assert_rule(
            '9.3.0.0-2',
            [self::defined(vocab::VERB_COMPLETED), self::defined(vocab::VERB_COMPLETED)],
            self::initialized()
        );
        $this->assert_rule(
            '9.3.0.0-2',
            [self::defined(vocab::VERB_PASSED), self::defined(vocab::VERB_FAILED)],
            self::initialized()
        );
        $this->assert_rule(
            '9.3.0.0-2',
            [self::defined(vocab::VERB_FAILED), self::defined(vocab::VERB_PASSED)],
            self::initialized()
        );
        $done = self::initialized(['sessionverbs' => [vocab::VERB_INITIALIZED => true, vocab::VERB_FAILED => true]]);
        $this->assert_rule('9.3.0.0-2', [self::defined(vocab::VERB_FAILED)], $done);
    }

    /**
     * Rule 11: per registration and AU, one completed, one passed, no failed after passed (§9.3).
     */
    public function test_rule_11_registration(): void {
        $this->assert_rule('9.3', [self::defined(vocab::VERB_COMPLETED)], self::initialized(['regcompleted' => true]));
        $this->assert_rule('9.3', [self::defined(vocab::VERB_PASSED)], self::initialized(['regpassed' => true]));
        $this->assert_rule('9.3', [self::defined(vocab::VERB_FAILED)], self::initialized(['regpassed' => true]));
    }

    /**
     * Rule 12: score only in passed/failed; scaled 0-1; raw needs min and max (§9.5.1).
     */
    public function test_rule_12_score(): void {
        $st = self::defined(vocab::VERB_COMPLETED);
        $st['result']['score'] = ['scaled' => 1];
        $this->assert_rule('9.5.1', [$st], self::initialized());
        $st = self::defined(vocab::VERB_PASSED);
        $st['result']['score'] = ['raw' => 90];
        $this->assert_rule('9.5.1', [$st], self::initialized());
        $st = self::defined(vocab::VERB_PASSED);
        unset($st['result']['score']['max']);
        $this->assert_rule('9.5.1', [$st], self::initialized());
        $st = self::defined(vocab::VERB_FAILED);
        $st['result']['score'] = ['scaled' => -0.5];
        $this->assert_rule('9.5.1', [$st], self::initialized());
    }

    /**
     * Rule 13: passed/failed consistent with the masteryScore, with its extension (§9.3.4, §9.3.5, §9.6.3.2).
     */
    public function test_rule_13_mastery(): void {
        $state = self::initialized(['masteryscore' => 0.8]);
        $st = self::defined(vocab::VERB_PASSED, 0.8);
        $st['result']['score'] = ['scaled' => 0.7];
        $this->assert_rule('9.3.4', [$st], $state);
        $st = self::defined(vocab::VERB_FAILED, 0.8);
        $st['result']['score'] = ['scaled' => 0.9];
        $this->assert_rule('9.3.4', [$st], $state);
        $st = self::defined(vocab::VERB_FAILED, 0.8);
        $st['result']['score'] = ['scaled' => 0.8];
        $this->assert_rule('9.3.4', [$st], $state);
        $this->assert_rule('9.3.4', [self::defined(vocab::VERB_PASSED)], $state);
        $this->assert_rule('9.3.4', [self::defined(vocab::VERB_FAILED)], $state);
        $this->assert_rule('9.3.4', [self::defined(vocab::VERB_PASSED, 0.5)], $state);
        $st = self::defined(vocab::VERB_PASSED);
        $st['context']['extensions'][vocab::EXT_MASTERYSCORE] = '0.8';
        $this->assert_rule('9.3.4', [$st], $state);
        // A wrong extension is refused even on a scoreless statement.
        $st = self::defined(vocab::VERB_PASSED, 0.5);
        unset($st['result']['score']);
        $this->assert_rule('9.3.4', [$st], $state);
    }

    /**
     * Rule 13: a scoreless passed/failed needs no masteryscore extension (§9.6.3.2 "based on the masteryScore";
     * CATAPULT 9.6.3.2-2 (d); LTS 001-essentials and 005-2-invalid-au, both with masteryScore 0.9).
     */
    public function test_rule_13_scoreless_without_extension(): void {
        foreach ([vocab::VERB_PASSED, vocab::VERB_FAILED] as $verb) {
            $st = self::defined($verb);
            unset($st['result']['score']);
            $this->assert_ok([$st], self::initialized(['masteryscore' => 0.9]), [2]);
            $st = self::defined($verb, 0.9);
            unset($st['result']['score']);
            $this->assert_ok([$st], self::initialized(['masteryscore' => 0.9]), [2]);
        }
    }

    /**
     * Rule 14: success and completion only where cmi5 sets them (§9.5.2, §9.5.3).
     */
    public function test_rule_14_success_completion(): void {
        $st = self::defined(vocab::VERB_PASSED);
        $st['result']['success'] = false;
        $this->assert_rule('9.5.2', [$st], self::initialized());
        $st = self::defined(vocab::VERB_FAILED);
        unset($st['result']['success']);
        $this->assert_rule('9.5.2', [$st], self::initialized());
        $st = self::defined(vocab::VERB_COMPLETED);
        unset($st['result']['completion']);
        $this->assert_rule('9.5.2', [$st], self::initialized());
        $st = self::defined(vocab::VERB_COMPLETED);
        $st['result']['completion'] = false;
        $this->assert_rule('9.5.2', [$st], self::initialized());
        $st = self::defined(vocab::VERB_TERMINATED);
        $st['result']['success'] = true;
        $this->assert_rule('9.5.2', [$st], self::initialized());
        $st = self::defined(vocab::VERB_PASSED);
        $st['result']['completion'] = true;
        $this->assert_rule('9.5.2', [$st], self::initialized());
    }

    /**
     * Rule 15: duration in terminated, completed, passed and failed (§9.5.4.1).
     */
    public function test_rule_15_duration(): void {
        $st = self::defined(vocab::VERB_TERMINATED);
        unset($st['result']);
        $this->assert_rule('9.5.4.1', [$st], self::initialized());
        $st = self::defined(vocab::VERB_TERMINATED);
        $st['result']['duration'] = 'PTx';
        $this->assert_rule('9.5.4.1', [$st], self::initialized());
        foreach ([vocab::VERB_COMPLETED, vocab::VERB_PASSED, vocab::VERB_FAILED] as $verb) {
            $st = self::defined($verb);
            unset($st['result']['duration']);
            $this->assert_rule('9.5.4.1', [$st], self::initialized());
        }
    }

    /**
     * Rule 16: the moveOn category exactly on defined statements with success or completion (§9.6.2.2).
     */
    public function test_rule_16_moveon_category(): void {
        $moveon = ['id' => vocab::CAT_MOVEON];
        $st = self::defined(vocab::VERB_COMPLETED);
        $st['context']['contextActivities']['category'] = [['id' => vocab::CAT_CMI5]];
        $this->assert_rule('9.6.2.2', [$st], self::initialized());
        $st = self::allowed();
        $st['context']['contextActivities']['category'] = [$moveon];
        $this->assert_rule('9.6.2.2', [$st], self::initialized());
        $st = self::defined(vocab::VERB_TERMINATED);
        $st['context']['contextActivities']['category'][] = $moveon;
        $this->assert_rule('9.6.2.2', [$st], self::initialized());
        $st = self::defined(vocab::VERB_INITIALIZED);
        $st['context']['contextActivities']['category'][] = $moveon;
        $this->assert_rule('9.6.2.2', [$st], self::state());
    }

    /**
     * Rule 17: no completed, passed or failed in Browse or Review (§10.2.2).
     */
    public function test_rule_17_launchmode(): void {
        foreach (['Browse', 'Review'] as $mode) {
            foreach ([vocab::VERB_COMPLETED, vocab::VERB_PASSED, vocab::VERB_FAILED] as $verb) {
                $this->assert_rule('10.2.2', [self::defined($verb)], self::initialized(['launchmode' => $mode]));
            }
            $this->assert_ok(
                [self::defined(vocab::VERB_INITIALIZED), self::allowed(), self::defined(vocab::VERB_TERMINATED)],
                self::state(['launchmode' => $mode]),
                [2, 1, 2]
            );
        }
    }

    /**
     * Rule 18: progress is a whole number from 0 to 100 (§9.5.5.1).
     */
    public function test_rule_18_progress(): void {
        foreach ([101, 50.5, -1, '50', true] as $value) {
            $st = self::allowed(self::ADL . 'progressed');
            $st['result'] = ['extensions' => [vocab::EXT_PROGRESS => $value]];
            $this->assert_rule('9.5.5.1', [$st], self::initialized());
        }
        foreach ([0, 50, 100, 50.0] as $value) {
            $st = self::allowed(self::ADL . 'progressed');
            $st['result'] = ['extensions' => [vocab::EXT_PROGRESS => $value]];
            $this->assert_ok([$st], self::initialized(), [1]);
        }
    }

    /**
     * "initialized" needs the learner preferences read first when the setting asks for it (11.0.0.0-3).
     */
    public function test_prefs_read(): void {
        $init = self::defined(vocab::VERB_INITIALIZED);
        $this->assert_rule('11.0.0.0-3', [$init], self::state(['prefsread' => false]));
        $after = $this->assert_ok([$init], self::state(['prefsread' => false, 'requireprefsread' => false]), [2]);
        $this->assertSame(launcher::STATUS_INITIALIZED, $after->sessionstatus);
    }

    /**
     * A batch is judged statement by statement, in order: [initialized, experienced, terminated] (Review Focus 4).
     */
    public function test_batch_in_order(): void {
        $state = self::state();
        $after = $this->assert_ok(
            [self::defined(vocab::VERB_INITIALIZED), self::allowed(), self::defined(vocab::VERB_TERMINATED)],
            $state,
            [2, 1, 2]
        );
        $this->assertSame(launcher::STATUS_TERMINATED, $after->sessionstatus);
        $this->assertSame([vocab::VERB_INITIALIZED => true, vocab::VERB_TERMINATED => true], $after->sessionverbs);
        $this->assertSame(launcher::STATUS_LAUNCHED, $state->sessionstatus);
        $this->assertSame([], $state->sessionverbs);
    }

    /**
     * Two requests: [initialized] and then [completed, passed (0.9 with mastery 0.8), terminated].
     */
    public function test_two_requests(): void {
        $state = self::state(['masteryscore' => 0.8]);
        $state = $this->assert_ok([self::defined(vocab::VERB_INITIALIZED)], $state, [2]);
        $state = $this->assert_ok(
            [self::defined(vocab::VERB_COMPLETED), self::defined(vocab::VERB_PASSED, 0.8),
                self::defined(vocab::VERB_TERMINATED)],
            $state,
            [2, 2, 2]
        );
        $this->assertTrue($state->regcompleted);
        $this->assertTrue($state->regpassed);
        $this->assertFalse($state->regfailed);
        $this->assertSame(launcher::STATUS_TERMINATED, $state->sessionstatus);
    }

    /**
     * Accepted variants: +00:00 offset, upper-case registration, allowed statement with a score, masteryScore
     * given as the number stored in the database, failed with a scaled score below mastery.
     */
    public function test_accepted_variants(): void {
        $st = self::allowed();
        $st['timestamp'] = '2026-09-30T12:00:00+00:00';
        $this->assert_ok([$st], self::initialized(), [1]);

        $st = self::allowed();
        $st['context']['registration'] = strtoupper(self::REG);
        $this->assert_ok([$st], self::initialized(), [1]);

        $st = self::allowed(vocab::VERB_ANSWERED);
        $st['result'] = ['success' => true, 'completion' => true, 'score' => ['raw' => 1, 'min' => 0, 'max' => 1]];
        $this->assert_ok([$st], self::initialized(), [1]);

        $st = self::defined(vocab::VERB_FAILED, 0.8);
        $this->assert_ok([$st], self::initialized(['masteryscore' => 0.8]), [2]);

        $st = self::defined(vocab::VERB_PASSED, 1);
        $st['result']['score']['scaled'] = 1;
        $this->assert_ok([$st], self::initialized(['masteryscore' => 1.0]), [2]);

        $st = self::defined(vocab::VERB_PASSED, 0.8);
        $this->assert_ok([$st], self::initialized(), [2]);

        $this->assert_ok([], self::initialized(), []);
    }

    /**
     * failed in one session and passed in the next one are accepted (§9.3: only failed after passed is refused).
     */
    public function test_failed_then_passed_in_another_session(): void {
        $first = $this->assert_ok(
            [self::defined(vocab::VERB_INITIALIZED), self::defined(vocab::VERB_FAILED),
                self::defined(vocab::VERB_TERMINATED)],
            self::state(),
            [2, 2, 2]
        );
        $this->assertTrue($first->regfailed);
        $second = self::state(['regfailed' => true, 'sessionuuid' => '9e3f5a4c-1d6f-4a8b-8c3d-4e5f6a7b8c9d']);
        $batch = [self::defined(vocab::VERB_INITIALIZED), self::defined(vocab::VERB_PASSED),
            self::defined(vocab::VERB_TERMINATED)];
        foreach ($batch as $i => $st) {
            $batch[$i]['context']['extensions'][vocab::EXT_SESSIONID] = $second->sessionuuid;
        }
        $after = $this->assert_ok($batch, $second, [2, 2, 2]);
        $this->assertTrue($after->regpassed);
    }

    /**
     * A cmi5 verb without the cmi5 category is allowed and does not count for the registration (§7.1.3).
     */
    public function test_cmi5_verb_without_category_is_allowed(): void {
        $st = self::allowed(vocab::VERB_COMPLETED);
        $st['object']['id'] = self::ACTIVITY;
        $st['result'] = ['duration' => 'PT1M'];
        $after = $this->assert_ok([self::defined(vocab::VERB_INITIALIZED), $st], self::state(), [2, 1]);
        $this->assertFalse($after->regcompleted);
        $this->assertSame([vocab::VERB_INITIALIZED => true], $after->sessionverbs);
        $this->assertSame(launcher::STATUS_INITIALIZED, $after->sessionstatus);
    }

    /**
     * The violation of the second statement refuses the batch and names that rule (the first statement is fine).
     */
    public function test_first_violation_wins(): void {
        $bad = self::defined(vocab::VERB_COMPLETED);
        unset($bad['id']);
        $batch = [self::defined(vocab::VERB_INITIALIZED), $bad, self::allowed()];
        $this->assert_rule('9.1.0.0-1', $batch, self::state());
        try {
            cmi5_rules::check_batch($batch, self::state());
            $this->fail('Expected a refusal');
        } catch (lrs_exception $e) {
            $this->assertSame('2', $e->a);
            $this->assertStringContainsString('Statement 2 of the request', $e->getMessage());
        }
    }

    /**
     * from_context reads the session, the AU, the registration, regau and the defined verbs of the session.
     */
    public function test_from_context(): void {
        global $DB;
        $this->setup_lrs_activity(1790000000);
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        [$session, $token] = $this->launch_with_token();
        $ctx = auth::session_from_request($this->lrs_request('GET', 'about', $token));

        $state = rules_state::from_context($ctx);
        $this->assertSame(launcher::STATUS_LAUNCHED, $state->sessionstatus);
        $this->assertSame([], $state->sessionverbs);
        $this->assertSame('Normal', $state->launchmode);
        $this->assertSame($ctx->au->moveon, $state->moveon);
        $this->assertNull($state->masteryscore);
        $this->assertSame($ctx->reg->registration, $state->registration);
        $this->assertSame($session->sessionuuid, $state->sessionuuid);
        $this->assertSame($ctx->au->lmsactivityid, $state->activityid);
        $this->assertSame('https://example.com/au/1', $state->publisherid);
        $this->assertSame($ctx->actor['account'], $state->actoraccount);
        $this->assertFalse($state->prefsread);
        $this->assertFalse($state->requireprefsread);
        $this->assertFalse($state->regcompleted);

        $DB->set_field('scormmakercmi5_regau', 'passed', 1, ['regid' => $ctx->reg->id, 'auid' => $ctx->au->id]);
        $DB->set_field('scormmakercmi5_session', 'prefsread', 1, ['id' => $session->id]);
        $DB->set_field('scormmakercmi5_au', 'masteryscore', 0.75, ['id' => $ctx->au->id]);
        $row = (object)[
            'scormmakercmi5id' => $ctx->reg->scormmakercmi5id, 'pkgid' => $ctx->reg->pkgid, 'regid' => $ctx->reg->id,
            'auid' => $ctx->au->id, 'userid' => $ctx->reg->userid, 'sessionid' => $session->id,
            'statementid' => \core\uuid::generate(), 'source' => statement_store::SOURCE_AU,
            'verb' => vocab::VERB_INITIALIZED, 'objecthash' => sha1($ctx->au->lmsactivityid),
            'objectid' => $ctx->au->lmsactivityid, 'cmi5kind' => cmi5_rules::KIND_DEFINED, 'timestampms' => 1,
            'storedms' => 1, 'smprofile' => 0, 'payload' => '{}',
        ];
        $DB->insert_record('scormmakercmi5_stmt', $row);
        $row->statementid = \core\uuid::generate();
        $row->verb = self::ADL . 'experienced';
        $row->cmi5kind = cmi5_rules::KIND_ALLOWED;
        $DB->insert_record('scormmakercmi5_stmt', $row);
        $DB->set_field('scormmakercmi5_session', 'status', launcher::STATUS_INITIALIZED, ['id' => $session->id]);

        $ctx = auth::session_from_request($this->lrs_request('GET', 'about', $token));
        $state = rules_state::from_context($ctx);
        $this->assertSame(launcher::STATUS_INITIALIZED, $state->sessionstatus);
        $this->assertSame([vocab::VERB_INITIALIZED => true], $state->sessionverbs);
        $this->assertTrue($state->prefsread);
        $this->assertTrue($state->regpassed);
        $this->assertFalse($state->regfailed);
        $this->assertSame(0.75, $state->masteryscore);
    }
}
