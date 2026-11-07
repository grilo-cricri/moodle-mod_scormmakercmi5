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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\basic_table::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\user_statements_table::class)]
/**
 * Tests for the basic report (R7.1, R7.3, design §8.1) and the statements of a learner (R7.2).
 *
 * Three learners: A never started, B in progress (two launches and a synthetic session), C satisfied. A and B are in
 * group G1, C in G2; a non-editing teacher (no moodle/site:accessallgroups) is in G1.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\report\basic_table
 * @covers     \mod_scormmakercmi5\local\report\user_statements_table
 */
final class basic_table_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Instance (generator result, with cmid). */
    private \stdClass $instance;

    /** @var \stdClass[] Learners A, B, C by letter. */
    private array $learners = [];

    /** @var \stdClass Non-editing teacher in G1. */
    private \stdClass $teacher;

    /** @var \stdClass[] Groups G1, G2 by name. */
    private array $groups = [];

    /**
     * Rows with the right course and AU states, the times and the counts of sessions.
     */
    public function test_rows(): void {
        $this->setup_activity();
        $this->setAdminUser();
        $table = $this->table(0, '');
        $rows = $this->rows($table);
        [$au1, $au2] = $this->aus();

        $this->assertEqualsCanonicalizing($this->ids('A', 'B', 'C'), array_keys($rows), 'the teacher is not a learner');
        $a = $rows[$this->learners['A']->id];
        $b = $rows[$this->learners['B']->id];
        $c = $rows[$this->learners['C']->id];

        $this->assertStringContainsString('Not started', $a['coursestatus']);
        $this->assertStringContainsString('In progress', $b['coursestatus']);
        $this->assertStringContainsString('Satisfied', $c['coursestatus']);
        $this->assertStringContainsString('Not started', $a['au' . $au1->id]);
        $this->assertStringContainsString('In progress', $b['au' . $au1->id]);
        $this->assertStringContainsString('Not started', $b['au' . $au2->id]);
        $this->assertStringContainsString('Passed', $c['au' . $au1->id]);
        $this->assertStringContainsString('Waived', $c['au' . $au2->id]);
        // Text and icon, never colour alone (NF5).
        $this->assertStringContainsString('<i ', $c['au' . $au1->id]);

        // Total time = Σ durationms of every session; sessions = launches only (kind 0); first and last access.
        $raw = $this->raw($table);
        $this->assertSame(90000, (int)$raw[$this->learners['B']->id]->totaltime);
        $this->assertSame(2, (int)$raw[$this->learners['B']->id]->sessions);
        $this->assertSame(1000000, (int)$raw[$this->learners['B']->id]->timefirst);
        $this->assertSame(2500000, (int)$raw[$this->learners['B']->id]->timelast);
        $this->assertSame(format_time(90), $b['totaltime']);
        $this->assertSame('2', (string)$b['sessions']);
        $this->assertSame(userdate(1000), $b['timefirst']);
        $this->assertSame(userdate(2500), $b['timelast']);
        $this->assertSame('-', $a['totaltime']);
        $this->assertSame('0', (string)$a['sessions']);
        $this->assertSame('-', $a['timefirst']);
        $this->assertSame('-', $a['grade']);
        $this->assertSame(format_float(80, 2), $c['grade']);
        // The name links to the learner's detail, escaped.
        $this->assertStringContainsString('mode=user', $c['fullname']);
        $this->assertStringContainsString('Caio &amp; Cia', $c['fullname']);
    }

    /**
     * Final review N-2: a registration at maxstatementsperreg is flagged next to the course status, in the page and
     * in the download; the others are not.
     */
    public function test_statement_limit_flag(): void {
        global $DB;
        $this->setup_activity();
        $this->setAdminUser();
        set_config('maxstatementsperreg', 5, 'mod_scormmakercmi5');
        $regb = $DB->get_record('scormmakercmi5_reg', ['userid' => $this->learners['B']->id], '*', MUST_EXIST);
        $DB->set_field('scormmakercmi5_reg', 'statementcount', 5, ['id' => $regb->id]);
        $DB->set_field('scormmakercmi5_reg', 'statementcount', 4, ['userid' => $this->learners['C']->id]);
        $rows = $this->rows($this->table(0, ''));
        $flag = get_string('reportstatementlimit', 'mod_scormmakercmi5');
        $this->assertStringContainsString('In progress', $rows[$this->learners['B']->id]['coursestatus']);
        $this->assertStringContainsString($flag, $rows[$this->learners['B']->id]['coursestatus']);
        $this->assertStringNotContainsString($flag, $rows[$this->learners['C']->id]['coursestatus']);
        $this->assertStringNotContainsString($flag, $rows[$this->learners['A']->id]['coursestatus']);

        $table = $this->table(0, '');
        ob_start();
        // The CSV writer prints its byte order mark as soon as the download starts.
        $table->is_downloading('csv', 'x');
        $raw = $this->raw($table);
        $cell = $table->format_row($raw[$this->learners['B']->id])['coursestatus'];
        ob_end_clean();
        $this->assertSame('In progress (' . $flag . ')', $cell);
    }

    /**
     * The status filter keeps the matching learners; a value outside the allowlist is no filter.
     */
    public function test_status_filter(): void {
        $this->setup_activity();
        $this->setAdminUser();
        $this->assertSame($this->ids('C'), array_keys($this->rows($this->table(0, 'satisfied'))));
        $this->assertSame($this->ids('B'), array_keys($this->rows($this->table(0, 'inprogress'))));
        $this->assertSame($this->ids('A'), array_keys($this->rows($this->table(0, 'notstarted'))));
        $all = $this->table(0, '<script>');
        $this->assertSame('', $all->get_status_filter());
        $this->assertCount(3, $this->rows($all));
    }

    /**
     * Separate groups: a teacher without accessallgroups sees their group; with it, everyone; another group is refused.
     */
    public function test_groups(): void {
        $this->setup_activity();
        $this->setUser($this->teacher);
        $groupid = groups_get_activity_group($this->cm(), true);
        $this->assertEquals($this->groups['G1']->id, $groupid);
        $this->assertEqualsCanonicalizing($this->ids('A', 'B'), array_keys($this->rows($this->table((int)$groupid, ''))));
        $this->assertEqualsCanonicalizing($this->ids('A', 'B'), array_keys($this->rows($this->table(0, ''))));
        try {
            $this->table((int)$this->groups['G2']->id, '');
            $this->fail('A group of others must be refused');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $role = $this->get_role('teacher');
        assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $role, \context_course::instance($this->course->id));
        $this->assertEqualsCanonicalizing($this->ids('A', 'B', 'C'), array_keys($this->rows($this->table(0, ''))));
        $this->assertEqualsCanonicalizing($this->ids('C'), array_keys($this->rows($this->table((int)$this->groups['G2']->id, ''))));
    }

    /**
     * The CSV has the header and one line per learner, with spreadsheet formulas neutralised.
     */
    public function test_csv(): void {
        $this->setup_activity();
        $this->setAdminUser();
        $table = $this->table(0, '');
        ob_start();
        // The table's dataformat export without the exit() that ends a real download.
        $export = new class ($table, 'csv') extends \core_table\dataformat_export_format {
            /**
             * Closes the output without exit().
             */
            public function finish_document() {
                $this->dataformat->close_output();
            }
        };
        $export->start_document('x', '');
        $table->export_class_instance($export);
        // A table that gets its export class from outside thinks the headers are out already.
        $table->started_output = false;
        $table->is_downloading('csv', 'x');
        $table->out(0, false);
        $csv = ob_get_clean();
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r?\n/', trim($csv)), fn($l) => $l !== ''));
        $this->assertCount(4, $lines, $csv);
        $header = str_getcsv($lines[0]);
        $this->assertSame('Learner', $header[0]);
        $this->assertContains('Course status', $header);
        $this->assertContains('Unidade 1', $header);
        $this->assertContains('Total time', $header);
        $this->assertStringNotContainsString('<', $csv, 'no HTML in the CSV');
        // Learner A is called "=cmd …": the cell starts with an apostrophe (CSV injection).
        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringNotContainsString('"=cmd', $csv);
        $this->assertSame("'=x", basic_table::spreadsheet_safe('=x'));
        $this->assertSame("'+x", basic_table::spreadsheet_safe('+x'));
        $this->assertSame("'-x", basic_table::spreadsheet_safe('-x'));
        $this->assertSame("'@x", basic_table::spreadsheet_safe('@x'));
        $this->assertSame("'\tx", basic_table::spreadsheet_safe("\tx"));
        $this->assertSame("'\rx", basic_table::spreadsheet_safe("\rx"));
        $this->assertSame('x=', basic_table::spreadsheet_safe('x='));
    }

    /**
     * More than 20 AUs become one column "x/y" of satisfied AUs.
     */
    public function test_many_aus(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $aus = [];
        for ($i = 1; $i <= 21; $i++) {
            $aus[] = [
                'id' => 'https://example.com/au/' . $i, 'url' => 'index.html', 'title' => 'Unidade ' . $i, 'moveOn' => 'Completed',
            ];
        }
        $entries = builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml($aus)]);
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $this->course->id, 'package_entries' => $entries]);
        $reg = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_registration(['scormmakercmi5id' => $this->instance->id, 'userid' => $learner->id]);
        $first = $DB->get_records('scormmakercmi5_regau', ['regid' => $reg->id], 'auid', '*', 0, 3);
        foreach ($first as $regau) {
            $DB->set_field('scormmakercmi5_regau', 'satisfied', 1, ['id' => $regau->id]);
        }

        $table = $this->table(0, '');
        $rows = $this->rows($table);
        $this->assertArrayHasKey('ausatisfied', $table->columns);
        $this->assertEmpty(preg_grep('/^au\d+$/', array_keys($table->columns)));
        $this->assertSame('3/21', $rows[$learner->id]['ausatisfied']);
    }

    /**
     * The statements of a learner: short verb, object name (escaped) or IRI, result and date, newest first.
     */
    public function test_user_statements(): void {
        global $DB;
        $this->setup_activity();
        $this->setAdminUser();
        $reg = $DB->get_record('scormmakercmi5_reg', ['userid' => $this->learners['B']->id], '*', MUST_EXIST);
        [$au1] = $this->aus();
        $this->statement($reg, $au1, 'http://adlnet.gov/expapi/verbs/experienced', 1000000, [
            'object' => ['id' => 'https://example.com/x/1', 'definition' => ['name' => ['en-US' => '<script>x</script>']]],
            'result' => ['success' => true, 'completion' => true, 'score' => ['scaled' => 0.5], 'duration' => 'PT1M'],
        ], ['success' => 1, 'completion' => 1, 'scorescaled' => 0.5, 'durationms' => 60000]);
        $this->statement($reg, $au1, 'http://adlnet.gov/expapi/verbs/answered', 2000000, [
            'object' => ['id' => 'https://example.com/x/2'],
        ]);

        $table = new user_statements_table('stmts', (int)$reg->id);
        $table->define_baseurl(new \moodle_url('/mod/scormmakercmi5/report.php', ['id' => $this->instance->cmid]));
        ob_start();
        $table->out(10, false);
        $html = ob_get_clean();
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('experienced', $html);
        $this->assertStringContainsString('https://example.com/x/2', $html);
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertLessThan(strpos($html, 'experienced'), strpos($html, 'answered'), 'newest first');
        $this->assertStringContainsString('50%', $html);
        $this->assertSame('experienced', user_statements_table::short_verb('http://adlnet.gov/expapi/verbs/experienced'));
        $this->assertSame('x', user_statements_table::short_verb('https://example.com/v#x'));
    }

    /**
     * Only CSV, Excel and ODS are offered and accepted: the HTML and PDF writers would print the cells as markup.
     */
    public function test_download_formats(): void {
        $this->setup_activity();
        $this->setAdminUser();
        $formats = basic_table::download_formats();
        $this->assertSame(['csv', 'excel', 'ods'], $formats);
        $this->assertNotContains('html', $formats);
        $this->assertNotContains('pdf', $formats);
        $this->assertNotContains('json', $formats);

        $table = $this->table(0, '');
        $buttons = $table->download_buttons();
        $this->assertStringContainsString('value="csv"', $buttons);
        $this->assertStringNotContainsString('value="html"', $buttons);
        $this->assertStringNotContainsString('value="pdf"', $buttons);

        // The table has a caption (NF5).
        ob_start();
        $table->out(10, false);
        $html = ob_get_clean();
        $this->assertMatchesRegularExpression('~<caption class="accesshide">Learners</caption>~', $html);
    }

    /**
     * Course, learners, groups, the teacher and the activity in separate groups with two AUs and the runtime data.
     */
    private function setup_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $this->learners['A'] = $gen->create_and_enrol($this->course, 'student', ['firstname' => '=cmd', 'lastname' => 'A']);
        $this->learners['B'] = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Bia', 'lastname' => 'B']);
        $this->learners['C'] = $gen->create_and_enrol($this->course, 'student', ['firstname' => 'Caio & Cia', 'lastname' => 'C']);
        $this->teacher = $gen->create_and_enrol($this->course, 'teacher');
        $this->groups['G1'] = $gen->create_group(['courseid' => $this->course->id, 'name' => 'G1']);
        $this->groups['G2'] = $gen->create_group(['courseid' => $this->course->id, 'name' => 'G2']);
        foreach (['A' => 'G1', 'B' => 'G1', 'C' => 'G2'] as $letter => $group) {
            $gen->create_group_member(['groupid' => $this->groups[$group]->id, 'userid' => $this->learners[$letter]->id]);
        }
        $gen->create_group_member(['groupid' => $this->groups['G1']->id, 'userid' => $this->teacher->id]);

        $entries = builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Unidade 1', 'moveOn' => 'Completed'],
            ['id' => 'https://example.com/au/2', 'url' => 'index.html', 'title' => 'Unidade 2', 'moveOn' => 'Completed'],
        ])]);
        $plugin = $gen->get_plugin_generator('mod_scormmakercmi5');
        $this->instance = $plugin->create_instance(['course' => $this->course->id, 'package_entries' => $entries]);
        [$au1, $au2] = $this->aus();

        // B: two launches of AU1 (60 s and 30 s) and a synthetic session without duration.
        $regb = $plugin->create_registration(['scormmakercmi5id' => $this->instance->id, 'userid' => $this->learners['B']->id]);
        $this->session($regb, $au1, 0, 1000000, 1500000, 60000);
        $this->session($regb, $au1, 0, 2000000, 2500000, 30000);
        $this->session($regb, $au2, 1, 9000000, 9000000, null);

        // C: satisfied, AU1 passed, AU2 waived, grade 0.8.
        $regc = $plugin->create_registration([
            'scormmakercmi5id' => $this->instance->id, 'userid' => $this->learners['C']->id,
            'satisfied' => 1, 'grade' => 0.8,
        ]);
        $this->session($regc, $au1, 0, 3000000, 3100000, 100000);
        $DB->set_field('scormmakercmi5_regau', 'passed', 1, ['regid' => $regc->id, 'auid' => $au1->id]);
        $DB->set_field('scormmakercmi5_regau', 'satisfied', 1, ['regid' => $regc->id, 'auid' => $au1->id]);
        $DB->set_field('scormmakercmi5_regau', 'waived', 1, ['regid' => $regc->id, 'auid' => $au2->id]);
        $DB->set_field('scormmakercmi5_regau', 'satisfied', 1, ['regid' => $regc->id, 'auid' => $au2->id]);
    }

    /**
     * Inserts a session.
     *
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU.
     * @param int $kind 0 launch, 1 synthetic.
     * @param int $launchedms timelaunchedms.
     * @param int $lastms lastactivityms.
     * @param int|null $durationms durationms.
     */
    private function session(\stdClass $reg, \stdClass $au, int $kind, int $launchedms, int $lastms, ?int $durationms): void {
        global $DB;
        $DB->insert_record('scormmakercmi5_session', (object)[
            'regid' => $reg->id, 'auid' => $au->id, 'userid' => $reg->userid,
            'sessionuuid' => \core\uuid::generate(),
            'kind' => $kind, 'launchmode' => 'Normal', 'status' => 2, 'fetchused' => 1, 'tokenexpires' => 0,
            'prefsread' => 1, 'timelaunchedms' => $launchedms, 'timeinitializedms' => $launchedms,
            'lastactivityms' => $lastms, 'timeendedms' => $lastms, 'durationms' => $durationms,
        ]);
    }

    /**
     * Inserts a statement.
     *
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU.
     * @param string $verb Verb IRI.
     * @param int $timestampms Timestamp.
     * @param array $statement Fields of the payload (object, result).
     * @param array $columns Indexed columns (success, completion, scorescaled, durationms).
     */
    private function statement(
        \stdClass $reg,
        \stdClass $au,
        string $verb,
        int $timestampms,
        array $statement,
        array $columns = []
    ): void {
        global $DB;
        $id = \core\uuid::generate();
        $payload = ['id' => $id, 'verb' => ['id' => $verb]] + $statement;
        $DB->insert_record('scormmakercmi5_stmt', (object)($columns + [
            'scormmakercmi5id' => $this->instance->id, 'pkgid' => $reg->pkgid, 'regid' => $reg->id, 'auid' => $au->id,
            'userid' => $reg->userid, 'statementid' => $id, 'source' => 0, 'verb' => $verb,
            'objecthash' => sha1($statement['object']['id']), 'objectid' => $statement['object']['id'], 'cmi5kind' => 1,
            'timestampms' => $timestampms, 'storedms' => $timestampms, 'smprofile' => 0,
            'payload' => json_encode($payload),
        ]));
    }

    /**
     * The table of the activity, as report.php builds it.
     *
     * @param int $groupid Group.
     * @param string $status Status filter.
     * @return basic_table
     */
    private function table(int $groupid, string $status): basic_table {
        global $DB;
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $aus = $DB->get_records('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], 'sortorder, id');
        $table = new basic_table('report' . random_string(4), $this->cm(), $instance, array_values($aus), $groupid, $status);
        $table->define_baseurl(new \moodle_url('/mod/scormmakercmi5/report.php', ['id' => $this->instance->cmid]));
        return $table;
    }

    /**
     * The formatted rows of the first page (HTML), by user id.
     *
     * @param basic_table $table Table.
     * @return array userid => column => cell.
     */
    private function rows(basic_table $table): array {
        $rows = [];
        foreach ($this->raw($table) as $userid => $row) {
            $rows[$userid] = $table->format_row($row);
        }
        return $rows;
    }

    /**
     * The raw rows of the first page, by user id.
     *
     * @param basic_table $table Table.
     * @return \stdClass[]
     */
    private function raw(basic_table $table): array {
        if (!$table->setup) {
            $table->setup();
            $table->query_db(100, false);
        }
        return $table->rawdata;
    }

    /**
     * The AUs of the current package, in order.
     *
     * @return \stdClass[]
     */
    private function aus(): array {
        global $DB;
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $this->instance->id], MUST_EXIST);
        return array_values($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder'));
    }

    /**
     * The course module.
     *
     * @return \cm_info
     */
    private function cm(): \cm_info {
        return get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
    }

    /**
     * The ids of learners by letter.
     *
     * @param string ...$letters Letters.
     * @return int[]
     */
    private function ids(string ...$letters): array {
        return array_map(fn($l) => (int)$this->learners[$l]->id, $letters);
    }

    /**
     * A role id by shortname.
     *
     * @param string $shortname Shortname.
     * @return int
     */
    private function get_role(string $shortname): int {
        global $DB;
        return (int)$DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
