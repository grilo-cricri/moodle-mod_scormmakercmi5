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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\task\recompute_summary_durations::class)]
/**
 * Tests for the recompute of the time of the block summaries already stored (final wave W2a, M-2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\task\recompute_summary_durations
 */
final class recompute_summary_durations_test extends \advanced_testcase {
    /**
     * A stored statement row with the columns of the profile 2.
     *
     * @param string $duration result.duration of the payload.
     * @param int|null $ms durationms as stored before the rule.
     * @param array $overrides Row fields.
     * @return int Row id.
     */
    private function row(string $duration, ?int $ms, array $overrides = []): int {
        global $DB;
        return (int)$DB->insert_record('scormmakercmi5_stmt', (object)($overrides + [
            'scormmakercmi5id' => 1, 'pkgid' => 1, 'regid' => 1, 'auid' => 1, 'userid' => 2, 'sessionid' => 1,
            'statementid' => \core\uuid::generate(), 'source' => statement_store::SOURCE_AU, 'verb' => vocab::VERB_INTERACTED,
            'objecthash' => sha1('b1'), 'objectid' => 'https://scormmaker.com.br/xapi/curso/c1/bloco/b1',
            'cmi5kind' => statement_store::KIND_ALLOWED, 'timestampms' => 1, 'storedms' => 1, 'smprofile' => 1,
            'smprofilever' => 2, 'smversion' => 'v#1', 'smkind' => 'bloco', 'smblock' => 'b1', 'durationms' => $ms,
            'payload' => json_encode(['result' => ['duration' => $duration]]),
        ]));
    }

    /**
     * The duration column of a row.
     *
     * @param int $id Row id.
     * @return int|null
     */
    private function ms(int $id): ?int {
        global $DB;
        $ms = $DB->get_field('scormmakercmi5_stmt', 'durationms', ['id' => $id], MUST_EXIST);
        return $ms === null ? null : (int)$ms;
    }

    /**
     * The summaries get the time of the rule of the contract (null outside it, the decimal text half up inside it);
     * a lesson, a statement of another profile and an LMS row keep their column.
     */
    public function test_execute_recomputes_the_summaries_only(): void {
        $this->resetAfterTest();
        $day = $this->row('P1D', 86400000);
        $hour = $this->row('PT1H', 3600000);
        $long = $this->row('PT0.0004999999999999999999S', 1);
        $good = $this->row('PT3S', 3000);
        $selected = $this->row('PT1H', 3600000, ['verb' => vocab::VERB_SELECTED]);
        $lesson = $this->row('PT1H', 3600000, ['smkind' => 'licao', 'verb' => vocab::VERB_EXPERIENCED]);
        $profile1 = $this->row('PT1H', 3600000, ['smprofilever' => 1]);
        $lms = $this->row('PT1H', 3600000, ['cmi5kind' => 2]);

        (new recompute_summary_durations())->execute();
        $this->assertNull($this->ms($day));
        $this->assertNull($this->ms($hour));
        $this->assertSame(0, $this->ms($long));
        $this->assertSame(3000, $this->ms($good));
        $this->assertNull($this->ms($selected), 'a selected on a block is analysis of kind bloco too');
        $this->assertSame(3600000, $this->ms($lesson));
        $this->assertSame(3600000, $this->ms($profile1));
        $this->assertSame(3600000, $this->ms($lms));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(recompute_summary_durations::class), 'done: nothing queued');
    }

    /**
     * Batches: run() stops after its batches and gives the id to resume from; execute() queues the rest with the cursor.
     */
    public function test_resumable_in_batches(): void {
        $this->resetAfterTest();
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->row('P1D', 86400000);
        }
        $next = recompute_summary_durations::run(0, 2, 1);
        $this->assertSame($ids[1], $next);
        $this->assertNull($this->ms($ids[1]));
        $this->assertSame(86400000, $this->ms($ids[2]), 'not reached yet');

        $task = new recompute_summary_durations();
        $task->set_custom_data((object)['lastid' => $next, 'batch' => 2, 'batches' => 1]);
        $task->execute();
        $this->assertNull($this->ms($ids[3]));
        $this->assertSame(86400000, $this->ms($ids[4]));
        $queued = \core\task\manager::get_adhoc_tasks(recompute_summary_durations::class);
        $this->assertCount(1, $queued);
        $follow = reset($queued);
        $this->assertSame($ids[3], (int)$follow->get_custom_data()->lastid);
        $follow->execute();
        $this->assertNull($this->ms($ids[4]));
    }
}
