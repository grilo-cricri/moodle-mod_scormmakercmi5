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

namespace mod_scormmakercmi5;

use mod_scormmakercmi5\local\lrs\endpoint;
use mod_scormmakercmi5\privacy\provider;

/**
 * Event observers of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * A deleted user account takes its learner data along: every registration with its sessions, statements and
     * State, and the Agent Profile (learner preferences, design §11), through the same code as the Privacy API
     * deletion (provider::delete_all_data_of_user()). delete_user() purges the user_preferences, grades and completion
     * of core but leaves the module data (the plugin tables), which a later privacy request might never ask for, so
     * the data would otherwise outlive the account. The event fires after the user row is marked deleted: nothing here
     * needs a live user, only the id, and grade and completion are not recomputed (core already removed them). Package
     * versions the user uploaded stay, with usermodified = 0. A failure is logged and does not stop the account
     * deletion: the observer is not internal, so inside an outer transaction (as the core_user_delete_users web
     * service opens) it runs only after that transaction commits. A registration whose lock is held (an AU batch in
     * flight) is waited for, up to the privacy lock timeout.
     *
     * @param \core\event\user_deleted $event Event.
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        try {
            provider::delete_all_data_of_user((int)$event->objectid);
        } catch (\Throwable $e) {
            endpoint::log_failure('user deleted', $e);
        }
    }
}
