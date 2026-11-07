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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/db/upgrade.php');

#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_scormmakercmi5_upgrade')]
/**
 * Tests for the upgrade from 0.1.0 (design §3.9, §3.2; subproject D).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_scormmakercmi5_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * The 0.1.0 schema (without the D fields) upgrades to the 0.2.0 one, and running it again changes nothing.
     */
    public function test_upgrade_from_010_adds_the_d_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $stmt = new \xmldb_table('scormmakercmi5_stmt');
        $pkg = new \xmldb_table('scormmakercmi5_pkg');
        $index = new \xmldb_index('instanceversionblock', XMLDB_INDEX_NOTUNIQUE, ['scormmakercmi5id', 'smversion', 'smblock']);
        // Back to the 0.1.0 schema.
        if ($dbman->index_exists($stmt, $index)) {
            $dbman->drop_index($stmt, $index);
        }
        foreach (['smprofilever', 'smblocktype', 'smblock'] as $name) {
            $dbman->drop_field($stmt, new \xmldb_field($name));
        }
        $dbman->drop_field($pkg, new \xmldb_field('richstructure'));

        // The savepoints refuse to go below the installed version: pretend 0.1.0 is installed.
        set_config('version', 2026093007, 'mod_scormmakercmi5');
        $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026093007));
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));
        foreach (['smblock', 'smblocktype', 'smprofilever'] as $name) {
            $this->assertTrue($dbman->field_exists($stmt, $name), $name);
        }
        $this->assertTrue($dbman->index_exists($stmt, $index));
        $this->assertTrue($dbman->field_exists($pkg, 'richstructure'));
        // Safe to run again after a crash in the middle (fields and index already there).
        set_config('version', 2026093007, 'mod_scormmakercmi5');
        $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026093007));
    }

    /**
     * The step 2026100102 queues the backfill of the profile 2 columns only when a statement of the profile may need
     * it, and only once.
     */
    public function test_upgrade_queues_the_backfill(): void {
        global $DB;
        $this->resetAfterTest();
        $task = task\backfill_profile_columns::class;

        set_config('version', 2026100101, 'mod_scormmakercmi5');
        $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100101));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks($task), 'no statement of the profile: nothing queued');

        // A lesson of C (smkind set) is no candidate.
        $DB->insert_record('scormmakercmi5_stmt', (object)[
            'scormmakercmi5id' => 1, 'pkgid' => 1, 'regid' => 1, 'auid' => 1, 'userid' => 2,
            'statementid' => \core\uuid::generate(), 'source' => 0, 'verb' => 'http://adlnet.gov/expapi/verbs/experienced',
            'objecthash' => sha1('l'), 'objectid' => 'https://scormmaker.com.br/xapi/curso/c1/licao/l1', 'cmi5kind' => 1,
            'smprofile' => 1, 'smversion' => 'v#1', 'smkind' => 'licao', 'smitem' => 'l1', 'payload' => '{}',
        ]);
        set_config('version', 2026100101, 'mod_scormmakercmi5');
        $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100101));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks($task), 'only C rows: nothing queued');

        $DB->insert_record('scormmakercmi5_stmt', (object)[
            'scormmakercmi5id' => 1, 'pkgid' => 1, 'regid' => 1, 'auid' => 1, 'userid' => 2,
            'statementid' => \core\uuid::generate(), 'source' => 0, 'verb' => 'http://adlnet.gov/expapi/verbs/interacted',
            'objecthash' => sha1('x'), 'objectid' => 'https://scormmaker.com.br/xapi/curso/c1/bloco/b1', 'cmi5kind' => 1,
            'smprofile' => 1, 'smversion' => 'v#1', 'payload' => '{}',
        ]);
        foreach ([1, 2] as $run) {
            set_config('version', 2026100101, 'mod_scormmakercmi5');
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100101));
            $this->assertCount(1, \core\task\manager::get_adhoc_tasks($task), "run {$run}");
        }
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));
    }

    /**
     * The step 2026100103 queues the backfill of the analysis structure only when a package version has the file and
     * no structure, and only once.
     */
    public function test_upgrade_queues_the_structure_backfill(): void {
        global $DB;
        $this->resetAfterTest();
        $task = task\backfill_rich_structure::class;
        $pkg = static fn(?string $structure): int => (int)$DB->insert_record('scormmakercmi5_pkg', (object)[
            'scormmakercmi5id' => 1, 'versionnum' => $DB->count_records('scormmakercmi5_pkg') + 1, 'filename' => 'a.zip',
            'zipcontenthash' => sha1('z'), 'totalbytes' => 1, 'filecount' => 1, 'publishercourseid' => 'c', 'cmi5xml' => '<x/>',
            'sigstatus' => 'unsigned', 'richstructure' => $structure, 'timecreated' => 1,
        ]);
        $file = static fn(int $pkgid, string $path) => $DB->insert_record('scormmakercmi5_pkgfile', (object)[
            'pkgid' => $pkgid, 'pathhash' => sha1($path), 'path' => $path, 'sha256' => str_repeat('0', 64),
            'contenthash' => sha1('c'), 'filesize' => 1,
        ]);
        $upgrade = function (): void {
            set_config('version', 2026100102, 'mod_scormmakercmi5');
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100102));
        };

        // A version without the file, and a version with the file and a structure already: nothing to do.
        $file($pkg(null), 'index.html');
        $file($pkg('{"v":1}'), 'analise/estrutura.json');
        $upgrade();
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks($task));

        $file($pkg(null), 'analise/estrutura.json');
        foreach ([1, 2] as $run) {
            $upgrade();
            $this->assertCount(1, \core\task\manager::get_adhoc_tasks($task), "run {$run}");
        }
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));
    }

    /**
     * The step 2026100200 queues the repair of the JPEG files only when a signed version has a JPEG in its content
     * area, and only once.
     */
    public function test_upgrade_queues_the_jpeg_repair(): void {
        global $DB;
        $this->resetAfterTest();
        $task = task\repair_redacted_images::class;
        $pkg = static fn(string $sigstatus): int => (int)$DB->insert_record('scormmakercmi5_pkg', (object)[
            'scormmakercmi5id' => 1, 'versionnum' => $DB->count_records('scormmakercmi5_pkg') + 1, 'filename' => 'a.zip',
            'zipcontenthash' => sha1('z'), 'totalbytes' => 1, 'filecount' => 1, 'publishercourseid' => 'c', 'cmi5xml' => '<x/>',
            'sigstatus' => $sigstatus, 'timecreated' => 1,
        ]);
        $jpeg = static fn(int $pkgid) => get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'mod_scormmakercmi5', 'filearea' => 'content',
            'itemid' => $pkgid, 'filepath' => '/media/', 'filename' => 'a.jpg', 'mimetype' => 'image/jpeg',
        ], 'x');
        $upgrade = function (): void {
            set_config('version', 2026100103, 'mod_scormmakercmi5');
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100103));
        };

        // An unsigned version with a JPEG: nothing to do.
        $jpeg($pkg('unsigned'));
        $upgrade();
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks($task));

        $jpeg($pkg('verified'));
        foreach ([1, 2] as $run) {
            $upgrade();
            $this->assertCount(1, \core\task\manager::get_adhoc_tasks($task), "run {$run}");
        }
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));
    }

    /**
     * The step 2026100202 runs no plugin code: it only queues the recompute of the summary times, and only when a
     * block summary of the analysis is stored (its guard repeats the WHERE literally), and only once. The column is
     * not touched during the upgrade.
     */
    public function test_upgrade_queues_the_summary_recompute(): void {
        global $DB;
        $this->resetAfterTest();
        $task = task\recompute_summary_durations::class;
        $row = static function (string $kind, string $verb) use ($DB): int {
            return (int)$DB->insert_record('scormmakercmi5_stmt', (object)[
                'scormmakercmi5id' => 1, 'pkgid' => 1, 'regid' => 1, 'auid' => 1, 'userid' => 2,
                'statementid' => \core\uuid::generate(), 'source' => 0, 'verb' => 'http://adlnet.gov/expapi/verbs/' . $verb,
                'objecthash' => sha1('x'), 'objectid' => 'https://scormmaker.com.br/xapi/curso/c1/bloco/b1', 'cmi5kind' => 1,
                'smprofile' => 1, 'smprofilever' => 2, 'smversion' => 'v#1', 'smkind' => $kind, 'smblock' => 'b1',
                'durationms' => 86400000, 'payload' => json_encode(['result' => ['duration' => 'P1D']]),
            ]);
        };
        $upgrade = function (): void {
            set_config('version', 2026100201, 'mod_scormmakercmi5');
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100201));
        };

        // A lesson of the profile 2, an item of a block, and a block with a verb that is not a summary: nothing to do.
        $row('licao', 'experienced');
        $row('item', 'interacted');
        $row('bloco', 'experienced');
        $upgrade();
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks($task));

        $summary = $row('bloco', 'interacted');
        foreach ([1, 2] as $run) {
            $upgrade();
            $this->assertCount(1, \core\task\manager::get_adhoc_tasks($task), "run {$run}");
        }
        $this->assertSame('86400000', (string)$DB->get_field('scormmakercmi5_stmt', 'durationms', ['id' => $summary]));
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));
    }

    /**
     * The step 2026100300 adds scormmakercmi5_au.auorigin and its index (AU outside the package), as install.xml has
     * them; nothing to migrate (such AUs were refused); safe to run again.
     */
    public function test_upgrade_adds_auorigin(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('scormmakercmi5_au');
        $index = new \xmldb_index('auorigin', XMLDB_INDEX_NOTUNIQUE, ['auorigin']);
        // Back to the 0.2.0 schema.
        $dbman->drop_index($table, $index);
        $dbman->drop_field($table, new \xmldb_field('auorigin'));
        $this->assertFalse($dbman->field_exists($table, 'auorigin'));

        foreach ([1, 2] as $run) {
            set_config('version', 2026100203, 'mod_scormmakercmi5');
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100203), "run {$run}");
            $this->assertTrue($dbman->field_exists($table, 'auorigin'), "run {$run}");
            $this->assertTrue($dbman->index_exists($table, $index), "run {$run}");
        }
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));
        $column = $DB->get_columns('scormmakercmi5_au')['auorigin'];
        $this->assertSame(255, (int)$column->max_length);
        $this->assertFalse((bool)$column->not_null);
        // Same definition as a new install (install.xml).
        $xmldb = new \xmldb_file(\core_component::get_component_directory('mod_scormmakercmi5') . '/db/install.xml');
        $xmldb->loadXMLStructure();
        $installed = $xmldb->getStructure()->getTable('scormmakercmi5_au');
        $this->assertSame('activitytype', $installed->getField('auorigin')->getPrevious());
        $this->assertNotNull($installed->getIndex('auorigin'));
    }

    /**
     * The step 2026100500 (PROD key embedded) turns the HML key off on the sites that never changed accepthmlkey: the
     * value was never saved, or every config_log row of it is a first write (old value NULL: the default applied at
     * install or on the upgrade settings page). A site where an admin changed it (a row with an old value) keeps its
     * value. When the step turns it off it writes a config_log row and prints a notice; running again changes nothing.
     */
    public function test_upgrade_turns_the_hml_key_off_where_never_changed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $where = ['plugin' => 'mod_scormmakercmi5', 'name' => 'accepthmlkey'];
        $log = static fn(?string $old, string $new) => $DB->insert_record('config_log', (object)($where + [
            'userid' => 2, 'timemodified' => time(), 'oldvalue' => $old, 'value' => $new,
        ]));
        $upgrade = function (): string {
            set_config('version', 2026100300, 'mod_scormmakercmi5');
            ob_start();
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100300));
            // Text only: the renderer of the CLI and the one of the web upgrade wrap and escape it differently.
            return trim(html_entity_decode(strip_tags((string)ob_get_clean()), ENT_QUOTES));
        };
        $notice = get_string('upgrade_hmlkeyoff', 'mod_scormmakercmi5');
        $scenario = function (?string $value, array $rows) use ($DB, $where, $log): void {
            $DB->delete_records('config_log', $where);
            if ($value === null) {
                unset_config('accepthmlkey', 'mod_scormmakercmi5');
            } else {
                set_config('accepthmlkey', $value, 'mod_scormmakercmi5');
            }
            foreach ($rows as [$old, $new]) {
                $log($old, $new);
            }
        };

        // Default 1 of 0.1.0-0.3.0, written once at install: off, with a log row and the notice.
        $scenario('1', [[null, '1']]);
        $out = $upgrade();
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'accepthmlkey'));
        $this->assertStringContainsString($notice, $out);
        $last = $DB->get_records('config_log', $where, 'id DESC', '*', 0, 1);
        $last = reset($last);
        $this->assertSame(['1', '0'], [$last->oldvalue, $last->value]);
        // Running again: already off, nothing printed, no new row.
        $rows = $DB->count_records('config_log', $where);
        $this->assertSame('', $upgrade());
        $this->assertSame($rows, $DB->count_records('config_log', $where));
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));

        // Value 1 with no log at all (log cleaned up): no sign of a change, off.
        $scenario('1', []);
        $this->assertStringContainsString($notice, $upgrade());
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'accepthmlkey'));

        // Never saved (0.3.0 read that as on): off, with the notice.
        $scenario(null, []);
        $this->assertStringContainsString($notice, $upgrade());
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'accepthmlkey'));

        // An admin turned it off and on again: explicit 1, kept.
        $scenario('1', [[null, '1'], ['1', '0'], ['0', '1']]);
        $this->assertSame('', $upgrade());
        $this->assertSame('1', get_config('mod_scormmakercmi5', 'accepthmlkey'));

        // Already off: kept, no notice.
        $scenario('0', [[null, '1'], ['1', '0']]);
        $this->assertSame('', $upgrade());
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'accepthmlkey'));
    }

    /**
     * The step 2026110600 keeps the old effective default of allowexternalau (on) on a site that has no stored value, so
     * the new default 0 only reaches new installs; a stored 0 or 1 is kept as it is.
     */
    public function test_upgrade_keeps_allowexternalau_on_where_never_saved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $upgrade = function (): void {
            set_config('version', 2026100501, 'mod_scormmakercmi5');
            ob_start();
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026100501));
            ob_end_clean();
        };

        // No row (0.3.x never saved it, and read that as on): stays on.
        unset_config('allowexternalau', 'mod_scormmakercmi5');
        $upgrade();
        $this->assertSame('1', get_config('mod_scormmakercmi5', 'allowexternalau'));
        $this->assertTrue(\mod_scormmakercmi5\local\package\importer::external_au_allowed());

        // An administrator turned it off: kept off. Turned on: kept on.
        set_config('allowexternalau', '0', 'mod_scormmakercmi5');
        $upgrade();
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'allowexternalau'));
        set_config('allowexternalau', '1', 'mod_scormmakercmi5');
        $upgrade();
        $this->assertSame('1', get_config('mod_scormmakercmi5', 'allowexternalau'));
    }

    /**
     * A site on build 2026110500 (the first 0.4.0 build) runs only the step 2026110600: allowexternalau is stored as 1
     * where it was never saved, a stored value is kept, the settings of the earlier steps are not touched again, and
     * the version ends on the last savepoint.
     */
    public function test_upgrade_from_2026110500_runs_only_the_last_step(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $upgrade = function (): void {
            set_config('version', 2026110500, 'mod_scormmakercmi5');
            ob_start();
            $this->assertTrue(xmldb_scormmakercmi5_upgrade(2026110500));
            ob_end_clean();
        };
        // The step 2026100500 would turn this off on a site where it was never changed: it must not run again.
        set_config('accepthmlkey', '1', 'mod_scormmakercmi5');

        unset_config('allowexternalau', 'mod_scormmakercmi5');
        $upgrade();
        $this->assertSame('1', get_config('mod_scormmakercmi5', 'allowexternalau'));
        $this->assertSame('1', get_config('mod_scormmakercmi5', 'accepthmlkey'));
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));

        set_config('allowexternalau', '0', 'mod_scormmakercmi5');
        $upgrade();
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'allowexternalau'));
        $this->assertSame('2026110600', get_config('mod_scormmakercmi5', 'version'));
    }

    /**
     * The release is 0.4.0 and the version is the last savepoint.
     */
    public function test_version(): void {
        $plugin = new \stdClass();
        require(\core_component::get_component_directory('mod_scormmakercmi5') . '/version.php');
        $this->assertSame('0.4.0', $plugin->release);
        $this->assertSame(2026110600, $plugin->version);
    }
}
