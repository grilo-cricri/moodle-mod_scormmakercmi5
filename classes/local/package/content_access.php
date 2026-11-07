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
 * Who may read the files of the module through pluginfile.php (design §10.1).
 *
 * The caller has already run require_course_login($course, true, $cm); this adds the capability and checks that the
 * item id is a package version of this very instance, so a pkg id of another activity never serves its files here.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_access {
    /**
     * Whether the user may read files of the area and item id.
     *
     * - content: mod/scormmakercmi5:view, and itemid is a pkg of the instance (any version: registrations stay on
     *   the version they started with, R1.5);
     * - package: moodle/course:manageactivities, and itemid is a pkg of the instance;
     * - any other area: never.
     *
     * @param \stdClass $cm Course module (instance is the scormmakercmi5 id).
     * @param \context_module $context Module context of $cm.
     * @param string $filearea File area.
     * @param int $itemid Item id (pkg id).
     * @param int $userid User id.
     * @return bool
     */
    public static function can_serve(\stdClass $cm, \context_module $context, string $filearea, int $itemid, int $userid): bool {
        global $DB;
        if ($filearea === importer::AREA_CONTENT) {
            $capability = 'mod/scormmakercmi5:view';
        } else if ($filearea === importer::AREA_PACKAGE) {
            $capability = 'moodle/course:manageactivities';
        } else {
            return false;
        }
        if ($itemid <= 0 || (int)$context->instanceid !== (int)$cm->id) {
            return false;
        }
        if (!has_capability($capability, $context, $userid)) {
            return false;
        }
        return $DB->record_exists('scormmakercmi5_pkg', ['id' => $itemid, 'scormmakercmi5id' => $cm->instance]);
    }
}
