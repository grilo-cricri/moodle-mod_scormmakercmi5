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
 * A learner launched an assignable unit (design §6.1, R4.1).
 *
 * objectid is the scormmakercmi5_session id; other holds auid and launchmode. The launch URL is never logged: it
 * carries the one-time fetch key.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class au_launched extends \core\event\base {
    /**
     * Event data.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'scormmakercmi5_session';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventaulaunched', 'mod_scormmakercmi5');
    }

    /**
     * Description for the logs (not translated, as in core events).
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' launched the assignable unit with id '{$this->other['auid']}' " .
            "in mode '{$this->other['launchmode']}' of the cmi5 activity with course module id " .
            "'{$this->contextinstanceid}' (session id '{$this->objectid}').";
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
        foreach (['auid', 'launchmode'] as $key) {
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
        return ['db' => 'scormmakercmi5_session', 'restore' => 'scormmakercmi5_session'];
    }

    /**
     * Mapping of the other fields for restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'auid' => ['db' => 'scormmakercmi5_au', 'restore' => 'scormmakercmi5_au'],
            'launchmode' => \core\event\base::NOT_MAPPED,
        ];
    }
}
