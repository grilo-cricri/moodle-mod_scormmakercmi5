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

namespace mod_scormmakercmi5\backup;

use mod_scormmakercmi5\local\backup_verbatim;

/**
 * A backup element whose package and learner text fields travel base64-encoded (local\backup_verbatim).
 *
 * Used only by backup/moodle2, once the backup library (backup_nested_element) is loaded.
 *
 * @package    mod_scormmakercmi5
 * @category   backup
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verbatim_element extends \backup_nested_element {
    /**
     * Sets the values, encoding the verbatim fields of this element.
     *
     * @param array|\stdClass $values Source row.
     */
    public function fill_values($values) {
        parent::fill_values(backup_verbatim::encode($this->get_name(), (array)$values));
    }
}
