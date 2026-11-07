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

namespace mod_scormmakercmi5\local\report;

/**
 * Records of a spout writer of Moodle (excel, ods) with numbers kept as numbers. The core write_record() passes every
 * value through \core\dataformat::escape_spreadsheet_formula(), whose ?string return type turns each int and float into
 * text, so every number of a sheet would be a text cell. Here only text goes through that escape (when the core has
 * it); block_export has already guarded every text cell with its own rule.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait typed_cells {
    /**
     * Writes one record.
     *
     * @param array $record Record.
     * @param int $rownum Row number.
     */
    public function write_record($record, $rownum) {
        $escape = method_exists(\core\dataformat::class, 'escape_spreadsheet_formula');
        $values = [];
        foreach ($this->format_record($record) as $value) {
            $values[] = is_string($value) && $escape ? \core\dataformat::escape_spreadsheet_formula($value) : $value;
        }
        $this->writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($values));
    }
}
