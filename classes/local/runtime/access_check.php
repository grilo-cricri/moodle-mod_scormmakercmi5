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

namespace mod_scormmakercmi5\local\runtime;

/**
 * Whether a learner may still take an activity (R4.7), asked by the fetch URL when it hands out a token and by the
 * LRS on every request that carries one, so that a token does not outlive the learner's access.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access_check {
    /**
     * Whether a user may take the activity: active account (not deleted, suspended, nologin or guest), course access,
     * the activity visible and available to them, and mod/scormmakercmi5:view in its context.
     *
     * Neither caller has a Moodle session of the learner (fetch.php and lrs.php), so nothing here looks at $USER.
     *
     * @param \stdClass $course Course of the activity.
     * @param \cm_info $cm The activity; one built for another user is replaced by
     *     that of get_fast_modinfo($course, $userid).
     * @param int $userid User id.
     * @return bool
     */
    public static function user_can_launch(\stdClass $course, \cm_info $cm, int $userid): bool {
        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted || $user->suspended || $user->auth === 'nologin' || isguestuser($user)) {
            return false;
        }
        // An active enrolment or moodle/course:view.
        if (!can_access_course($course, $userid, '', true)) {
            return false;
        }
        // The cm_info decides visibility and availability for the user its modinfo was built for. A cm_info of anyone
        // else (the caller's own, say) would answer for the wrong person, so build it again for this user.
        if ((int)$cm->get_modinfo()->get_user_id() !== $userid) {
            $cm = get_fast_modinfo($course, $userid)->get_cm($cm->id);
        }
        // The cm_info of this user's own modinfo: its uservisible already folds in the visible flag and the
        // availability conditions (core's info_module::is_user_visible() returns exactly that for such a cm_info).
        if (!$cm->uservisible) {
            return false;
        }
        return has_capability('mod/scormmakercmi5:view', $cm->context, $userid);
    }
}
