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

/**
 * What zip_inspector found in an accepted zip.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class zip_listing {
    /**
     * Constructor.
     *
     * @param string[] $files File paths in zip order, without directory entries.
     * @param int $totalbytes Sum of the uncompressed sizes.
     */
    public function __construct(
        /** @var string[] File paths in zip order, without directory entries. */
        public readonly array $files,
        /** @var int Sum of the uncompressed sizes. */
        public readonly int $totalbytes
    ) {
    }
}
