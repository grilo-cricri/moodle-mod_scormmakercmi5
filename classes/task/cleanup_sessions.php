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

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\lrs\xapi_time;
use mod_scormmakercmi5\local\runtime\launcher;

/**
 * Hourly cleanup of sessions (design §10.3).
 *
 * Sessions launched or initialized with no activity for longer than sessiontimeout are abandoned (cmi5 §9.3.6,
 * "system failure"), and the key and token hashes of sessions that ended more than a day ago are cleared.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_sessions extends \core\task\scheduled_task {
    /** @var int Sessions read per query. */
    private const BATCH = 500;

    /** @var int Seconds to wait for a registration lock; a busy registration is left for the next run. */
    private const LOCK_TIMEOUT = 1;

    /** @var int Milliseconds after its end before a session loses its hashes. */
    private const KEEP_HASHES_MS = DAYSECS * 1000;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcleanupsessions', 'mod_scormmakercmi5');
    }

    /**
     * Runs the cleanup.
     */
    public function execute(): void {
        $nowms = xapi_time::now_ms();
        $abandoned = $this->abandon_idle($nowms, $nowms - limits::session_timeout_seconds() * 1000);
        mtrace("Abandoned {$abandoned} idle cmi5 sessions.");
        $this->clear_hashes($nowms - self::KEEP_HASHES_MS);
    }

    /**
     * Abandons open sessions idle since before the cutoff, each under its registration lock.
     *
     * @param int $nowms Now.
     * @param int $cutoffms Last activity before this is idle.
     * @return int Sessions abandoned.
     */
    private function abandon_idle(int $nowms, int $cutoffms): int {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(
            [launcher::STATUS_LAUNCHED, launcher::STATUS_INITIALIZED],
            SQL_PARAMS_NAMED
        );
        $select = "status {$insql} AND lastactivityms < :cutoff AND id > :lastid";
        $params['cutoff'] = $cutoffms;
        $lastid = 0;
        $abandoned = 0;
        do {
            $params['lastid'] = $lastid;
            $rs = $DB->get_recordset_select('scormmakercmi5_session', $select, $params, 'id', 'id, regid', 0, self::BATCH);
            $read = 0;
            foreach ($rs as $row) {
                $read++;
                $lastid = (int)$row->id;
                // One broken session (an orphaned row, a database error) must not stop the run; it is reported by
                // id and exception class only (never a hash, never the message: it can carry SQL or data of the row)
                // and retried next hour, while the rest of the run goes on.
                try {
                    $abandoned += (int)$this->abandon_one($row, $nowms, $cutoffms);
                } catch (\Throwable $e) {
                    mtrace('Could not abandon cmi5 session ' . (int)$row->id . ': ' . get_class($e));
                }
            }
            $rs->close();
        } while ($read === self::BATCH);
        return $abandoned;
    }

    /**
     * Abandons one session if, read again under the lock, it is still open and idle.
     *
     * @param \stdClass $row id and regid.
     * @param int $nowms Now.
     * @param int $cutoffms Idle cutoff.
     * @return bool Whether it was abandoned.
     */
    private function abandon_one(\stdClass $row, int $nowms, int $cutoffms): bool {
        global $DB;
        $lock = launcher::registration_lock((int)$row->regid, self::LOCK_TIMEOUT);
        if (!$lock) {
            return false;
        }
        try {
            $session = $DB->get_record('scormmakercmi5_session', ['id' => $row->id]);
            $open = $session && in_array((int)$session->status, [launcher::STATUS_LAUNCHED, launcher::STATUS_INITIALIZED], true);
            if (!$open || (int)$session->lastactivityms >= $cutoffms) {
                return false;
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                launcher::abandon_session($session, $nowms);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Clears fetchkeyhash and tokenhash of sessions that ended before a time.
     *
     * @param int $endedbeforems End time limit.
     */
    private function clear_hashes(int $endedbeforems): void {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(
            [launcher::STATUS_TERMINATED, launcher::STATUS_ABANDONED],
            SQL_PARAMS_NAMED
        );
        $select = "status {$insql} AND timeendedms > 0 AND timeendedms < :endedbefore"
            . " AND (fetchkeyhash IS NOT NULL OR tokenhash IS NOT NULL)";
        $params['endedbefore'] = $endedbeforems;
        $DB->set_field_select('scormmakercmi5_session', 'fetchkeyhash', null, $select, $params);
        $DB->set_field_select('scormmakercmi5_session', 'tokenhash', null, $select, $params);
    }
}
