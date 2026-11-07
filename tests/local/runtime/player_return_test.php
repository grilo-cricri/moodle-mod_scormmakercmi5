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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\player_return::class)]
/**
 * Tests for the recognition of an AU going to its returnURL inside the player.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\runtime\player_return
 */
final class player_return_test extends \basic_testcase {
    /**
     * Only an iframe load whose referer is the content of the plugin is the return of an AU.
     */
    public function test_is_au_return(): void {
        $au = 'https://moodle.example/pluginfile.php/16/mod_scormmakercmi5/content/2/index.html?endpoint=x#f';
        $this->assertTrue(player_return::is_au_return('iframe', $au));
        $this->assertFalse(player_return::is_au_return('document', $au), 'top-level load after the player');
        $this->assertFalse(player_return::is_au_return('', $au), 'browser without Sec-Fetch-Dest');
        $this->assertFalse(player_return::is_au_return('iframe', ''), 'no referer');
        $this->assertFalse(
            player_return::is_au_return('iframe', 'https://moodle.example/course/view.php?id=2'),
            'the whole Moodle embedded somewhere'
        );
        $this->assertFalse(
            player_return::is_au_return('iframe', 'https://moodle.example/pluginfile.php/16/mod_scorm/content/2/x.html'),
            'content of another plugin'
        );
        $this->assertFalse(
            player_return::is_au_return('iframe', 'https://moodle.example/mod/x.php?u=/mod_scormmakercmi5/content/'),
            'only the path counts'
        );
    }
}
