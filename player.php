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
 * Player of an AnyWindow assignable unit (design §2.2, §6.1 step 9, §13; R4.2, NF5): the AU in an iframe with a
 * full screen button and a link back to the activity.
 *
 * The launch URL is used once: a reload, a session of another user or activity, or a session that is no longer
 * launched shows "This session has ended" instead (a second load of the AU would fetch with a used key). While the
 * AU runs, the page asks whether its session is still open, so an AU abandoned by a launch from another window or
 * by the idle timeout is replaced by the same message.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\runtime\launcher;

$id = required_param('id', PARAM_INT);
$sessionuuid = required_param('s', PARAM_ALPHANUMEXT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'scormmakercmi5');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scormmakercmi5:view', $context);

$instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);
$viewurl = new moodle_url('/mod/scormmakercmi5/view.php', ['id' => $cm->id]);
// Every guest is the same user, so a guest would share one registration, State and Agent Profile (design §9).
if (isguestuser()) {
    redirect($viewurl, get_string('err_guest', 'mod_scormmakercmi5'), null, \core\output\notification::NOTIFY_ERROR);
}

$PAGE->set_url(new moodle_url('/mod/scormmakercmi5/player.php', ['id' => $cm->id, 's' => $sessionuuid]));
$PAGE->set_title(format_string($instance->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
$PAGE->set_activity_record($instance);
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);

// Only a launch session of this user in this activity is playable; anything else looks like an ended session.
$session = $DB->get_record('scormmakercmi5_session', [
    'sessionuuid' => $sessionuuid,
    'userid' => $USER->id,
    'kind' => launcher::KIND_LAUNCH,
]);
if ($session && !$DB->record_exists('scormmakercmi5_reg', ['id' => $session->regid, 'scormmakercmi5id' => $instance->id])) {
    $session = false;
}
$url = null;
if ($session) {
    $url = $SESSION->mod_scormmakercmi5_launch[$sessionuuid] ?? null;
    unset($SESSION->mod_scormmakercmi5_launch[$sessionuuid]);
}

/** @var \mod_scormmakercmi5\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_scormmakercmi5');

if ($url === null || (int)$session->status !== launcher::STATUS_LAUNCHED) {
    echo $OUTPUT->header();
    echo $renderer->session_ended($viewurl);
    echo $OUTPUT->footer();
    die();
}

$au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], 'id, title, auorigin', MUST_EXIST);
$PAGE->requires->js_call_amd('mod_scormmakercmi5/player', 'init', [
    '[data-region="mod_scormmakercmi5-fullscreen"]',
    '[data-region="mod_scormmakercmi5-frame"]',
]);
// The link back asks the AU to close first, so its last statements are not lost to the page leaving (final review I-1).
$PAGE->requires->js_call_amd('mod_scormmakercmi5/player', 'backLink', [
    '[data-region="mod_scormmakercmi5-back"]',
    '[data-region="mod_scormmakercmi5-back-status"]',
    '[data-region="mod_scormmakercmi5-frame"]',
]);
$PAGE->requires->js_call_amd('mod_scormmakercmi5/player', 'watchSession', [
    '[data-region="mod_scormmakercmi5-frame"]',
    (int)$cm->id,
    $sessionuuid,
    $viewurl->out(false),
]);

echo $OUTPUT->header();
$count = (int)$DB->get_field('scormmakercmi5_reg', 'statementcount', ['id' => $session->regid], MUST_EXIST);
echo $renderer->player($au, $url, $viewurl, limits::statement_limit_reached($count));
echo $OUTPUT->footer();
