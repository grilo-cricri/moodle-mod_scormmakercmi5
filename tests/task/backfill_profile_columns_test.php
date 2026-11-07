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

namespace mod_scormmakercmi5\task;

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\statement_store;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\task\backfill_profile_columns::class)]
/**
 * Tests for the backfill of the profile 2 columns of statements stored before 0.2.0 (subproject D).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\task\backfill_profile_columns
 */
final class backfill_profile_columns_test extends \advanced_testcase {
    /** @var string Base IRI of a SCORM Maker course. */
    private const SMCOURSE = 'https://scormmaker.com.br/xapi/curso/c1/';

    /**
     * A statement row as 0.1.0 stored it: the C columns only.
     *
     * @param string $object Object IRI.
     * @param bool $profile2 With the extensions of the profile 2.
     * @param array $overrides Row fields.
     * @return int Row id.
     */
    private function row(string $object, bool $profile2 = true, array $overrides = []): int {
        global $DB;
        $extensions = [vocab::SM_EXT_VERSION => 'v#1'];
        if ($profile2) {
            $extensions += [vocab::SM_EXT_PROFILEVER => 2, vocab::SM_EXT_BLOCKTYPE => 'cenario'];
        }
        $statement = [
            'id' => \core\uuid::generate(),
            'verb' => ['id' => vocab::VERB_INTERACTED],
            'object' => ['objectType' => 'Activity', 'id' => $object],
            'context' => [
                'contextActivities' => ['category' => [['id' => vocab::SM_PROFILE]]],
                'extensions' => $extensions,
            ],
        ];
        $c = statement_store::sm_columns($statement);
        $isblock = $c->smblock !== null;
        return (int)$DB->insert_record('scormmakercmi5_stmt', (object)($overrides + [
            'scormmakercmi5id' => 1, 'pkgid' => 1, 'regid' => 1, 'auid' => 1, 'userid' => 2, 'sessionid' => 1,
            'statementid' => $statement['id'], 'source' => statement_store::SOURCE_AU, 'verb' => vocab::VERB_INTERACTED,
            'objecthash' => sha1($object), 'objectid' => $object, 'cmi5kind' => statement_store::KIND_ALLOWED,
            'timestampms' => 1, 'storedms' => 1,
            // 0.1.0: profile and version, a lesson or question kind, never a block.
            'smprofile' => $c->smprofile, 'smversion' => $c->smversion,
            'smkind' => $isblock ? null : $c->smkind, 'smitem' => $isblock ? null : $c->smitem,
            'payload' => json_encode($statement, statement_store::JSON_FLAGS),
        ]));
    }

    /**
     * The columns of a row.
     *
     * @param int $id Row id.
     * @return array
     */
    private function columns(int $id): array {
        global $DB;
        $r = $DB->get_record('scormmakercmi5_stmt', ['id' => $id], '*', MUST_EXIST);
        return [(int)$r->smprofile, $r->smversion, $r->smkind, $r->smitem, $r->smblock, $r->smblocktype,
            $r->smprofilever === null ? null : (int)$r->smprofilever];
    }

    /**
     * The rows of the profile 2 get the columns a fresh ingestion gives; the others are left as they were.
     */
    public function test_execute_fills_the_profile_2_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $summary = $this->row(self::SMCOURSE . 'bloco/b1');
        $decision = $this->row(self::SMCOURSE . 'bloco/b1/item/k1');
        $lesson = $this->row(self::SMCOURSE . 'licao/l1', false);
        $wide = $this->row(self::SMCOURSE . 'bloco/' . str_repeat('b', 65));
        $lms = $this->row(self::SMCOURSE . 'bloco/b2', true, ['source' => statement_store::SOURCE_LMS, 'smprofile' => 0,
            'smversion' => null]);
        $broken = $this->row(self::SMCOURSE . 'bloco/b3', true, ['payload' => '{"id":']);
        $this->assertSame([1, 'v#1', null, null, null, null, null], $this->columns($summary));
        $lessonbefore = $this->columns($lesson);

