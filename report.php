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
 * Reports of the activity (R7, R8, design §8): the learners (mode=basic, with the status and group filters and the
 * download), the detail of one learner (mode=user, with the links to waive an AU and delete the attempt), the
 * statements of one learner (mode=statements) and the rich report of a SCORM Maker package (mode=rich, per activity
 * or per learner with userid, per package version). The groups of the activity apply to every mode.
 *
 * mode=rich checks the signature and the stored files at every opening (verifier::evaluate, design §5.4, R2.4), without
 * writing anything: the verdict is recorded by the scheduled task recheck_current_packages. Anything but "verified"
 * shows the basic report with the reason (no data is touched).
 * Under the rich report comes the activity inside the lessons (subproject D, design §8.3): only for a package
 * verified at this opening with an analysis structure (block_report::structure), else "unavailable"; per learner
 * only the summary, read through the registration resolved here after the group checks. Its aggregates download as
 * nine tables (blocks=1&download=, block_export): excel/ods one sheet per table, csv a zip of one file per table, with
 * the checks of the page.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_scormmakercmi5\local\report\basic_table;
use mod_scormmakercmi5\local\report\block_export;
use mod_scormmakercmi5\local\report\block_report;
use mod_scormmakercmi5\local\report\group_filter;
use mod_scormmakercmi5\local\report\rich_report;
use mod_scormmakercmi5\local\report\status;
use mod_scormmakercmi5\local\report\user_statements_table;
use mod_scormmakercmi5\local\runtime\attempt_manager;
use mod_scormmakercmi5\local\runtime\launcher;
use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'basic', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$statusfilter = optional_param('status', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$version = optional_param('version', '', PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'scormmakercmi5');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/scormmakercmi5:viewreports', $context);
$instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);

if (!in_array($mode, ['basic', 'rich', 'user', 'statements'], true)) {
    $mode = 'basic';
}
// The version is an allowlist: only a version with statements in this activity is accepted (R8.2).
$versions = $mode === 'rich' ? rich_report::versions((int)$instance->id) : [];
$defaultversion = '';
if ($mode === 'rich') {
    $current = $DB->get_field('scormmakercmi5_pkg', 'smversion', ['id' => $instance->currentpkgid]);
    $defaultversion = ($current !== false && $current !== null && array_key_exists($current, $versions))
        ? (string)$current : (string)(array_key_first($versions) ?? '');
    if (!array_key_exists($version, $versions)) {
        $version = $defaultversion;
    }
}
$learnermode = in_array($mode, ['user', 'statements'], true) || ($mode === 'rich' && $userid > 0);

$params = ['id' => $cm->id];
if ($mode === 'rich') {
    $params += ['mode' => 'rich'] + ($version !== '' ? ['version' => $version] : []) + ($userid > 0 ? ['userid' => $userid] : []);
} else if ($mode !== 'basic') {
    $params += ['mode' => $mode, 'userid' => $userid];
} else if (in_array($statusfilter, basic_table::STATUS_FILTERS, true) && $statusfilter !== '') {
    $params['status'] = $statusfilter;
}
$url = new moodle_url('/mod/scormmakercmi5/report.php', $params);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => context_course::instance($course->id)]));
$PAGE->set_activity_record($instance);
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);
// The "Reports" tab of the secondary navigation stays active in every mode.
navigation_node::override_active_url(new moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id]));

$user = null;
$reg = null;
if ($learnermode) {
    // A learner outside the groups of the viewer is refused, and an id of any other user of the site names nobody.
    if (!groups_user_groups_visible($course, $userid, $cm)) {
        throw new required_capability_exception($context, 'moodle/site:accessallgroups', 'nopermissions', '');
    }
    if (!attempt_manager::is_learner($instance, $context, $userid)) {
        throw new moodle_exception('err_notlearner', 'mod_scormmakercmi5');
    }
    $user = core_user::get_user($userid, '*', MUST_EXIST);
    $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $userid]) ?: null;
}

