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

namespace mod_scormmakercmi5\task;

use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verifier;

/**
 * Full recheck of a package too large to hash while the rich report opens (design §5.4, §10.3).
 *
 * Queued by verifier::recheck() with custom data {pkgid}, deduplicated (queue_adhoc_task(…, true)). Runs the three
 * steps whatever the size and records the result and timefullrecheck. A package or activity deleted meanwhile is
 * skipped.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recheck_package extends \core\task\adhoc_task {
    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrecheckpackage', 'mod_scormmakercmi5');
    }

    /**
     * Rechecks the package of the custom data.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => (int)($data->pkgid ?? 0)]);
        if (!$pkg || !get_coursemodule_from_instance('scormmakercmi5', $pkg->scormmakercmi5id)) {
            return;
        }
        verifier::recheck($pkg, keyring::from_config(), PHP_INT_MAX);
    }
}
