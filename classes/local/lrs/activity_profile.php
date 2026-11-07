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

namespace mod_scormmakercmi5\local\lrs;

/**
 * The xAPI Activity Profile resource, read only and always empty (design §3.12, §7.1): the AU only reads it (R5.1)
 * and nothing in the LMS writes one, so there is no table.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_profile {
    /**
     * GET activities/profile?activityId=[&profileId=][&since=]: 404 for one document, [] for the list.
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response
     * @throws lrs_exception 400 bad parameters, 403 another activity, 404 with profileId.
     */
    public static function get_profile(request $r, session_context $ctx): response {
        $activityid = documents::string_param($r, 'activityId', true);
        $ctx->assert_scope(null, $activityid, null);
        $profileid = documents::string_param($r, 'profileId', false, documents::MAX_PROFILEID);
        if ($profileid !== null) {
            throw new lrs_exception(404, 'err_lrsnodoc');
        }
        documents::since_param($r);
        return response::json(200, []);
    }
}