// Rich report: check the package of the version at every opening (R2.4), read-only: a GET records nothing (the
// scheduled task recheck_current_packages does); anything else shows the basic report.
$verification = null;
if ($mode === 'rich') {
    $pkg = $version !== '' ? rich_report::package_for_version((int)$instance->id, $version) : null;
    $pkg = $pkg ?: $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
    $verification = verifier::evaluate($pkg, keyring::from_config());
}
$richok = $verification && $verification->status === verification_result::STATUS_VERIFIED;

$event = \mod_scormmakercmi5\event\report_viewed::create([
    'context' => $context,
    'relateduserid' => $user ? (int)$user->id : null,
    'other' => ['mode' => $mode],
]);
$event->trigger();

// Detailed download of the block activity (nine tables), as the download of the basic report: after the checks and the
// event above, before any output. Only csv/excel/ods, only the activity view of a version, and block_export::sheet()
// gives nothing unless the package was verified at this opening and has a structure (then the page is shown instead).
if (
    $mode === 'rich' && $richok && !$learnermode && $versions && optional_param('blocks', 0, PARAM_BOOL)
        && block_export::accepts($download)
) {
    $tables = block_export::sheet($cm, $pkg, $verification, $version, (int)groups_get_activity_group($cm, true));
    if ($tables !== null) {
        // Unescaped: the file name is plain text (clean_filename), never HTML.
        $filename = clean_filename(format_string($instance->name, true, ['context' => $context, 'escape' => false]) . '-' .
            get_string('richblocks', 'mod_scormmakercmi5'));
        block_export::download_tables($filename, $download, $tables);
        die();
    }
}

