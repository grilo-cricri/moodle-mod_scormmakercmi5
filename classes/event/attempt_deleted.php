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
 * A teacher deleted the attempt of a learner: the registration and everything recorded in it (design §2.2, §11).
 *
 * objectid is the id the scormmakercmi5_reg row had; relateduserid is the learner.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_deleted extends \core\event\base {
    /**
     * Event data.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'scormmakercmi5_reg';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptdeleted', 'mod_scormmakercmi5');
    }

    /**
     * Description for the logs (not translated, as in core events).
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' deleted the attempt (registration id '{$this->objectid}') of the " .
            "user with id '{$this->relateduserid}' in the cmi5 activity with course module id " .
            "'{$this->contextinstanceid}'.";
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
    }

    /**
     * Mapping of the object id for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'scormmakercmi5_reg', 'restore' => 'scormmakercmi5_reg'];
    }

    /**
     * Mapping of the other fields for restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
