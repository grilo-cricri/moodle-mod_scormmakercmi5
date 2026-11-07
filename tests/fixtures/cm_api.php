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
 * Delete/duplicate a course module on every supported Moodle.
 *
 * Load with require_once(core_component::get_component_directory('mod_scormmakercmi5')
 * . '/tests/fixtures/cm_api.php').
 *
 * course_delete_module() and duplicate_module() are deprecated in Moodle 5.2 (replaced by
 * core_courseformat\local\cmactions::delete()/duplicate()); cmactions has no such methods in 4.5, so the
 * functions are used there.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Version-independent course module delete/duplicate for tests.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_scormmakercmi5_test_cm_api {
    /**
     * Whether this Moodle has the cmactions delete/duplicate API (5.2+).
     *
     * @return bool
     */
    private static function has_cmactions(): bool {
        return method_exists(\core_courseformat\local\cmactions::class, 'delete');
    }

    /**
     * Deletes a course module (recycle bin and grade cleanup included).
     *
     * @param int $cmid
     */
    public static function delete(int $cmid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        if (self::has_cmactions()) {
            $courseid = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST)->course;
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            (new \core_courseformat\local\cmactions($course))->delete($cmid);
            return;
        }
        course_delete_module($cmid);
    }

    /**
     * Duplicates a course module in its own section.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return \cm_info|null
     */
    public static function duplicate(\stdClass $course, \cm_info $cm): ?\cm_info {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        if (self::has_cmactions()) {
            return (new \core_courseformat\local\cmactions($course))->duplicate($cm->id);
        }
        return duplicate_module($course, $cm);
    }
}
