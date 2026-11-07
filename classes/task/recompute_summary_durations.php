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

use mod_scormmakercmi5\local\analysis_bounds;
use mod_scormmakercmi5\local\lrs\statement_store;

/**
 * Recomputes the time column of the block summaries of the activity analysis already stored (final wave W2a, M-2).
 *
 * The time of a block summary follows the rule of the contract shared with the hosted course: result.duration only
 * PT<n>S or PT<n>.<d>S up to 86400 s, milliseconds from the decimal text (analysis_bounds::duration_ms()), null
 * outside it. Rows stored before 2026100202 had the column of iso_duration::to_ms, so a forged P1D of one learner
 * counted one day in the average time of the class. Queued by the upgrade step 2026100202 (which runs no plugin code);
 * the rows stored by 0.1.0 get the same column from backfill_profile_columns once they are classified.
 *
 * Reads only the analysis rows of kind bloco (statement_store::analysis_where()), by id, in batches; writes a row only
 * when its column changes. Idempotent and resumable: after its batches it queues itself again with the last id read
 * as the cursor (custom data {lastid}).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recompute_summary_durations extends \core\task\adhoc_task {
    /** @var int Rows read per batch. */
    private const BATCH = 1000;

    /** @var int Batches per run of the task before it queues the rest. */
    private const BATCHES = 50;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrecomputesummarydurations', 'mod_scormmakercmi5');
    }

    /**
     * Runs the batches from the cursor of the custom data and queues the rest, if any.
     *
     * Custom data: lastid (cursor, 0 by default); batch and batches (sizes, for tests; capped by the defaults).
     */
    public function execute() {
        $data = $this->get_custom_data();
        $lastid = max(0, (int)($data->lastid ?? 0));
        $batch = min(self::BATCH, max(1, (int)($data->batch ?? self::BATCH)));
        $batches = min(self::BATCHES, max(1, (int)($data->batches ?? self::BATCHES)));
        $next = self::run($lastid, $batch, $batches);
        if ($next !== null) {
            $task = new self();
            $task->set_custom_data((object)['lastid' => $next, 'batch' => $batch, 'batches' => $batches]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Recomputes the rows after $lastid, at most $batches batches of $batch rows.
     *
     * @param int $lastid Cursor: rows with a greater id.
     * @param int $batch Rows per batch.
     * @param int $batches Batches.
     * @return int|null The id to resume from, or null when no row is left.
     */
    public static function run(int $lastid, int $batch, int $batches): ?int {
        global $DB;
        [$where, $params] = statement_store::analysis_where();
        $select = "id > :lastid AND {$where} AND smkind = :onlybloco";
        for ($b = 0; $b < $batches; $b++) {
            $rs = $DB->get_recordset_select('scormmakercmi5_stmt', $select, $params + ['lastid' => $lastid,
                'onlybloco' => 'bloco'], 'id', 'id, durationms, payload', 0, $batch);
            $read = 0;
            $changes = [];
            foreach ($rs as $row) {
                $read++;
                $lastid = (int)$row->id;
                $payload = json_decode((string)$row->payload, false);
                $result = is_object($payload) && isset($payload->result) && is_object($payload->result) ? $payload->result : null;
                $ms = analysis_bounds::duration_ms($result->duration ?? null);
                if ($ms !== ($row->durationms === null ? null : (int)$row->durationms)) {
                    $changes[(int)$row->id] = $ms;
                }
            }
            $rs->close();
            foreach ($changes as $id => $ms) {
                $DB->set_field('scormmakercmi5_stmt', 'durationms', $ms, ['id' => $id]);
            }
            if ($read < $batch) {
                return null;
            }
        }
        return $lastid;
    }
}
