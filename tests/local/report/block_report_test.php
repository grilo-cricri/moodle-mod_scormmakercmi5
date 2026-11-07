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
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contract.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5\local\lrs\xapi_time;
use mod_scormmakercmi5\local\package\analysis_structure;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5_test_contract as contract;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\block_report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\block_aggregator::class)]
/**
 * The block activity report against the contract shared with SCORM Maker (subproject D): the statements of every case
 * of the contract go through the real ingestion (cmi5 sessions, one per learner) and the aggregates must be the
 * expected ones.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\report\block_report
 * @covers     \mod_scormmakercmi5\local\report\block_aggregator
 */
final class block_report_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time: 2026-10-01T12:00:00Z, after every statement of the contract. */
    private const NOW = 1790856000;

    /** @var array The case of the contract being tested. */
    private array $case;

    /** @var array Registration id by learner letter. */
    private array $regs = [];

    /** @var array User id by learner letter. */
    private array $users = [];

    /**
     * The contract.
     *
     * @return array
     */
    private static function contract(): array {
        $path = \core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contrato-dados-ricos.json';
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Every case of the contract.
     *
     * @return array
     */
    public static function case_provider(): array {
        $out = [];
        foreach (self::contract()['casos'] as $n => $case) {
            $out[$case['nome']] = [$n];
        }
        return $out;
    }

    /**
     * The learners of a case post its statements, each in a cmi5 session of their own.
     *
     * @param int $n Index of the case.
     * @param array $record More fields of the instance.
     * @param bool $storeoutside Whether to also store the refused statements as rows written before the limits existed.
     */
    private function load_case(int $n, array $record = [], bool $storeoutside = false): void {
        global $DB;
        $this->case = self::contract()['casos'][$n];
        $outside = $this->case['foraDoLimite'] ?? [];
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Unidade 1', 'moveOn' => 'Completed'],
        ]), $record);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        foreach (array_keys($this->case['statements']) as $i => $letter) {
            $user = $i === 0 ? $this->user : $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            $this->users[$letter] = (int)$user->id;
            [$session, $token] = $this->launch_with_token((int)$user->id);
            $this->regs[$letter] = (int)$session->regid;
            $batch = [$this->cmi5_statement($session, vocab::VERB_INITIALIZED)];
            $refused = [];
            foreach ($this->case['statements'][$letter] as $st) {
                if (in_array($st['id'], $outside, true)) {
                    $refused[] = $this->adapt($st, $session);
                } else {
                    $batch[] = $this->adapt($st, $session);
                }
            }
            $response = $this->post_statements($token, $batch);
            $this->assertSame(200, $response->status, $response->body);
            // The statements outside the limits are refused, one by one, as the player sends them again (FilaDeEnvio).
            foreach ($refused as $st) {
                $response = $this->post_statements($token, [$st]);
                $this->assertSame(400, $response->status, $st['id']);
                $this->assertSame('err_lrsanalysislimit', json_decode($response->body, true)['error'], $st['id']);
                $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
                if ($storeoutside) {
                    // A row stored before the limits existed: the columns of a valid row of the same learner, this payload.
                    $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => strtolower($batch[1]['id'])], '*', MUST_EXIST);
                    unset($row->id);
                    $row->statementid = strtolower($st['id']);
                    $row->objectid = $st['object']['id'];
                    $row->objecthash = sha1($st['object']['id']);
                    $sm = statement_store::sm_columns($st);
                    $row->smkind = $sm->smkind;
                    $row->smblock = $sm->smblock;
                    $row->smitem = $sm->smitem;
                    $row->smblocktype = $sm->smblocktype;
                    $row->verb = $st['verb']['id'];
                    // Its own time; without a timestamp (the plugin cannot store one), the time of that valid row.
                    if (isset($st['timestamp'])) {
                        $row->timestampms = xapi_time::to_ms($st['timestamp']);
                    }
                    $row->durationms = 0;
                    $row->payload = json_encode($st, statement_store::JSON_FLAGS);
                    $DB->insert_record('scormmakercmi5_stmt', $row);
                }
            }
        }
    }

    /**
     * Starts measuring the peak memory: the current usage, from which memory_get_peak_usage() then grows (PHP 8.2+;
     * before it, the peak of the whole process, so the base is that peak and the check is weaker).
     *
     * @return int Base, bytes.
     */
    private static function reset_peak(): int {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
            return memory_get_usage();
        }
        return memory_get_peak_usage();
    }

    /**
     * Final wave W2a (M-10): a heatmap counts only on a block the structure marks as spatial.
     */
    public function test_heatmap_only_on_spatial_blocks(): void {
        $structure = ['blocos' => [
            't' => ['id' => 't', 'itens' => [], 'cenas' => [], 'midia' => false, 'espacial' => false],
            'm' => ['id' => 'm', 'itens' => [], 'cenas' => [], 'midia' => false, 'espacial' => true],
        ]];
        $map = ['extensions' => [vocab::SM_EXT_HEATMAP => ['grade' => 10, 'celulas' => ['1,1' => 2]]]];
        $rows = [self::row(1, 1, 't', null, $map), self::row(2, 1, 'm', null, $map)];
        $blocks = block_aggregator::activity(1, [], $rows, $structure)['blocos'];
        $this->assertArrayNotHasKey('mapa', $blocks['t']);
        $this->assertSame(['grade' => 10, 'celulas' => ['1,1' => 2], 'cenas' => []], $blocks['m']['mapa']);
    }

    /**
     * Final wave W2a (M-1, M-2): a summary stored before with a duration outside the rule or a percentualAssistido
     * outside 0..100 is ignored entirely, as any summary outside the limits.
     */
    public function test_summary_with_bad_duration_or_percent_is_ignored(): void {
        $summary = [vocab::SM_EXT_SUMMARY => ['cliques' => 7, 'aberturas' => 0, 'itensVistos' => ['a'], 'itensTotal' => 1]];
        $bad = [
            ['duration' => 'P1D', 'extensions' => $summary],
            ['duration' => 'PT86400.5S', 'extensions' => $summary],
            ['duration' => null, 'extensions' => $summary],
            ['extensions' => $summary + [vocab::SM_EXT_VIDEO => [
                'duracaoMidia' => 20, 'trechosAssistidos' => [], 'percentualAssistido' => 1.5e19,
            ]]],
        ];
        foreach ($bad as $n => $result) {
            $rows = [self::row(1, 1, 'b', null, $result)];
            $this->assertSame([], block_aggregator::activity(1, [], $rows)['blocos'], (string)$n);
            $this->assertSame([], block_aggregator::learner([], $rows)['blocos'], (string)$n);
        }
    }

    /**
     * Rows outside the limits stored before the LRS refused them (a duration of 10^7 s among them) are ignored by the
     * report, which gives the aggregates of the contract and never allocates from them (audit M-D1).
     */
    public function test_rows_outside_the_limits_stored_before_are_ignored(): void {
        global $DB;
        $n = array_search('valores-fora-do-limite', array_column(self::contract()['casos'], 'nome'), true);
        $this->load_case($n, [], true);
        $this->assertSame(8, $DB->count_records_select('scormmakercmi5_stmt', 'durationms = 0'));
        $before = self::reset_peak();
        $actual = block_report::activity(
            (int)$this->instance->id,
            $this->case['versaoDoPacote'],
            group_filter::everyone(),
            $this->case['estrutura']
        );
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertEquals($this->case['atividade'], $actual);
        foreach ($this->case['porAluno'] as $letter => $expected) {
            $actual = block_report::learner($this->regs[$letter], $this->case['versaoDoPacote'], $this->case['estrutura']);
            $this->assertEquals($expected, $actual, $letter);
        }
    }

    /**
     * Every case of the contract with statements outside the limits.
     *
     * @return array
     */
    public static function outside_case_provider(): array {
        $cases = self::contract()['casos'];
        return array_filter(self::case_provider(), static fn(array $n): bool => !empty($cases[$n[0]]['foraDoLimite']));
    }

    /**
     * In every case with statements outside the limits (percentualAssistido, duration of the summary, timestamp, …),
     * those rows stored before the LRS refused them are ignored: the aggregates are still the ones of the contract
     * (the same check as the hosted course, test_fora_do_limite_gravado_antes_e_ignorado).
     *
     * @dataProvider outside_case_provider
     * @param int $n Index of the case.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('outside_case_provider')]
    public function test_outside_rows_stored_before_are_ignored_in_every_case(int $n): void {
        global $DB;
        $this->load_case($n, [], true);
        $ids = array_map('strtolower', $this->case['foraDoLimite']);
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $this->assertSame(count($ids), $DB->count_records_select('scormmakercmi5_stmt', "statementid {$insql}", $params));
        $actual = block_report::activity(
            (int)$this->instance->id,
            $this->case['versaoDoPacote'],
            group_filter::everyone(),
            $this->case['estrutura']
        );
        $this->assertEquals($this->case['atividade'], $actual);
        foreach ($this->case['porAluno'] as $letter => $expected) {
            $actual = block_report::learner($this->regs[$letter], $this->case['versaoDoPacote'], $this->case['estrutura']);
            $this->assertEquals($expected, $actual, $letter);
        }
    }

    /**
     * A statement of the contract inside a cmi5 session: actor, registration, the AU grouping and the session id.
     *
     * @param array $st Statement of the contract.
     * @param \stdClass $session Session.
     * @return array
     */
    private function adapt(array $st, \stdClass $session): array {
        $base = $this->cmi5_statement($session, $st['verb']['id']);
        $st['actor'] = $base['actor'];
        $st['context']['registration'] = $base['context']['registration'];
        $st['context']['contextActivities']['grouping'] = array_merge(
            $base['context']['contextActivities']['grouping'],
            $st['context']['contextActivities']['grouping'] ?? []
        );
        $st['context']['extensions'][vocab::EXT_SESSIONID] = $session->sessionuuid;
        return $st;
    }

    /**
     * The aggregates of the class are the ones of the contract, in every case.
     *
     * @dataProvider case_provider
     * @param int $n Index of the case.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('case_provider')]
    public function test_activity_matches_the_contract(int $n): void {
        $this->load_case($n);
        $actual = block_report::activity(
            (int)$this->instance->id,
            $this->case['versaoDoPacote'],
            group_filter::everyone(),
            $this->case['estrutura']
        );
        $this->assertEquals($this->case['atividade'], $actual);
        $this->assertSame(contract::by_value($this->case['atividade']), contract::by_value($actual), 'numbers by value');
        // Same ids, as text, in the order of the contract (an all-digits id is an integer key in PHP: case 3).
        $this->assertSame(
            array_map('strval', array_keys($this->case['atividade']['blocos'])),
            array_map('strval', array_keys($actual['blocos']))
        );
    }

    /**
     * The summary of each learner is the one of the contract (no sequence, no coordinates), in every case.
     *
     * @dataProvider case_provider
     * @param int $n Index of the case.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('case_provider')]
    public function test_learners_match_the_contract(int $n): void {
        $this->load_case($n);
        foreach ($this->case['porAluno'] as $letter => $expected) {
            $actual = block_report::learner($this->regs[$letter], $this->case['versaoDoPacote'], $this->case['estrutura']);
            $this->assertEquals($expected, $actual, $letter);
            $this->assertSame(contract::by_value($expected), contract::by_value($actual), $letter);
        }
    }

    /**
     * Nesting changes no number: the same statements with the children flattened to top-level blocks.
     */
    public function test_nesting_changes_no_number(): void {
        $this->resetAfterTest();
        $case = contract::case_named('blocos-aninhados');
        $flat = $case['estrutura'];
        $flat['v'] = 1;
        $flat['licoes'][0]['blocos'] = ['mp1', 'vn1', 'fn1'];
        foreach ($flat['blocos'] as &$b) {
            unset($b['pai'], $b['filhos']);
        }
        unset($b);
        $this->load_case(array_search('blocos-aninhados', array_column(self::contract()['casos'], 'nome'), true));
        $nested = block_report::activity(
            (int)$this->instance->id,
            $case['versaoDoPacote'],
            group_filter::everyone(),
            $case['estrutura']
        );
        $this->assertEquals($case['atividade'], $nested);
        $this->assertEquals(
            $nested,
            block_report::activity((int)$this->instance->id, $case['versaoDoPacote'], group_filter::everyone(), $flat)
        );
        foreach ($this->regs as $letter => $regid) {
            $this->assertEquals(
                block_report::learner($regid, $case['versaoDoPacote'], $case['estrutura']),
                block_report::learner($regid, $case['versaoDoPacote'], $flat),
                $letter
            );
        }
    }

    /**
     * The user filter restricts the learners; another version has its own numbers; nobody gives an empty report.
     */
    public function test_filters(): void {
        $this->load_case(0);
        $groupc = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupc->id, 'userid' => $this->users['C']]);
        $only = block_report::activity(
            (int)$this->instance->id,
            $this->case['versaoDoPacote'],
            group_filter::from_groups([(int)$groupc->id]),
            $this->case['estrutura']
        );
        $this->assertSame(1, $only['alunos']);
        $this->assertSame(['v1', 'x1'], array_keys($only['blocos']));
        $other = block_report::activity(
            (int)$this->instance->id,
            'outra-versao',
            group_filter::everyone(),
            $this->case['estrutura']
        );
        $this->assertSame(1, $other['alunos']);
        $this->assertSame(['t1'], array_keys($other['blocos']));
        $nobody = block_report::activity(
            (int)$this->instance->id,
            $this->case['versaoDoPacote'],
            group_filter::from_groups([-1]),
            $this->case['estrutura']
        );
        $this->assertSame(['alunos' => 0, 'blocos' => []], $nobody);
        $this->assertTrue(block_report::has_blocks((int)$this->instance->id, $this->case['versaoDoPacote']));
        $this->assertFalse(block_report::has_blocks((int)$this->instance->id, 'nenhuma'));
    }

    /**
     * Separate groups: a teacher without accessallgroups sees the aggregates of their own groups only, and a teacher
     * in no group sees nobody (the same group restriction as the reports of C).
     */
    public function test_separate_groups(): void {
        $this->load_case(0, ['groupmode' => SEPARATEGROUPS]);
        $gen = $this->getDataGenerator();
        $mine = $gen->create_group(['courseid' => $this->course->id]);
        $others = $gen->create_group(['courseid' => $this->course->id]);
        $gen->create_group_member(['groupid' => $mine->id, 'userid' => $this->users['C']]);
        foreach (['A', 'B'] as $letter) {
            $gen->create_group_member(['groupid' => $others->id, 'userid' => $this->users[$letter]]);
        }
        $teacher = $gen->create_and_enrol($this->course, 'teacher');
        $gen->create_group_member(['groupid' => $mine->id, 'userid' => $teacher->id]);
        $lonely = $gen->create_and_enrol($this->course, 'teacher');
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
        $context = \context_module::instance($cm->id);

        $this->setUser($teacher);
        $filter = group_filter::from_restriction(basic_table::restrict_groups($cm, $context, 0));
        $seen = block_report::activity(
            (int)$this->instance->id,
            $this->case['versaoDoPacote'],
            $filter,
            $this->case['estrutura']
        );
        $this->assertSame(1, $seen['alunos']);
        $this->assertSame(['v1', 'x1'], array_keys($seen['blocos']));
        $this->assertSame(100, $seen['blocos']['v1']['percentualAlcance']);
        $this->assertSame([100, 0, 0, 0], $seen['blocos']['v1']['midia']['retencao']);

        $this->setUser($lonely);
        $filter = group_filter::from_restriction(basic_table::restrict_groups($cm, $context, 0));
        $this->assertTrue($filter->nobody());
        $seen = block_report::activity(
            (int)$this->instance->id,
            $this->case['versaoDoPacote'],
            $filter,
            $this->case['estrutura']
        );
        $this->assertSame(['alunos' => 0, 'blocos' => []], $seen);
    }

    /**
     * Visible groups: all groups give the aggregates of the contract; one group, only its learners; a learner in
     * several of the chosen groups counts once. The export of a group is the same as the report of that group.
     */
    public function test_visible_groups_and_a_learner_in_several_groups(): void {
        $this->load_case(0, ['groupmode' => VISIBLEGROUPS]);
        $gen = $this->getDataGenerator();
        $one = $gen->create_group(['courseid' => $this->course->id]);
        $two = $gen->create_group(['courseid' => $this->course->id]);
        $gen->create_group_member(['groupid' => $one->id, 'userid' => $this->users['C']]);
        foreach (['A', 'B', 'C'] as $letter) {
            $gen->create_group_member(['groupid' => $two->id, 'userid' => $this->users[$letter]]);
        }
        $teacher = $gen->create_and_enrol($this->course, 'teacher');
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
        $context = \context_module::instance($cm->id);
        $this->setUser($teacher);
        $version = $this->case['versaoDoPacote'];
        $activity = fn(array $groupids): array => block_report::activity(
            (int)$this->instance->id,
            $version,
            group_filter::from_restriction($groupids),
            $this->case['estrutura']
        );

        $all = basic_table::restrict_groups($cm, $context, 0);
        $this->assertSame([], $all, 'visible groups: no restriction');
        $this->assertEquals($this->case['atividade'], $activity($all));
        $this->assertEquals($this->case['atividade'], $activity([(int)$one->id, (int)$two->id]), 'C counts once');
        $this->assertEquals($this->case['atividade'], $activity([(int)$two->id]));
        $onlyone = $activity(basic_table::restrict_groups($cm, $context, (int)$one->id));
        $this->assertSame(1, $onlyone['alunos']);
        $this->assertSame(['v1', 'x1'], array_keys($onlyone['blocos']));

        $pkg = (object)[
            'scormmakercmi5id' => (int)$this->instance->id,
            'richstructure' => analysis_structure::encode(analysis_structure::from_json(
                json_encode($this->case['estrutura']),
                ['media/m1.jpg']
            )),
        ];
        $verified = new verification_result(verification_result::STATUS_VERIFIED);
        $structure = block_report::structure($pkg, $verified);
        $expected = block_export::tables($structure, block_report::activity(
            (int)$this->instance->id,
            $version,
            group_filter::from_groups([(int)$one->id]),
            $structure
        ));
        $this->assertSame($expected, block_export::sheet($cm, $pkg, $verified, $version, (int)$one->id));
        $this->assertNotSame($expected, block_export::sheet($cm, $pkg, $verified, $version, 0));
    }

    /**
     * The structure of the package is read only when the signature was verified at this opening.
     */
    public function test_structure_only_when_verified(): void {
        $structure = ['v' => 1, 'cursoId' => 'c1', 'licoes' => [], 'blocos' => (object)[]];
        $pkg = (object)['richstructure' => json_encode($structure)];
        $verified = new verification_result(verification_result::STATUS_VERIFIED);
        $this->assertNotNull(block_report::structure($pkg, $verified));
        $this->assertSame(analysis_structure::decode($pkg->richstructure), block_report::structure($pkg, $verified));
        $this->assertNull(block_report::structure($pkg, new verification_result(verification_result::STATUS_UNSIGNED)));
        $invalid = new verification_result(verification_result::STATUS_INVALID, 'filechanged');
        $this->assertNull(block_report::structure($pkg, $invalid));
        $this->assertNull(block_report::structure((object)['richstructure' => null], $verified));
        // The structure of the case with all-digits ids is accepted by the import and read back with those ids.
        $digits = self::contract()['casos'][2]['estrutura'];
        $stored = analysis_structure::encode(analysis_structure::from_json(json_encode($digits), []));
        $read = block_report::structure((object)['richstructure' => $stored], $verified);
        $this->assertSame(['123', '456'], array_map('strval', array_keys($read['blocos'])));
        $this->assertSame('42', $read['blocos']['456']['itens'][0]['id']);
    }

    /**
     * The rounding rules of the contract.
     */
    public function test_rounding(): void {
        $this->assertSame(67, block_aggregator::pct(2, 3));
        $this->assertSame(33, block_aggregator::pct(1, 3));
        $this->assertSame(0, block_aggregator::pct(5, 0));
        $this->assertSame(7500, block_aggregator::mean(15000, 2));
        $this->assertSame(1.5, block_aggregator::tenth(3, 2));
        $this->assertSame(0.0, block_aggregator::tenth(0, 0));
    }

    /**
     * A row as the report query gives it.
     *
     * @param int $id Row id.
     * @param int $reg Registration.
     * @param string $block Block.
     * @param string|null $item Item of a decision (null = block summary).
     * @param array $result result of the statement.
     * @param array $context Context extensions.
     * @return \stdClass
     */
    private static function row(int $id, int $reg, string $block, ?string $item, array $result, array $context = []): \stdClass {
        return (object)[
            'id' => $id, 'regid' => $reg, 'smkind' => $item === null ? 'bloco' : 'item', 'smblock' => $block,
            'smitem' => $item ?? $block, 'smblocktype' => 'x', 'timestampms' => $id,
            'payload' => json_encode(['result' => $result, 'context' => ['extensions' => $context]]),
        ];
    }

    /**
     * A choice of an old package (no cena-de-destino) leaves the learner in the scene of the choice; with it, in the
     * destination of the latest choice.
     */
    public function test_scenario_dropoff_fallback(): void {
        $kind = [vocab::SM_EXT_DECISIONTYPE => 'escolha'];
        $rows = [
            self::row(1, 1, 'c', 's1', ['response' => 'Ir']),
            self::row(2, 2, 'c', 's1', ['response' => 'Ir', 'extensions' => [vocab::SM_EXT_DESTSCENE => 's2']], $kind),
            self::row(3, 2, 'c', 's2', ['response' => 'Voltar', 'extensions' => [vocab::SM_EXT_DESTSCENE => '']], $kind),
            self::row(4, 3, 'c', 's1', ['response' => 'Ir', 'extensions' => [vocab::SM_EXT_DESTSCENE => 's9']], $kind),
        ];
        $rows[0]->payload = json_encode(['result' => ['response' => 'Ir'], 'context' => ['extensions' => $kind]]);
        $out = block_aggregator::activity(3, [], $rows)['blocos']['c'];
        $this->assertSame(['s1' => 1, 's2' => 1, 's9' => 1], $out['abandonoPorCena']);
        $this->assertSame(['s1' => ['Ir' => 3], 's2' => ['Voltar' => 1]], $out['escolhas']);
        $this->assertSame([], $out['finais']);
    }

    /**
     * A point found again on every page load counts once per learner; "sei" counts the last answer of each learner.
     */
    public function test_decisions_count_learners(): void {
        $found = [vocab::SM_EXT_DECISIONTYPE => 'descoberto'];
        $know = [vocab::SM_EXT_DECISIONTYPE => 'sei'];
        $rows = [
            self::row(1, 1, 'tv', 'h1', ['response' => 'descoberto'], $found),
            self::row(2, 1, 'tv', 'h1', ['response' => 'descoberto'], $found),
            self::row(3, 1, 'tv', 'h1', ['response' => 'descoberto'], $found),
            self::row(4, 1, 'f', 'k1', ['response' => 'sei'], $know),
            self::row(5, 1, 'f', 'k1', ['response' => 'não sei'], $know),
            self::row(6, 2, 'tv', 'h1', ['response' => 'descoberto'], $found),
            self::row(7, 2, 'tv', 'h2', ['response' => 'descoberto'], $found),
            self::row(8, 2, 'f', 'k1', ['response' => 'sei'], $know),
        ];
        $out = block_aggregator::activity(2, [], $rows)['blocos'];
        $this->assertSame(['h1' => 2, 'h2' => 1], $out['tv']['descobertos']);
        $this->assertSame(['k1' => ['sei' => 1, 'naoSei' => 1, 'percentualSei' => 50]], $out['f']['sei']);
    }

    /**
     * A band is covered when the overlap is exactly one second, in decimal seconds (1.3 to 2.3 is 1 s, although the
     * float subtraction gives 0.9999999999999998); overlapping segments are merged.
     */
    public function test_media_in_exact_tenths(): void {
        $video = static fn(array $segments, float $duration, ?int $pct = null): array => ['extensions' => [
            vocab::SM_EXT_VIDEO => ['duracaoMidia' => $duration, 'trechosAssistidos' => $segments]
                + ($pct === null ? [] : ['percentualAssistido' => $pct]),
        ]];
        $rows = [
            self::row(1, 1, 'v', null, $video([[1.3, 2.3]], 12.5)),
            self::row(2, 2, 'v', null, $video([[0, 4], [3.5, 12.5]], 12.5)),
            self::row(3, 3, 'v', null, $video([[0, 1]], 12.5, 90)),
        ];
        $media = block_aggregator::activity(3, ['v' => ['alcancaram' => 3, 'tempo' => 0]], $rows)['blocos']['v']['midia'];
        $this->assertSame(12.5, $media['duracaoMidia']);
        $this->assertSame([100, 33, 33], $media['retencao']);
        $this->assertSame([0, 0, 0], $media['pulados']);
        $this->assertSame([2, 0, 0], $media['abandonos']);
        // 1: 8 %; 2: 100 %; 3: max(8 %, 90 % of the statement).
        $this->assertSame(66, $media['percentualMedioAssistido']);
        $learner = block_aggregator::learner(['v' => ['tempo' => 5]], [$rows[2]]);
        $this->assertSame(['tempoMs' => 5, 'percentualAssistido' => 90], $learner['blocos']['v']);
    }

    /**
     * A summary outside the limits (stored before the LRS refused it) is ignored entirely, never clamped: its
     * interactions, items, media and map do not count, and a duration of 10^7 s allocates nothing.
     */
    public function test_summary_outside_the_limits_is_ignored(): void {
        $good = ['extensions' => [
            vocab::SM_EXT_SUMMARY => ['cliques' => 1, 'aberturas' => 0, 'itensVistos' => ['i1'], 'itensTotal' => 1],
            vocab::SM_EXT_VIDEO => ['duracaoMidia' => 10, 'trechosAssistidos' => [[0, 5]], 'percentualAssistido' => 50],
            vocab::SM_EXT_HEATMAP => ['grade' => 10, 'celulas' => ['1,1' => 1]],
        ]];
        $bad = static function (array $change) use ($good): array {
            return array_replace_recursive($good, ['extensions' => $change]);
        };
        $outside = [
            $bad([vocab::SM_EXT_VIDEO => ['duracaoMidia' => 10000000]]),
            $bad([vocab::SM_EXT_VIDEO => ['duracaoMidia' => 'Infinity']]),
            $bad([vocab::SM_EXT_VIDEO => ['trechosAssistidos' => [[-3, 4]]]]),
            $bad([vocab::SM_EXT_VIDEO => ['trechosAssistidos' => [[0, 11]]]]),
            $bad([vocab::SM_EXT_VIDEO => ['trechosAssistidos' => array_fill(0, 41, [0, 1])]]),
            $bad([vocab::SM_EXT_HEATMAP => ['celulas' => ['10,0' => 1]]]),
            $bad([vocab::SM_EXT_HEATMAP => ['grade' => 1000]]),
            $bad([vocab::SM_EXT_SUMMARY => ['cliques' => 100001]]),
            $bad([vocab::SM_EXT_SUMMARY => ['itensVistos' => array_fill(0, 501, 'i1')]]),
        ];
        $rows = [self::row(1, 1, 'b', null, $good)];
        foreach ($outside as $n => $result) {
            $rows[] = self::row($n + 2, 1, 'b', null, $result);
        }
        $groups = ['b' => ['alcancaram' => 1, 'tempo' => 0]];
        $before = self::reset_peak();
        $all = block_aggregator::activity(1, $groups, $rows)['blocos']['b'];
        $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertEquals(block_aggregator::activity(1, $groups, [$rows[0]])['blocos']['b'], $all);
        $this->assertSame(10, $all['midia']['duracaoMidia']);
        $this->assertSame(1.0, $all['interacoesPorAluno']);
        $this->assertSame(['1,1' => 1], $all['mapa']['celulas']);
        $this->assertSame(['tempoMs' => 0, 'percentualAssistido' => 50], block_aggregator::learner($groups, $rows)['blocos']['b']);
        // A learner whose only summary is outside the limits has no media at all (the block still comes from SQL).
        $this->assertArrayNotHasKey('midia', block_aggregator::activity(1, $groups, [$rows[1]])['blocos']['b'] ?? []);
    }

    /**
     * A media of unknown duration (the player sends 0, with no segments) counts for reach, time and clicks and stays
     * out of the media math, even with a percentage in the statement.
     */
    public function test_unknown_duration_is_out_of_the_media(): void {
        $video = static fn(float $duration, array $segments, int $pct): array => ['extensions' => [
            vocab::SM_EXT_SUMMARY => ['cliques' => 2, 'aberturas' => 0, 'itensVistos' => [], 'itensTotal' => 0],
            vocab::SM_EXT_VIDEO => ['duracaoMidia' => $duration, 'trechosAssistidos' => $segments,
                'percentualAssistido' => $pct],
        ]];
        $rows = [self::row(1, 1, 'v', null, $video(0, [], 80)), self::row(2, 2, 'v', null, $video(10, [[0, 5]], 50))];
        $groups = ['v' => ['alcancaram' => 2, 'tempo' => 0]];
        $out = block_aggregator::activity(2, $groups, $rows)['blocos']['v'];
        $this->assertSame(25, $out['midia']['percentualMedioAssistido']);
        $this->assertSame([50, 0], $out['midia']['retencao']);
        $this->assertSame([1, 0], $out['midia']['abandonos']);
        $this->assertSame(2.0, $out['interacoesPorAluno']);
        $this->assertSame(['tempoMs' => 0], block_aggregator::learner($groups, [$rows[0]])['blocos']['v']);
        $this->assertArrayNotHasKey('midia', block_aggregator::activity(1, $groups, [$rows[0]])['blocos']['v']);
    }

    /**
     * Blocks, items, scenes and decision kinds outside the verified structure are ignored, and thousands of distinct
     * ids cost nothing: the memory of the aggregates is bounded by the structure, not by what was stored.
     */
    public function test_ids_outside_the_structure_are_ignored(): void {
        $structure = ['blocos' => [
            'b' => ['id' => 'b', 'itens' => [['id' => 'i1']], 'cenas' => [['id' => 'cA']], 'espacial' => true],
            '7' => ['id' => '7', 'itens' => [['id' => '42']], 'cenas' => []],
        ]];
        $summary = static fn(array $items, array $scenes = []): array => ['extensions' => [
            vocab::SM_EXT_SUMMARY => ['cliques' => 1, 'aberturas' => 0, 'itensVistos' => $items, 'itensTotal' => 1],
            vocab::SM_EXT_HEATMAP => ['grade' => 10, 'celulas' => ['1,1' => 1]] + ($scenes ? ['cenas' => $scenes] : []),
        ]];
        $found = [vocab::SM_EXT_DECISIONTYPE => 'descoberto'];
        $rows = [
            self::row(1, 1, 'b', null, $summary(['i1', 'zz'], ['cA' => ['2,2' => 1], 'cZ' => ['3,3' => 1]])),
            self::row(2, 1, 'b', 'i1', ['response' => 'descoberto'], $found),
            self::row(3, 1, '7', '42', ['response' => 'descoberto'], $found),
        ];
        $id = 4;
        for ($n = 0; $n < 1500; $n++) {
            $rows[] = self::row($id++, 1, "x{$n}", null, $summary(['i1']));
            $unknownitems = array_map(fn($k) => "u{$n}-{$k}", range(1, 60));
            $rows[] = self::row($id++, 1, 'b', null, $summary($unknownitems, ["s{$n}" => ['1,1' => 1]]));
            $rows[] = self::row($id++, 1, 'b', "h{$n}", ['response' => 'descoberto'], $found);
            $rows[] = self::row($id++, 1, 'b', 'i1', ['response' => 'x'], [vocab::SM_EXT_DECISIONTYPE => "k{$n}"]);
        }
        $groups = ['b' => ['alcancaram' => 1, 'tempo' => 0], '7' => ['alcancaram' => 1, 'tempo' => 0]];
        $before = self::reset_peak();
        $out = block_aggregator::activity(1, $groups, $rows, $structure);
        $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame(['b', '7'], array_map('strval', array_keys($out['blocos'])));
        $b = $out['blocos']['b'];
        $this->assertSame(['i1' => ['abriram' => 1, 'percentual' => 100]], $b['itens']);
        $this->assertSame(['cA' => ['2,2' => 1]], $b['mapa']['cenas']);
        $this->assertSame(['i1' => 1], $b['descobertos']);
        $this->assertSame([42 => 1], $out['blocos']['7']['descobertos']);
        $learner = block_aggregator::learner($groups, $rows, $structure);
        $this->assertSame(['b'], array_map('strval', array_keys($learner['blocos'])));
        $this->assertSame(['b', '7'], array_map('strval', array_keys($learner['decisoes'])));
        $this->assertSame(['i1' => ['tipo' => 'descoberto', 'opcao' => 'descoberto']], $learner['decisoes']['b']);
    }

    /**
     * Thirty learners at the limits on one 86400 s media (300 summaries of 40 disjoint segments each): each learner
     * is folded into the per-band counters of the block as soon as their rows end, so the memory does not grow with
     * the class, and the numbers are those of the contract rules (a naive reference computed here).
     */
    public function test_many_learners_at_the_limits_bounded_memory(): void {
        $structure = ['blocos' => ['v' => ['id' => 'v', 'itens' => [], 'cenas' => [], 'midia' => true]]];
        $learners = 30;
        $bands = 17280;
        $duration = 864000;
        $rows = [];
        $covers = [];
        $id = 1;
        for ($j = 0; $j < $learners; $j++) {
            for ($n = 0; $n < 300; $n++) {
                $segments = [];
                for ($k = 0; $k < 40; $k++) {
                    $band = $j * 7 + $n * 40 + $k;
                    $covers[$j][$band] = true;
                    $segments[] = [$band * 5 + 1, $band * 5 + 2];
                }
                $rows[] = self::row($id++, $j + 1, 'v', null, ['extensions' => [
                    vocab::SM_EXT_VIDEO => ['duracaoMidia' => 86400, 'trechosAssistidos' => $segments],
                ]]);
            }
        }
        // Reference: a band is covered by 1 s of a segment; % per learner from the tenths covered.
        $covering = array_fill(0, $bands, 0);
        $skipped = array_fill(0, $bands, 0);
        $dropped = array_fill(0, $bands, 0);
        $sumpct = 0;
        foreach ($covers as $set) {
            $last = max(array_keys($set));
            foreach (array_keys($set) as $band) {
                $covering[$band]++;
            }
            for ($k = 0; $k < $last; $k++) {
                if (!isset($set[$k])) {
                    $skipped[$k]++;
                }
            }
            if ($last < $bands - 1) {
                $dropped[$last]++;
            }
            $sumpct += min(100, intdiv(200 * count($set) * 10 + $duration, 2 * $duration));
        }
        $groups = ['v' => ['alcancaram' => $learners, 'tempo' => 0]];

        $before = self::reset_peak();
        $media = block_aggregator::activity($learners, $groups, $rows, $structure)['blocos']['v']['midia'];
        // About 3 MB: the bands of the block and of one learner (17280 each) and one learner's union. Keeping every
        // learner's union until the end (before Task 24b round 3) took 11.4 MB here, and grew with the class.
        $this->assertLessThan(5 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame(86400, $media['duracaoMidia']);
        $this->assertSame(array_map(fn(int $n): int => block_aggregator::pct($n, $learners), $covering), $media['retencao']);
        $this->assertSame($skipped, $media['pulados']);
        $this->assertSame($dropped, $media['abandonos']);
        $this->assertSame(block_aggregator::mean($sumpct, $learners), $media['percentualMedioAssistido']);
    }

    /**
     * A decision whose answer is longer than 500 characters (stored before the LRS refused it) is ignored; 500
     * characters, emoji included, count.
     */
    public function test_long_answers_are_ignored(): void {
        $choice = [vocab::SM_EXT_DECISIONTYPE => 'escolha'];
        $ok = str_repeat('á', 498) . '🙂🙂';
        $rows = [
            self::row(1, 1, 'c', 's1', ['response' => $ok], $choice),
            self::row(2, 2, 'c', 's1', ['response' => 'Ir'], $choice),
            self::row(3, 2, 'c', 's1', ['response' => $ok . 'x'], $choice),
        ];
        $out = block_aggregator::activity(2, [], $rows)['blocos']['c'];
        $this->assertSame([$ok => 1, 'Ir' => 1], $out['escolhas']['s1']);
        $learner = block_aggregator::learner([], [$rows[1], $rows[2]]);
        $this->assertSame('Ir', $learner['decisoes']['c']['s1']['opcao']);
    }

    /**
     * The media of a block counts only when the structure says the block has one: a video extension sent for a text
     * block allocates no band.
     */
    public function test_video_only_on_media_blocks(): void {
        $structure = ['blocos' => [
            'x' => ['id' => 'x', 'itens' => [], 'cenas' => [], 'midia' => false],
            'v' => ['id' => 'v', 'itens' => [], 'cenas' => [], 'midia' => true],
        ]];
        $video = ['extensions' => [vocab::SM_EXT_VIDEO => ['duracaoMidia' => 86400, 'trechosAssistidos' => [[0, 10]]]]];
        $rows = [self::row(1, 1, 'x', null, $video), self::row(2, 1, 'v', null, $video)];
        $groups = ['x' => ['alcancaram' => 1, 'tempo' => 0], 'v' => ['alcancaram' => 1, 'tempo' => 0]];
        $out = block_aggregator::activity(1, $groups, $rows, $structure)['blocos'];
        $this->assertArrayNotHasKey('midia', $out['x']);
        $this->assertCount(17280, $out['v']['midia']['retencao']);
        $this->assertSame(['tempoMs' => 0], block_aggregator::learner($groups, [$rows[0]], $structure)['blocos']['x']);
    }

    /**
     * Thousands of summaries of one learner cost the size of the union of their segments, not their number.
     */
    public function test_many_segments_of_one_learner(): void {
        $rows = [];
        for ($n = 0; $n < 2000; $n++) {
            $segments = [];
            for ($k = 0; $k < 40; $k++) {
                $segments[] = [$k * 2, $k * 2 + 1];
            }
            $rows[] = self::row($n + 1, 1, 'v', null, ['extensions' => [
                vocab::SM_EXT_VIDEO => ['duracaoMidia' => 80, 'trechosAssistidos' => $segments],
            ]]);
        }
        $media = block_aggregator::activity(1, ['v' => ['alcancaram' => 1, 'tempo' => 0]], $rows)['blocos']['v']['midia'];
        $this->assertSame(array_fill(0, 16, 100), $media['retencao']);
        $this->assertSame(50, $media['percentualMedioAssistido']);
    }
}
