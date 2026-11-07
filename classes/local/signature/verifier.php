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

namespace mod_scormmakercmi5\local\signature;

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\task\recheck_package;

/**
 * Checks the SCORM Maker signature of an extracted package (design §5.1, R2).
 *
 * The file META-INF/scormmaker-assinatura.json holds {manifesto, assinatura}; the Ed25519 signature is over the
 * canonical form of the manifest, and the manifest lists the SHA-256 of every other file of the package. A failed
 * check never stops the import: the package is then a plain cmi5 package with the reason recorded (R2.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class verifier {
    /** @var int Largest signature file accepted, in bytes. */
    public const MAX_SIGNATURE_BYTES = 2 * 1048576;

    /** @var int Largest JSON nesting depth accepted. */
    public const MAX_DEPTH = 16;

    /** @var string[] Fields of the manifest, exactly these. */
    private const MANIFEST_FIELDS = ['arquivos', 'cursoId', 'donoId', 'formato', 'gerador', 'kid', 'tipoDePacote',
        'versao'];

    /** @var string[] Manifest fields that are non-empty strings. */
    private const STRING_FIELDS = ['cursoId', 'donoId', 'gerador', 'kid', 'versao'];

    /** @var string Format of a file hash in the manifest. */
    private const HASH_PATTERN = '/^sha256:[0-9a-f]{64}$/D';

    /** @var string Component of the file areas. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /**
     * Checks the signature of a package extracted at import.
     *
     * @param string $extractdir Directory the package was extracted to.
     * @param array $filehashes Relative path ("/" separated) => lower-case hex SHA-256 of every extracted file, as
     *     hash_files() returns; the file bytes are not read again here.
     * @param keyring $keyring Trusted keys.
     * @return verification_result
     */
    public static function verify_at_import(string $extractdir, array $filehashes, keyring $keyring): verification_result {
        $path = verification_result::SIGNATURE_PATH;
        if (!array_key_exists($path, $filehashes)) {
            return new verification_result(verification_result::STATUS_UNSIGNED);
        }
        $document = self::read_document(rtrim($extractdir, '/\\') . '/' . $path);
        if ($document === null) {
            return self::invalid('malformed');
        }
        [$manifest, $signature, $canonical] = $document;
        $kid = $manifest->kid;
        $invalid = static fn(string $reason): verification_result =>
            self::invalid($reason, $kid, $manifest, $canonical, $signature);

        $status = $keyring->status_of($kid);
        if ($status !== keyring::STATUS_TRUSTED) {
            return $invalid($status);
        }
        $raw = base64_decode($signature, true);
        $valid = $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($raw, $canonical, $keyring->public_key($kid));
        if (!$valid) {
            return $invalid('badsignature');
        }

        $reason = self::compare_files($manifest->arquivos, $filehashes);
        if ($reason !== null) {
            return $invalid($reason);
        }
        return new verification_result(
            verification_result::STATUS_VERIFIED,
            null,
            $kid,
            $manifest,
            $canonical,
            $signature
        );
    }

    /**
     * Checks a stored package again (design §5.4, R2.4, R3.4) and records the outcome. The rich report opens with the
     * read-only evaluate() instead; this one runs from the scheduled and background tasks.
     *
     * 1. The stored canonical manifest against its stored signature, with the keyring of TODAY: a kid revoked (or
     *    the HML key turned off) after the import fails here.
     * 2. The file map, without reading bytes: the signed manifest against the pkgfile rows (SHA-256), then the
     *    stored_files of the content area against the pkgfile rows (path and contenthash) — a file replaced, added
     *    or deleted through the File API fails here.
     * 3. The SHA-256 of every stored byte, streamed, when pkg.totalbytes ≤ $maxbytes (recheckmaxmb) and step 2
     *    passed — also when step 1 failed, so the byte state never ages behind a keyring change. Bytes changed
     *    directly in the filedir (same contenthash in the database) fail here; a file whose bytes cannot be read is
     *    "filemissing". ONLY this step writes fullrecheckstatus, fullrecheckreason and timefullrecheck.
     *
     * Above the limit the byte check is queued as task\recheck_package (deduplicated, and not at all while the last
     * one recorded is younger than recheckminhours) and the byte result is the last one recorded by step 3 (none
     * yet = the import, which hashed every byte into pkgfile.sha256). The result is the first failure of: step 1 now,
     * step 2 now, step 3 (now or the last recorded one).
     *
     * Writes, never sigstatus/sigreason (the import): recheckstatus, recheckreason and timerecheck always; the three
     * full* columns with them when step 3 ran. A write that hashed is skipped if a newer byte check was recorded
     * meanwhile; a write that did not hash is skipped if any byte check was recorded after the row was read — an
     * opening of the report racing the background task never overwrites the newer result with an older one.
     *
     * A version with an AU outside the package (auorigin set) is unsigned with the reason external_au before anything
     * else, whatever its stored signature says (a hand-made backup, a changed row): its content is not in the zip.
     *
     * An unsigned package stays unsigned. A package that was invalid at import is checked again the same way: the
     * step-2 comparison of the manifest with the pkgfile rows keeps one whose files never matched from turning
     * verified.
     *
     * @param \stdClass $pkg scormmakercmi5_pkg row (id is used; the row is read again).
     * @param keyring $keyring Keyring of today.
     * @param int|null $maxbytes Largest package hashed now, in bytes; null = limits::recheck_max_bytes().
     * @return verification_result With timefullcheck (date of the byte check the result relies on) and deferred.
     */
    public static function recheck(\stdClass $pkg, keyring $keyring, ?int $maxbytes = null): verification_result {
        return self::run_recheck($pkg, $keyring, $maxbytes, true);
    }

    /**
     * The verdict of recheck(), read-only: the same three steps and the same result, but nothing is written to the
     * package row and no background byte check is queued. A report opened with GET uses this, so that opening a page
     * never changes state (a GET that writes can be triggered by any link a teacher follows); the scheduled task
     * recheck_current_packages and the background recheck_package task persist through recheck().
     *
     * @param \stdClass $pkg scormmakercmi5_pkg row (id is used; the row is read again).
     * @param keyring $keyring Keyring of today.
     * @param int|null $maxbytes Largest package hashed now, in bytes; null = limits::recheck_max_bytes().
     * @return verification_result As recheck() returns it.
     */
    public static function evaluate(\stdClass $pkg, keyring $keyring, ?int $maxbytes = null): verification_result {
        return self::run_recheck($pkg, $keyring, $maxbytes, false);
    }

    /**
     * The recheck, with or without its writes (see recheck() and evaluate()).
     *
     * @param \stdClass $pkg Pkg row.
     * @param keyring $keyring Keyring of today.
     * @param int|null $maxbytes Largest package hashed now, in bytes; null = limits::recheck_max_bytes().
     * @param bool $persist Whether to record the outcome and queue the background byte check.
     * @return verification_result
     */
    private static function run_recheck(\stdClass $pkg, keyring $keyring, ?int $maxbytes, bool $persist): verification_result {
        global $DB;
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkg->id], '*', MUST_EXIST);
        $now = \core\di::get(\core\clock::class)->time();
        $maxbytes = $maxbytes ?? limits::recheck_max_bytes();
        $seenfull = (int)$pkg->timefullrecheck;
        $invalid = verification_result::STATUS_INVALID;
        $verified = verification_result::STATUS_VERIFIED;

        if ($DB->record_exists_select('scormmakercmi5_au', 'pkgid = :pkgid AND auorigin IS NOT NULL', ['pkgid' => $pkg->id])) {
            $reason = verification_result::REASON_EXTERNAL_AU;
            if ($persist) {
                self::record($pkg, verification_result::STATUS_UNSIGNED, $reason, $now, $seenfull);
            }
            $lastfull = $seenfull ?: (int)$pkg->timecreated;
            return new verification_result(verification_result::STATUS_UNSIGNED, $reason, null, null, null, null, $lastfull);
        }
        if ($pkg->sigstatus === verification_result::STATUS_UNSIGNED) {
            if ($persist) {
                self::record($pkg, verification_result::STATUS_UNSIGNED, null, $now, $seenfull);
            }
            $lastfull = $seenfull ?: (int)$pkg->timecreated;
            return new verification_result(verification_result::STATUS_UNSIGNED, null, null, null, null, null, $lastfull);
        }

        $full = (int)$pkg->totalbytes <= $maxbytes;
        [$manifest, $sigreason] = self::recheck_signature($pkg, $keyring);
        $mapreason = null;
        $files = [];
        $pkgfiles = [];
        if ($manifest !== null) {
            [$mapreason, $files, $pkgfiles] = self::recheck_file_map($pkg, $manifest);
        }
        $mapok = $manifest !== null && $mapreason === null;

        $hashed = $mapok && $full;
        if ($hashed) {
            $bytereason = self::recheck_bytes($files, $pkgfiles);
            $lastfull = $now;
        } else {
            $bytereason = $pkg->fullrecheckstatus === $invalid ? ((string)$pkg->fullrecheckreason ?: 'filechanged') : null;
            $lastfull = $seenfull ?: (int)$pkg->timecreated;
            // A byte check recorded less than recheckminhours ago is reused: a large package is not hashed again on
            // every opening of the report (final review #38). The import alone does not count, so the first opening
            // after it queues one.
            $recent = $seenfull > 0 && $now - $seenfull < limits::recheck_min_interval_seconds();
            if ($persist && $mapok && !$recent) {
                $task = new recheck_package();
                $task->set_custom_data((object)['pkgid' => (int)$pkg->id]);
                \core\task\manager::queue_adhoc_task($task, true);
            }
        }

        $reason = $sigreason ?? $mapreason ?? $bytereason;
        $status = $reason === null ? $verified : $invalid;
        if ($persist && $hashed) {
            self::record_full($pkg, $status, $reason, $bytereason, $now);
        } else if ($persist) {
            self::record($pkg, $status, $reason, $now, $seenfull);
        }
        $kid = $manifest->kid ?? $pkg->sigkid;
        return new verification_result($status, $reason, $kid, $manifest, $pkg->manifest, $pkg->signature, $lastfull, !$full);
    }

    /**
     * Whether the stored signature and file map of a version hold with the keyring, before its files exist: the
     * canonical manifest verifies against the signature, the signed manifest matches the pkgfile rows (SHA-256), and
     * no AU lies outside the package. No stored file is read, so it can run while a backup is being restored (the
     * restore must decide how to store the files before they are there). Nothing is written.
     *
     * @param int $pkgid scormmakercmi5_pkg id.
     * @param keyring $keyring Keyring of today.
     * @return bool
     */
    public static function signature_and_map_hold(int $pkgid, keyring $keyring): bool {
        global $DB;
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkgid], '*', MUST_EXIST);
        if ($DB->record_exists_select('scormmakercmi5_au', 'pkgid = :pkgid AND auorigin IS NOT NULL', ['pkgid' => $pkgid])) {
            return false;
        }
        [$manifest, $reason] = self::recheck_signature($pkg, $keyring);
        if ($manifest === null || $reason !== null) {
            return false;
        }
        $sha256 = [];
        $rows = $DB->get_records('scormmakercmi5_pkgfile', ['pkgid' => $pkgid], '', 'id, path, sha256');
        foreach ($rows as $row) {
            $sha256[(string)$row->path] = (string)$row->sha256;
        }
        return self::compare_files($manifest->arquivos, $sha256) === null;
    }

    /**
     * Step 1 of the recheck: the stored manifest and signature with the keyring of today.
     *
     * @param \stdClass $pkg Pkg row.
     * @param keyring $keyring Keyring.
     * @return array [\stdClass|null manifest, string|null reason].
     */
    private static function recheck_signature(\stdClass $pkg, keyring $keyring): array {
        $canonical = (string)$pkg->manifest;
        try {
            $manifest = $canonical === '' ? null : json_decode($canonical, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $manifest = null;
        }
        if (!self::valid_manifest($manifest) || !is_string($pkg->signature) || $pkg->signature === '') {
            return [null, 'malformed'];
        }
        $status = $keyring->status_of($manifest->kid);
        if ($status !== keyring::STATUS_TRUSTED) {
            return [$manifest, $status];
        }
        $raw = base64_decode($pkg->signature, true);
        $valid = $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($raw, $canonical, $keyring->public_key($manifest->kid));
        return [$manifest, $valid ? null : 'badsignature'];
    }

    /**
     * Step 2 of the recheck: the signed manifest against pkgfile, then the content area against pkgfile (one query
     * each, no bytes read).
     *
     * @param \stdClass $pkg Pkg row.
     * @param \stdClass $manifest Verified manifest.
     * @return array [string|null reason, path => \stored_file, path => pkgfile row].
     */
    private static function recheck_file_map(\stdClass $pkg, \stdClass $manifest): array {
        global $DB;
        $pkgfiles = [];
        $sha256 = [];
        $contenthashes = [];
        $rows = $DB->get_records('scormmakercmi5_pkgfile', ['pkgid' => $pkg->id], '', 'id, path, sha256, contenthash');
        foreach ($rows as $row) {
            $pkgfiles[(string)$row->path] = $row;
            $sha256[(string)$row->path] = (string)$row->sha256;
            $contenthashes[(string)$row->path] = (string)$row->contenthash;
        }
        $reason = self::compare_files($manifest->arquivos, $sha256);
        if ($reason !== null) {
            return [$reason, [], []];
        }
        $cm = get_coursemodule_from_instance('scormmakercmi5', $pkg->scormmakercmi5id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $files = [];
        $stored = [];
        $area = get_file_storage()->get_area_files($context->id, self::COMPONENT, 'content', (int)$pkg->id, 'id', false);
        foreach ($area as $file) {
            $path = ltrim($file->get_filepath(), '/') . $file->get_filename();
            $files[$path] = $file;
            $stored[$path] = $file->get_contenthash();
        }
        return [self::compare_maps($contenthashes, $stored), $files, $pkgfiles];
    }

    /**
     * Step 3 of the recheck: the SHA-256 of every stored byte against pkgfile.
     *
     * @param \stored_file[] $files path => stored file (same paths as $pkgfiles after step 2).
     * @param \stdClass[] $pkgfiles path => pkgfile row.
     * @return string|null Reason, or null when every file matches.
     */
    private static function recheck_bytes(array $files, array $pkgfiles): ?string {
        foreach ($files as $path => $file) {
            $hash = self::hash_stored_file($file);
            if ($hash === null) {
                return 'filemissing';
            }
            if ($hash !== (string)$pkgfiles[$path]->sha256) {
                return 'filechanged';
            }
        }
        return null;
    }

    /**
     * Writes the outcome of a recheck that did not hash the bytes (never sigstatus/sigreason), unless a byte check
     * was recorded after the row was read: the outcome was then combined with an older byte result.
     *
     * @param \stdClass $pkg Pkg row as read.
     * @param string $status Status.
     * @param string|null $reason Reason.
     * @param int $now Time.
     * @param int $seenfull pkg.timefullrecheck as read.
     */
    private static function record(\stdClass $pkg, string $status, ?string $reason, int $now, int $seenfull): void {
        global $DB;
        $DB->execute(
            "UPDATE {scormmakercmi5_pkg}
                SET recheckstatus = :status, recheckreason = :reason, timerecheck = :now
              WHERE id = :id AND timefullrecheck = :seen",
            ['status' => $status, 'reason' => $reason, 'now' => $now, 'id' => $pkg->id, 'seen' => $seenfull]
        );
    }

    /**
     * Writes the outcome of a recheck that hashed every byte, with the result of step 3 and its date, unless a newer
     * byte check was recorded meanwhile.
     *
     * @param \stdClass $pkg Pkg row.
     * @param string $status Combined status.
     * @param string|null $reason Combined reason.
     * @param string|null $bytereason Reason of step 3 (null = every byte matched).
     * @param int $now Time.
     */
    private static function record_full(\stdClass $pkg, string $status, ?string $reason, ?string $bytereason, int $now): void {
        global $DB;
        $bytestatus = $bytereason === null ? verification_result::STATUS_VERIFIED : verification_result::STATUS_INVALID;
        $DB->execute(
            "UPDATE {scormmakercmi5_pkg}
                SET recheckstatus = :status, recheckreason = :reason, timerecheck = :now,
                    fullrecheckstatus = :bytestatus, fullrecheckreason = :bytereason, timefullrecheck = :fullnow
              WHERE id = :id AND timefullrecheck <= :seennow",
            ['status' => $status, 'reason' => $reason, 'now' => $now, 'bytestatus' => $bytestatus,
                'bytereason' => $bytereason, 'fullnow' => $now, 'id' => $pkg->id, 'seennow' => $now]
        );
    }

    /**
     * SHA-256 of the bytes of a stored file, streamed from the file system (constant memory).
     *
     * @param \stored_file $file File.
     * @return string|null Lower-case hex SHA-256, or null when its bytes cannot be read.
     */
    public static function hash_stored_file(\stored_file $file): ?string {
        try {
            $handle = $file->get_content_file_handle();
        } catch (\Throwable $e) {
            return null;
        }
        return $handle ? self::hash_stream($handle) : null;
    }

    /**
     * SHA-256 of an open stream, read to its end and closed.
     *
     * @param resource $handle Stream.
     * @return string|null Lower-case hex SHA-256, or null on a read error.
     */
    private static function hash_stream($handle): ?string {
        $context = hash_init('sha256');
        while (!feof($handle)) {
            // Blocks of 1 MB (design §5.4): constant memory whatever the file size.
            $chunk = fread($handle, 1048576);
            if ($chunk === false) {
                fclose($handle);
                return null;
            }
            hash_update($context, $chunk);
        }
        fclose($handle);
        return hash_final($context);
    }

    /**
     * SHA-256 of every file under a directory, streamed (constant memory).
     *
     * An I/O fault is not a verification result: the caller (the importer) refuses the package with its own
     * message when this returns null.
     *
     * @param string $dir Directory.
     * @return array|null Relative path ("/" separated) => lower-case hex SHA-256, or null when the directory or one
     *     of its files cannot be read.
     */
    public static function hash_files(string $dir): ?array {
        $dir = rtrim($dir, '/\\');
        $hashes = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $hash = self::hash_file($file->getPathname());
                if ($hash === null) {
                    return null;
                }
                $hashes[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($dir) + 1))] = $hash;
            }
        } catch (\UnexpectedValueException $e) {
            return null;
        }
        return $hashes;
    }

    /**
     * SHA-256 of one file, streamed (constant memory).
     *
     * @param string $path File path.
     * @return string|null Lower-case hex SHA-256, or null when the file cannot be read.
     */
    public static function hash_file(string $path): ?string {
        $handle = is_file($path) && is_readable($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            return null;
        }
        return self::hash_stream($handle);
    }

    /**
     * Reads and validates the signature file (§5.1 step 2).
     *
     * @param string $file Path of the signature file.
     * @return array|null [manifest, base64 signature, canonical manifest], or null when malformed.
     */
    private static function read_document(string $file): ?array {
        $size = @filesize($file);
        if ($size === false || $size > self::MAX_SIGNATURE_BYTES) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        try {
            $document = json_decode($raw, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        if (!self::has_fields($document, ['assinatura', 'manifesto']) || !is_string($document->assinatura)) {
            return null;
        }
        $manifest = $document->manifesto;
        if (!self::valid_manifest($manifest)) {
            return null;
        }
        try {
            $canonical = canonical_json::encode($manifest);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        return [$manifest, $document->assinatura, $canonical];
    }

    /**
     * Whether the manifest has exactly the expected fields with the expected types and values.
     *
     * @param mixed $manifest Decoded manifest.
     * @return bool
     */
    private static function valid_manifest(mixed $manifest): bool {
        if (!self::has_fields($manifest, self::MANIFEST_FIELDS)) {
            return false;
        }
        foreach (self::STRING_FIELDS as $field) {
            if (!is_string($manifest->$field) || $manifest->$field === '') {
                return false;
            }
        }
        if ($manifest->formato !== 1 || $manifest->tipoDePacote !== 'cmi5') {
            return false;
        }
        $files = $manifest->arquivos;
        if (!$files instanceof \stdClass) {
            return false;
        }
        $vars = get_object_vars($files);
        if (!$vars) {
            return false;
        }
        foreach ($vars as $path => $hash) {
            if ((string)$path === '' || !is_string($hash) || !preg_match(self::HASH_PATTERN, $hash)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is an object with exactly these fields.
     *
     * @param mixed $value Decoded value.
     * @param string[] $fields Field names.
     * @return bool
     */
    private static function has_fields(mixed $value, array $fields): bool {
        if (!$value instanceof \stdClass) {
            return false;
        }
        $keys = array_map('strval', array_keys(get_object_vars($value)));
        sort($keys, SORT_STRING);
        sort($fields, SORT_STRING);
        return $keys === $fields;
    }

    /**
     * Compares the extracted files with the manifest (§5.1 step 5): changed, then extra, then missing.
     *
     * @param \stdClass $expected Manifest arquivos, path => "sha256:<hex>".
     * @param array $filehashes Extracted files, path => hex (the signature file is left out here).
     * @return string|null Reason, or null when they match.
     */
    private static function compare_files(\stdClass $expected, array $filehashes): ?string {
        $wanted = [];
        foreach (get_object_vars($expected) as $path => $hash) {
            $wanted[(string)$path] = substr($hash, strlen('sha256:'));
        }
        $actual = [];
        foreach ($filehashes as $path => $hash) {
            if ((string)$path !== verification_result::SIGNATURE_PATH) {
                $actual[(string)$path] = (string)$hash;
            }
        }
        return self::compare_maps($wanted, $actual);
    }

    /**
     * Compares two maps path => hash: changed, then extra, then missing.
     *
     * @param array $wanted Expected path => hash.
     * @param array $actual Found path => hash.
     * @return string|null Reason, or null when they match.
     */
    private static function compare_maps(array $wanted, array $actual): ?string {
        // Paths are compared as exact bytes: array keys are byte strings (no Unicode normalisation).
        foreach ($wanted as $path => $hash) {
            if (isset($actual[$path]) && $actual[$path] !== $hash) {
                return 'filechanged';
            }
        }
        if (array_diff_key($actual, $wanted)) {
            return 'fileextra';
        }
        if (array_diff_key($wanted, $actual)) {
            return 'filemissing';
        }
        return null;
    }

    /**
     * An invalid result.
     *
     * @param string $reason One of verification_result::REASONS.
     * @param string|null $kid Key identifier.
     * @param \stdClass|null $manifest Manifest.
     * @param string|null $canonical Canonical manifest.
     * @param string|null $signature Base64 signature.
     * @return verification_result
     */
    private static function invalid(
        string $reason,
        ?string $kid = null,
        ?\stdClass $manifest = null,
        ?string $canonical = null,
        ?string $signature = null
    ): verification_result {
        return new verification_result(
            verification_result::STATUS_INVALID,
            $reason,
            $kid,
            $manifest,
            $canonical,
            $signature
        );
    }
}
