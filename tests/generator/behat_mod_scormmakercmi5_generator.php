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

/**
 * Behat data generator of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_scormmakercmi5_generator extends behat_generator_base {
    /**
     * Entities: "mod_scormmakercmi5 > registrations" with activity (idnumber) and user (username).
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'registrations' => [
                'singular' => 'registration',
                'datagenerator' => 'registration',
                'required' => ['activity', 'user'],
                'switchids' => ['activity' => 'scormmakercmi5id', 'user' => 'userid'],
            ],
        ];
    }

    /**
     * The instance id of an activity by its idnumber (the base class gives the course module id).
     *
     * @param string $idnumber Course module idnumber.
     * @return int
     */
    protected function get_activity_id(string $idnumber): int {
        global $DB;
        $cmid = parent::get_activity_id($idnumber);
        return (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid], MUST_EXIST);
    }
}
