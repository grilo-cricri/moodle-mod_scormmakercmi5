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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\router;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\satisfaction::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\lms_statements::class)]
/**
 * Tests for the satisfaction of AU, block and course (design §6.5, §6.1 step 2; cmi5 §9.3.9, §13.1.4; R5.4, R6.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\runtime\satisfaction
 * @covers     \mod_scormmakercmi5\local\runtime\lms_statements
 */
final class satisfaction_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string Publisher id of the course in the fixtures. */
    private const COURSE = 'https://example.com/course';

    /**
     * The moveOn matrix (cmi5 §13.1.4 moveOn; §9.3.2: a waived AU met its moveOn criteria).
     *
     * @return array
     */
    public static function moveon_provider(): array {
        return [
            'Passed, passed' => ['Passed', 0, 1, 0, true],
            'Passed, completed' => ['Passed', 1, 0, 0, false],
            'Completed, completed' => ['Completed', 1, 0, 0, true],
            'Completed, passed' => ['Completed', 0, 1, 0, false],
            'CompletedAndPassed, both' => ['CompletedAndPassed', 1, 1, 0, true],
            'CompletedAndPassed, completed' => ['CompletedAndPassed', 1, 0, 0, false],
            'CompletedAndPassed, passed' => ['CompletedAndPassed', 0, 1, 0, false],
            'CompletedOrPassed, passed' => ['CompletedOrPassed', 0, 1, 0, true],
            'CompletedOrPassed, completed' => ['CompletedOrPassed', 1, 0, 0, true],
            'CompletedOrPassed, none' => ['CompletedOrPassed', 0, 0, 0, false],
            'NotApplicable, none' => ['NotApplicable', 0, 0, 0, true],
            'Passed, waived' => ['Passed', 0, 0, 1, true],
            'Completed, waived' => ['Completed', 0, 0, 1, true],
            'CompletedAndPassed, waived' => ['CompletedAndPassed', 0, 0, 1, true],
            'CompletedOrPassed, waived' => ['CompletedOrPassed', 0, 0, 1, true],
            'unknown moveOn' => ['Other', 1, 1, 0, false],
        ];
    }

    /**
     * is_au_satisfied() follows moveOn; waived satisfies any moveOn.
     *
     * @dataProvider moveon_provider
     * @param string $moveon moveOn.
     * @param int $completed regau.completed.
     * @param int $passed regau.passed.
     * @param int $waived regau.waived.
     * @param bool $expected Expected.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('moveon_provider')]
    public function test_is_au_satisfied(string $moveon, int $completed, int $passed, int $waived, bool $expected): void {
        $regau = (object)['completed' => $completed, 'passed' => $passed, 'failed' => 0, 'waived' => $waived];
        $this->assertSame($expected, satisfaction::is_au_satisfied($moveon, $regau));
    }

    /**
     * All AUs NotApplicable in one block: the registration is born satisfied (R6.3), with one synthetic session.
     */
    public function test_notapplicable_satisfied_at_creation(): void {
        global $DB;
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/b1', 'children' => [
                ['id' => 'https://example.com/au/1', 'moveOn' => 'NotApplicable'],
                ['id' => 'https://example.com/au/2', 'moveOn' => 'NotApplicable'],
            ]],
        ]]));
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);

        $this->assertSame(2, $DB->count_records('scormmakercmi5_regau', ['regid' => $reg->id, 'satisfied' => 1]));
        $this->assertSame(1, (int)$reg->satisfied);
        $this->assertSame(self::NOW, (int)$reg->timesatisfied);
        $block = $DB->get_record('scormmakercmi5_block', ['pkgid' => $reg->pkgid], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_regblock', ['regid' => $reg->id, 'blockid' => $block->id]));

        $sessions = $DB->get_records('scormmakercmi5_session', ['regid' => $reg->id]);
        $this->assertCount(1, $sessions);
        $session = reset($sessions);
        $this->assertSame(launcher::KIND_SYNTHETIC, (int)$session->kind);
        $this->assertSame(launcher::STATUS_TERMINATED, (int)$session->status);

        $statements = $this->satisfied_statements((int)$reg->id);
        $this->assertCount(2, $statements);
        [$blockst, $coursest] = $statements;
        $this->assert_satisfied($blockst, $reg, $block->lmsiri, vocab::TYPE_BLOCK, 'https://example.com/b1', $session);
        $this->assert_satisfied($coursest, $reg, $instance->lmscourseiri, vocab::TYPE_COURSE, self::COURSE, $session);
        $this->assertSame(2, (int)$reg->statementcount);
        $this->assertSame(2, $DB->count_records('scormmakercmi5_stmt', ['regid' => $reg->id]));
    }

    /**
     * A NotApplicable AU next to one that is not: the AU is satisfied, but nothing is written and no session exists.
     */
    public function test_notapplicable_partial_writes_nothing(): void {
        global $DB;
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'moveOn' => 'NotApplicable'],
            ['id' => 'https://example.com/au/2', 'moveOn' => 'Completed'],
        ]));
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $au1 = $this->au('https://example.com/au/1');
        $this->assertSame(1, (int)$DB->get_field('scormmakercmi5_regau', 'satisfied', ['regid' => $reg->id, 'auid' => $au1->id]));
        $this->assertSame(1, $DB->count_records('scormmakercmi5_regau', ['regid' => $reg->id, 'satisfied' => 1]));
        $this->assertSame(0, (int)$reg->satisfied);
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session', ['regid' => $reg->id]));
        $this->assertSame(0, $DB->count_records('scormmakercmi5_stmt', ['regid' => $reg->id]));
    }

    /**
     * B1{AU1 Completed, B2{AU2 Passed}}: the inner block first, then the outer block and the course, each in the
     * session that triggered it, through the real ingestion; replays and repeated evaluations write nothing twice.
     */
    public function test_nested_blocks_through_ingestion(): void {
        global $DB;
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/b1', 'children' => [
                ['id' => 'https://example.com/au/1', 'moveOn' => 'Completed'],
                ['id' => 'https://example.com/b2', 'children' => [
                    ['id' => 'https://example.com/au/2', 'moveOn' => 'Passed'],
                ]],
            ]],
        ]]));
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        $au1 = $this->au('https://example.com/au/1');
        $au2 = $this->au('https://example.com/au/2');
        $b1 = $DB->get_record('scormmakercmi5_block', ['publisherid' => 'https://example.com/b1'], '*', MUST_EXIST);
        $b2 = $DB->get_record('scormmakercmi5_block', ['publisherid' => 'https://example.com/b2'], '*', MUST_EXIST);

        // AU2 passed → B2 only.
        [$session2, $token2] = $this->launch_with_token(null, (int)$au2->id);
        $batch = [$this->defined($session2, vocab::VERB_INITIALIZED), $this->defined($session2, vocab::VERB_PASSED)];
        $this->assertSame(200, $this->post($token2, $batch)->status);
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session2->regid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$DB->get_field('scormmakercmi5_regau', 'satisfied', ['regid' => $reg->id, 'auid' => $au2->id]));
        $key2 = ['regid' => $reg->id, 'auid' => $au2->id];
        $this->assertSame(self::NOW, (int)$DB->get_field('scormmakercmi5_regau', 'timesatisfied', $key2));
        $this->assertSame(0, (int)$DB->get_field('scormmakercmi5_regau', 'satisfied', ['regid' => $reg->id, 'auid' => $au1->id]));
        $statements = $this->satisfied_statements((int)$reg->id);
        $this->assertCount(1, $statements);
        $this->assert_satisfied($statements[0], $reg, $b2->lmsiri, vocab::TYPE_BLOCK, 'https://example.com/b2', $session2);
        $this->assertSame(0, (int)$reg->satisfied);
        $this->assertSame([(int)$b2->id], array_map('intval', $DB->get_fieldset_select(
            'scormmakercmi5_regblock',
            'blockid',
            'regid = :regid',
            ['regid' => $reg->id]
        )));

        // The identical batch again (lost response): nothing new.
        $this->assertSame(200, $this->post($token2, $batch)->status);
        $this->assertCount(1, $this->satisfied_statements((int)$reg->id));

        // AU1 completed → B1 and the course, in the session of AU1.
        [$session1, $token1] = $this->launch_with_token(null, (int)$au1->id);
        $batch = [$this->defined($session1, vocab::VERB_INITIALIZED), $this->defined($session1, vocab::VERB_COMPLETED)];
        $this->assertSame(200, $this->post($token1, $batch)->status);
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $reg->id], '*', MUST_EXIST);
        $statements = $this->satisfied_statements((int)$reg->id);
        $this->assertCount(3, $statements);
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $this->assert_satisfied($statements[1], $reg, $b1->lmsiri, vocab::TYPE_BLOCK, 'https://example.com/b1', $session1);
        $this->assert_satisfied($statements[2], $reg, $instance->lmscourseiri, vocab::TYPE_COURSE, self::COURSE, $session1);
        $this->assertSame(1, (int)$reg->satisfied);
        $this->assertSame(self::NOW, (int)$reg->timesatisfied);
        $this->assertSame(2, $DB->count_records('scormmakercmi5_regblock', ['regid' => $reg->id]));
        // Every statement of the registration is counted, the LMS ones included.
        $this->assertSame($DB->count_records('scormmakercmi5_stmt', ['regid' => $reg->id]), (int)$reg->statementcount);
        $synthetic = ['regid' => $reg->id, 'kind' => launcher::KIND_SYNTHETIC];
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session', $synthetic));

        // AU2 "passed again" in another session (the rules forbid it; evaluate() called twice): nothing duplicated.
        $DB->set_field('scormmakercmi5_regau', 'passed', 1, ['regid' => $reg->id, 'auid' => $au2->id]);
        $count = (int)$reg->statementcount;
        $this->assertSame([], satisfaction::evaluate($reg, $session2));
        $this->assertSame([], satisfaction::evaluate($reg, $session1));
        $this->assertSame([], satisfaction::evaluate($reg, null));
        $this->assertCount(3, $this->satisfied_statements((int)$reg->id));
        $this->assertSame(2, $DB->count_records('scormmakercmi5_regblock', ['regid' => $reg->id]));
        $this->assertSame($count, (int)$DB->get_field('scormmakercmi5_reg', 'statementcount', ['id' => $reg->id]));
        $synthetic = ['regid' => $reg->id, 'kind' => launcher::KIND_SYNTHETIC];
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session', $synthetic));
    }

    /**
     * A regau with passed but no satisfied flag yet (block row missing) is repaired by evaluate() without
     * duplicating what exists; failed never satisfies Passed.
     */
    public function test_evaluate_returns_ids_and_failed_does_not_satisfy(): void {
        global $DB;
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'moveOn' => 'Passed'],
        ]));
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $au1 = $this->au('https://example.com/au/1');
        $DB->set_field('scormmakercmi5_regau', 'failed', 1, ['regid' => $reg->id, 'auid' => $au1->id]);
        $this->assertSame([], satisfaction::evaluate($reg, null));
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session', ['regid' => $reg->id]));

        $DB->set_field('scormmakercmi5_regau', 'passed', 1, ['regid' => $reg->id, 'auid' => $au1->id]);
        $ids = satisfaction::evaluate($reg, null);
        $this->assertCount(1, $ids);
        $stmt = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $ids[0]], '*', MUST_EXIST);
        $this->assertSame(vocab::VERB_SATISFIED, $stmt->verb);
        $this->assertSame(statement_store::SOURCE_LMS, (int)$stmt->source);
        $this->assertSame($instance->lmscourseiri, $stmt->objectid);
        $this->assertSame((int)$au1->id, (int)$stmt->auid);
        $this->assertSame(1, (int)$reg->satisfied);
        $this->assertSame(1, (int)$reg->statementcount);
        $this->assertSame([], satisfaction::evaluate($reg, null));
    }

    /**
     * The hook for Task 19: a waived AU satisfies its block and the course, outside a launch (synthetic session).
     */
    public function test_waived_counts_as_satisfied(): void {
        global $DB;
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([], ['blocks' => [
            ['id' => 'https://example.com/b1', 'children' => [
                ['id' => 'https://example.com/au/1', 'moveOn' => 'CompletedAndPassed'],
            ]],
        ]]));
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $au1 = $this->au('https://example.com/au/1');
        $DB->set_field('scormmakercmi5_regau', 'waived', 1, ['regid' => $reg->id, 'auid' => $au1->id]);

        $ids = satisfaction::evaluate($reg, null);
        $this->assertCount(2, $ids);
        $session = $DB->get_record('scormmakercmi5_session', ['regid' => $reg->id], '*', MUST_EXIST);
        $this->assertSame(launcher::KIND_SYNTHETIC, (int)$session->kind);
        $statements = $this->satisfied_statements((int)$reg->id);
        $block = $DB->get_record('scormmakercmi5_block', ['pkgid' => $reg->pkgid], '*', MUST_EXIST);
        $this->assert_satisfied($statements[0], $reg, $block->lmsiri, vocab::TYPE_BLOCK, 'https://example.com/b1', $session);
        $this->assert_satisfied($statements[1], $reg, $instance->lmscourseiri, vocab::TYPE_COURSE, self::COURSE, $session);
        $this->assertSame(1, (int)$DB->get_field('scormmakercmi5_regau', 'satisfied', ['regid' => $reg->id, 'auid' => $au1->id]));
    }

    /**
     * An AU of the current package by publisher id.
     *
     * @param string $publisherid Publisher id.
     * @return \stdClass
     */
    private function au(string $publisherid): \stdClass {
        global $DB;
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $this->instance->id], MUST_EXIST);
        return $DB->get_record('scormmakercmi5_au', ['pkgid' => $pkgid, 'publisherid' => $publisherid], '*', MUST_EXIST);
    }

    /**
     * The satisfied statements of a registration, decoded, in the order they were stored.
     *
     * @param int $regid Registration id.
     * @return array [\stdClass row, array payload] per statement.
     */
    private function satisfied_statements(int $regid): array {
        global $DB;
        $rows = $DB->get_records('scormmakercmi5_stmt', ['regid' => $regid, 'verb' => vocab::VERB_SATISFIED], 'id');
        return array_values(array_map(
            static fn(\stdClass $row): array => [$row, json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR)],
            $rows
        ));
    }

    /**
     * Asserts a satisfied statement (cmi5 §9.3.9, §9.4, §9.6.2.1, §9.6.2.3, §9.6.3.1).
     *
     * @param array $statement [row, payload].
     * @param \stdClass $reg Registration.
     * @param string $iri Object id.
     * @param string $type Activity type.
     * @param string $publisherid Publisher id expected in grouping.
     * @param \stdClass $session Session expected.
     */
    private function assert_satisfied(
        array $statement,
        \stdClass $reg,
        string $iri,
        string $type,
        string $publisherid,
        \stdClass $session
    ): void {
        [$row, $payload] = $statement;
        $this->assertSame(statement_store::SOURCE_LMS, (int)$row->source);
        $this->assertSame((int)$session->id, (int)$row->sessionid);
        $this->assertSame(strtolower($payload['id']), $row->statementid);
        $this->assertSame(vocab::VERB_SATISFIED, $payload['verb']['id']);
        $this->assertSame(['objectType' => 'Activity', 'id' => $iri, 'definition' => ['type' => $type]], $payload['object']);
        $this->assertSame($iri, $row->objectid);
        $this->assertSame(actor::for_user((int)$reg->userid), $payload['actor']);
        $this->assertSame($reg->registration, $payload['context']['registration']);
        $activities = $payload['context']['contextActivities'];
        $this->assertSame([['objectType' => 'Activity', 'id' => $publisherid]], $activities['grouping']);
        $this->assertSame([['objectType' => 'Activity', 'id' => vocab::CAT_CMI5]], $activities['category']);
        $this->assertSame([vocab::EXT_SESSIONID => $session->sessionuuid], $payload['context']['extensions']);
        $this->assertSame(actor::lms_authority(), $payload['authority']);
        $this->assertArrayHasKey('stored', $payload);
        $this->assertArrayNotHasKey('result', $payload);
    }

    /**
     * A cmi5 defined statement of a session.
     *
     * @param \stdClass $session Session.
     * @param string $verb Verb IRI.
     * @return array
     */
    private function defined(\stdClass $session, string $verb): array {
        global $DB, $CFG;
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], '*', MUST_EXIST);
        $st = [
            'id' => \core\uuid::generate(),
            'timestamp' => '2026-09-30T12:00:00.000Z',
            'actor' => ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]],
            'verb' => ['id' => $verb, 'display' => ['pt-BR' => 'verbo']],
            'object' => ['objectType' => 'Activity', 'id' => $au->lmsactivityid],
            'context' => [
                'registration' => $reg->registration,
                'contextActivities' => [
                    'grouping' => [['objectType' => 'Activity', 'id' => $au->publisherid]],
                    'category' => [['id' => vocab::CAT_CMI5]],
                ],
                'extensions' => [vocab::EXT_SESSIONID => $session->sessionuuid],
            ],
        ];
        if ($verb === vocab::VERB_COMPLETED) {
            $st['result'] = ['completion' => true, 'duration' => 'PT5M'];
            $st['context']['contextActivities']['category'][] = ['id' => vocab::CAT_MOVEON];
        } else if ($verb === vocab::VERB_PASSED) {
            $st['result'] = ['success' => true, 'duration' => 'PT5M'];
            $st['context']['contextActivities']['category'][] = ['id' => vocab::CAT_MOVEON];
        }
        return $st;
    }

    /**
     * POST statements through the router.
     *
     * @param string $token Session token.
     * @param array $statements Statements.
     * @return \mod_scormmakercmi5\local\lrs\response
     */
    private function post(string $token, array $statements): \mod_scormmakercmi5\local\lrs\response {
        $body = json_encode($statements, statement_store::JSON_FLAGS);
        return router::dispatch($this->lrs_request('POST', 'statements', $token, [], $body));
    }
}
