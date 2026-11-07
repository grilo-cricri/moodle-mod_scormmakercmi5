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

namespace mod_scormmakercmi5\local\package;

/**
 * Makes text that comes from a package safe to put inside an error message.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class display_text {
    /**
     * Valid UTF-8, NUL shown as the text \0, control characters and markup characters replaced by "?",
     * cut at 200 characters.
     *
     * @param string $text Text from the package (entry name, id, URL, parser message).
     * @return string
     */
    public static function of(string $text): string {
        $text = fix_utf8(str_replace("\0", '\0', $text));
        $text = preg_replace('~[[:cntrl:]<>&"\'\x60]~u', '?', $text) ?? '?';
        if (\core_text::strlen($text) > 200) {
            $text = \core_text::substr($text, 0, 200) . '...';
        }
        return $text;
    }
}
