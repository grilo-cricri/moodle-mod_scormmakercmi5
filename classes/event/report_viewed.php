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
 * A report of the activity was viewed or downloaded (R7).
 *
 * other holds the mode (basic, user or statements); relateduserid is the learner of the user and statements modes.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_viewed extends \core\event\base {
    /**
     * Event data.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventreportviewed', 'mod_scormmakercmi5');
    }

    /**
     * Description for the logs (not translated, as in core events).
     *
     * @return string
     */
    public function get_description() {
        $learner = isset($this->relateduserid) ? " of the user with id '{$this->relateduserid}'" : '';
        return "The user with id '{$this->userid}' viewed the '{$this->other['mode']}' report{$learner} of the cmi5 " .
            "activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * The report page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        $params = ['id' => $this->contextinstanceid, 'mode' => $this->other['mode']];
        if (isset($this->relateduserid)) {
            $params['userid'] = $this->relateduserid;
        }
        return new \moodle_url('/mod/scormmakercmi5/report.php', $params);
    }

    /**
     * Checks the event data.
     */
    protected function validate_data() {
        parent::validate_data();
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }
        if (!isset($this->other['mode'])) {
            throw new \coding_exception("The 'mode' value must be set in other.");
        }
    }

    /**
     * Mapping of the other fields for restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['mode' => \core\event\base::NOT_MAPPED];
    }
}
