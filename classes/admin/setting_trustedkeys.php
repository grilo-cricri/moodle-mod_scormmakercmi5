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

namespace mod_scormmakercmi5\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

use mod_scormmakercmi5\local\signature\key_lines;

/**
 * Admin setting with extra trusted signing keys: one "kid=base64 public key" per line (design §5.3, R3.2, R3.3).
 *
 * Parsing lives in local\signature\key_lines, which the keyring uses too. Error messages carry only the line
 * number, never the text the admin typed.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_trustedkeys extends \admin_setting_configtextarea {
    /**
     * Validates the whole textarea.
     *
     * @param string $data Submitted text.
     * @return true|string True when valid, otherwise the error message of the first bad line.
     */
    public function validate($data) {
        $error = key_lines::trusted((string)$data)['error'];
        return $error === null ? true : get_string($error['key'], 'mod_scormmakercmi5', $error['line']);
    }

    /**
     * Valid entries of a stored value (see key_lines::trusted()).
     *
     * @param string $raw Stored text.
     * @return array kid => base64 public key.
     */
    public static function parse(string $raw): array {
        return key_lines::trusted($raw)['keys'];
    }
}
