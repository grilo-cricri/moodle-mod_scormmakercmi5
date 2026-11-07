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

use core_files\hook\before_file_created;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;

/**
 * Keeps the files of VERIFIED packages byte for byte in the File API.
 *
 * Since Moodle 4.4 the file redactor of core (core_files\redactor, setting file_redactor_exifremoverenabled, on by
 * default) rewrites every JPEG stored with a mime type through a listener of the hook before_file_created. A SCORM
 * Maker package is signed file by file: a rewritten JPEG no longer has the SHA-256 of the signed manifest, so every
 * recheck reported "file changed", and the learner was served an asset that is not the one the author published.
 *
 * Core offers no per-area switch, and the hook cannot be stopped (before_file_created uses stoppable_trait but does
 * not implement StoppableEventInterface, which is what the dispatcher checks). So two listeners bracket every other
 * one: remember() (priority 10000, before the redactor at 100) keeps the original path or content, restore() (priority
 * -10000, after it) puts it back when a listener changed it, through the hook's own update_filepath() and
 * update_filecontent(). Whoever stores the files (import, package replacement, restore of a backup,
 * task\repair_redacted_images), the File API stores the original bytes.
 *
 * Only the package and content areas of this plugin, and only for a package version whose signature is verified
 * (sigstatus "verified"): the redactor is a privacy control (GPS position, camera serial) and verbatim storage only
 * serves the signature check. An unsigned package has none, and an invalid one (any signature file that does not
 * verify, even garbage) proves nothing about its author, so both get the file processing of the site. The metadata of
 * a verified package is the author's, as signed. When the version cannot be found while its file is stored, the
 * redaction applies (fail toward privacy).
 *
 * The bytes kept are the ones the signature covers, and this class checks it itself: a file of the content area is
 * kept only when the SHA-256 of the incoming bytes equals the pkgfile.sha256 of its path (the value the signed
 * manifest lists; recheck_file_map() uses the same key, the path without the leading slash). A file that does not
 * match, or whose path has no pkgfile row, gets the file processing of the site. That holds for every way of storing
 * a file (import, package replacement, restore of a backup, task\repair_redacted_images), so a backup that carries a
 * signed manifest with swapped images does not get them stored verbatim. The import inserts the pkgfile rows after
 * the files, so it hands the expected hashes over with expecting(). The temporary files of the repair task (item id
 * 0, see for_temporary_files_of()) are not compared: the task hashed them against pkgfile itself, by path, from the
 * entries of the stored zip. The package area is only the original zip of the version: it is kept only for the
 * file at its root that has the name recorded in the pkg row, a zip mime type, and the SHA-1 recorded in the pkg row
 * (the signature covers the entries of the zip, not the zip bytes, so that hash is the best check there is). Any other
 * file of the package area, such as an extra image that a hand-made backup puts there, gets the file processing of the
 * site.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class verbatim_storage {
    /** @var string[] File areas of the plugin stored byte for byte. */
    public const AREAS = [importer::AREA_PACKAGE, importer::AREA_CONTENT];

    /** @var \WeakMap|null Hook => ['path' => string|null, 'content' => string|null] while it is dispatched. */
    private static ?\WeakMap $originals = null;

    /** @var array pkgid => [path => sha256], the hashes the import expects while it stores a version's files. */
    private static array $expected = [];

    /** @var int Package version of the temporary files (item id 0 of the content area), inside for_temporary_files_of(). */
    private static int $temporaryfor = 0;

    /**
     * Runs $fn while the temporary files (item id 0 of the content area) count as files of the package version $pkgid.
     *
     * Used by task\repair_redacted_images, which stores the signed bytes there before it swaps them in.
     *
     * @param int $pkgid Package version.
     * @param callable $fn Work.
     * @return mixed What $fn returns.
     */
    public static function for_temporary_files_of(int $pkgid, callable $fn): mixed {
        $previous = self::$temporaryfor;
        self::$temporaryfor = $pkgid;
        try {
            return $fn();
        } finally {
            self::$temporaryfor = $previous;
        }
    }

    /**
     * Runs $fn while the files of the content area of version $pkgid are compared with $sha256 (path => SHA-256)
     * instead of the pkgfile rows, which the import only inserts after storing the files.
     *
     * @param int $pkgid Package version.
     * @param array $sha256 Relative path => lower-case hex SHA-256 the import verified.
     * @param callable $fn Work.
     * @return mixed What $fn returns.
     */
    public static function expecting(int $pkgid, array $sha256, callable $fn): mixed {
        self::$expected[$pkgid] = $sha256;
        try {
            return $fn();
        } finally {
            unset(self::$expected[$pkgid]);
        }
    }

    /**
     * First listener of before_file_created: remembers the original file of a verified package version.
     *
     * @param before_file_created $hook The hook.
     */
    public static function remember(before_file_created $hook): void {
        if (!self::keeps($hook)) {
            return;
        }
        self::$originals ??= new \WeakMap();
        self::$originals[$hook] = ['path' => $hook->get_filepath(), 'content' => $hook->get_filecontent()];
    }

    /**
     * Last listener of before_file_created: puts the original file back when another listener changed it.
     *
     * @param before_file_created $hook The hook.
     */
    public static function restore(before_file_created $hook): void {
        if (self::$originals === null || !isset(self::$originals[$hook])) {
            return;
        }
        $original = self::$originals[$hook];
        unset(self::$originals[$hook]);
        $path = $original['path'];
        $content = $original['content'];
        if ($hook->has_filepath() && $path !== null && $hook->get_filepath() !== $path) {
            $hook->update_filepath($path);
        } else if ($hook->has_filecontent() && $content !== null && $hook->get_filecontent() !== $content) {
            $hook->update_filecontent($content);
        }
    }

    /**
     * Whether a file is kept byte for byte: a file with a mime type (the redactor ignores the others) of the package or
     * content area of this plugin, whose package version is verified (sigstatus verified) and, in the content area,
     * whose bytes are the ones the signed manifest lists.
     *
     * The pkg row is always there before its files: the import inserts it before store_files() (same transaction) and
     * the restore adds the files of the activity after its rows. A row that cannot be found means redaction.
     *
     * @param before_file_created $hook The hook.
     * @return bool
     */
    private static function keeps(before_file_created $hook): bool {
        global $DB;
        $record = $hook->get_filerecord();
        if ($record === null || ($record->component ?? null) !== importer::COMPONENT || empty($record->mimetype)) {
            return false;
        }
        if (!in_array($record->filearea ?? null, self::AREAS, true)) {
            return false;
        }
        $pkgid = (int)($record->itemid ?? 0);
        $temporary = $pkgid === 0 && $record->filearea === importer::AREA_CONTENT;
        if ($temporary) {
            $pkgid = self::$temporaryfor;
        }
        if ($pkgid <= 0) {
            return false;
        }
        $status = $DB->get_field('scormmakercmi5_pkg', 'sigstatus', ['id' => $pkgid]);
        if ($status !== verification_result::STATUS_VERIFIED) {
            return false;
        }
        if ($temporary) {
            return true;
        }
        if ($record->filearea === importer::AREA_PACKAGE) {
            return self::is_the_package_zip($hook, $record, $pkgid);
        }
        return self::matches_signed_file($hook, $record, $pkgid);
    }

    /**
     * Whether a file of the package area is the zip of the version: name and root folder as recorded, a zip mime type
     * and the SHA-1 of the pkg row.
     *
     * @param before_file_created $hook The hook.
     * @param \stdClass $record File record.
     * @param int $pkgid Package version.
     * @return bool
     */
    private static function is_the_package_zip(before_file_created $hook, \stdClass $record, int $pkgid): bool {
        global $DB;
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkgid], 'id, filename, zipcontenthash');
        if (!$pkg || ($record->filepath ?? '/') !== '/' || (string)($record->filename ?? '') !== (string)$pkg->filename) {
            return false;
        }
        if (($record->mimetype ?? '') !== 'application/zip') {
            return false;
        }
        $actual = self::incoming_hash($hook, 'sha1');
        return $actual !== null && (string)$pkg->zipcontenthash !== '' && hash_equals((string)$pkg->zipcontenthash, $actual);
    }

    /**
     * Whether the incoming bytes of a content file are the SHA-256 listed for its path.
     *
     * @param before_file_created $hook The hook.
     * @param \stdClass $record File record.
     * @param int $pkgid Package version.
     * @return bool
     */
    private static function matches_signed_file(before_file_created $hook, \stdClass $record, int $pkgid): bool {
        global $DB;
        $path = ltrim((string)($record->filepath ?? '/'), '/') . (string)($record->filename ?? '');
        if (isset(self::$expected[$pkgid])) {
            $wanted = self::$expected[$pkgid][$path] ?? null;
        } else {
            // Outside the import the hash comes from a pkgfile row, which a restore took from the backup. The signature
            // file is the one path the signature does not cover (the verifier leaves it out), so its row is never trusted.
            if ($path === verification_result::SIGNATURE_PATH) {
                return false;
            }
            // The row is found by pathhash (the index) and must carry this very path: a key that does not come from
            // the path is not trusted (the restore recomputes it, this is the second lock).
            $row = $DB->get_record('scormmakercmi5_pkgfile', ['pkgid' => $pkgid, 'pathhash' => sha1($path)], 'id, path, sha256');
            $wanted = $row && (string)$row->path === $path ? $row->sha256 : null;
        }
        if (!is_string($wanted) || $wanted === '') {
            return false;
        }
        $actual = self::incoming_hash($hook, 'sha256');
        return $actual !== null && hash_equals($wanted, $actual);
    }

    /**
     * Hash of the bytes the hook is about to store.
     *
     * @param before_file_created $hook The hook.
     * @param string $algo sha256 or sha1.
     * @return string|null Lower-case hex, or null when the bytes cannot be read.
     */
    private static function incoming_hash(before_file_created $hook, string $algo): ?string {
        if ($hook->has_filepath() && $hook->get_filepath() !== null) {
            return $algo === 'sha256' ? verifier::hash_file($hook->get_filepath()) : (sha1_file($hook->get_filepath()) ?: null);
        }
        if ($hook->has_filecontent() && $hook->get_filecontent() !== null) {
            return hash($algo, $hook->get_filecontent());
        }
        return null;
    }
}
