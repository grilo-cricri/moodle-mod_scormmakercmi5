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
 * Backup task of mod_scormmakercmi5 (design §12, NF7).
 *
 * @package    mod_scormmakercmi5
 * @category   backup
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/scormmakercmi5/backup/moodle2/backup_scormmakercmi5_stepslib.php');

/**
 * Settings and steps of the backup of one activity.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_scormmakercmi5_activity_task extends backup_activity_task {
    /**
     * No particular settings.
     */
    protected function define_my_settings() {
    }

    /**
     * The structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_scormmakercmi5_activity_structure_step('scormmakercmi5_structure', 'scormmakercmi5.xml'));
    }

    /**
     * Encodes the links to index.php and view.php of this module.
     *
     * @param string $content Content.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot . '/mod/scormmakercmi5', '/');
        $content = preg_replace('/(' . $base . '\/index.php\?id\=)([0-9]+)/', '$@SCORMMAKERCMI5INDEX*$2@$', $content);
        return preg_replace('/(' . $base . '\/view.php\?id\=)([0-9]+)/', '$@SCORMMAKERCMI5VIEWBYID*$2@$', $content);
    }
}
