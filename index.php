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
 * List of the cmi5 activities of a course.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);
$coursecontext = context_course::instance($course->id);

$event = \mod_scormmakercmi5\event\course_module_instance_list_viewed::create(['context' => $coursecontext]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$strplural = get_string('modulenameplural', 'mod_scormmakercmi5');
$PAGE->set_url(new moodle_url('/mod/scormmakercmi5/index.php', ['id' => $course->id]));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title($strplural);
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $coursecontext]));
$PAGE->navbar->add($strplural);

echo $OUTPUT->header();
echo $OUTPUT->heading($strplural);

// Only the activities the user can see (get_all_instances_in_course() checks visibility and availability).
$instances = get_all_instances_in_course('scormmakercmi5', $course);
if (!$instances) {
    notice(get_string('thereareno', 'moodle', $strplural), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$usesections = course_format_uses_sections($course->format);
$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';
$table->head = [];
if ($usesections) {
    $table->head[] = get_string('sectionname', 'format_' . $course->format);
}
$table->head[] = get_string('name');
foreach ($instances as $instance) {
    $row = [];
    if ($usesections) {
        $row[] = get_section_name($course, $instance->section);
    }
    $attributes = $instance->visible ? [] : ['class' => 'dimmed'];
    $row[] = html_writer::link(
        new moodle_url('/mod/scormmakercmi5/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name, true, ['context' => context_module::instance($instance->coursemodule)]),
        $attributes
    );
    $table->data[] = $row;
}
echo html_writer::table($table);
echo $OUTPUT->footer();
