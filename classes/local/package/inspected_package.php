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

use mod_scormmakercmi5\local\signature\verification_result;

/**
 * A package that passed inspection, extraction, cmi5.xml validation and the signature check (design §4.1 steps 1–5).
 *
 * The extracted tree lives in a request directory until discard() or the end of the request.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class inspected_package {
    /**
     * Constructor.
     *
     * @param course_structure $structure The parsed cmi5.xml.
     * @param verification_result $verification Outcome of the signature check.
     * @param string $extractdir Directory holding the extracted files.
     * @param array $filehashes Relative path => lower-case hex SHA-256, every extracted file.
     * @param array $filesizes Relative path => size in bytes.
     * @param int $totalbytes Sum of the real extracted sizes.
     * @param string $cmi5xml Bytes of cmi5.xml.
     * @param array $contenthashes Relative path => SHA-1 (the File API contenthash), computed while extracting.
     */
    public function __construct(
        /** @var course_structure The parsed cmi5.xml. */
        public readonly course_structure $structure,
        /** @var verification_result Outcome of the signature check. */
        public readonly verification_result $verification,
        /** @var string Directory holding the extracted files. */
        public readonly string $extractdir,
        /** @var array Relative path => SHA-256. */
        public readonly array $filehashes,
        /** @var array Relative path => size in bytes. */
        public readonly array $filesizes,
        /** @var int Sum of the real extracted sizes. */
        public readonly int $totalbytes,
        /** @var string Bytes of cmi5.xml. */
        public readonly string $cmi5xml,
        /** @var array Relative path => SHA-1 (File API contenthash). */
        public readonly array $contenthashes = []
    ) {
    }

    /**
     * Deletes the extracted files now instead of at the end of the request.
     */
    public function discard(): void {
        if (is_dir($this->extractdir)) {
            fulldelete($this->extractdir);
        }
    }
}
