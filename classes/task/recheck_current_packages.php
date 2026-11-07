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

use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;

/**
 * Daily recheck of the current package of every activity, cheap steps only.
 *
 * The rich report checks a package read-only when it opens (verifier::evaluate()); recording the verdict (which the
 * "Rich report" link on the basic report reads) is done here, with verifier::recheck(), so that opening a report
 * writes nothing. This task never hashes bytes: it records the signature and file-map steps (database only) and
 * leaves the SHA-256 of the stored bytes to the ad hoc task recheck_package, which recheck() queues (deduplicated)
 * for a package whose last byte check is older than recheckminhours. Each run takes at most MAX_PER_RUN packages,
 * the ones rechecked longest ago first, so a large site is covered over several days and no run does unbounded work.
 * Unsigned packages have nothing to recheck. A package that cannot be checked (its activity is being deleted) is
 * reported and skipped, with its timerecheck moved to now so it goes to the back of the queue; the others still run.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recheck_current_packages extends \core\task\scheduled_task {
    /** @var int Packages recorded (and at most queued for a byte check) per run. */
    public const MAX_PER_RUN = 200;

    /** @var int Size limit that no package fits under: the byte check is left to recheck_package. */
    private const NO_SYNC_BYTES = -1;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrecheckcurrentpackages', 'mod_scormmakercmi5');
    }

    /**
     * Rechecks and records the current package of the activities that have a signed one, oldest recheck first.
     */
    public function execute(): void {
        global $DB;
        $keyring = keyring::from_config();
        $done = 0;
        foreach ($this->pending_package_ids(self::MAX_PER_RUN) as $id) {
            try {
                $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $id], '*', MUST_EXIST);
                verifier::recheck($pkg, $keyring, self::NO_SYNC_BYTES);
                $done++;
            } catch (\Throwable $e) {
                mtrace("Could not recheck package {$id}: " . $e->getMessage());
                // Sent to the back of the queue, so a package that keeps failing does not hold a slot of every run.
                $now = \core\di::get(\core\clock::class)->time();
                $DB->set_field('scormmakercmi5_pkg', 'timerecheck', $now, ['id' => $id]);
            }
        }
        mtrace("Rechecked {$done} SCORM Maker packages.");
    }

    /**
     * The signed current packages, the one rechecked longest ago first.
     *
     * @param int $limit Most packages to return.
     * @return int[] Package ids.
     */
    protected function pending_package_ids(int $limit): array {
        global $DB;
        $rows = $DB->get_recordset_sql(
            "SELECT p.id
               FROM {scormmakercmi5_pkg} p
               JOIN {scormmakercmi5} s ON s.currentpkgid = p.id
              WHERE p.sigstatus <> :unsigned
           ORDER BY p.timerecheck ASC, p.id ASC",
            ['unsigned' => verification_result::STATUS_UNSIGNED],
            0,
            $limit
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row->id;
        }
        $rows->close();
        return $ids;
    }
}
