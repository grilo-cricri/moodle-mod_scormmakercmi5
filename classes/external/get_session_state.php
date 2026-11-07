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

namespace mod_scormmakercmi5\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\runtime\launcher;

/**
 * Whether a launch session of the current user is still open, for the player page (design §6.1 step 9, §6.3).
 *
 * A relaunch from another window or tab abandons every open session of the registration (cmi5 §9.3.6), and the
 * cleanup task abandons idle ones: the player asks this so it can replace a dead AU with a clear message.
 * Only a session of the current user in this activity can be active; any other uuid answers false, so the answer
 * says nothing about sessions of other users. It also says whether the registration of the session reached
 * maxstatementsperreg (statementlimit), so the player can tell the learner that nothing more is recorded.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_session_state extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the activity'),
            'sessionuuid' => new external_value(PARAM_ALPHANUMEXT, 'cmi5 session id'),
        ]);
    }

    /**
     * Whether the session is launched or initialized, and whether its registration reached the statement limit.
     *
     * @param int $cmid Course module id.
     * @param string $sessionuuid Session uuid.
     * @return array ['active' => bool, 'statementlimit' => bool]
     */
    public static function execute(int $cmid, string $sessionuuid): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'sessionuuid' => $sessionuuid]);
        [, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'scormmakercmi5');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/scormmakercmi5:view', $context);

        $session = $DB->get_record('scormmakercmi5_session', [
            'sessionuuid' => $params['sessionuuid'],
            'userid' => $USER->id,
            'kind' => launcher::KIND_LAUNCH,
        ], 'id, regid, status');
        $count = $session ? $DB->get_field(
            'scormmakercmi5_reg',
            'statementcount',
            ['id' => $session->regid, 'scormmakercmi5id' => $cm->instance]
        ) : false;
        $active = $count !== false
            && in_array((int)$session->status, [launcher::STATUS_LAUNCHED, launcher::STATUS_INITIALIZED], true);
        return [
            'active' => $active,
            'statementlimit' => $count !== false && limits::statement_limit_reached((int)$count),
        ];
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'active' => new external_value(PARAM_BOOL, 'Whether the session is still open'),
            'statementlimit' => new external_value(PARAM_BOOL, 'Whether the registration reached the statement limit'),
        ]);
    }
}
