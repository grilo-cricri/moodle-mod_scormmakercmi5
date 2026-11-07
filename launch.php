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
 * Launches an assignable unit (design §2.2, §6.1; R4.1, R4.2, R4.7): POST with sesskey from view.php, then
 * post/redirect/get to the player (AnyWindow) or straight to the AU (OwnWindow, in the window the form opened).
 *
 * The launch URL carries the one-time fetch key: for AnyWindow it is kept in $SESSION only until player.php
 * consumes it, so it never appears in a Moodle URL, in the logs or in the browser history of the activity.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_scormmakercmi5\local\runtime\launcher;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'scormmakercmi5');
// With the cm, require_login() also refuses an activity hidden from the user or outside its access restrictions.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scormmakercmi5:view', $context);

$viewurl = new moodle_url('/mod/scormmakercmi5/view.php', ['id' => $cm->id]);
// Every guest is the same user, so a guest would share one registration, State and Agent Profile (design §9).
if (isguestuser()) {
    redirect($viewurl, get_string('err_guest', 'mod_scormmakercmi5'), null, \core\output\notification::NOTIFY_ERROR);
}
$PAGE->set_url(new moodle_url('/mod/scormmakercmi5/launch.php', ['id' => $cm->id]));

// A launch changes state: only a POST from the activity page launches, a GET (a bookmark, the back button) goes there.
if (!data_submitted()) {
    redirect($viewurl);
}
require_sesskey();
$auid = required_param('au', PARAM_INT);
$mode = required_param('mode', PARAM_ALPHA);

$instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);
try {
    $result = launcher::launch($cm, $instance, $auid, (int)$USER->id, $mode);
} catch (moodle_exception $e) {
    // Mode not offered, AU of another version, lock busy: back to the activity with the reason.
    if ($e->module !== 'mod_scormmakercmi5') {
        throw $e;
    }
    redirect($viewurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

$event = \mod_scormmakercmi5\event\au_launched::create([
    'objectid' => $result->session->id,
    'context' => $context,
    'other' => ['auid' => (int)$result->session->auid, 'launchmode' => $result->session->launchmode],
]);
$event->add_record_snapshot('scormmakercmi5', $instance);
$event->trigger();

if ($result->launchmethod === 'OwnWindow') {
    redirect($result->url);
}

// AnyWindow: the player shows the AU. A relaunch abandons the older sessions of the registration, so only the
// last few pending launches (several activities opened at once) are kept.
$uuid = $result->session->sessionuuid;
$pending = (array)($SESSION->mod_scormmakercmi5_launch ?? []);
$pending = array_slice($pending, -4, null, true);
$pending[$uuid] = $result->url->out(false);
$SESSION->mod_scormmakercmi5_launch = $pending;
redirect(new moodle_url('/mod/scormmakercmi5/player.php', ['id' => $cm->id, 's' => $uuid]));
