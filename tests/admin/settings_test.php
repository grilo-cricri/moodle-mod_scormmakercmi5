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

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * The settings of settings.php are stored in config_plugins under mod_scormmakercmi5, never in the core config table,
 * where a name such as sessiontimeout would collide with a core setting.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_test extends \advanced_testcase {
    /**
     * Every setting of the module page belongs to the plugin, and its installed default is in config_plugins.
     */
    public function test_settings_are_plugin_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $page = admin_get_root(true, true)->locate('modsettingscormmakercmi5');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $names = [];
        foreach ($page->settings as $setting) {
            $this->assertSame('mod_scormmakercmi5', $setting->plugin, $setting->name);
            if ($setting instanceof \admin_setting_heading) {
                continue;
            }
            $names[] = $setting->name;
            $this->assertTrue(
                $DB->record_exists('config_plugins', ['plugin' => 'mod_scormmakercmi5', 'name' => $setting->name]),
                $setting->name
            );
        }
        $this->assertContains('maxbodykb', $names);
        $this->assertContains('allowexternalau', $names);
    }

    /**
     * A fresh install keeps AUs outside the package off: the administrator opts in (privacy by default). The default
     * is the one of settings.php, and the installed value of the test site is the stored '0'.
     */
    public function test_allowexternalau_is_off_by_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $page = admin_get_root(true, true)->locate('modsettingscormmakercmi5');
        $setting = null;
        foreach ($page->settings as $candidate) {
            if ($candidate->name === 'allowexternalau') {
                $setting = $candidate;
            }
        }
        $this->assertNotNull($setting);
        $this->assertSame(0, $setting->get_defaultsetting());
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'allowexternalau'));
        $this->assertFalse(\mod_scormmakercmi5\local\package\importer::external_au_allowed());
    }
}