/** @var \mod_scormmakercmi5\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_scormmakercmi5');

if ($mode === 'basic' || ($mode === 'rich' && !$richok)) {
    // The basic report, also as the fallback of the rich one: its filters, paging and download go to mode=basic.
    $basicparams = ['id' => $cm->id];
    if ($statusfilter !== '' && in_array($statusfilter, basic_table::STATUS_FILTERS, true)) {
        $basicparams['status'] = $statusfilter;
    }
    $basicurl = new moodle_url('/mod/scormmakercmi5/report.php', $basicparams);
    $groupid = (int)groups_get_activity_group($cm, true);
    $aus = $DB->get_records('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], 'sortorder, id', 'id, title, lmsactivityid');
    $tableid = 'mod_scormmakercmi5-report-' . $cm->id;
    $table = new basic_table($tableid, $cm, $instance, array_values($aus), $groupid, $statusfilter);
    $table->define_baseurl($basicurl);
    if ($mode === 'basic' && $download !== '' && in_array($download, basic_table::download_formats(), true)) {
        // Unescaped: the file name is plain text (clean_filename), never HTML.
        $filename = clean_filename(format_string($instance->name, true, ['context' => $context, 'escape' => false]) . '-' .
            get_string('report'));
        $table->is_downloading($download, $filename, get_string('report'));
        $table->out(0, false);
        die();
    }

    echo $OUTPUT->header();
    if ($verification) {
        // Another version than the default failed: offer the way back to the default one.
        $currenturl = $version !== $defaultversion
            ? new moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id, 'mode' => 'rich']) : null;
        echo $renderer->rich_unavailable($verification, $currenturl);
    } else {
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], 'id, sigstatus, recheckstatus');
        echo $pkg ? $renderer->rich_report_link($cm, $pkg) : '';
    }
    echo $OUTPUT->heading(get_string('reportlearners', 'mod_scormmakercmi5'), 3);
    echo html_writer::start_div('d-flex flex-wrap align-items-center mod-scormmakercmi5-reportfilters');
    groups_print_activity_menu($cm, $basicurl);
    $options = ['' => get_string('reportstatusall', 'mod_scormmakercmi5')];
    foreach (array_filter(basic_table::STATUS_FILTERS) as $state) {
        $options[$state] = status::label($state);
    }
    $filterurl = new moodle_url($basicurl);
    $filterurl->remove_params('status');
    $selected = $table->get_status_filter();
    $select = new single_select($filterurl, 'status', $options, $selected, null, 'mod_scormmakercmi5-statusfilter');
    $select->set_label(get_string('reportstatusfilter', 'mod_scormmakercmi5'));
    echo $OUTPUT->render($select);
    echo html_writer::end_div();
    // No initials bar: its letters are smaller than the 24 px touch target of WCAG 2.2 (axe target-size).
    $table->out(50, false);
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
if ($mode === 'rich') {
    echo $OUTPUT->heading(get_string('reportrich', 'mod_scormmakercmi5'), 2);
    if ($verification->deferred) {
        $deferred = get_string('richdeferred', 'mod_scormmakercmi5', userdate((int)$verification->timefullcheck));
        echo $OUTPUT->notification($deferred, \core\output\notification::NOTIFY_INFO);
    }
    if (!$versions) {
        echo $OUTPUT->notification(get_string('richnoversion', 'mod_scormmakercmi5'), \core\output\notification::NOTIFY_INFO);
    } else {
        echo html_writer::start_div('d-flex flex-wrap align-items-center mod-scormmakercmi5-reportfilters');
        $options = [];
        foreach ($versions as $value => $time) {
            $label = (object)['version' => (string)$value, 'date' => userdate($time, get_string('strftimedatetimeshort'))];
            $options[(string)$value] = get_string('richversionoption', 'mod_scormmakercmi5', $label);
        }
        $versionurl = new moodle_url($url);
        $versionurl->remove_params('version', 'page');
        $select = new single_select($versionurl, 'version', $options, $version, null, 'mod_scormmakercmi5-version');
        $select->set_label(get_string('richversion', 'mod_scormmakercmi5'));
        echo $OUTPUT->render($select);
        if (!$learnermode) {
            groups_print_activity_menu($cm, $url);
        }
        echo html_writer::end_div();
        if ($learnermode) {
            $backurl = new moodle_url($url);
            $backurl->remove_params('userid');
            $viewfullnames = has_capability('moodle/site:viewfullnames', $context);
            echo $renderer->rich_learner($user, $reg, $version, $backurl, $viewfullnames);
            // Activity inside the lessons (subproject D): the $reg resolved above by instance and user, after the
            // group checks; only verified packages with an analysis structure, the summary only.
            if ($reg) {
                echo $renderer->rich_blocks_learner(block_report::structure($pkg, $verification), $reg, $version);
            }
        } else {
            $filter = group_filter::from_restriction(
                basic_table::restrict_groups($cm, $context, (int)groups_get_activity_group($cm, true))
            );
            echo $renderer->rich_activity($cm, (int)$instance->id, $version, $filter, $page, $url);
            echo $renderer->rich_blocks($cm, $pkg, block_report::structure($pkg, $verification), $version, $filter, $url);
        }
    }
} else if ($mode === 'user') {
    $pkgid = $reg ? (int)$reg->pkgid : (int)$instance->currentpkgid;
    $aus = $DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder, id', 'id, title');
    $regaus = [];
    $launched = [];
    if ($reg) {
        foreach ($DB->get_records('scormmakercmi5_regau', ['regid' => $reg->id]) as $regau) {
            $regaus[$regau->auid] = $regau;
        }
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
    $canmanage = has_capability(attempt_manager::CAPABILITY, $context);
    echo $renderer->report_user($cm, $instance, $user, array_values($aus), $reg, $regaus, $launched, $canmanage);
} else {
    $learner = fullname($user, has_capability('moodle/site:viewfullnames', $context));
    $title = get_string('reportstatementsof', 'mod_scormmakercmi5', s($learner));
    echo $OUTPUT->heading($title, 3);
    if (!$reg) {
        echo $OUTPUT->notification(get_string('err_noattempt', 'mod_scormmakercmi5'), \core\output\notification::NOTIFY_INFO);
    } else {
        $table = new user_statements_table('mod_scormmakercmi5-statements-' . $cm->id, (int)$reg->id);
        $table->define_baseurl($url);
        // The caption goes out as HTML: the name is already escaped in $title.
        $table->set_caption($title, ['class' => 'accesshide']);
        $table->out(50, false);
    }
    $back = new moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id, 'mode' => 'user', 'userid' => $userid]);
    echo html_writer::link($back, get_string('reportbacktolearner', 'mod_scormmakercmi5'), ['class' => 'btn btn-link']);
}
echo $OUTPUT->footer();
