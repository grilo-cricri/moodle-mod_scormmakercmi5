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

/**
 * Outcome of checking the SCORM Maker signature of a package (design §5.1).
 *
 * The strings are sigstatus_<status> and sigreason_<reason>. kid, manifest, canonicalmanifest and signature are
 * set whenever the signature file had the expected structure, including when a later step failed.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class verification_result {
    /** @var string Signed by a trusted key and every file matches. */
    public const STATUS_VERIFIED = 'verified';

    /** @var string No signature file. */
    public const STATUS_UNSIGNED = 'unsigned';

    /** @var string Signature file present but the check failed; the reason says why. */
    public const STATUS_INVALID = 'invalid';

    /** @var string Path of the signature file inside the package. */
    public const SIGNATURE_PATH = 'META-INF/scormmaker-assinatura.json';

    /**
     * @var string Reason of an unsigned result whatever the zip holds: an AU outside the package (absolute url), whose
     * content no signature of the zip covers (decision of the list after D).
     */
    public const REASON_EXTERNAL_AU = 'external_au';

    /** @var string[] Every reason of an invalid result. */
    public const REASONS = ['malformed', 'unknownkid', 'revokedkid', 'hmldisabled', 'badsignature', 'filechanged',
        'fileextra', 'filemissing'];

    /**
     * Builds a result.
     *
     * @param string $status One of the STATUS_* constants.
     * @param string|null $reason One of REASONS for an invalid result, REASON_EXTERNAL_AU or null for an unsigned one,
     *     null for a verified one.
     * @param string|null $kid Key identifier of the manifest.
     * @param \stdClass|null $manifest Decoded manifest.
     * @param string|null $canonicalmanifest Canonical form of the manifest (the signed bytes).
     * @param string|null $signature Base64 signature, as in the file.
     * @param int|null $timefullcheck Recheck only: when every byte was last hashed (seconds; the import counts).
     * @param bool $deferred Recheck only: the package was too large to hash now; the byte check is the last full one
     *     and a new one was queued (task\recheck_package).
     */
    public function __construct(
        /** @var string One of the STATUS_* constants. */
        public readonly string $status,
        /** @var string|null One of REASONS (invalid), REASON_EXTERNAL_AU or null (unsigned), null (verified). */
        public readonly ?string $reason = null,
        /** @var string|null Key identifier of the manifest. */
        public readonly ?string $kid = null,
        /** @var \stdClass|null Decoded manifest. */
        public readonly ?\stdClass $manifest = null,
        /** @var string|null Canonical form of the manifest. */
        public readonly ?string $canonicalmanifest = null,
        /** @var string|null Base64 signature, as in the file. */
        public readonly ?string $signature = null,
        /** @var int|null Recheck only: when every byte was last hashed (seconds; the import counts). */
        public readonly ?int $timefullcheck = null,
        /** @var bool Recheck only: the byte check is the last full one and a new one was queued. */
        public readonly bool $deferred = false
    ) {
    }
}
