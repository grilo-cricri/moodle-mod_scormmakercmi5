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

namespace mod_scormmakercmi5\local\runtime;

/**
 * Recognises the activity page loaded inside the player by an AU leaving through its returnURL.
 *
 * The player then takes the whole page to the activity (mod_scormmakercmi5/player), which loads view.php a second
 * time: only that top-level load is logged. The browser says where a navigation lands (Sec-Fetch-Dest, sent by
 * current browsers) and the referer is the AU, served from the content area of the plugin. A browser that sends
 * neither just logs the view twice.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class player_return {
    /**
     * Whether this load of view.php is an AU going to its returnURL inside the player iframe.
     *
     * @param string $fetchdest Value of the Sec-Fetch-Dest request header ('' when absent).
     * @param string $referer Local referer ('' when absent or from another site).
     * @return bool
     */
    public static function is_au_return(string $fetchdest, string $referer): bool {
        if ($fetchdest !== 'iframe' || $referer === '') {
            return false;
        }
        $path = (string)parse_url($referer, PHP_URL_PATH);
        return str_contains($path, '/pluginfile.php/') && str_contains($path, '/mod_scormmakercmi5/content/');
    }

    /**
     * The same check on the current request.
     *
     * @return bool
     */
    public static function is_current_request(): bool {
        return self::is_au_return((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''), (string)get_local_referer(false));
    }
}
