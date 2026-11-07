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
use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5\local\runtime\launcher;

/**
 * Fills the SCORM Maker profile columns of the statements stored before 0.2.0 (subproject D, design §3.9).
 *
 * A SCORM Maker package exported with the activity analysis may have been used while the site still ran 0.1.0: its
 * block summaries and decisions were stored with their profile 2 data only inside the payload. Queued by the upgrade
 * step 2026100102, this task re-extracts every column of statement_store::sm_columns() from the payload of the
 * candidates: AU statements in the profile (smprofile = 1) with smprofilever NULL and smkind NULL — the regex of 0.1.0
 * never matched …/bloco/, so a block row has no kind, while a lesson or question of C has one and is never read (a
 * profile 2 lesson keeping smprofilever NULL is harmless: the report of D reads only the kinds bloco and item).
 *
 * A row that becomes a block summary of the analysis (kind bloco, interacted or selected, cmi5 allowed) also gets
 * its time column from the rule of the contract (analysis_bounds::duration_ms(): result.duration only PT<n>S up to
 * 86400 s, null outside it), as a fresh ingestion and the hosted course give it: 0.1.0 stored the column of
 * iso_duration, where a forged P1D counted one day.
 *
 * The registrations of those rows had the analysis counted in statementcount (0.1.0 counted every row); once a row is
 * analysis, statementcount is recomputed as the rows of the registration that are not analysis
 * (statement_store::analysis_where()), under the registration lock and in the same transaction as the rows.
 *
 * Idempotent (the columns are a pure function of the payload; a row is written only when they differ; the counter is
 * recomputed, never decremented) and resumable: after its batches it queues itself again with the last id read as the
 * cursor (custom data {lastid}). A registration whose lock is busy fails the task before any write of it, so the
 * retry reads its rows again. An unreadable payload is skipped. Rows are streamed (recordset): only the new columns of
 * a batch are kept in memory, never its payloads.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_profile_columns extends \core\task\adhoc_task {
    /** @var int Rows read per batch. */
    private const BATCH = 1000;

    /** @var int Batches per run of the task before it queues the rest. */
    private const BATCHES = 50;

    /** @var int Seconds to wait for the lock of a registration. */
    private const LOCK_TIMEOUT = 10;

    /** @var string[] The columns written. */
    private const COLUMNS = ['smprofile', 'smversion', 'smkind', 'smitem', 'smblock', 'smblocktype', 'smprofilever'];

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskbackfillprofilecolumns', 'mod_scormmakercmi5');
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
     * The SQL condition of the candidates (also the guard of the upgrade step, which repeats it literally).
     *
     * @return string
     */
    public static function candidates_where(): string {
        return 'source = :source AND smprofile = 1 AND smprofilever IS NULL AND smkind IS NULL';
    }

    /**
     * Fills the rows after $lastid, at most $batches batches of $batch rows.
     *
     * @param int $lastid Cursor: rows with a greater id.
     * @param int $batch Rows per batch.
     * @param int $batches Batches.
     * @param int $locktimeout Seconds to wait for the lock of a registration.
     * @return int|null The id to resume from, or null when no row is left.
     * @throws \moodle_exception When the lock of a registration is busy (the task is retried).
     */
    public static function run(int $lastid, int $batch, int $batches, int $locktimeout = self::LOCK_TIMEOUT): ?int {
        global $DB;
        $select = 'id > :lastid AND ' . self::candidates_where();
        $fields = 'id, regid, payload, cmi5kind, verb, durationms, ' . implode(', ', self::COLUMNS);
        for ($b = 0; $b < $batches; $b++) {
            $params = ['lastid' => $lastid, 'source' => statement_store::SOURCE_AU];
            $rs = $DB->get_recordset_select('scormmakercmi5_stmt', $select, $params, 'id', $fields, 0, $batch);
            $read = 0;
            $updates = [];
            foreach ($rs as $row) {
                $read++;
                $lastid = (int)$row->id;
                $statement = json_decode($row->payload, true);
                if (!is_array($statement)) {
                    continue;
                }
                $columns = statement_store::sm_columns($statement);
                $update = [];
                foreach (self::COLUMNS as $name) {
                    $old = $row->{$name} === null ? null : (string)$row->{$name};
                    $new = $columns->{$name} === null ? null : (string)$columns->{$name};
                    if ($old !== $new) {
                        $update[$name] = $columns->{$name};
                    }
                }
                if (
                    $columns->smkind === 'bloco' && $columns->smprofile === 1 && $columns->smprofilever === 2
                        && (int)$row->cmi5kind === statement_store::KIND_ALLOWED
                        && in_array($row->verb, [vocab::VERB_INTERACTED, vocab::VERB_SELECTED], true)
                ) {
                    $ms = analysis_bounds::duration_ms($statement['result']['duration'] ?? null);
                    if ($ms !== ($row->durationms === null ? null : (int)$row->durationms)) {
                        $update['durationms'] = $ms;
                    }
                }
                if ($update) {
                    $updates[(int)$row->regid][] = (object)(['id' => $row->id] + $update);
                }
            }
            $rs->close();
            foreach ($updates as $regid => $rows) {
                self::write_registration($regid, $rows, $locktimeout);
            }
            if ($read < $batch) {
                return null;
            }
        }
        return $lastid;
    }

    /**
     * Writes the new columns of rows of one registration and recomputes its statementcount, under its lock.
     *
     * @param int $regid Registration id.
     * @param \stdClass[] $rows Row updates (id + changed columns).
     * @param int $locktimeout Seconds to wait for the lock.
     * @throws \moodle_exception When the lock is busy.
     */
    private static function write_registration(int $regid, array $rows, int $locktimeout): void {
        global $DB;
        $lock = launcher::registration_lock($regid, $locktimeout);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            foreach ($rows as $row) {
                $DB->update_record('scormmakercmi5_stmt', $row);
            }
            if ($DB->record_exists('scormmakercmi5_reg', ['id' => $regid])) {
                [$where, $params] = statement_store::analysis_where();
                $all = $DB->count_records('scormmakercmi5_stmt', ['regid' => $regid]);
                $analysis = $DB->count_records_select(
                    'scormmakercmi5_stmt',
                    "regid = :regid AND {$where}",
                    $params + ['regid' => $regid]
                );
                $DB->set_field('scormmakercmi5_reg', 'statementcount', $all - $analysis, ['id' => $regid]);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }
}
