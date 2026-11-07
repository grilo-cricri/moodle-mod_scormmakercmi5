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
 * Waives an assignable unit of a learner (design §2.2, §6.5, §17 Q3; R4.8): GET shows the confirmation form with the
 * reason, its POST (with the sesskey of the form) waives and goes back to the learner's report.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_scormmakercmi5\form\waive_form;
use mod_scormmakercmi5\local\package\course_structure;
use mod_scormmakercmi5\local\runtime\attempt_manager;

$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$auid = required_param('au', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'scormmakercmi5');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability(attempt_manager::CAPABILITY, $context);

$instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);
// The group and the learner are checked before the name is shown, on GET too.
attempt_manager::check_access($instance, $cm, $userid);
if (!attempt_manager::is_learner($instance, $context, $userid)) {
    throw new moodle_exception('err_notlearner', 'mod_scormmakercmi5');
}
$user = core_user::get_user($userid, '*', MUST_EXIST);
$au = $DB->get_record('scormmakercmi5_au', ['id' => $auid], '*', MUST_EXIST);
if (!$DB->record_exists('scormmakercmi5_pkg', ['id' => $au->pkgid, 'scormmakercmi5id' => $instance->id])) {
    throw new moodle_exception('err_aunotinpkg', 'mod_scormmakercmi5');
}

$url = new moodle_url('/mod/scormmakercmi5/waive.php', ['id' => $cm->id, 'userid' => $userid, 'au' => $auid]);
$returnurl = attempt_manager::return_url($cm, $userid);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
$PAGE->set_activity_record($instance);
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);

$titles = json_decode((string)$au->title, true);
$unit = course_structure::pick(is_array($titles) ? $titles : [], current_language());
$form = new waive_form($url, ['learner' => s(fullname($user)), 'unit' => s($unit)]);
$form->set_data(['id' => $cm->id, 'userid' => $userid, 'au' => $auid]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    try {
        attempt_manager::waive($instance, $cm, $userid, $auid, (string)$data->reason);
    } catch (moodle_exception $e) {
        // Already waived or satisfied, not a learner, another group, attempt busy: back with the reason.
        if ($e->module !== 'mod_scormmakercmi5') {
            throw $e;
        }
        redirect($returnurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($returnurl, get_string('waived', 'mod_scormmakercmi5', s($unit)), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('waiveau', 'mod_scormmakercmi5'), 3);
$form->display();
echo $OUTPUT->footer();
