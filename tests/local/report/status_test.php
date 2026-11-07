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

namespace mod_scormmakercmi5\local\report;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\status::class)]
/**
 * Tests for the status of an AU and of the course shown to the learner (R4.6).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\report\status
 */
final class status_test extends \advanced_testcase {
    /**
     * A regau row with the given flags set.
     *
     * @param array $flags completed, passed, failed, waived, satisfied.
     * @return \stdClass
     */
    private function regau(array $flags = []): \stdClass {
        return (object)($flags + ['completed' => 0, 'passed' => 0, 'failed' => 0, 'waived' => 0, 'satisfied' => 0]);
    }

    /**
     * The state of an AU follows waived > passed > failed > completed > satisfied > inprogress > notstarted.
     */
    public function test_au_state(): void {
        $this->assertSame('notstarted', status::au_state(null, false));
        $this->assertSame(status::NOT_STARTED, status::au_state($this->regau(), false));
        $this->assertSame('inprogress', status::au_state($this->regau(), true));
        $this->assertSame(status::IN_PROGRESS, status::au_state(null, true));
        $this->assertSame('completed', status::au_state($this->regau(['completed' => 1]), true));
        $this->assertSame('passed', status::au_state($this->regau(['passed' => 1, 'completed' => 1]), true));
        $this->assertSame('failed', status::au_state($this->regau(['failed' => 1]), true));
        $this->assertSame('failed', status::au_state($this->regau(['failed' => 1, 'completed' => 1]), true));
        $this->assertSame('passed', status::au_state($this->regau(['failed' => 1, 'passed' => 1]), true));
        $this->assertSame('waived', status::au_state($this->regau(['waived' => 1, 'failed' => 1]), true));
        $this->assertSame('waived', status::au_state($this->regau(['waived' => 1]), false), 'waived needs no launch');
        $this->assertSame('completed', status::au_state($this->regau(['completed' => 1]), false));
        $this->assertSame('satisfied', status::au_state($this->regau(['satisfied' => 1]), false), 'moveOn NotApplicable');
        $this->assertSame('satisfied', status::au_state($this->regau(['satisfied' => 1]), true));
        $this->assertSame('passed', status::au_state($this->regau(['satisfied' => 1, 'passed' => 1]), true));
        $this->assertSame('completed', status::au_state($this->regau(['satisfied' => 1, 'completed' => 1]), true));
    }

    /**
     * The course is satisfied, in progress or not started.
     */
    public function test_course_state(): void {
        $this->assertSame('satisfied', status::course_state((object)['satisfied' => 1], true));
        $this->assertSame(status::SATISFIED, status::course_state((object)['satisfied' => 1], false));
        $this->assertSame('inprogress', status::course_state((object)['satisfied' => 0], true));
        $this->assertSame('notstarted', status::course_state((object)['satisfied' => 0], false));
        $this->assertSame('notstarted', status::course_state(null, false));
    }

    /**
     * Every state has a label in English and in Brazilian Portuguese.
     */
    public function test_label(): void {
        global $CFG, $SESSION;
        $this->resetAfterTest();
        $states = [
            status::NOT_STARTED, status::IN_PROGRESS, status::COMPLETED, status::PASSED,
            status::FAILED, status::WAIVED, status::SATISFIED,
        ];
        foreach ($states as $state) {
            $this->assertNotEmpty(status::label($state), $state);
        }
        $this->assertSame('Passed', status::label('passed'));
        // The pt_br pack is not installed on the test site, and the string manager reads a plugin's pt_br file only
        // for an installed language: a minimal langconfig makes pt_br installed for this test.
        $dir = $CFG->langotherroot . '/pt_br';
        $createddir = !is_dir($dir);
        $createdfile = !is_file($dir . '/langconfig.php');
        if ($createdfile) {
            make_writable_directory($dir);
            file_put_contents($dir . '/langconfig.php', "<?php\n\$string['thislanguage'] = 'Português - Brasil';\n");
        }
        get_string_manager()->reset_caches(true);
        $SESSION->forcelang = 'pt_br';
        try {
            $this->assertSame('Aprovado', status::label('passed'));
            $this->assertSame('Não iniciado', status::label('notstarted'));
        } finally {
            unset($SESSION->forcelang);
            if ($createddir) {
                remove_dir($dir);
            } else if ($createdfile) {
                unlink($dir . '/langconfig.php');
            }
            get_string_manager()->reset_caches(true);
        }
    }

    /**
     * An unknown state is a programming error.
     */
    public function test_label_unknown(): void {
        $this->expectException(\coding_exception::class);
        status::label('nope');
    }
}
