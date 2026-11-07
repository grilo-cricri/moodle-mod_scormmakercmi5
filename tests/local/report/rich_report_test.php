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

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\rich_report::class)]
/**
 * Tests for the rich report of a SCORM Maker package (R8, design §8.2).
 *
 * Statements go through the real ingestion (store_batch, via the LRS router). Learner A (version '1'): lessons l1
 * (30 s + 10 s) and l2 (20 s), progress 30 then 60, question q1 wrong then right (response "b"), q2 right, then the
 * session is terminated with the AU not completed; plus one lesson l9 of version '2'. Learner B (version '1'): l1
 * (50 s), q1 right then wrong, progress 50, session still open.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\report\rich_report
 */
final class rich_report_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string Base IRI of the SCORM Maker course. */
    private const SMCOURSE = 'https://scormmaker.com.br/xapi/curso/c1';

    /** @var \stdClass Learner B. */
    private \stdClass $userb;

    /** @var int Group of learner A. */
    private int $groupa;

    /** @var int Group of learner B. */
    private int $groupb;

    /** @var array Registrations by letter. */
    private array $regs = [];

    /** @var array Open sessions by letter: [session, token]. */
    private array $launches = [];

    /**
     * Activity, two learners and their statements.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Unidade 1', 'moveOn' => 'Completed'],
        ]));
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        $this->userb = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->groupa = (int)$this->getDataGenerator()->create_group(['courseid' => $this->course->id])->id;
        $this->groupb = (int)$this->getDataGenerator()->create_group(['courseid' => $this->course->id])->id;
        $this->getDataGenerator()->create_group_member(['groupid' => $this->groupa, 'userid' => $this->user->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $this->groupb, 'userid' => $this->userb->id]);
        $this->launches['A'] = $this->launch_with_token((int)$this->user->id);
        $this->launches['B'] = $this->launch_with_token((int)$this->userb->id);
        foreach (['A' => $this->user, 'B' => $this->userb] as $letter => $user) {
            $this->regs[$letter] = $DB->get_record(
                'scormmakercmi5_reg',
                ['scormmakercmi5id' => $this->instance->id, 'userid' => $user->id],
                '*',
                MUST_EXIST
            );
        }
        $definition = [
            'type' => 'http://adlnet.gov/expapi/activities/cmi.interaction',
            'name' => ['pt-BR' => 'Questão 1'],
            'description' => ['pt-BR' => 'Quanto é <b>1 + 1</b>?'],
            'interactionType' => 'choice',
            'correctResponsesPattern' => ['b'],
            'choices' => [['id' => 'a', 'description' => ['pt-BR' => 'Um']], ['id' => 'b', 'description' => ['pt-BR' => 'Dois']]],
        ];
        $lesson = ['name' => ['pt-BR' => 'Lição 1']];

        [$session, $token] = $this->launches['A'];
        $this->post_ok($token, [
            $this->cmi5_statement($session, vocab::VERB_INITIALIZED),
            $this->sm($session, 'experienced', 'licao/l1', 1, ['duration' => 'PT30S'], $lesson),
            $this->sm($session, 'progressed', '', 1, ['extensions' => [vocab::EXT_PROGRESS => 30]]),
            $this->sm($session, 'answered', 'questao/q1', 2, ['success' => false, 'response' => 'a'], $definition),
            $this->sm($session, 'experienced', 'licao/l2', 2, ['duration' => 'PT20S']),
            $this->sm($session, 'experienced', 'licao/l1', 3, ['duration' => 'PT10S']),
            $this->sm($session, 'progressed', '', 3, ['extensions' => [vocab::EXT_PROGRESS => 60]]),
            $this->sm($session, 'answered', 'questao/q1', 4, ['success' => true, 'response' => 'b']),
            $this->sm($session, 'answered', 'questao/q2', 4, ['success' => true, 'response' => 'x']),
            $this->sm($session, 'experienced', 'licao/l9', 5, ['duration' => 'PT99S'], [], '2'),
            $this->terminated($session),
        ]);
        [$session, $token] = $this->launches['B'];
        $this->post_ok($token, [
            $this->cmi5_statement($session, vocab::VERB_INITIALIZED),
            $this->sm($session, 'experienced', 'licao/l1', 1, ['duration' => 'PT50S']),
            $this->sm($session, 'answered', 'questao/q1', 2, ['success' => true, 'response' => 'b']),
            $this->sm($session, 'answered', 'questao/q1', 3, ['success' => false, 'response' => 'a']),
            $this->sm($session, 'progressed', '', 3, ['extensions' => [vocab::EXT_PROGRESS => 50]]),
        ]);
    }

    /**
     * Lessons of a learner: visits and summed time, in the order of the first visit, with the name from itemdef.
     */
    public function test_lessons_for_registration(): void {
        $lessons = rich_report::lessons_for_registration((int)$this->regs['A']->id, '1');
        $this->assertSame(['l1', 'l2'], array_column($lessons, 'smitem'));
        $this->assertSame(2, $lessons[0]->visitas);
        $this->assertSame(40000, $lessons[0]->tempo);
        $this->assertSame('Lição 1', $lessons[0]->name);
        $this->assertSame(1, $lessons[1]->visitas);
        $this->assertSame(20000, $lessons[1]->tempo);
        $this->assertSame('', $lessons[1]->name, 'no definition was sent for l2');
        // Version '2' is apart.
        $this->assertSame(['l9'], array_column(rich_report::lessons_for_registration((int)$this->regs['A']->id, '2'), 'smitem'));
    }

    /**
     * Progress over time, ordered by timestamp.
     */
    public function test_progress_timeline(): void {
        $timeline = rich_report::progress_timeline((int)$this->regs['A']->id, '1');
        $this->assertSame([30, 60], array_column($timeline, 'progress'));
        $this->assertSame([$this->ms(1), $this->ms(3)], array_column($timeline, 'timestampms'));
        $this->assertSame([], rich_report::progress_timeline((int)$this->regs['A']->id, '2'));
    }

    /**
     * The last answer of each question, with the response read from the payload and the question text.
     */
    public function test_answers_for_registration(): void {
        $answers = rich_report::answers_for_registration((int)$this->regs['A']->id, '1');
        $this->assertSame(['q1', 'q2'], array_column($answers, 'smitem'));
        $this->assertTrue($answers[0]->success);
        $this->assertSame('Dois', $answers[0]->response, 'the choice text, not its id');
        $this->assertSame('b', $answers[0]->rawresponse);
        $this->assertSame('x', $answers[1]->response, 'no definition for q2: the raw value');
        $this->assertSame('Quanto é <b>1 + 1</b>?', $answers[0]->question, 'raw text: escaped on output');
        $b = rich_report::answers_for_registration((int)$this->regs['B']->id, '1');
        $this->assertFalse($b[0]->success);
        $this->assertSame('Um', $b[0]->response);
    }

    /**
     * Average time per lesson = average of the per-registration sums; the user filter restricts it.
     */
    public function test_avg_time_per_lesson(): void {
        global $DB;
        $avg = $this->by_item(rich_report::avg_time_per_lesson((int)$this->instance->id, '1', $this->everyone()));
        $this->assertSame(['l1', 'l2'], array_keys($avg));
        $this->assertSame(45000, $avg['l1']->average);
        $this->assertSame(2, $avg['l1']->learners);
        $this->assertSame(20000, $avg['l2']->average);
        $this->assertSame('Lição 1', $avg['l1']->name);

        $onlyb = $this->by_item(
            rich_report::avg_time_per_lesson((int)$this->instance->id, '1', group_filter::from_groups([$this->groupb]))
        );
        $this->assertSame(['l1'], array_keys($onlyb));
        $this->assertSame(50000, $onlyb['l1']->average);
        $this->assertSame([], rich_report::avg_time_per_lesson((int)$this->instance->id, '1', group_filter::from_groups([-1])));

        // A registration whose time is unknown (null durationms) is out of the average and of its base (M-1).
        $DB->set_field('scormmakercmi5_stmt', 'durationms', null, ['regid' => $this->regs['B']->id, 'smitem' => 'l1']);
        $avg = $this->by_item(rich_report::avg_time_per_lesson((int)$this->instance->id, '1', $this->everyone()));
        $this->assertSame(40000, $avg['l1']->average);
        $this->assertSame(1, $avg['l1']->learners);
        $v2 = rich_report::avg_time_per_lesson((int)$this->instance->id, '2', $this->everyone());
        $this->assertSame(['l9'], array_keys($this->by_item($v2)));
    }

    /**
     * Drop-off: the lesson of the last "experienced" of registrations with an AU not completed whose sessions ended.
     */
    public function test_dropoff_by_lesson(): void {
        global $DB;
        $drop = $this->by_item(rich_report::dropoff_by_lesson((int)$this->instance->id, '1', $this->everyone()));
        // A stopped at l1 (its last experienced, after l2); B's session is still open.
        $this->assertSame(['l1'], array_keys($drop));
        $this->assertSame(1, $drop['l1']->learners);
        $this->assertEquals(100.0, $drop['l1']->percent);

        // B's session ends: B also stopped at l1.
        $DB->set_field('scormmakercmi5_session', 'status', 3, ['id' => $this->launches['B'][0]->id]);
        $drop = $this->by_item(rich_report::dropoff_by_lesson((int)$this->instance->id, '1', $this->everyone()));
        $this->assertSame(2, $drop['l1']->learners);

        // A completed the AU: no longer a drop-off.
        $DB->set_field('scormmakercmi5_regau', 'completed', 1, ['regid' => $this->regs['A']->id]);
        $drop = $this->by_item(rich_report::dropoff_by_lesson((int)$this->instance->id, '1', $this->everyone()));
        $this->assertSame(1, $drop['l1']->learners);
        $onlya = group_filter::from_groups([$this->groupa]);
        $this->assertSame([], rich_report::dropoff_by_lesson((int)$this->instance->id, '1', $onlya));
    }

    /**
     * Success rate per question from the last answer of each registration, with the texts from itemdef.
     */
    public function test_question_success_rates(): void {
        $rates = $this->by_item(rich_report::question_success_rates((int)$this->instance->id, '1', $this->everyone()));
        $this->assertSame(['q1', 'q2'], array_keys($rates));
        $q1 = $rates['q1'];
        $this->assertSame(2, $q1->respondents);
        $this->assertSame(4, $q1->attempts);
        $this->assertSame(1, $q1->correctlast, 'A right at last, B wrong at last');
        $this->assertEquals(50.0, $q1->rate);
        $this->assertSame('Quanto é <b>1 + 1</b>?', $q1->question);
        $this->assertSame([['id' => 'a', 'text' => 'Um'], ['id' => 'b', 'text' => 'Dois']], $q1->choices);
        $this->assertSame('Dois', $q1->correct, 'the choice text, not its id');
        $this->assertSame(1, $rates['q2']->correctlast);
        $this->assertSame('', $rates['q2']->question);

        $onlyb = $this->by_item(
            rich_report::question_success_rates((int)$this->instance->id, '1', group_filter::from_groups([$this->groupb]))
        );
        $this->assertSame(['q1'], array_keys($onlyb));
        $this->assertSame(1, $onlyb['q1']->respondents);
        $this->assertSame(2, $onlyb['q1']->attempts);
        $this->assertSame(0, $onlyb['q1']->correctlast);
        $this->assertSame([], rich_report::question_success_rates((int)$this->instance->id, '2', $this->everyone()));
    }

    /**
     * The learners with statements of a version, by name, paged; the group filter restricts them.
     */
    public function test_learners(): void {
        $id = (int)$this->instance->id;
        [$users, $total] = rich_report::learners($id, '1', $this->everyone(), 0, 50);
        $this->assertSame(2, $total);
        $this->assertEqualsCanonicalizing(
            [(int)$this->user->id, (int)$this->userb->id],
            array_map('intval', array_column($users, 'id'))
        );
        [$users, $total] = rich_report::learners($id, '1', group_filter::from_groups([$this->groupb]), 0, 50);
        $this->assertSame(1, $total);
        $this->assertSame((int)$this->regs['B']->id, (int)$users[0]->regid);
        [$users, $total] = rich_report::learners($id, '1', $this->everyone(), 1, 1);
        $this->assertSame(2, $total);
        $this->assertCount(1, $users);
        $this->assertSame([[], 0], rich_report::learners($id, '1', group_filter::from_groups([-1]), 0, 50));
        [, $total] = rich_report::learners($id, '2', group_filter::from_groups([$this->groupb]), 0, 50);
        $this->assertSame(0, $total, 'B has nothing in version 2');
    }

    /**
     * A learner in several of the groups of the filter counts once in every per-activity number (as the user-id list
     * of before did); a group without members gives the empty results.
     */
    public function test_learner_in_several_groups_counts_once(): void {
        $this->getDataGenerator()->create_group_member(['groupid' => $this->groupb, 'userid' => $this->user->id]);
        $id = (int)$this->instance->id;
        $both = group_filter::from_groups([$this->groupa, $this->groupb]);
        $all = $this->everyone();
        $this->assertEquals(rich_report::avg_time_per_lesson($id, '1', $all), rich_report::avg_time_per_lesson($id, '1', $both));
        $this->assertEquals(rich_report::dropoff_by_lesson($id, '1', $all), rich_report::dropoff_by_lesson($id, '1', $both));
        $rates = $this->by_item(rich_report::question_success_rates($id, '1', $both));
        $this->assertSame(2, $rates['q1']->respondents);
        $this->assertSame(4, $rates['q1']->attempts);
        $this->assertSame(1, $rates['q1']->correctlast);
        $this->assertSame(2, rich_report::learners($id, '1', $both, 0, 50)[1]);
        // B's group now has A too: both are counted for it.
        $this->assertSame(2, rich_report::learners($id, '1', group_filter::from_groups([$this->groupb]), 0, 50)[1]);

        $empty = (int)$this->getDataGenerator()->create_group(['courseid' => $this->course->id])->id;
        $none = group_filter::from_groups([$empty]);
        $this->assertSame([], rich_report::avg_time_per_lesson($id, '1', $none));
        $this->assertSame([], rich_report::dropoff_by_lesson($id, '1', $none));
        $this->assertSame([], rich_report::question_success_rates($id, '1', $none));
        $this->assertSame([[], 0], rich_report::learners($id, '1', $none, 0, 50));
    }

    /**
     * No group restriction.
     *
     * @return group_filter
     */
    private function everyone(): group_filter {
        return group_filter::everyone();
    }

    /**
     * Readable responses per interaction type (raw xAPI patterns → the texts of itemdef).
     *
     * @return array
     */
    public static function readable_response_provider(): array {
        $choices = ['choices' => ['a' => 'Um', 'b' => 'Dois', 'c' => '<i>Três</i>']];
        $matching = ['source' => ['e1' => 'França', 'e2' => 'Japão'], 'target' => ['d1' => 'Paris', 'd2' => 'Tóquio']];
        return [
            'choice' => ['choice', $choices, 'b', 'Dois'],
            'multiple choice' => ['choice', $choices, 'a[,]c', 'Um, <i>Três</i>'],
            'choice with an unknown id' => ['choice', $choices, 'a[,]zz', 'Um, zz'],
            'choice with an empty text' => ['choice', ['choices' => ['a' => '']], 'a', 'a'],
            'true' => ['true-false', [], 'true', 'True'],
            'false' => ['true-false', [], 'false', 'False'],
            'true-false unknown' => ['true-false', [], 'maybe', 'maybe'],
            'matching' => ['matching', $matching, 'e1[.]d1[,]e2[.]d2', 'França → Paris; Japão → Tóquio'],
            'matching unknown and malformed' => ['matching', $matching, 'e1[.]d9[,]x', 'França → d9; x'],
            'sequencing' => ['sequencing', $choices, 'b[,]a[,]c', '1. Dois, 2. Um, 3. <i>Três</i>'],
            'sequencing unknown' => ['sequencing', $choices, 'b[,]q', '1. Dois, 2. q'],
            'likert' => ['likert', ['scale' => ['s1' => 'Concordo']], 's1', 'Concordo'],
            'fill-in stays raw' => ['fill-in', $choices, 'a[,]b', 'a[,]b'],
            'unknown type stays raw' => ['', $choices, 'b', 'b'],
            'empty' => ['choice', $choices, '', ''],
        ];
    }

    /**
     * The response of a learner (and the correct response) is shown with the texts of the alternatives.
     *
     * @dataProvider readable_response_provider
     * @param string $type xAPI interaction type.
     * @param array $texts Texts by component list and id.
     * @param string $value Raw xAPI response.
     * @param string $expected Readable text (raw: escaped on output).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('readable_response_provider')]
    public function test_readable_response(string $type, array $texts, string $value, string $expected): void {
        $this->assertSame($expected, rich_report::readable_response($type, $texts, $value));
    }

    /**
     * Versions of the activity with the date of their package.
     */
    public function test_versions(): void {
        global $DB;
        $versions = rich_report::versions((int)$this->instance->id);
        $this->assertSame(['1', '2'], array_map('strval', array_keys($versions)));
        $timecreated = (int)$DB->get_field('scormmakercmi5_pkg', 'timecreated', ['id' => $this->instance->currentpkgid]);
        $this->assertSame($timecreated, $versions['1']);
    }

    /**
     * Versions with the same package date are ordered by version in code point order (strcmp), never numerically.
     */
    public function test_version_ties_by_code_point(): void {
        [$session, $token] = $this->launches['B'];
        $this->post_ok($token, [
            $this->sm($session, 'experienced', 'licao/l9', 4, ['duration' => 'PT1S'], [], '9'),
            $this->sm($session, 'experienced', 'licao/l9', 5, ['duration' => 'PT1S'], [], '10'),
        ]);
        $versions = rich_report::versions((int)$this->instance->id);
        $this->assertSame(['1', '10', '2', '9'], array_map('strval', array_keys($versions)));
    }

    /**
     * Lessons with as many drop-offs are ordered by id in code point order (strcmp): "10" before "9".
     */
    public function test_dropoff_ties_by_code_point(): void {
        global $DB;
        // A stopped at l1, renamed "9"; B stops at "10" and its session ends.
        $DB->set_field('scormmakercmi5_stmt', 'smitem', '9', ['regid' => $this->regs['A']->id, 'smitem' => 'l1']);
        [$session, $token] = $this->launches['B'];
        $this->post_ok($token, [$this->sm($session, 'experienced', 'licao/10', 4, ['duration' => 'PT1S'])]);
        $DB->set_field('scormmakercmi5_session', 'status', 3, ['id' => $session->id]);
        $drop = rich_report::dropoff_by_lesson((int)$this->instance->id, '1', $this->everyone());
        $this->assertSame(['10', '9'], array_column($drop, 'smitem'));
    }

    /**
     * Rows by smitem.
     *
     * @param \stdClass[] $rows Rows.
     * @return \stdClass[]
     */
    private function by_item(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->smitem] = $row;
        }
        return $out;
    }

    /**
     * Timestamp (ms) of the n-th second after 12:00 on 2026-09-30.
     *
     * @param int $second Second.
     * @return int
     */
    private function ms(int $second): int {
        return (strtotime('2026-09-30T12:00:00Z') + $second) * 1000;
    }

    /**
     * A statement of the SCORM Maker profile.
     *
     * @param \stdClass $session Session.
     * @param string $verb ADL verb (last segment).
     * @param string $item 'licao/<id>', 'questao/<id>' or '' for the course.
     * @param int $second Second of the timestamp.
     * @param array $result Result.
     * @param array $definition Object definition.
     * @param string $version Package version.
     * @return array
     */
    private function sm(
        \stdClass $session,
        string $verb,
        string $item,
        int $second,
        array $result,
        array $definition = [],
        string $version = '1'
    ): array {
        $st = $this->cmi5_statement($session, 'http://adlnet.gov/expapi/verbs/' . $verb);
        unset($st['result']);
        $st['timestamp'] = gmdate('Y-m-d\TH:i:s', $this->ms($second) / 1000) . '.000Z';
        $st['object'] = ['objectType' => 'Activity', 'id' => self::SMCOURSE . ($item === '' ? '' : '/' . $item)];
        if ($definition) {
            $st['object']['definition'] = $definition;
        }
        $st['context']['contextActivities']['category'] = [['id' => vocab::SM_PROFILE]];
        $st['context']['extensions'][vocab::SM_EXT_VERSION] = $version;
        $st['result'] = $result;
        return $st;
    }

    /**
     * The terminated statement of a session.
     *
     * @param \stdClass $session Session.
     * @return array
     */
    private function terminated(\stdClass $session): array {
        $st = $this->cmi5_statement($session, vocab::VERB_TERMINATED);
        $st['context']['contextActivities']['category'] = [['id' => vocab::CAT_CMI5]];
        $st['result'] = ['duration' => 'PT5M'];
        return $st;
    }

    /**
     * Posts statements and expects 200.
     *
     * @param string $token Token.
     * @param array $statements Statements.
     */
    private function post_ok(string $token, array $statements): void {
        $response = $this->post_statements($token, $statements);
        $this->assertSame(200, $response->status, $response->body);
    }
}
