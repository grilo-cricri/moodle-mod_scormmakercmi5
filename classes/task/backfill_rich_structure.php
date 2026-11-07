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

use mod_scormmakercmi5\local\package\analysis_structure;
use mod_scormmakercmi5\local\package\importer;

/**
 * Stores the analysis structure of the package versions imported before 0.2.0 (subproject D, design §3.2).
 *
 * 0.1.0 imported analise/estrutura.json with the other files of a SCORM Maker package but did not read it. Queued by
 * the upgrade step 2026100103, this task reads it from the content area of the File API (never extracting the zip
 * again) for the candidates — pkg rows with richstructure NULL whose file list has the file — and validates it as the
 * import does (analysis_structure::from_json(), with the paths of pkgfile as the files of the package). The stored
 * file must still have the size and SHA-256 recorded at import; otherwise it is skipped (the recheck reports it).
 *
 * Idempotent (a value is written only while richstructure is still NULL; an absent, changed or invalid file leaves
 * NULL, as the import would) and resumable: after its batches it queues itself again with the last pkg id read as the
 * cursor (custom data {lastid}). One file (at most analysis_structure::MAX_BYTES) is in memory at a time.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_rich_structure extends \core\task\adhoc_task {
    /** @var int Package versions read per batch. */
    private const BATCH = 100;

    /** @var int Batches per run of the task before it queues the rest. */
    private const BATCHES = 10;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskbackfillrichstructure', 'mod_scormmakercmi5');
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
     * The SQL of the candidates (the guard of the upgrade step repeats its FROM and WHERE literally).
     *
     * @return array [sql, params] selecting p.id, p.scormmakercmi5id, f.sha256, f.filesize after :lastid.
     */
    public static function candidates_sql(): array {
        $sql = 'SELECT p.id, p.scormmakercmi5id, f.sha256, f.filesize
                  FROM {scormmakercmi5_pkg} p
                  JOIN {scormmakercmi5_pkgfile} f ON f.pkgid = p.id AND f.pathhash = :pathhash
                 WHERE p.richstructure IS NULL AND p.id > :lastid
              ORDER BY p.id';
        return [$sql, ['pathhash' => sha1(analysis_structure::PATH)]];
    }

    /**
     * Fills the package versions after $lastid, at most $batches batches of $batch versions.
     *
     * @param int $lastid Cursor: pkg rows with a greater id.
     * @param int $batch Versions per batch.
     * @param int $batches Batches.
     * @return int|null The id to resume from, or null when no candidate is left.
     */
    public static function run(int $lastid, int $batch, int $batches): ?int {
        global $DB;
        [$sql, $params] = self::candidates_sql();
        for ($b = 0; $b < $batches; $b++) {
            $rows = $DB->get_records_sql($sql, $params + ['lastid' => $lastid], 0, $batch);
            foreach ($rows as $row) {
                $lastid = (int)$row->id;
                $json = self::structure_of($row);
                if ($json !== null) {
                    $DB->set_field_select(
                        'scormmakercmi5_pkg',
                        'richstructure',
                        $json,
                        'id = :id AND richstructure IS NULL',
                        ['id' => $row->id]
                    );
                }
            }
            if (count($rows) < $batch) {
                return null;
            }
        }
        return $lastid;
    }

    /**
     * The validated structure of one stored package version, or null.
     *
     * @param \stdClass $row Candidate (id, scormmakercmi5id, sha256 and filesize of the structure file).
     * @return string|null
     */
    private static function structure_of(\stdClass $row): ?string {
        global $DB;
        if ((int)$row->filesize > analysis_structure::MAX_BYTES) {
            return null;
        }
        $cm = get_coursemodule_from_instance('scormmakercmi5', $row->scormmakercmi5id, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }
        $file = get_file_storage()->get_file(
            \context_module::instance($cm->id)->id,
            importer::COMPONENT,
            importer::AREA_CONTENT,
            $row->id,
            '/' . dirname(analysis_structure::PATH) . '/',
            basename(analysis_structure::PATH)
        );
        if (!$file || (int)$file->get_filesize() !== (int)$row->filesize) {
            return null;
        }
        $json = $file->get_content();
        if (hash('sha256', $json) !== $row->sha256) {
            return null;
        }
        $paths = $DB->get_fieldset_select(
            'scormmakercmi5_pkgfile',
            'path',
            'pkgid = :pkgid AND ' . $DB->sql_like('path', ':media'),
            ['pkgid' => $row->id, 'media' => 'media/%']
        );
        return analysis_structure::encode(analysis_structure::from_json($json, array_map('strval', $paths)));
    }
}
