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

use mod_scormmakercmi5\local\package\importer;
use mod_scormmakercmi5\local\package\verbatim_storage;
use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;

/**
 * Gives back the signed bytes of the JPEG files that the EXIF remover of core rewrote at an import before 0.2.0.
 *
 * Before local\package\verbatim_storage, the redactor of core (Moodle 4.4+, file_redactor_exifremoverenabled) rewrote
 * every JPEG the import stored, so every recheck of a signed package with a JPEG reported "file changed". Queued by
 * the upgrade step 2026100200, this task takes, for each candidate (a verified package version with a JPEG in its
 * content area), each JPEG whose stored bytes do not have the signed SHA-256, and replaces them with the entry of the
 * stored zip — only when every one of these holds, so a file really changed after the import is never repaired:
 *
 * - the stored file still has the contenthash the import recorded in pkgfile (not replaced through the File API);
 * - its bytes still have that contenthash as SHA-1 (not changed in the filedir);
 * - the stored zip still has the contenthash recorded in pkg.zipcontenthash, and its entry has exactly the SHA-256
 *   and the size of pkgfile — the SHA-256 that the signed manifest lists (the recheck compares them again).
 *
 * The file row keeps its id and path (stored_file::replace_file_with()) and pkgfile.contenthash follows in the same
 * transaction. A version where a file was repaired is then rechecked at once with every byte hashed
 * (verifier::recheck()), which decides the status as for any package: the task writes no status itself.
 *
 * Idempotent and resumable like backfill_rich_structure (custom data {lastid}).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repair_redacted_images extends \core\task\adhoc_task {
    /** @var int Package versions read per batch. */
    private const BATCH = 20;

    /** @var int Batches per run of the task before it queues the rest. */
    private const BATCHES = 5;

    /** @var int Item id of the temporary files in the content area (pkg ids start at 1). */
    private const TEMP_ITEMID = 0;

    /** @var int Bytes copied per read. */
    private const CHUNK_BYTES = 262144;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrepairredactedimages', 'mod_scormmakercmi5');
    }

    /**
     * Runs the batches from the cursor of the custom data and queues the rest, if any.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $lastid = max(0, (int)($data->lastid ?? 0));
        $next = self::run($lastid, self::BATCH, self::BATCHES);
        if ($next !== null) {
            $task = new self();
            $task->set_custom_data((object)['lastid' => $next]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * The SQL of the candidates (the guard of the upgrade step repeats its FROM and WHERE literally).
     *
     * @return array [sql, params] selecting p.id, p.scormmakercmi5id, p.zipcontenthash after :lastid.
     */
    public static function candidates_sql(): array {
        $sql = "SELECT p.id, p.scormmakercmi5id, p.zipcontenthash
                  FROM {scormmakercmi5_pkg} p
                 WHERE p.sigstatus = :verified AND p.id > :lastid
                       AND EXISTS (SELECT 1 FROM {files} f
                                    WHERE f.component = :component AND f.filearea = :filearea
                                          AND f.itemid = p.id AND f.mimetype = :mimetype)
              ORDER BY p.id";
        return [$sql, ['verified' => verification_result::STATUS_VERIFIED, 'component' => importer::COMPONENT,
            'filearea' => importer::AREA_CONTENT, 'mimetype' => 'image/jpeg']];
    }

    /**
     * Repairs the package versions after $lastid, at most $batches batches of $batch versions.
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
                if (self::repair_package($row) > 0) {
                    verifier::recheck($row, keyring::from_config(), PHP_INT_MAX);
                }
            }
            if (count($rows) < $batch) {
                return null;
            }
        }
        return $lastid;
    }

    /**
     * Repairs the JPEG files of one package version.
     *
     * @param \stdClass $row Candidate (id, scormmakercmi5id, zipcontenthash).
     * @return int Files repaired.
     */
    private static function repair_package(\stdClass $row): int {
        global $DB;
        $cm = get_coursemodule_from_instance('scormmakercmi5', $row->scormmakercmi5id, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return 0;
        }
        $contextid = \context_module::instance($cm->id)->id;
        $fs = get_file_storage();
        $pkgfiles = [];
        $fields = 'id, path, sha256, contenthash, filesize';
        foreach ($DB->get_records('scormmakercmi5_pkgfile', ['pkgid' => $row->id], '', $fields) as $f) {
            $pkgfiles[(string)$f->path] = $f;
        }
        $broken = [];
        foreach ($fs->get_area_files($contextid, importer::COMPONENT, importer::AREA_CONTENT, $row->id, 'id', false) as $file) {
            $path = ltrim($file->get_filepath(), '/') . $file->get_filename();
            $pkgfile = $pkgfiles[$path] ?? null;
            if ($file->get_mimetype() !== 'image/jpeg' || !$pkgfile || $file->get_contenthash() !== (string)$pkgfile->contenthash) {
                continue;
            }
            $hashes = self::hash_stream(self::open_stored($file), PHP_INT_MAX);
            // Intact, unreadable, or changed in the filedir after the import: not repaired.
            if ($hashes === null || $hashes['sha256'] === (string)$pkgfile->sha256) {
                continue;
            }
            if ($hashes['sha1'] !== $file->get_contenthash()) {
                continue;
            }
            $broken[$path] = $file;
        }
        if (!$broken) {
            return 0;
        }

        $zips = $fs->get_area_files($contextid, importer::COMPONENT, importer::AREA_PACKAGE, $row->id, 'id', false);
        $zipfile = reset($zips);
        if (!$zipfile || $zipfile->get_contenthash() !== (string)$row->zipcontenthash) {
            return 0;
        }
        $dir = make_request_directory();
        $zippath = $dir . '/package.zip';
        $repaired = 0;
        try {
            if (!$zipfile->copy_content_to($zippath) || sha1_file($zippath) !== (string)$row->zipcontenthash) {
                return 0;
            }
            $zip = new \ZipArchive();
            if ($zip->open($zippath, \ZipArchive::RDONLY) !== true) {
                return 0;
            }
            try {
                foreach ($broken as $path => $file) {
                    $repaired += self::repair_file($zip, (string)$path, $file, $pkgfiles[$path], $dir, $contextid);
                }
            } finally {
                $zip->close();
            }
        } finally {
            fulldelete($dir);
            $fs->delete_area_files($contextid, importer::COMPONENT, importer::AREA_CONTENT, self::TEMP_ITEMID);
        }
        return $repaired;
    }

    /**
     * Replaces one stored file with its zip entry when the entry has the signed SHA-256 and size.
     *
     * @param \ZipArchive $zip Stored zip, open.
     * @param string $path Relative path.
     * @param \stored_file $file Stored file.
     * @param \stdClass $pkgfile pkgfile row.
     * @param string $dir Request directory.
     * @param int $contextid Module context id.
     * @return int 1 when repaired, 0 otherwise.
     */
    private static function repair_file(
        \ZipArchive $zip,
        string $path,
        \stored_file $file,
        \stdClass $pkgfile,
        string $dir,
        int $contextid
    ): int {
        global $DB;
        $stream = $zip->getStream($path);
        $entrypath = $dir . '/entry';
        $out = fopen($entrypath, 'wb');
        $hashes = $out ? self::hash_stream($stream ?: null, (int)$pkgfile->filesize, $out) : null;
        if ($out) {
            fclose($out);
        }
        if ($hashes === null || $hashes['sha256'] !== (string)$pkgfile->sha256 || $hashes['size'] !== (int)$pkgfile->filesize) {
            return 0;
        }
        // Under an item id no pkg has, stored byte for byte as a file of this verified version (verbatim_storage).
        $temp = verbatim_storage::for_temporary_files_of(
            (int)$file->get_itemid(),
            static fn() => get_file_storage()->create_file_from_pathname([
                'contextid' => $contextid,
                'component' => importer::COMPONENT,
                'filearea' => importer::AREA_CONTENT,
                'itemid' => self::TEMP_ITEMID,
                'filepath' => '/',
                'filename' => 'repair-' . sha1($path) . '.jpg',
            ], $entrypath)
        );
        if ($temp->get_contenthash() !== $hashes['sha1']) {
            $temp->delete();
            return 0;
        }
        $transaction = $DB->start_delegated_transaction();
        $file->replace_file_with($temp);
        $DB->set_field('scormmakercmi5_pkgfile', 'contenthash', $hashes['sha1'], ['id' => $pkgfile->id]);
        $transaction->allow_commit();
        $temp->delete();
        return 1;
    }

    /**
     * Opens the bytes of a stored file.
     *
     * @param \stored_file $file File.
     * @return resource|null
     */
    private static function open_stored(\stored_file $file) {
        try {
            return $file->get_content_file_handle() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * SHA-1, SHA-256 and size of a stream read to its end (at most $max + 1 bytes), optionally copied, then closed.
     *
     * @param resource|null $in Stream.
     * @param int $max Largest size accepted; one byte more stops the reading.
     * @param resource|null $out Stream the bytes are copied to.
     * @return array|null ['sha1' => hex, 'sha256' => hex, 'size' => int], or null when unreadable or too large.
     */
    private static function hash_stream($in, int $max, $out = null): ?array {
        if (!$in) {
            return null;
        }
        $sha1 = hash_init('sha1');
        $sha256 = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if ($chunk === false) {
                    return null;
                }
                $size += strlen($chunk);
                if ($size > $max || ($out && fwrite($out, $chunk) !== strlen($chunk))) {
                    return null;
                }
                hash_update($sha1, $chunk);
                hash_update($sha256, $chunk);
            }
        } finally {
            fclose($in);
        }
        return ['sha1' => hash_final($sha1), 'sha256' => hash_final($sha256), 'size' => $size];
    }
}
