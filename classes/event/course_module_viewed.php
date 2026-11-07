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

namespace mod_scormmakercmi5\event;

/**
 * A user viewed a cmi5 activity (view.php).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Sets the object table.
     */
    protected function init() {
        $this->data['objecttable'] = 'scormmakercmi5';
        parent::init();
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventcoursemoduleviewed', 'mod_scormmakercmi5');
    }

    /**
     * Mapping of the object id for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'scormmakercmi5', 'restore' => 'scormmakercmi5'];
    }
}