        $task = new backfill_profile_columns();
        $task->execute();
        $this->assertSame([1, 'v#1', 'bloco', 'b1', 'b1', 'cenario', 2], $this->columns($summary));
        $this->assertSame([1, 'v#1', 'item', 'k1', 'b1', 'cenario', 2], $this->columns($decision));
        $this->assertSame($lessonbefore, $this->columns($lesson), 'a C lesson is left as it was');
        $this->assertSame([0, null, null, null, null, null, null], $this->columns($wide), 'too wide: as a fresh ingestion');
        $this->assertSame([0, null, null, null, null, null, null], $this->columns($lms), 'LMS rows are not touched');
        $this->assertSame([1, 'v#1', null, null, null, null, null], $this->columns($broken), 'an unreadable payload is skipped');
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(backfill_profile_columns::class), 'done: nothing queued');

        // Idempotent: a second run changes nothing.
        $before = $DB->get_records('scormmakercmi5_stmt', null, 'id');
        (new backfill_profile_columns())->execute();
        $this->assertEquals($before, $DB->get_records('scormmakercmi5_stmt', null, 'id'));
    }

    /**
     * Final wave W2a (I-2 of its review): a block summary stored by 0.1.0 had its time column from iso_duration, so a
     * forged P1D counted one day. Once the backfill makes it a summary of the analysis, its time follows the rule of
     * the contract, as the hosted course (migration 0004): null outside it, the decimal text half up inside it. A
     * decision and a C lesson keep their column.
     */
    public function test_profile_2_summary_gets_the_time_of_the_contract(): void {
        global $DB;
        $this->resetAfterTest();
        $with = function (string $object, string $duration, int $ms, bool $profile2 = true): int {
            global $DB;
            $id = $this->row($object, $profile2, ['durationms' => $ms]);
            $payload = json_decode($DB->get_field('scormmakercmi5_stmt', 'payload', ['id' => $id]), true);
            $payload['result'] = ['duration' => $duration];
            $DB->set_field('scormmakercmi5_stmt', 'payload', json_encode($payload, statement_store::JSON_FLAGS), ['id' => $id]);
            return $id;
        };
        $day = $with(self::SMCOURSE . 'bloco/b1', 'P1D', 86400000);
        $half = $with(self::SMCOURSE . 'bloco/b2', 'PT1.0005S', 1000);
        $good = $with(self::SMCOURSE . 'bloco/b3', 'PT3S', 3000);
        $decision = $with(self::SMCOURSE . 'bloco/b1/item/k1', 'PT1H', 3600000);
        $lesson = $with(self::SMCOURSE . 'licao/l1', 'PT1H', 3600000, false);

        (new backfill_profile_columns())->execute();
        $ms = static fn(int $id) => $DB->get_field('scormmakercmi5_stmt', 'durationms', ['id' => $id]);
        $this->assertNull($ms($day));
        $this->assertSame('1001', (string)$ms($half));
        $this->assertSame('3000', (string)$ms($good));
        $this->assertSame('3600000', (string)$ms($decision));
        $this->assertSame('3600000', (string)$ms($lesson));
        // What the report sums for the block: the forged day is gone.
        $this->assertSame(0, (int)$DB->get_field_sql(
            "SELECT COALESCE(SUM(durationms), 0) FROM {scormmakercmi5_stmt} WHERE smblock = 'b1' AND smkind = 'bloco'"
        ));
    }

    /**
     * Batches: run() stops after its batches and gives the id to resume from; execute() queues the rest.
     */
    public function test_resumable_in_batches(): void {
        $this->resetAfterTest();
        $ids = [];
        foreach (['b1', 'b2', 'b3'] as $block) {
            $ids[] = $this->row(self::SMCOURSE . 'bloco/' . $block);
        }
        $lesson = $this->row(self::SMCOURSE . 'licao/l1', false);

        $next = backfill_profile_columns::run(0, 2, 1);
        $this->assertSame($ids[1], $next);
        $this->assertSame('b2', $this->columns($ids[1])[4]);
        $this->assertNull($this->columns($ids[2])[4], 'not reached yet');
        // Resumed from the id: the rest, then done (the lesson keeps smprofilever NULL and is not read again).
        $this->assertNull(backfill_profile_columns::run($next, 2, 5));
        $this->assertSame('b3', $this->columns($ids[2])[4]);
        $this->assertNull($this->columns($lesson)[6]);
    }

    /**
     * execute() queues a follow-up with the cursor when its time is up, and the follow-up finishes the work.
     */
    public function test_execute_requeues_until_done(): void {
        $this->resetAfterTest();
        $ids = [];
        foreach (['b1', 'b2', 'b3'] as $block) {
            $ids[] = $this->row(self::SMCOURSE . 'bloco/' . $block);
        }
        $task = new backfill_profile_columns();
        $task->set_custom_data((object)['batch' => 1, 'batches' => 2]);
        $task->execute();
        $queued = \core\task\manager::get_adhoc_tasks(backfill_profile_columns::class);
        $this->assertCount(1, $queued);
        $data = reset($queued)->get_custom_data();
        $this->assertSame($ids[1], $data->lastid);
        $this->assertNull($this->columns($ids[2])[4]);
        $this->runAdhocTasks(backfill_profile_columns::class);
        $this->assertSame('b3', $this->columns($ids[2])[4]);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(backfill_profile_columns::class));
    }

    /**
     * A registration of 0.1.0 had its analysis counted in statementcount: once the rows are analysis, the counter is
     * recomputed without them (it may unblock a registration that hit maxstatementsperreg because of them).
     */
    public function test_statementcount_recomputed_without_the_analysis(): void {
        global $DB;
        $this->resetAfterTest();
        $regid = (int)$DB->insert_record('scormmakercmi5_reg', (object)[
            'scormmakercmi5id' => 1, 'userid' => 2, 'pkgid' => 1, 'registration' => \core\uuid::generate(),
            'statementcount' => 5,
        ]);
        $other = (int)$DB->insert_record('scormmakercmi5_reg', (object)[
            'scormmakercmi5id' => 1, 'userid' => 3, 'pkgid' => 1, 'registration' => \core\uuid::generate(),
            'statementcount' => 7,
        ]);
        $this->row(self::SMCOURSE . 'bloco/b1', true, ['regid' => $regid]);
        $this->row(self::SMCOURSE . 'bloco/b1/item/k1', true, ['regid' => $regid, 'verb' => vocab::VERB_SELECTED]);
        $this->row(self::SMCOURSE . 'licao/l1', false, ['regid' => $regid]);
        $this->row(self::SMCOURSE . 'licao/l2', false, ['regid' => $regid]);
        $this->row(self::SMCOURSE . 'licao/l3', false, ['regid' => $regid, 'source' => statement_store::SOURCE_LMS]);
        $this->row(self::SMCOURSE . 'licao/l1', false, ['regid' => $other]);

        (new backfill_profile_columns())->execute();
        $this->assertEquals(3, $DB->get_field('scormmakercmi5_reg', 'statementcount', ['id' => $regid]));
        $this->assertEquals(7, $DB->get_field('scormmakercmi5_reg', 'statementcount', ['id' => $other]), 'untouched');
        // Again: nothing is a candidate any more, the counter stays.
        (new backfill_profile_columns())->execute();
        $this->assertEquals(3, $DB->get_field('scormmakercmi5_reg', 'statementcount', ['id' => $regid]));
    }

    /**
     * A busy registration lock fails the task before any write of that registration: the retry reads its rows again.
     */
    public function test_busy_lock_fails_before_writing(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        // The database lock factories are reentrant within one connection; the file factory is not.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $regid = (int)$DB->insert_record('scormmakercmi5_reg', (object)[
            'scormmakercmi5id' => 1, 'userid' => 2, 'pkgid' => 1, 'registration' => \core\uuid::generate(),
            'statementcount' => 1,
        ]);
        $id = $this->row(self::SMCOURSE . 'bloco/b1', true, ['regid' => $regid]);
        $lock = \mod_scormmakercmi5\local\runtime\launcher::registration_lock($regid, 0);
        $this->assertNotFalse($lock);
        try {
            $this->expectException(\moodle_exception::class);
            backfill_profile_columns::run(0, 10, 1, 0);
        } finally {
            $lock->release();
            $this->assertNull($this->columns($id)[4], 'nothing written');
        }
    }

    /**
     * The name of the task is a language string.
     */
    public function test_name(): void {
        $this->assertSame(
            get_string('taskbackfillprofilecolumns', 'mod_scormmakercmi5'),
            (new backfill_profile_columns())->get_name()
        );
    }
}
