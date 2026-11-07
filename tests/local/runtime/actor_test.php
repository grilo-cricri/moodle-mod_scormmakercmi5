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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\actor::class)]
/**
 * Tests for the learner actor (cmi5 §9.2, §8.1.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\runtime\actor
 */
final class actor_test extends \advanced_testcase {
    /**
     * The actor is an account on the site, without e-mail or name.
     */
    public function test_for_user(): void {
        global $CFG;
        $actor = actor::for_user(42);
        $this->assertSame(['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => '42']], $actor);
        $this->assertArrayNotHasKey('mbox', $actor);
        $this->assertArrayNotHasKey('name', $actor);
        $this->assertSame('42', $actor['account']['name']);
    }

    /**
     * Matching: implicit Agent, other home page, other user, extra identifier, Group.
     */
    public function test_matches(): void {
        global $CFG;
        $account = ['homePage' => $CFG->wwwroot, 'name' => '42'];
        $this->assertTrue(actor::matches(actor::for_user(42), 42));
        $this->assertTrue(actor::matches(['account' => $account], 42), 'objectType absent means Agent');
        $this->assertTrue(actor::matches(['objectType' => 'Agent', 'name' => 'Ana', 'account' => $account], 42));
        $this->assertFalse(actor::matches(['account' => ['homePage' => 'https://other.example', 'name' => '42']], 42));
        $this->assertFalse(actor::matches(['account' => $account], 43));
        $this->assertFalse(actor::matches(['account' => $account, 'mbox' => 'mailto:a@example.com'], 42));
        $this->assertFalse(actor::matches(['objectType' => 'Group', 'account' => $account], 42));
        $this->assertFalse(actor::matches(['mbox' => 'mailto:a@example.com'], 42));
        $this->assertFalse(actor::matches(['account' => ['homePage' => $CFG->wwwroot, 'name' => 42]], 42), 'name is a string');
    }

    /**
     * The LMS authority is the plugin account on the site.
     */
    public function test_lms_authority(): void {
        global $CFG;
        $this->assertSame(
            ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => 'mod_scormmakercmi5']],
            actor::lms_authority()
        );
    }
}
