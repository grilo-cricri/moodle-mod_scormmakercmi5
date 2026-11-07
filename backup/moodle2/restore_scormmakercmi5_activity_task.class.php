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
 * Restore task of mod_scormmakercmi5 (design §12, NF7).
 *
 * @package    mod_scormmakercmi5
 * @category   backup
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/scormmakercmi5/backup/moodle2/restore_scormmakercmi5_stepslib.php');

/**
 * Settings, steps, link decoding and log rules of the restore of one activity.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_scormmakercmi5_activity_task extends restore_activity_task {
    /**
     * No particular settings.
     */
    protected function define_my_settings() {
    }

    /**
     * The structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_scormmakercmi5_activity_structure_step('scormmakercmi5_structure', 'scormmakercmi5.xml'));
    }

    /**
     * Contents whose links are decoded: the intro (the package and learner text travels verbatim, never encoded).
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [new restore_decode_content('scormmakercmi5', ['intro'], 'scormmakercmi5')];
    }

    /**
     * Rules decoding the links encoded by backup_scormmakercmi5_activity_task::encode_content_links().
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('SCORMMAKERCMI5VIEWBYID', '/mod/scormmakercmi5/view.php?id=$1', 'course_module'),
            new restore_decode_rule('SCORMMAKERCMI5INDEX', '/mod/scormmakercmi5/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Log rules of the activity.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('scormmakercmi5', 'add', 'view.php?id={course_module}', '{scormmakercmi5}'),
            new restore_log_rule('scormmakercmi5', 'update', 'view.php?id={course_module}', '{scormmakercmi5}'),
            new restore_log_rule('scormmakercmi5', 'view', 'view.php?id={course_module}', '{scormmakercmi5}'),
        ];
    }

    /**
     * Log rules of the course.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [new restore_log_rule('scormmakercmi5', 'view all', 'index.php?id={course}', null)];
    }

    /**
     * After the whole restore (files and gradebook included):
     *
     * - every restored version is verified again on THIS site — signature with this site's keyring and file map, no
     *   byte hashing (that is queued, design §5.4) — and the result becomes its sigstatus/sigreason: the verdict shown
     *   on view.php is never the source site's (another keyring, or a hand-made backup);
     * - with user data, the grades are recomputed from the restored AU scores (design §6.6), so the gradebook matches
     *   the registrations whatever grades the backup carried.
     */
    public function after_restore() {
        global $DB;
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->get_activityid()]);
        if (!$instance) {
            return;
        }
        $keyring = \mod_scormmakercmi5\local\signature\keyring::from_config();
        foreach ($DB->get_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id], 'versionnum') as $pkg) {
            $result = \mod_scormmakercmi5\local\signature\verifier::recheck($pkg, $keyring, 0);
            $DB->update_record('scormmakercmi5_pkg', (object)['id' => $pkg->id, 'sigstatus' => $result->status,
                'sigreason' => $result->reason]);
        }
        $hasregs = $DB->record_exists('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id]);
        if (!$this->get_setting_value('userinfo') || !$hasregs) {
            return;
        }
        $instance->cmidnumber = $DB->get_field('course_modules', 'idnumber', ['id' => $this->get_moduleid()]);
        \mod_scormmakercmi5\local\grading\grade_calculator::update($instance);
    }
}
