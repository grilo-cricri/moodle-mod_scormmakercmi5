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

namespace mod_scormmakercmi5\local;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\limits::class)]
/**
 * Tests for the configurable limits (design §7.4).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\limits
 */
final class limits_test extends \advanced_testcase {
    /**
     * Without any config every limit has its documented default.
     */
    public function test_defaults(): void {
        $this->resetAfterTest();
        $names = ['maxbodykb', 'maxbatch', 'maxstatementkb', 'maxstatementsperreg', 'maxanalysisperreg', 'maxstatekb',
            'maxstatedocs', 'sessiontimeout', 'maxentries', 'maxunzippedmb', 'recheckmaxmb', 'recheckminhours',
            'requireprefsread'];
        foreach ($names as $name) {
            unset_config($name, 'mod_scormmakercmi5');
        }
        $this->assertSame(20000, limits::max_entries());
        $this->assertSame(1024 * 1048576, limits::max_unzipped_bytes());
        $this->assertSame(1024 * 1024, limits::max_body_bytes());
        $this->assertSame(100, limits::max_batch());
        $this->assertSame(64 * 1024, limits::max_statement_bytes());
        $this->assertSame(10000, limits::max_statements_per_reg());
        $this->assertSame(20000, limits::max_analysis_per_reg());
        $this->assertSame(1024 * 1024, limits::max_state_bytes());
        $this->assertSame(100, limits::max_state_docs());
        $this->assertSame(8 * 3600, limits::session_timeout_seconds());
        $this->assertSame(100 * 1048576, limits::recheck_max_bytes());
        $this->assertSame(24 * 3600, limits::recheck_min_interval_seconds());
        $this->assertTrue(limits::require_prefs_read());
    }

    /**
     * A positive configured value wins over the default.
     */
    public function test_configured_value(): void {
        $this->resetAfterTest();
        set_config('maxbatch', 7, 'mod_scormmakercmi5');
        $this->assertSame(7, limits::max_batch());
        set_config('maxunzippedmb', 2, 'mod_scormmakercmi5');
        $this->assertSame(2 * 1048576, limits::max_unzipped_bytes());
        set_config('maxbodykb', 3, 'mod_scormmakercmi5');
        $this->assertSame(3 * 1024, limits::max_body_bytes());
        set_config('sessiontimeout', 60, 'mod_scormmakercmi5');
        $this->assertSame(60, limits::session_timeout_seconds());
        set_config('maxstatedocs', 4, 'mod_scormmakercmi5');
        $this->assertSame(4, limits::max_state_docs());
        set_config('maxanalysisperreg', 9, 'mod_scormmakercmi5');
        $this->assertSame(9, limits::max_analysis_per_reg());
        set_config('recheckminhours', 2, 'mod_scormmakercmi5');
        $this->assertSame(2 * 3600, limits::recheck_min_interval_seconds());
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        $this->assertFalse(limits::require_prefs_read());
        set_config('requireprefsread', 1, 'mod_scormmakercmi5');
        $this->assertTrue(limits::require_prefs_read());
    }

    /**
     * Invalid values for a numeric limit.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'zero' => ['0'],
            'negative' => ['-3'],
            'not numeric' => ['abc'],
            'empty' => [''],
            'fraction' => ['1.5'],
        ];
    }

    /**
     * Zero, negative or non-numeric values fall back to the default.
     *
     * @dataProvider invalid_provider
     * @param string $value Stored config value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid_falls_back_to_default(string $value): void {
        $this->resetAfterTest();
        set_config('maxbatch', $value, 'mod_scormmakercmi5');
        $this->assertSame(100, limits::max_batch());
        set_config('maxentries', $value, 'mod_scormmakercmi5');
        $this->assertSame(20000, limits::max_entries());
        set_config('maxstatedocs', $value, 'mod_scormmakercmi5');
        $this->assertSame(100, limits::max_state_docs());
    }

    /**
     * The settings form refuses a limit that is not a positive whole number of up to 9 digits.
     */
    public function test_settings_refuse_invalid_limits(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $page = \admin_get_root(true, true)->locate('modsettingscormmakercmi5');
        $this->assertNotNull($page);
        $limits = ['maxbodykb', 'maxbatch', 'maxstatementkb', 'maxstatementsperreg', 'maxanalysisperreg', 'maxstatekb',
            'maxstatedocs', 'sessiontimeout', 'maxentries', 'maxunzippedmb', 'recheckmaxmb', 'recheckminhours'];
        $found = [];
        foreach ($page->settings as $setting) {
            if (!in_array($setting->name, $limits, true)) {
                continue;
            }
            $found[] = $setting->name;
            foreach (['0', '-3', 'abc', '1.5', '', '1234567890'] as $bad) {
                $this->assertNotSame('', $setting->write_setting($bad), "{$setting->name} = '{$bad}'");
            }
            $this->assertSame('', $setting->write_setting('5'), $setting->name);
            $this->assertSame('5', get_config('mod_scormmakercmi5', $setting->name));
        }
        sort($found);
        sort($limits);
        $this->assertSame($limits, $found);
    }
}
