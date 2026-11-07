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

namespace mod_scormmakercmi5\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;

/**
 * Confirmation of a waiver (R4.8, design §2.2, §17 Q3): who and which unit, what it does, and the reason, one of the
 * cmi5 vocabulary (§9.5.5.2) shown translated and sent as in the spec. The form carries the sesskey.
 *
 * Custom data: learner and unit, both already escaped for HTML.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class waive_form extends \moodleform {
    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /**
     * The reasons as select options: the cmi5 value => its translation.
     *
     * @return string[]
     */
    public static function reason_options(): array {
        $options = [];
        foreach (vocab::WAIVE_REASONS as $reason) {
            $key = 'waivereason_' . strtolower(str_replace(' ', '', $reason));
            $options[$reason] = get_string($key, self::COMPONENT);
        }
        return $options;
    }

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        foreach (['id', 'userid', 'au'] as $name) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, PARAM_INT);
        }
        $mform->addElement('static', 'learner', get_string('waivelearner', self::COMPONENT), $this->_customdata['learner']);
        $mform->addElement('static', 'unit', get_string('waiveunit', self::COMPONENT), $this->_customdata['unit']);
        $mform->addElement('static', 'warning', '', get_string('waivewarning', self::COMPONENT));

        $options = ['' => get_string('choosedots')] + self::reason_options();
        $mform->addElement('select', 'reason', get_string('waivereason', self::COMPONENT), $options);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('waivesubmit', self::COMPONENT));
    }

    /**
     * The reason must be one of the cmi5 vocabulary.
     *
     * @param array $data Submitted data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!in_array($data['reason'] ?? '', vocab::WAIVE_REASONS, true)) {
            $errors['reason'] = get_string('required');
        }
        return $errors;
    }
}
