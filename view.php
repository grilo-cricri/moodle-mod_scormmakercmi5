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
 * Activity page: description (activity header), status of the course, assignable units in file order with their
 * status and launch buttons (R4.6) and, for those who see reports, the verification of the package and the
 * slasharguments warning (design §2.2, §13).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_scormmakercmi5\local\runtime\launcher;
use mod_scormmakercmi5\local\runtime\player_return;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'scormmakercmi5');
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scormmakercmi5:view', $context);

$instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);

// An AU going to its returnURL inside the player loads this page in the iframe just before the player takes the
// whole window here: only the top-level load is logged.
if (!player_return::is_current_request()) {
    $event = \mod_scormmakercmi5\event\course_module_viewed::create([
        'objectid' => $instance->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('scormmakercmi5', $instance);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
    // A grade or a completion left behind by an update that failed after the commit is repaired here (design §6.6):
    // the grade first, because a completion rule may depend on it. A failed grade repair never stops the page.
    try {
        \mod_scormmakercmi5\local\grading\grade_calculator::repair_user($instance, (int)$USER->id);
    } catch (\Throwable $e) {
        \mod_scormmakercmi5\local\lrs\endpoint::log_failure('view grade', $e);
    }
    \mod_scormmakercmi5\completion\custom_completion::repair_user($cm, (int)$USER->id);
}

$PAGE->set_url(new moodle_url('/mod/scormmakercmi5/view.php', ['id' => $cm->id]));
$PAGE->set_title(format_string($instance->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
$PAGE->set_activity_record($instance);

// A learner sees the version of their registration (R1.5); everyone else the current one.
$reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $USER->id]) ?: null;
$pkgid = $reg ? (int)$reg->pkgid : (int)$instance->currentpkgid;
$aus = $DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder, id', 'id, title, sortorder, launchmethod');
$regaus = [];
$launched = [];
if ($reg) {
    foreach ($DB->get_records('scormmakercmi5_regau', ['regid' => $reg->id]) as $regau) {
        $regaus[$regau->auid] = $regau;
    }
    // Launch sessions only: a synthetic session (waived, satisfied at registration) is not a launch by the learner.
    $sessions = $DB->get_records(
        'scormmakercmi5_session',
        ['regid' => $reg->id, 'kind' => launcher::KIND_LAUNCH],
        '',
        'DISTINCT auid'
    );
    foreach ($sessions as $session) {
        $launched[$session->auid] = true;
    }
}

/** @var \mod_scormmakercmi5\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_scormmakercmi5');

echo $OUTPUT->header();
if (has_capability('mod/scormmakercmi5:viewreports', $context)) {
    if (empty($CFG->slasharguments)) {
        echo $OUTPUT->notification(get_string('slashargumentswarning', 'mod_scormmakercmi5'), 'warning');
    }
    $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], 'id, sigstatus, sigreason, recheckstatus');
    if ($pkg) {
        echo $renderer->verification_notice($pkg);
        $origins = $DB->get_fieldset_select(
            'scormmakercmi5_au',
            'DISTINCT auorigin',
            'pkgid = :pkgid AND auorigin IS NOT NULL',
            ['pkgid' => $pkg->id]
        );
        echo $renderer->external_au_notice($origins);
        echo $renderer->rich_report_link($cm, $pkg);
    }
}
// AUs outside the package: the learner is told before starting, as an OwnWindow AU leaves Moodle at once (those who
// see the reports already got externalaunotice above).
if (!has_capability('mod/scormmakercmi5:viewreports', $context)) {
    $origins = $DB->get_fieldset_select(
        'scormmakercmi5_au',
        'DISTINCT auorigin',
        'pkgid = :pkgid AND auorigin IS NOT NULL',
        ['pkgid' => $reg ? $reg->pkgid : $instance->currentpkgid]
    );
    echo $renderer->external_au_learner_notice($origins);
}
// A guest has no registration of their own (every guest is the same user): no launch (design §9).
$canlaunch = !isguestuser();
if (!$canlaunch) {
    echo $OUTPUT->notification(get_string('err_guest', 'mod_scormmakercmi5'), \core\output\notification::NOTIFY_INFO);
}
// The AU's statements are refused once the registration reached the limit (final review N-2): say so.
if ($reg && \mod_scormmakercmi5\local\limits::statement_limit_reached((int)$reg->statementcount)) {
    echo $OUTPUT->notification(get_string('statementlimitlearner', 'mod_scormmakercmi5'), 'warning');
}
echo $renderer->view_aus($cm, $instance, array_values($aus), $reg, $regaus, $launched, $canlaunch);
echo $OUTPUT->footer();
