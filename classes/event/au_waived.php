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

namespace mod_scormmakercmi5\event;

/**
 * A teacher waived an assignable unit of a learner (R4.8, cmi5 §9.3.7).
 *
 * objectid is the scormmakercmi5_regau id; relateduserid is the learner; other holds auid and the reason, one of the
 * cmi5 vocabulary (§9.5.5.2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class au_waived extends \core\event\base {
    /**
     * Event data.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'scormmakercmi5_regau';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventauwaived', 'mod_scormmakercmi5');
    }

    /**
     * Description for the logs (not translated, as in core events).
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' waived the assignable unit with id '{$this->other['auid']}' " .
            "for the user with id '{$this->relateduserid}' with the reason '{$this->other['reason']}' in the cmi5 " .
            "activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * The activity page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/scormmakercmi5/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Checks the event data.
     */
    protected function validate_data() {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        foreach (['auid', 'reason'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Mapping of the object id for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'scormmakercmi5_regau', 'restore' => 'scormmakercmi5_regau'];
    }

    /**
     * Mapping of the other fields for restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'auid' => ['db' => 'scormmakercmi5_au', 'restore' => 'scormmakercmi5_au'],
            'reason' => \core\event\base::NOT_MAPPED,
        ];
    }
}
