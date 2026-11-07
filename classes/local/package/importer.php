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

namespace mod_scormmakercmi5\local\package;

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;

/**
 * Imports a cmi5 package, all or nothing (design §4.1, §3.2–§3.4, §3.13).
 *
 * inspect_file() runs steps 1–5 (size, inspection without extracting, extraction, cmi5.xml, hashes and signature)
 * and creates nothing in the database; store() runs step 6 in one delegated transaction. Any failure leaves no
 * row, no stored file and no temporary directory behind.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var string Component of the file areas. */
    public const COMPONENT = 'mod_scormmakercmi5';

    /** @var string File area of the original zip (itemid = pkg id). */
    public const AREA_PACKAGE = 'package';

    /** @var string File area of the extracted files (itemid = pkg id). */
    public const AREA_CONTENT = 'content';

    /** @var int Width of the columns that take ids and activityType from cmi5.xml (§3.13). */
    public const MAX_ID_CHARS = 1333;

    /** @var int Width of files.filepath and files.filename in the File API. */
    private const MAX_FILE_API_CHARS = 255;

    /** @var int Bytes copied per read while extracting. */
    private const CHUNK_BYTES = 262144;

    /** @var int Seconds store() waits for the import lock of the instance. Protected so a test can make it 0. */
    protected static int $locktimeout = 30;

    /**
     * The package file the current user put in a draft area (the first one; the form allows only one).
     *
     * @param int $draftitemid Draft item id of the file manager.
     * @return \stored_file|null Null when the draft area has no file.
     */
    public static function draft_file(int $draftitemid): ?\stored_file {
        global $USER;
        if ($draftitemid <= 0 || empty($USER->id)) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'sortorder, id',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Copies the package the current user put in a draft area to a request directory.
     *
     * @param int $draftitemid Draft item id of the file manager.
     * @return array ['path' => zip path, 'filename' => original file name]. The zip is alone in its directory.
     * @throws \moodle_exception err_nopackage.
     */
    public static function draft_to_temp(int $draftitemid): array {
        $file = self::draft_file($draftitemid);
        if (!$file) {
            throw self::error('err_nopackage');
        }
        $dir = make_request_directory();
        $path = $dir . '/package.zip';
        if (!$file->copy_content_to($path)) {
            fulldelete($dir);
            throw self::error('err_nopackage');
        }
        return ['path' => $path, 'filename' => $file->get_filename()];
    }

    /**
     * Steps 1–5 of §4.1: checks, extracts, validates, hashes and verifies the package. Creates nothing.
     *
     * @param string $zippath Path of the zip.
     * @param int $maxbytes Largest accepted zip, in bytes; 0 or less means no limit.
     * @param string $lang Moodle language of the course/user, passed to the parser.
     * @return inspected_package
     * @throws \moodle_exception err_toolarge, err_nocmi5xml, err_pathtoolong, err_zipextract, err_packageunreadable,
     *         err_idtoolong, err_externalaudisabled, and the errors of zip_inspector and cmi5_parser.
     */
    public function inspect_file(string $zippath, int $maxbytes, string $lang = ''): inspected_package {
        // Step 1: size, before the zip is opened.
        $size = is_file($zippath) ? filesize($zippath) : false;
        if ($size === false) {
            throw self::error('err_notzip');
        }
        if ($maxbytes > 0 && $size > $maxbytes) {
            throw self::error('err_toolarge', display_size($maxbytes));
        }

        // Step 2: inspection without extracting (declared names and sizes).
        $listing = zip_inspector::inspect($zippath, limits::max_entries(), limits::max_unzipped_bytes());
        self::check_paths($listing->files);
        if (!in_array('cmi5.xml', $listing->files, true)) {
            throw self::error('err_nocmi5xml');
        }

        // Step 3: extraction entry by entry into a request directory, removed on any failure.
        $dir = make_request_directory();
        try {
            [$sizes, $sha1s] = $this->extract($zippath, $dir, $listing);

            // Step 5 (hashes) first: the file set is checked against the listing before anything is read.
            $hashes = verifier::hash_files($dir);
            if ($hashes === null) {
                throw self::error('err_packageunreadable');
            }
            ksort($hashes, SORT_STRING);
            if (array_keys($hashes) !== array_keys($sizes)) {
                $odd = array_merge(array_diff_key($hashes, $sizes), array_diff_key($sizes, $hashes));
                throw self::error('err_zipextract', display_text::of((string)array_key_first($odd)));
            }

            // Step 4: cmi5.xml at the root. Its size is checked before it is read into memory.
            if ($sizes['cmi5.xml'] > cmi5_parser::MAX_BYTES) {
                throw self::error('err_xmlsize', (int)(cmi5_parser::MAX_BYTES / 1048576));
            }
            $xml = $this->read_cmi5xml($dir . '/cmi5.xml');
            if ($xml === false) {
                throw self::error('err_packageunreadable');
            }
            $structure = cmi5_parser::parse($xml, $listing->files, $lang);
            self::check_widths($structure);
            if (self::has_external_au($structure) && !self::external_au_allowed()) {
                throw self::error('err_externalaudisabled');
            }

            // Step 5: signature. A signature problem never blocks the import (R2.3). A package with an AU outside it is
            // unsigned whatever the zip holds: no signature of the zip covers that content (decision of the list after D).
            $verification = self::has_external_au($structure)
                ? new verification_result(verification_result::STATUS_UNSIGNED, verification_result::REASON_EXTERNAL_AU)
                : verifier::verify_at_import($dir, $hashes, keyring::from_config());

            return new inspected_package($structure, $verification, $dir, $hashes, $sizes, array_sum($sizes), $xml, $sha1s);
        } catch (\Throwable $e) {
            fulldelete($dir);
            throw $e;
        }
    }

    /**
     * Step 6 of §4.1: writes pkg, block, au and pkgfile and stores the files, in one transaction.
     *
     * On failure the transaction is rolled back and the file areas of the new pkg id are deleted (the File API is
     * not transactional). The extracted directory of $pkg is left to the caller.
     *
     * Imports into one instance are serialised by the lock 'import<instance id>' (Lock API): versionnum is read and
     * written under it. When the lock does not come within $locktimeout seconds, or the unique index
     * (scormmakercmi5id, versionnum) still refuses the row (an outer transaction of the caller keeps the other
     * import's row uncommitted after its lock is gone), the import fails with err_importbusy and creates nothing
     * (review M5 of Task 6).
     *
     * @param inspected_package $pkg Result of inspect_file().
     * @param \stdClass $instance scormmakercmi5 row (id is used).
     * @param \context_module $context Module context of the instance.
     * @param string $zippath Path of the original zip.
     * @param string $filename Original file name of the zip.
     * @return \stdClass The new scormmakercmi5_pkg row.
     * @throws \moodle_exception err_importbusy.
     */
    public function store(
        inspected_package $pkg,
        \stdClass $instance,
        \context_module $context,
        string $zippath,
        string $filename
    ): \stdClass {
        $factory = \core\lock\lock_config::get_lock_factory(self::COMPONENT);
        $lock = $factory->get_lock('import' . $instance->id, static::$locktimeout);
        if (!$lock) {
            throw self::error('err_importbusy');
        }
        try {
            return $this->store_locked($pkg, $instance, $context, $zippath, $filename);
        } finally {
            $lock->release();
        }
    }

    /**
     * The work of store(), under the import lock of the instance.
     *
     * @param inspected_package $pkg Result of inspect_file().
     * @param \stdClass $instance scormmakercmi5 row (id is used).
     * @param \context_module $context Module context of the instance.
     * @param string $zippath Path of the original zip.
     * @param string $filename Original file name of the zip.
     * @return \stdClass The new scormmakercmi5_pkg row.
     */
    private function store_locked(
        inspected_package $pkg,
        \stdClass $instance,
        \context_module $context,
        string $zippath,
        string $filename
    ): \stdClass {
        global $DB;
        $zipsha1 = sha1_file($zippath);
        $newcontents = self::new_contents(array_merge([$zipsha1], array_values($pkg->contenthashes)));
        $transaction = $DB->start_delegated_transaction();
        $pkgid = 0;
        $versionnum = 0;
        try {
            $previous = $DB->get_records(
                'scormmakercmi5_pkg',
                ['scormmakercmi5id' => $instance->id],
                'versionnum DESC',
                'id, versionnum'
            );
            $versionnum = $this->next_versionnum($previous);
            $inherited = self::previous_activity_ids(array_keys($previous));

            $filename = self::stored_filename($filename);
            $record = $this->pkg_record($pkg, $instance, $versionnum, $filename, $zipsha1);
            $pkgid = (int)$DB->insert_record('scormmakercmi5_pkg', $record);

            $contenthashes = $this->store_files($pkg, $context, $pkgid, $zippath, $filename);

            $order = 0;
            $aus = [];
            $this->store_items($pkg->structure->children, 0, $pkgid, $order, $aus, $inherited);
            $DB->insert_records('scormmakercmi5_au', $aus);

            $rows = [];
            foreach ($pkg->filehashes as $path => $sha256) {
                $path = (string)$path;
                $rows[] = [
                    'pkgid' => $pkgid,
                    'pathhash' => sha1($path),
                    'path' => $path,
                    'sha256' => $sha256,
                    'contenthash' => $contenthashes[$path],
                    'filesize' => $pkg->filesizes[$path],
                ];
            }
            $DB->insert_records('scormmakercmi5_pkgfile', $rows);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The pkg row itself was refused while another import holds its version number: a concurrent import,
            // not a broken package. The driver keeps a savepoint, so the transaction can still be read.
            if (!$pkgid && $versionnum && $e instanceof \dml_write_exception && $this->version_taken($instance, $versionnum)) {
                $e = self::error('err_importbusy');
            }
            if ($pkgid) {
                // Before the rollback, so the file rows go and their contents move to the trash. If this cleanup
                // fails (lost connection), cleanup_after_rollback() removes the contents this import added.
                try {
                    $this->delete_files($context, $pkgid);
                } catch (\Throwable $ignored) {
                    debugging('Cleanup of a failed package import did not complete', DEBUG_DEVELOPER);
                }
            }
            try {
                $transaction->rollback($e);
            } finally {
                $this->cleanup_after_rollback($context, $pkgid, $newcontents);
            }
        }
        return $DB->get_record('scormmakercmi5_pkg', ['id' => $pkgid], '*', MUST_EXIST);
    }

    /**
     * The version number of the next pkg row: the last one + 1. Protected so a test can simulate a stale read.
     *
     * @param array $previous Pkg rows of the instance (id, versionnum), newest first.
     * @return int
     */
    protected function next_versionnum(array $previous): int {
        return $previous ? (int)reset($previous)->versionnum + 1 : 1;
    }

    /**
     * Whether a pkg row of the instance already has the version number.
     *
     * @param \stdClass $instance scormmakercmi5 row.
     * @param int $versionnum Version number.
     * @return bool
     */
    private function version_taken(\stdClass $instance, int $versionnum): bool {
        global $DB;
        try {
            return $DB->record_exists('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id, 'versionnum' => $versionnum]);
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    /**
     * Imports the package of a draft area: draft_to_temp(), inspect_file() with the upload limit of the course, and
     * store(). Temporary files are removed whatever happens.
     *
     * @param int $draftitemid Draft item id of the file manager.
     * @param \stdClass $instance scormmakercmi5 row.
     * @param \context_module $context Module context.
     * @param \stdClass $course Course row (maxbytes is used).
     * @param course_structure|null $structure Receives the parsed cmi5.xml of the imported package.
     * @param bool $replacement Whether it replaces the package of an existing activity (check_replacement()).
     * @return \stdClass The new scormmakercmi5_pkg row.
     * @throws \moodle_exception err_externalaucapability, and those of inspect_file() and store().
     */
    public function import_draft(
        int $draftitemid,
        \stdClass $instance,
        \context_module $context,
        \stdClass $course,
        ?course_structure &$structure = null,
        bool $replacement = false
    ): \stdClass {
        global $CFG;
        \core_php_time_limit::raise();
        $temp = self::draft_to_temp($draftitemid);
        $pkg = null;
        try {
            $maxbytes = get_max_upload_file_size($CFG->maxbytes, $course->maxbytes ?? 0);
            $pkg = $this->inspect_file($temp['path'], (int)$maxbytes, current_language());
            $structure = $pkg->structure;
            if ($replacement) {
                self::check_replacement($pkg->structure, \context_course::instance((int)$course->id));
            }
            return $this->store($pkg, $instance, $context, $temp['path'], $temp['filename']);
        } finally {
            if ($pkg) {
                $pkg->discard();
            }
            fulldelete(dirname($temp['path']));
        }
    }

    /**
     * Stores the zip in the package area and every extracted file in the content area, itemid = pkg id.
     *
     * @param inspected_package $pkg Inspected package.
     * @param \context_module $context Module context.
     * @param int $pkgid New pkg id.
     * @param string $zippath Path of the original zip.
     * @param string $filename File name for the zip.
     * @return array Relative path => contenthash of the stored file.
     */
    protected function store_files(
        inspected_package $pkg,
        \context_module $context,
        int $pkgid,
        string $zippath,
        string $filename
    ): array {
        $fs = get_file_storage();
        $base = ['contextid' => $context->id, 'component' => self::COMPONENT, 'itemid' => $pkgid];
        $fs->create_file_from_pathname($base + [
            'filearea' => self::AREA_PACKAGE,
            'filepath' => '/',
            'filename' => $filename,
        ], $zippath);
        $verified = $pkg->verification->status === verification_result::STATUS_VERIFIED;
        $contenthashes = [];
        // The pkgfile rows are inserted after the files: verbatim_storage compares each file with the hashes verified
        // above, the same check the restore gets from the rows.
        verbatim_storage::expecting($pkgid, $pkg->filehashes, function () use ($pkg, $fs, $base, $verified, &$contenthashes) {
            foreach (array_keys($pkg->filehashes) as $path) {
                $path = (string)$path;
                [$filepath, $name] = self::split_path($path);
                $file = $fs->create_file_from_pathname($base + [
                    'filearea' => self::AREA_CONTENT,
                    'filepath' => $filepath,
                    'filename' => $name,
                ], $pkg->extractdir . '/' . $path);
                $contenthashes[$path] = $file->get_contenthash();
                // The class verbatim_storage keeps the bytes of a verified package (an unsigned or invalid one gets the
                // file processing of the site).
                // A site that reordered or disabled its listeners (hooks_callback_overrides) gets a stored file that the
                // recheck honestly reports as changed.
                if ($verified && isset($pkg->contenthashes[$path]) && $pkg->contenthashes[$path] !== $contenthashes[$path]) {
                    debugging('mod_scormmakercmi5: a listener of before_file_created rewrote the package file ' . s($path));
                }
            }
        });
        return $contenthashes;
    }

    /**
     * Extracts the zip entry by entry through the File API zip class, with REAL caps while writing.
     *
     * zip_inspector only saw the sizes the zip declares, and zip_packer::extract_to_pathname() copies each entry to
     * its end before comparing sizes, so a lying entry could fill the disk. Here each entry is copied in chunks and
     * never beyond its declared size + 1 byte, nor beyond what is left of limits::max_unzipped_bytes(); the first
     * byte over a cap refuses the package. Names are the ones zip_inspector judged (zip_archive::get_info()).
     *
     * The mkdir() calls below only create the folders of the package inside $dir, a make_request_directory() of
     * inspect_file(): zip_inspector already refused every unsafe name (".." segment, absolute path, drive letter,
     * backslash), and extraction only takes the names it listed, so nothing is written
     * outside that request directory, which Moodle removes at the end of the request.
     *
     * @param string $zippath Zip path.
     * @param string $dir Target directory, a request directory (make_request_directory()).
     * @param zip_listing $listing Inspection result.
     * @return array [relative path => real size, relative path => SHA-1], both sorted by path.
     * @throws \moodle_exception err_notzip, err_zipextract, err_zipentries, err_zipsize.
     */
    private function extract(string $zippath, string $dir, zip_listing $listing): array {
        global $CFG;
        $zip = new \zip_archive();
        if (!$zip->open($zippath, \file_archive::OPEN)) {
            throw self::error('err_notzip');
        }
        $sizes = [];
        $sha1s = [];
        $total = 0;
        $maxentries = limits::max_entries();
        $budget = limits::max_unzipped_bytes();
        try {
            foreach ($zip as $info) {
                $name = (string)$info->pathname;
                $target = $dir . '/' . rtrim($name, '/');
                if ($info->is_directory) {
                    if (!is_dir($target) && !mkdir($target, $CFG->directorypermissions, true)) {
                        throw self::error('err_zipextract', display_text::of($name));
                    }
                    continue;
                }
                if (isset($sizes[$name])) {
                    throw self::error('err_zipextract', display_text::of($name));
                }
                if (count($sizes) >= $maxentries) {
                    throw self::error('err_zipentries', $maxentries);
                }
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, $CFG->directorypermissions, true)) {
                    throw self::error('err_zipextract', display_text::of($name));
                }
                $declared = (int)$info->size;
                $in = $zip->get_stream($info->index);
                $out = $in ? fopen($target, 'xb') : false;
                if (!$in || !$out) {
                    if ($in) {
                        fclose($in);
                    }
                    throw self::error('err_zipextract', display_text::of($name));
                }
                try {
                    [$bytes, $sha1] = $this->copy_entry($in, $out, min($declared, max(0, $budget - $total)) + 1);
                } finally {
                    fclose($in);
                    fclose($out);
                }
                if ($bytes > $declared) {
                    // More data than the zip declares: a lie (zip bomb) or a damaged zip.
                    throw self::error('err_zipextract', display_text::of($name));
                }
                if ($total + $bytes > $budget) {
                    throw self::error('err_zipsize', (int)floor($budget / 1048576));
                }
                if ($bytes !== $declared) {
                    throw self::error('err_zipextract', display_text::of($name));
                }
                $total += $bytes;
                $sizes[$name] = $bytes;
                $sha1s[$name] = $sha1;
            }
        } finally {
            $zip->close();
        }
        $expected = array_map('strval', $listing->files);
        sort($expected, SORT_STRING);
        ksort($sizes, SORT_STRING);
        ksort($sha1s, SORT_STRING);
        if (array_keys($sizes) !== $expected) {
            $odd = array_merge(array_diff($expected, array_keys($sizes)), array_diff(array_keys($sizes), $expected));
            throw self::error('err_zipextract', display_text::of((string)reset($odd)));
        }
        return [$sizes, $sha1s];
    }

    /**
     * Copies at most $limit bytes of an entry stream to a file, in chunks, hashing with SHA-1 on the way.
     *
     * @param resource $in Entry stream.
     * @param resource $out Target file.
     * @param int $limit Most bytes to copy.
     * @return array [bytes copied, SHA-1 of the copied bytes].
     * @throws \moodle_exception err_zipextract when the file cannot be written.
     */
    protected function copy_entry($in, $out, int $limit): array {
        $context = hash_init('sha1');
        $copied = 0;
        while ($copied < $limit && !feof($in)) {
            $chunk = fread($in, min(self::CHUNK_BYTES, $limit - $copied));
            if ($chunk === false || $chunk === '') {
                break;
            }
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw self::error('err_zipextract', '');
            }
            hash_update($context, $chunk);
            $copied += strlen($chunk);
        }
        return [$copied, hash_final($context)];
    }

    /**
     * Reads cmi5.xml; its size was already checked against the parser cap.
     *
     * @param string $path Path of the extracted cmi5.xml.
     * @return string|false
     */
    protected function read_cmi5xml(string $path): string|false {
        return file_get_contents($path, false, null, 0, cmi5_parser::MAX_BYTES + 1);
    }

    /**
     * Every path must fit the File API columns: filepath ("/dir/") and filename up to 255 characters.
     *
     * @param string[] $files Relative file paths.
     * @throws \moodle_exception err_pathtoolong.
     */
    private static function check_paths(array $files): void {
        foreach ($files as $path) {
            [$filepath, $name] = self::split_path($path);
            if (
                \core_text::strlen($filepath) > self::MAX_FILE_API_CHARS
                || \core_text::strlen($name) > self::MAX_FILE_API_CHARS
            ) {
                throw self::error('err_pathtoolong', display_text::of($path));
            }
        }
    }

    /**
     * Ids and activityType from cmi5.xml must fit their 1333-character columns (§3.13); never truncated.
     *
     * @param course_structure $structure Parsed cmi5.xml.
     * @throws \moodle_exception err_idtoolong, with the element and attribute as $a.
     */
    private static function check_widths(course_structure $structure): void {
        $values = [['course@id', $structure->publisherid]];
        foreach ($structure->all_blocks() as $block) {
            $values[] = ['block@id', $block->publisherid];
        }
        foreach ($structure->all_aus() as $au) {
            $values[] = ['au@id', $au->publisherid];
            $values[] = ['au@activityType', (string)$au->activitytype];
        }
        foreach ($values as [$name, $value]) {
            if (\core_text::strlen($value) > self::MAX_ID_CHARS) {
                throw self::error('err_idtoolong', $name);
            }
        }
    }

    /**
     * Activity ids already given to AUs of the earlier versions of the instance (§3.4, cmi5 §8.1.5).
     *
     * @param int[] $pkgids Earlier pkg ids, newest first.
     * @return array publisherid => lmsactivityid; the newest version wins.
     */
    private static function previous_activity_ids(array $pkgids): array {
        global $DB;
        if (!$pkgids) {
            return [];
        }
        $rank = array_flip(array_values($pkgids));
        $ids = [];
        $best = [];
        $rows = $DB->get_recordset_list('scormmakercmi5_au', 'pkgid', $pkgids, '', 'id, pkgid, publisherid, lmsactivityid');
        foreach ($rows as $row) {
            $r = $rank[$row->pkgid];
            if (!isset($best[$row->publisherid]) || $r < $best[$row->publisherid]) {
                $best[$row->publisherid] = $r;
                $ids[$row->publisherid] = $row->lmsactivityid;
            }
        }
        $rows->close();
        return $ids;
    }

    /**
     * Inserts the blocks (one by one, for their ids) and collects the AUs, depth first in document order.
     *
     * Blocks and AUs share one sortorder counter, so the children of any parent sorted by sortorder follow the file.
     *
     * @param array $children au_definition|block_definition.
     * @param int $parentid Block id of the parent, 0 for the course.
     * @param int $pkgid Pkg id.
     * @param int $order Last sortorder used.
     * @param array $aus AU rows collected.
     * @param array $inherited publisherid => lmsactivityid of earlier versions.
     */
    private function store_items(
        array $children,
        int $parentid,
        int $pkgid,
        int &$order,
        array &$aus,
        array $inherited
    ): void {
        global $DB;
        foreach ($children as $child) {
            $order++;
            if ($child instanceof block_definition) {
                $blockid = (int)$DB->insert_record('scormmakercmi5_block', (object)[
                    'pkgid' => $pkgid,
                    'parentid' => $parentid,
                    'sortorder' => $order,
                    'depth' => $child->depth,
                    'publisherid' => $child->publisherid,
                    'lmsiri' => self::generated_iri('block'),
                    'title' => self::langstrings_json($child->title),
                    'description' => self::langstrings_json($child->description),
                ]);
                $this->store_items($child->children, $blockid, $pkgid, $order, $aus, $inherited);
                continue;
            }
            $aus[] = [
                'pkgid' => $pkgid,
                'blockid' => $parentid,
                'sortorder' => $order,
                'publisherid' => $child->publisherid,
                'lmsactivityid' => $inherited[$child->publisherid] ?? self::generated_iri('au'),
                'title' => self::langstrings_json($child->title),
                'description' => self::langstrings_json($child->description),
                'url' => $child->url,
                'launchmethod' => $child->launchmethod,
                'moveon' => $child->moveon,
                'masteryscore' => $child->masteryscore,
                'launchparameters' => $child->launchparameters,
                'entitlementkey' => $child->entitlementkey,
                'activitytype' => $child->activitytype,
                'auorigin' => $child->origin,
            ];
        }
    }

    /**
     * The pkg row, signature fields from the verification result.
     *
     * Manifest values wider than their column are left empty: a signature problem never blocks the import (R2.3).
     * sigkid, manifest, smcourseid and smversion are stored for an invalid package too (§3.2): they come from a
     * manifest that was NOT verified, so no consumer may trust them unless sigstatus (or recheck) is verified. The same
     * holds for richstructure (analise/estrutura.json, covered by the manifest).
     *
     * @param inspected_package $pkg Inspected package.
     * @param \stdClass $instance Instance row.
     * @param int $versionnum Version number.
     * @param string $filename Stored file name.
     * @param string $zipsha1 SHA-1 of the zip (its contenthash).
     * @return \stdClass
     */
    private function pkg_record(
        inspected_package $pkg,
        \stdClass $instance,
        int $versionnum,
        string $filename,
        string $zipsha1
    ): \stdClass {
        global $USER;
        $v = $pkg->verification;
        $manifest = $v->manifest;
        return (object)[
            'scormmakercmi5id' => $instance->id,
            'versionnum' => $versionnum,
            'filename' => $filename,
            'zipcontenthash' => $zipsha1,
            'totalbytes' => $pkg->totalbytes,
            'filecount' => count($pkg->filehashes),
            'publishercourseid' => $pkg->structure->publisherid,
            'cmi5xml' => $pkg->cmi5xml,
            'sigstatus' => $v->status,
            'sigreason' => $v->reason,
            'sigkid' => self::fit($v->kid, 64),
            'manifest' => $v->canonicalmanifest,
            'signature' => self::fit($v->signature, 100),
            'smcourseid' => self::fit($manifest->cursoId ?? null, 36),
            'smversion' => self::fit($manifest->versao ?? null, 64),
            'richstructure' => self::has_external_au($pkg->structure) ? null : self::richstructure($pkg),
            'timerecheck' => 0,
            'timefullrecheck' => 0,
            'usermodified' => $USER->id,
            'timecreated' => time(),
        ];
    }

    /**
     * Whether the site accepts packages with an AU outside them (setting allowexternalau: off by default, so also
     * while no value is stored; the administrator opts in).
     *
     * @return bool
     */
    public static function external_au_allowed(): bool {
        return (string)get_config('mod_scormmakercmi5', 'allowexternalau') === '1';
    }

    /**
     * A replacement package with an AU outside it needs mod/scormmakercmi5:addinstance in the course, not only the
     * moodle/course:manageactivities of the edit form: it sends the learners to a site of the uploader's choice, with a
     * token to their statements (Task 28, B-2). Any other replacement keeps the rule of mod_scorm.
     *
     * @param course_structure $structure Parsed cmi5.xml of the new package.
     * @param \context_course $coursecontext Context of the course of the activity.
     * @throws \moodle_exception err_externalaucapability.
     */
    public static function check_replacement(course_structure $structure, \context_course $coursecontext): void {
        if (self::has_external_au($structure) && !has_capability('mod/scormmakercmi5:addinstance', $coursecontext)) {
            throw self::error('err_externalaucapability');
        }
    }

    /**
     * Whether an AU of the package is outside it (absolute https url).
     *
     * @param course_structure $structure Parsed cmi5.xml.
     * @return bool
     */
    private static function has_external_au(course_structure $structure): bool {
        foreach ($structure->all_aus() as $au) {
            if ($au->origin !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * The analysis structure of the package (subproject D), validated; null when absent or invalid. Never fails the
     * import: the rich report says the structure is unavailable.
     *
     * @param inspected_package $pkg Inspected package.
     * @return string|null
     */
    private static function richstructure(inspected_package $pkg): ?string {
        try {
            return analysis_structure::from_dir($pkg->extractdir, $pkg->filehashes);
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    /**
     * The contenthashes that the file system does not hold yet, i.e. the contents this import would add.
     *
     * @param string[] $hashes SHA-1 contenthashes.
     * @return string[]
     */
    private static function new_contents(array $hashes): array {
        $filesystem = get_file_storage()->get_file_system();
        $new = [];
        foreach (array_unique($hashes) as $hash) {
            if (!$filesystem->is_file_readable_remotely_by_hash($hash)) {
                $new[] = $hash;
            }
        }
        return $new;
    }

    /**
     * After a rollback: deletes the file areas of the pkg id and the contents this import added to the file system.
     *
     * When the cleanup before the rollback could not run (for example the database connection was lost), the
     * rollback removes the files rows without the File API seeing it, which would leave their contents orphaned.
     * file_system::remove_file() keeps any content still referenced by a files row. Cleanup problems are reported,
     * never thrown: they must not hide why the import failed.
     *
     * @param \context_module $context Module context.
     * @param int $pkgid Pkg id, 0 when the pkg row was not inserted.
     * @param string[] $newcontents Contenthashes that did not exist before the import.
     */
    private function cleanup_after_rollback(\context_module $context, int $pkgid, array $newcontents): void {
        try {
            if ($pkgid) {
                $this->delete_files($context, $pkgid);
            }
        } catch (\Throwable $ignored) {
            debugging('Cleanup of a failed package import did not complete', DEBUG_DEVELOPER);
        }
        $filesystem = get_file_storage()->get_file_system();
        foreach ($newcontents as $hash) {
            try {
                $filesystem->remove_file($hash);
            } catch (\Throwable $ignored) {
                debugging('Cleanup of a failed package import did not complete', DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Deletes the package and content areas of a pkg id. Protected so tests can make the cleanup fail.
     *
     * @param \context_module $context Module context.
     * @param int $pkgid Pkg id.
     */
    protected function delete_files(\context_module $context, int $pkgid): void {
        $fs = get_file_storage();
        foreach ([self::AREA_CONTENT, self::AREA_PACKAGE] as $area) {
            $fs->delete_area_files($context->id, self::COMPONENT, $area, $pkgid);
        }
    }

    /**
     * A new LMS-generated IRI: {wwwroot}/mod/scormmakercmi5/xapi/{kind}/{uuid} (design §3.1, §3.4).
     *
     * @param string $kind au, block or course.
     * @return string
     */
    public static function generated_iri(string $kind): string {
        return (new \moodle_url('/mod/scormmakercmi5/xapi/' . $kind . '/' . \core\uuid::generate()))->out(false);
    }

    /**
     * Langstrings as the JSON object stored in title/description.
     *
     * @param array $langstrings lang => text.
     * @return string
     */
    private static function langstrings_json(array $langstrings): string {
        return json_encode((object)$langstrings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A string when it fits the column, null otherwise.
     *
     * @param mixed $value Value.
     * @param int $width Column width in characters.
     * @return string|null
     */
    private static function fit(mixed $value, int $width): ?string {
        return is_string($value) && $value !== '' && \core_text::strlen($value) <= $width ? $value : null;
    }

    /**
     * File name of the stored zip: cleaned for the File API, at most 255 characters.
     *
     * @param string $filename Original name.
     * @return string
     */
    private static function stored_filename(string $filename): string {
        $filename = \core_text::substr(clean_param($filename, PARAM_FILE), 0, self::MAX_FILE_API_CHARS);
        return $filename === '' || $filename === '.' || $filename === '..' ? 'package.zip' : $filename;
    }

    /**
     * Splits a relative path into the File API filepath ("/" or "/dir/") and filename.
     *
     * @param string $path Relative path.
     * @return string[] [filepath, filename].
     */
    private static function split_path(string $path): array {
        $slash = strrpos($path, '/');
        if ($slash === false) {
            return ['/', $path];
        }
        return ['/' . substr($path, 0, $slash) . '/', substr($path, $slash + 1)];
    }

    /**
     * A package error.
     *
     * @param string $code String key.
     * @param mixed $a String argument.
     * @return \moodle_exception
     */
    private static function error(string $code, $a = null): \moodle_exception {
        return new \moodle_exception($code, self::COMPONENT, '', $a);
    }
}
