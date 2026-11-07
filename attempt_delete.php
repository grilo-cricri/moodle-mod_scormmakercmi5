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
 * Deletes the attempt of a learner (design §2.2, §11): GET asks for confirmation, the POST of the confirmation (with
 * the sesskey) deletes and goes back to the learner's report.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_scormmakercmi5\local\runtime\attempt_manager;

$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'scormmakercmi5');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability(attempt_manager::CAPABILITY, $context);

$instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);
// The group is checked before the name is shown, on GET too; without an attempt the page names nobody.
attempt_manager::check_access($instance, $cm, $userid);
$hasattempt = $DB->record_exists('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $userid]);
$user = $hasattempt ? core_user::get_user($userid, '*', MUST_EXIST) : null;

$url = new moodle_url('/mod/scormmakercmi5/attempt_delete.php', ['id' => $cm->id, 'userid' => $userid]);
$returnurl = attempt_manager::return_url($cm, $userid);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
$PAGE->set_activity_record($instance);
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);
$learner = $user ? s(fullname($user)) : '';

if (data_submitted()) {
    require_sesskey();
    try {
        attempt_manager::delete_attempt($instance, $cm, $userid);
    } catch (moodle_exception $e) {
        // No attempt, another group, attempt busy: back with the reason.
        if ($e->module !== 'mod_scormmakercmi5') {
            throw $e;
        }
        redirect($returnurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $message = get_string('attemptdeleted', 'mod_scormmakercmi5', $learner);
    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deleteattempt', 'mod_scormmakercmi5'), 3);
if (!$hasattempt) {
    echo $OUTPUT->notification(get_string('err_noattempt', 'mod_scormmakercmi5'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->continue_button($returnurl);
} else {
    $confirm = new single_button($url, get_string('deleteattempt', 'mod_scormmakercmi5'), 'post', single_button::BUTTON_DANGER);
    echo $OUTPUT->confirm(get_string('deleteattemptconfirm', 'mod_scormmakercmi5', $learner), $confirm, $returnurl);
}
echo $OUTPUT->footer();
