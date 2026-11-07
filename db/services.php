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
 * Web service functions of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_scormmakercmi5_get_session_state' => [
        'classname' => \mod_scormmakercmi5\external\get_session_state::class,
        'description' => 'Whether a cmi5 launch session of the current user is still open (player page).',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'mod/scormmakercmi5:view',
    ],
];

// The read-only reporting credential of the embedded LRS (design §7.3; cmi5 4.2.0.0-2): an administrator enables the
// service, authorises a user and creates a permanent token for them; lrs.php then accepts
// "Authorization: Basic base64(wstoken:<token>)" on GET statements only. No function: nothing is callable through the
// web service servers.
$services = [
    'mod_scormmakercmi5_lrs' => [
        'functions' => [],
        'restrictedusers' => 1,
        'enabled' => 0,
        'shortname' => 'mod_scormmakercmi5_lrs',
    ],
];
