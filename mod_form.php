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
 * Settings form of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_scormmakercmi5\local\instance_manager;
use mod_scormmakercmi5\local\package\importer;

/**
 * Settings form: package upload (validated with the importer, R1.3), replacement with confirmation (R1.5), modes.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_scormmakercmi5_mod_form extends moodleform_mod {
    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $config = get_config(self::COMPONENT);

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('activityname', self::COMPONENT), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'activityname', self::COMPONENT);
        $this->standard_intro_elements();

        $mform->addElement(
            'filemanager',
            'packagefile',
            get_string('packagefile', self::COMPONENT),
            null,
            self::filemanager_options()
        );
        $mform->addHelpButton('packagefile', 'packagefile', self::COMPONENT);

        if ($this->has_registrations()) {
            $mform->addElement('static', 'replacewarning', '', html_writer::div(
                s(get_string('replacewarning', self::COMPONENT)),
                'alert alert-warning mb-0'
            ));
            $mform->addElement('advcheckbox', 'confirmreplace', get_string('confirmreplace', self::COMPONENT));
            $mform->setDefault('confirmreplace', 0);
        }

        $mform->addElement('select', 'grademethod', get_string('grademethod', self::COMPONENT), [
            0 => get_string('grademethod_highest', self::COMPONENT),
            1 => get_string('grademethod_last', self::COMPONENT),
        ]);
        $mform->setDefault('grademethod', (int)($config->grademethod ?? 0));
        $mform->addHelpButton('grademethod', 'grademethod', self::COMPONENT);

        foreach (['browsemode', 'reviewmode'] as $mode) {
            $mform->addElement('advcheckbox', $mode, get_string($mode, self::COMPONENT));
            $mform->setDefault($mode, (int)($config->$mode ?? 0));
            $mform->addHelpButton($mode, $mode, self::COMPONENT);
        }

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * On edit, the file manager starts with the current zip: saving without touching it creates no version.
     *
     * @param array $defaultvalues Form defaults, by reference.
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        $draftitemid = file_get_submitted_draft_itemid('packagefile');
        $pkgid = empty($this->current->instance) ? 0 : (int)($this->current->currentpkgid ?? 0);
        file_prepare_draft_area(
            $draftitemid,
            $this->context->id,
            self::COMPONENT,
            importer::AREA_PACKAGE,
            $pkgid,
            self::filemanager_options()
        );
        $defaultvalues['packagefile'] = $draftitemid;
    }

    /**
     * The custom completion rule "satisfy the cmi5 course" (design §6.6, R6.2).
     *
     * @return string[] Names of the elements added.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $element = 'completionsatisfied' . $this->get_suffix();
        $mform->addElement('advcheckbox', $element, '', get_string('completionsatisfied', self::COMPONENT));
        $mform->setDefault($element, 0);
        return [$element];
    }

    /**
     * Whether the custom rule is on.
     *
     * @param array $data Submitted data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionsatisfied' . $this->get_suffix()]);
    }

    /**
     * With completion unlocked and not automatic, the rule is turned off.
     *
     * @param stdClass $data Submitted data.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $completion = $data->{'completion' . $suffix} ?? COMPLETION_TRACKING_NONE;
            if (empty($data->{'completionsatisfied' . $suffix}) || $completion != COMPLETION_TRACKING_AUTOMATIC) {
                $data->{'completionsatisfied' . $suffix} = 0;
            }
        }
    }

    /**
     * Validates the package with the same code as the import, so errors show on the form and nothing is created
     * (R1.3); a replacement in an activity with registrations needs the confirmation (R1.5).
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        global $CFG, $COURSE;
        $errors = parent::validation($data, $files);
        // The base class requires a name; here an empty one is filled from cmi5.xml (R1.1).
        if (isset($errors['name']) && trim((string)($data['name'] ?? '')) === '') {
            unset($errors['name']);
        }
        // The grade comes from score.scaled (0–1) times the maximum grade: a scale has no meaning here (design §6.6).
        if ((int)($data['grade'] ?? 0) < 0) {
            $errors['grade'] = get_string('err_gradescale', self::COMPONENT);
        }
        $draftitemid = (int)($data['packagefile'] ?? 0);
        $instance = $this->current_instance();

        if (!importer::draft_file($draftitemid)) {
            if (!$instance) {
                $errors['packagefile'] = get_string('err_nopackage', self::COMPONENT);
            }
            return $errors;
        }
        if ($instance && !instance_manager::is_new_package($draftitemid, $instance)) {
            return $errors;
        }

        $temp = null;
        try {
            $temp = importer::draft_to_temp($draftitemid);
            $maxbytes = (int)get_max_upload_file_size($CFG->maxbytes, $COURSE->maxbytes ?? 0);
            $inspected = (new importer())->inspect_file($temp['path'], $maxbytes, current_language());
            $inspected->discard();
            if ($instance) {
                importer::check_replacement($inspected->structure, context_course::instance((int)$instance->course));
            }
        } catch (\moodle_exception $e) {
            $errors['packagefile'] = $e->getMessage();
        } finally {
            if ($temp) {
                fulldelete(dirname($temp['path']));
            }
        }
        $needsconfirm = $instance && instance_manager::has_registrations((int)$instance->id);
        if (!isset($errors['packagefile']) && $needsconfirm && empty($data['confirmreplace'])) {
            $errors['confirmreplace'] = get_string('err_confirmreplace', self::COMPONENT);
        }
        return $errors;
    }

    /**
     * The instance being edited, null when adding.
     *
     * @return stdClass|null
     */
    private function current_instance(): ?stdClass {
        global $DB;
        if (empty($this->current->instance)) {
            return null;
        }
        return $DB->get_record('scormmakercmi5', ['id' => $this->current->instance]) ?: null;
    }

    /**
     * Whether the activity being edited has registrations (the replacement warning and confirmation show then).
     *
     * @return bool
     */
    private function has_registrations(): bool {
        return !empty($this->current->instance) && instance_manager::has_registrations((int)$this->current->instance);
    }

    /**
     * Options of the package file manager: one zip up to the upload limit of the course.
     *
     * @return array
     */
    private static function filemanager_options(): array {
        global $CFG, $COURSE;
        return [
            'accepted_types' => ['.zip'],
            'maxfiles' => 1,
            'subdirs' => 0,
            'maxbytes' => get_max_upload_file_size($CFG->maxbytes, $COURSE->maxbytes ?? 0),
        ];
    }
}
