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
 * Result of a launch: the new session, the URL of the AU and how to open it.
 *
 * The URL carries the one-time fetch key: it is handed to the browser once and never logged.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class launch_result {
    /**
     * Constructor.
     *
     * @param \stdClass $session The scormmakercmi5_session row.
     * @param \moodle_url $url URL of the AU with the cmi5 launch parameters.
     * @param string $launchmethod AnyWindow|OwnWindow.
     */
    public function __construct(
        /** @var \stdClass The session row. */
        public readonly \stdClass $session,
        /** @var \moodle_url URL of the AU. */
        public readonly \moodle_url $url,
        /** @var string AnyWindow|OwnWindow. */
        public readonly string $launchmethod
    ) {
    }
}
