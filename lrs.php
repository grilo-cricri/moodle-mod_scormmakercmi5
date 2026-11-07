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
 * The embedded LRS of the cmi5 AUs (design §2.4, §7): lrs.php/<resource>, routed by PATH_INFO.
 *
 * Cookieless like mod/lti/services.php and fetch.php: no Moodle session and no debug output. The authorisation is
 * the auth token of a launch session (Authorization: Basic <token>); every answer is JSON or empty, never HTML.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing -- The session auth token is the authorisation (design §2.4, R5.2).
define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

\mod_scormmakercmi5\local\lrs\router::handle((string)get_file_argument());
