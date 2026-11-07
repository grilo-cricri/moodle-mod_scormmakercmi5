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
 * Library of interface functions for mod_scormmakercmi5.
 *
 * Only the callbacks live here; the logic is in local\instance_manager (instance lifecycle) and
 * local\package\content_access (file access), local\grading\grade_calculator (grades) and completion\custom_completion.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_scormmakercmi5\completion\custom_completion;
use mod_scormmakercmi5\local\grading\grade_calculator;
use mod_scormmakercmi5\local\instance_manager;
use mod_scormmakercmi5\local\package\content_access;
use mod_scormmakercmi5\local\package\importer;
use mod_scormmakercmi5\local\runtime\attempt_manager;

/**
 * Declares the features this module supports.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True or false for boolean features, a value for others, null when unknown.
 */
function scormmakercmi5_supports(string $feature): mixed {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Tells Moodle the activity icon is branded: pix/monologo.svg keeps its own colors instead of being recolored by the
 * activity purpose (Moodle 4.x/5.x, `<modname>_is_branded`).
 *
 * @return bool Always true.
 */
function scormmakercmi5_is_branded(): bool {
    return true;
}

/**
 * Creates a new instance from the package in the draft area $data->packagefile (design §4.1, R1.1).
 *
 * @param stdClass $data Form data.
 * @param mixed $mform The form, when called from it.
 * @return int The new instance id.
 */
function scormmakercmi5_add_instance(stdClass $data, $mform = null): int {
    return instance_manager::add($data);
}

/**
 * Updates an instance; a different zip in $data->packagefile becomes a new package version (R1.5).
 *
 * @param stdClass $data Form data, with instance holding the id.
 * @param mixed $mform The form, when called from it.
 * @return bool
 */
function scormmakercmi5_update_instance(stdClass $data, $mform = null): bool {
    return instance_manager::update($data);
}

/**
 * Deletes an instance with all its package versions, learner data, files and grade item.
 *
 * @param int $id Instance id.
 * @return bool
 */
function scormmakercmi5_delete_instance(int $id): bool {
    return instance_manager::delete($id);
}

/**
 * Serves the files of the module (design §10.1).
 *
 * content/<pkgid>/<path>: the extracted package, to users with view, when pkgid is a version of this instance; the
 * AU resolves its relative assets against this URL, so slasharguments must be on. The content of a pkg id never
 * changes, hence the long cache. Framing is limited to this site unless the site allows being framed.
 * package/<pkgid>/<zip>: the original zip, to those who manage activities.
 *
 * @param stdClass $course Course.
 * @param stdClass $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Item id followed by the path.
 * @param bool $forcedownload Whether to force the download.
 * @param array $options Options for send_stored_file().
 * @return bool False when the file is not served (the caller answers 404); otherwise it does not return.
 */
function scormmakercmi5_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG, $USER;
    if ($context->contextlevel != CONTEXT_MODULE || count($args) < 2) {
        return false;
    }
    require_course_login($course, true, $cm);

    $itemid = array_shift($args);
    if (!ctype_digit((string)$itemid)) {
        return false;
    }
    $itemid = (int)$itemid;
    if (!content_access::can_serve($cm, $context, (string)$filearea, $itemid, (int)$USER->id)) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, importer::COMPONENT, $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    if ($filearea === importer::AREA_CONTENT) {
        if (empty($CFG->allowframembedding)) {
            header('X-Frame-Options: SAMEORIGIN');
            header("Content-Security-Policy: frame-ancestors 'self'");
        }
        send_stored_file($file, 31536000, 0, false, $options);
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Creates or updates the grade item of the instance (design §6.6): points when grade > 0, none when 0, a scale when
 * < 0 (the form refuses a scale; an instance created otherwise keeps its item, with no automatic grade).
 *
 * @param stdClass $instance Instance row; cmidnumber, when present, becomes the item idnumber.
 * @param mixed $grades Grades (object or array of objects with userid and rawgrade), 'reset', or null.
 * @return int GRADE_UPDATE_OK or an error code.
 */
function scormmakercmi5_grade_item_update(stdClass $instance, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $params = ['itemname' => clean_param($instance->name, PARAM_NOTAGS)];
    if (isset($instance->cmidnumber)) {
        $params['idnumber'] = $instance->cmidnumber;
    }
    if ((int)$instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = (int)$instance->grade;
        $params['grademin'] = 0;
    } else if ((int)$instance->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -(int)$instance->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update('mod/scormmakercmi5', $instance->course, 'mod', 'scormmakercmi5', $instance->id, 0, $grades, $params);
}

/**
 * Recomputes the grades from the stored AU scores and sends them to the gradebook (design §6.6).
 *
 * @param stdClass $instance Instance row.
 * @param int $userid One learner, or 0 for all.
 * @param bool $nullifnone Unused: a learner without a score always gets a null grade.
 */
function scormmakercmi5_update_grades(stdClass $instance, int $userid = 0, bool $nullifnone = true): void {
    grade_calculator::update($instance, $userid);
}

/**
 * The grades of the instance, computed from the stored AU scores.
 *
 * @param stdClass $instance Instance row.
 * @param int $userid One learner, or 0 for all.
 * @return array userid => object with userid and rawgrade.
 */
function scormmakercmi5_get_user_grades(stdClass $instance, int $userid = 0): array {
    return grade_calculator::user_grades($instance, $userid);
}

/**
 * Rescales the grades after the maximum grade changed: they are recomputed from the AU scores.
 *
 * @param stdClass $course Course.
 * @param stdClass $cm Course module.
 * @param float $oldmin Old minimum.
 * @param float $oldmax Old maximum.
 * @param float $newmin New minimum.
 * @param float $newmax New maximum.
 * @return bool
 */
function scormmakercmi5_rescale_activity_grades($course, $cm, $oldmin, $oldmax, $newmin, $newmax): bool {
    global $DB;
    $instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);
    $instance->cmidnumber = $cm->idnumber;
    grade_calculator::update($instance);
    return true;
}

/**
 * The option of this module in the course reset form.
 *
 * @param MoodleQuickForm $mform Reset form.
 */
function scormmakercmi5_reset_course_form_definition(&$mform): void {
    $mform->addElement('header', 'scormmakercmi5header', get_string('modulenameplural', 'mod_scormmakercmi5'));
    $mform->addElement('advcheckbox', 'reset_scormmakercmi5', get_string('resetattempts', 'mod_scormmakercmi5'));
}

/**
 * Defaults of the course reset form.
 *
 * @param stdClass $course Course.
 * @return array
 */
function scormmakercmi5_reset_course_form_defaults($course): array {
    return ['reset_scormmakercmi5' => 1];
}

/**
 * Removes the grades of every instance of a course from the gradebook (course reset, grade_course_reset()).
 *
 * @param int $courseid Course.
 * @param string $type Unused (one grade item per instance).
 */
function scormmakercmi5_reset_gradebook($courseid, $type = ''): void {
    global $DB;
    $sql = "SELECT s.*, cm.idnumber AS cmidnumber
              FROM {scormmakercmi5} s
              JOIN {course_modules} cm ON cm.instance = s.id
              JOIN {modules} m ON m.id = cm.module AND m.name = :modname
             WHERE s.course = :courseid";
    $instances = $DB->get_records_sql($sql, ['modname' => 'scormmakercmi5', 'courseid' => $courseid]);
    foreach ($instances as $instance) {
        scormmakercmi5_grade_item_update($instance, 'reset');
    }
}

/**
 * Course reset: with "reset_scormmakercmi5", deletes the attempts of every learner in every instance of the course
 * (the cascade of "Delete attempt", attempt_manager::reset_instance()) and their grades; the learner preferences
 * (Agent Profile) belong to the user and stay.
 *
 * @param stdClass $data Data of the reset form (courseid, reset_scormmakercmi5, reset_gradebook_grades).
 * @return array Status rows: component, item, error.
 */
function scormmakercmi5_reset_userdata($data): array {
    global $DB;
    if (empty($data->reset_scormmakercmi5)) {
        return [];
    }
    $busy = 0;
    $survivors = [];
    foreach ($DB->get_records('scormmakercmi5', ['course' => $data->courseid]) as $instance) {
        $skipped = attempt_manager::reset_instance($instance);
        $busy += $skipped;
        if ($skipped) {
            $survivors[] = $instance;
        }
    }
    // The attempts are gone, so are their grades; with "delete all grades" core already resets the gradebook, then
    // grabs the grades again through scormmakercmi5_update_grades(). An attempt skipped because its lock was busy
    // survives, and so must its grade (final review N-3): the instances with one are graded again.
    if (empty($data->reset_gradebook_grades)) {
        scormmakercmi5_reset_gradebook($data->courseid);
        foreach ($survivors as $instance) {
            try {
                grade_calculator::update($instance);
            } catch (\Throwable $e) {
                \mod_scormmakercmi5\local\lrs\endpoint::log_failure('reset grade', $e);
            }
        }
    }
    return [[
        'component' => get_string('modulenameplural', 'mod_scormmakercmi5'),
        'item' => get_string('resetattempts', 'mod_scormmakercmi5'),
        'error' => $busy ? get_string('err_resetbusy', 'mod_scormmakercmi5', $busy) : false,
    ]];
}

/**
 * Cached course module information: the custom completion rule, when completion is automatic.
 *
 * @param stdClass $coursemodule Course module.
 * @return cached_cm_info|false
 */
function scormmakercmi5_get_coursemodule_info($coursemodule) {
    global $DB;
    $fields = 'id, name, intro, introformat, completionsatisfied';
    $instance = $DB->get_record('scormmakercmi5', ['id' => $coursemodule->instance], $fields);
    if (!$instance) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('scormmakercmi5', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules'][custom_completion::RULE] = (int)$instance->completionsatisfied;
    }
    return $info;
}

/**
 * Descriptions of the active custom completion rules (activity completion settings pages).
 *
 * @param cm_info|stdClass $cm Course module with completion and customdata.
 * @return string[]
 */
function mod_scormmakercmi5_get_completion_active_rule_descriptions($cm): array {
    if (empty($cm->customdata['customcompletionrules']) || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }
    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $rule => $value) {
        if ($rule === custom_completion::RULE && !empty($value)) {
            $descriptions[] = get_string('completionsatisfied', 'mod_scormmakercmi5');
        }
    }
    return $descriptions;
}

/**
 * The "Reports" entry of the activity navigation, for those who see the reports (R7).
 *
 * @param settings_navigation $settings Settings navigation.
 * @param navigation_node $node Node of the activity.
 */
function scormmakercmi5_extend_settings_navigation(settings_navigation $settings, navigation_node $node): void {
    $cm = $settings->get_page()->cm;
    if ($cm && has_capability('mod/scormmakercmi5:viewreports', $cm->context)) {
        $url = new moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id]);
        $node->add(get_string('reports'), $url, navigation_node::TYPE_CUSTOM, null, 'scormmakercmi5report');
    }
}
