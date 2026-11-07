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

namespace mod_scormmakercmi5;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Installation tests: schema, unique indexes and capabilities.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class install_test extends \advanced_testcase {
    /**
     * Every table of design §3 exists.
     */
    public function test_all_tables_exist(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $tables = ['scormmakercmi5', 'scormmakercmi5_pkg', 'scormmakercmi5_pkgfile', 'scormmakercmi5_block',
            'scormmakercmi5_au', 'scormmakercmi5_reg', 'scormmakercmi5_regau', 'scormmakercmi5_regblock',
            'scormmakercmi5_session', 'scormmakercmi5_stmt', 'scormmakercmi5_state', 'scormmakercmi5_agentprof',
            'scormmakercmi5_itemdef'];
        foreach ($tables as $table) {
            $this->assertTrue($dbman->table_exists($table), $table);
        }
    }

    /**
     * One registration per user and instance.
     */
    public function test_unique_registration_per_user(): void {
        global $DB;
        $this->resetAfterTest();
        $row = ['scormmakercmi5id' => 7, 'userid' => 3, 'pkgid' => 1,
            'registration' => '11111111-1111-4111-8111-111111111111'];
        $DB->insert_record('scormmakercmi5_reg', (object) $row);
        $row['registration'] = '22222222-2222-4222-8222-222222222222';
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('scormmakercmi5_reg', (object) $row);
    }

    /**
     * A statement id is unique in the LRS.
     */
    public function test_unique_statementid(): void {
        global $DB;
        $this->resetAfterTest();
        $row = ['scormmakercmi5id' => 1, 'pkgid' => 1, 'regid' => 1, 'auid' => 1, 'userid' => 3,
            'statementid' => '33333333-3333-4333-8333-333333333333', 'verb' => 'http://adlnet.gov/expapi/verbs/initialized',
            'objecthash' => sha1('https://example.invalid/au'), 'objectid' => 'https://example.invalid/au',
            'timestampms' => 1, 'storedms' => 1, 'payload' => '{}'];
        $DB->insert_record('scormmakercmi5_stmt', (object) $row);
        $row['regid'] = 2;
        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('scormmakercmi5_stmt', (object) $row);
    }

    /**
     * Sessions whose fetch key and token hashes were nulled by the cleanup task coexist
     * with the unique indexes on those columns.
     */
    public function test_null_hashes_do_not_collide(): void {
        global $DB;
        $this->resetAfterTest();
        foreach (['44444444-4444-4444-8444-444444444444', '55555555-5555-4555-8555-555555555555'] as $uuid) {
            $DB->insert_record('scormmakercmi5_session', (object) ['regid' => 1, 'auid' => 1, 'userid' => 3,
                'sessionuuid' => $uuid, 'launchmode' => 'Normal', 'fetchkeyhash' => null, 'tokenhash' => null]);
        }
        $select = 'fetchkeyhash IS NULL AND tokenhash IS NULL';
        $this->assertEquals(2, $DB->count_records_select('scormmakercmi5_session', $select));
    }

    /**
     * The four capabilities of design §9, with their risks and archetypes.
     */
    public function test_capabilities_defined(): void {
        foreach (['addinstance', 'view', 'viewreports', 'manageattempts'] as $name) {
            $this->assertNotNull(get_capability_info('mod/scormmakercmi5:' . $name), $name);
        }
        $this->assertEquals(RISK_XSS, get_capability_info('mod/scormmakercmi5:addinstance')->riskbitmask);
        $this->assertEquals(RISK_PERSONAL, get_capability_info('mod/scormmakercmi5:viewreports')->riskbitmask);
        $manageattempts = get_capability_info('mod/scormmakercmi5:manageattempts');
        $this->assertEquals(RISK_DATALOSS | RISK_PERSONAL, $manageattempts->riskbitmask);
        $this->assertEquals(0, get_capability_info('mod/scormmakercmi5:view')->riskbitmask);

        $guest = get_default_capabilities('guest');
        $this->assertArrayNotHasKey('mod/scormmakercmi5:view', $guest);
        $student = get_default_capabilities('student');
        $this->assertEquals(CAP_ALLOW, $student['mod/scormmakercmi5:view']);
        $this->assertArrayNotHasKey('mod/scormmakercmi5:viewreports', $student);
    }
}
