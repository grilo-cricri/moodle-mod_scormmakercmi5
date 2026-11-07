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

namespace mod_scormmakercmi5\local\lrs;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\statement_query::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\router::class)]
/**
 * Tests for GET statements with the session token of the AU (design §7.1; xAPI 1.0.3 part 3 §2.1.3; R5.1, R5.2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\statement_query
 * @covers     \mod_scormmakercmi5\local\lrs\router
 */
final class statement_query_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string Verb of the allowed statements. */
    private const EXPERIENCED = 'http://adlnet.gov/expapi/verbs/experienced';

    /** @var string Token of the current session. */
    private string $token;

    /** @var \stdClass Current session. */
    private \stdClass $session;

    /**
     * Activity launched and initialized, preferences not required, clock frozen.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setup_lrs_activity(self::NOW);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        [$this->session, $this->token] = $this->launch_with_token();
        $this->clock->bump();
        $this->assertSame(200, $this->post([$this->statement(vocab::VERB_INITIALIZED)])->status);
    }

    /**
     * A statement of the current session: cmi5 defined for initialized, allowed otherwise.
     *
     * @param string $verb Verb IRI.
     * @param string|null $objectid Object id (a lesson of the AU by default).
     * @return array
     */
    private function statement(string $verb = self::EXPERIENCED, ?string $objectid = null): array {
        global $DB, $CFG;
        $session = $DB->get_record('scormmakercmi5_session', ['id' => $this->session->id], '*', MUST_EXIST);
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], '*', MUST_EXIST);
        $st = [
            'id' => \core\uuid::generate(),
            'timestamp' => xapi_time::from_ms(xapi_time::now_ms()),
            'actor' => ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]],
            'verb' => ['id' => $verb, 'display' => ['en-US' => 'did', 'pt-BR' => 'fez']],
            'object' => [
                'objectType' => 'Activity',
                'id' => $objectid ?? $au->lmsactivityid . '/licao/1',
                'definition' => ['name' => ['en-US' => 'Lesson', 'pt-BR' => 'Lição']],
            ],
            'context' => [
                'registration' => $reg->registration,
                'contextActivities' => ['grouping' => [['objectType' => 'Activity', 'id' => $au->publisherid]]],
                'extensions' => [vocab::EXT_SESSIONID => $session->sessionuuid],
            ],
        ];
        if ($verb === vocab::VERB_INITIALIZED) {
            $st['object'] = ['objectType' => 'Activity', 'id' => $au->lmsactivityid];
            $st['context']['contextActivities']['category'] = [['id' => vocab::CAT_CMI5]];
        }
        return $st;
    }

    /**
     * POST statements with the session token.
     *
     * @param array|string $body Statements or raw JSON.
     * @return response
     */
    private function post(array|string $body): response {
        $raw = is_string($body) ? $body : json_encode($body, statement_store::JSON_FLAGS);
        return router::dispatch($this->lrs_request('POST', 'statements', $this->token, [], $raw));
    }

    /**
     * GET statements with a token.
     *
     * @param array $query Query.
     * @param string|null $token Token (the current one if null).
     * @param array $headers Extra headers.
     * @return response
     */
    private function get(array $query, ?string $token = null, array $headers = []): response {
        return router::dispatch($this->lrs_request('GET', 'statements', $token ?? $this->token, $query, '', $headers));
    }

    /**
     * Posts experienced statements one second apart.
     *
     * @param int $count How many.
     * @return string[] Their ids, in the order stored.
     */
    private function post_experienced(int $count): array {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $this->clock->bump();
            $st = $this->statement();
            $this->assertSame(200, $this->post([$st])->status);
            $ids[] = $st['id'];
        }
        return $ids;
    }

    /**
     * The ids of a statement result.
     *
     * @param response $response Response.
     * @return string[]
     */
    private function ids(response $response): array {
        $this->assertSame(200, $response->status, $response->body);
        return array_column(json_decode($response->body, true)['statements'], 'id');
    }

    /**
     * The query of a "more" IRL.
     *
     * @param string $more IRL.
     * @return array
     */
    private function more_query(string $more): array {
        $this->assertStringStartsWith('/', $more);
        $this->assertStringContainsString('/mod/scormmakercmi5/lrs.php/statements', $more);
        parse_str((string)parse_url($more, PHP_URL_QUERY), $query);
        return $query;
    }

    /**
     * statementId of the own registration → the stored payload, exactly; the id is looked up ignoring case.
     */
    public function test_statement_id_own(): void {
        global $DB;
        $st = $this->statement();
        $st['result'] = ['extensions' => ['https://example.com/ext/empty' => new \stdClass()]];
        $this->assertSame(200, $this->post([$st])->status);

        $response = $this->get(['statementId' => strtoupper($st['id'])]);
        $this->assertSame(200, $response->status, $response->body);
        $payload = $DB->get_field('scormmakercmi5_stmt', 'payload', ['statementid' => $st['id']], MUST_EXIST);
        $this->assertSame($payload, $response->body);
        $this->assertStringContainsString('"https://example.com/ext/empty":{}', $response->body);
        $this->assertSame('application/json', $response->headers['Content-Type']);
        $this->assertArrayHasKey('X-Experience-API-Consistent-Through', $response->headers);
        $this->assertTrue(xapi_time::is_utc($response->headers['X-Experience-API-Consistent-Through']));
        $this->assertArrayHasKey('Last-Modified', $response->headers);
    }

    /**
     * statementId of another registration (another learner of the same activity) → 404; unknown id → 404.
     */
    public function test_statement_id_other_registration(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->launch_with_token((int)$other->id);
        $theirs = $DB->get_field(
            'scormmakercmi5_stmt',
            'statementid',
            ['userid' => $other->id, 'verb' => vocab::VERB_LAUNCHED],
            MUST_EXIST
        );

        $response = $this->get(['statementId' => $theirs]);
        $this->assertSame(404, $response->status, $response->body);
        $this->assertSame('err_lrsnostatement', json_decode($response->body, true)['error']);
        $this->assertSame(404, $this->get(['statementId' => \core\uuid::generate()])->status);
    }

    /**
     * voidedStatementId → 404 (nothing is ever voided), also for an id that exists.
     */
    public function test_voided_statement_id(): void {
        [$id] = $this->post_experienced(1);
        $response = $this->get(['voidedStatementId' => $id]);
        $this->assertSame(404, $response->status, $response->body);
        $this->assertArrayHasKey('X-Experience-API-Consistent-Through', $response->headers);
    }

    /**
     * statementId with another filter, both ids, or a bad parameter → 400.
     */
    public function test_single_bad_parameters(): void {
        [$id] = $this->post_experienced(1);
        $this->assertSame(400, $this->get(['statementId' => $id, 'verb' => self::EXPERIENCED])->status);
        $this->assertSame(400, $this->get(['statementId' => $id, 'voidedStatementId' => $id])->status);
        $this->assertSame(400, $this->get(['statementId' => ''])->status);
        $this->assertSame(400, $this->get(['statementId' => $id, 'format' => 'full'])->status);
        $this->assertSame(200, $this->get(['statementId' => $id, 'format' => 'ids', 'attachments' => 'false'])->status);
    }

    /**
     * registration=<own>&ascending=true → oldest first (stored order); default is newest first; the own LMS and AU
     * statements are there.
     */
    public function test_registration_ascending(): void {
        global $DB;
        $ids = $this->post_experienced(3);
        $reg = $DB->get_field('scormmakercmi5_reg', 'registration', ['id' => $this->session->regid]);

        $response = $this->get(['registration' => strtoupper($reg), 'ascending' => 'true']);
        $all = $this->ids($response);
        // The only AU is NotApplicable (the moveOn default): the course was satisfied when the registration was
        // created, before the launch (Task 17, cmi5 §13.1.4).
        $this->assertCount(6, $all, 'satisfied + launched + initialized + 3');
        $this->assertSame($ids, array_slice($all, 3));
        $verbs = array_column(array_column(json_decode($response->body, true)['statements'], 'verb'), 'id');
        $this->assertSame([vocab::VERB_SATISFIED, vocab::VERB_LAUNCHED, vocab::VERB_INITIALIZED], array_slice($verbs, 0, 3));
        $stored = array_column(json_decode($response->body, true)['statements'], 'stored');
        $sorted = $stored;
        sort($sorted);
        $this->assertSame($sorted, $stored);
        $this->assertSame('', json_decode($response->body, true)['more']);

        $this->assertSame(array_reverse($all), $this->ids($this->get(['registration' => $reg])));
        $this->assertArrayHasKey('X-Experience-API-Consistent-Through', $response->headers);
    }

    /**
     * Stored order wins over the timestamp the AU sent (xAPI: results are ordered by stored).
     */
    public function test_order_is_stored_not_timestamp(): void {
        $this->clock->bump();
        $late = $this->statement();
        $late['timestamp'] = '2030-01-01T00:00:00.000Z';
        $this->post([$late]);
        [$early] = $this->post_experienced(1);
        $this->assertSame([$late['id'], $early], $this->ids($this->get(['verb' => self::EXPERIENCED, 'ascending' => 'true'])));
    }

    /**
     * limit=2 with 3 → 2 and a "more" that brings the 3rd; in both directions; every other filter is kept.
     */
    public function test_limit_and_more(): void {
        $ids = $this->post_experienced(3);
        foreach (['true' => $ids, 'false' => array_reverse($ids)] as $ascending => $expected) {
            $response = $this->get(['verb' => self::EXPERIENCED, 'limit' => '2', 'ascending' => $ascending]);
            $this->assertSame(array_slice($expected, 0, 2), $this->ids($response));
            $more = json_decode($response->body, true)['more'];
            $this->assertNotSame('', $more);
            $query = $this->more_query($more);
            $this->assertSame(self::EXPERIENCED, $query['verb']);
            $this->assertSame('2', $query['limit']);

            $next = $this->get($query);
            $this->assertSame([$expected[2]], $this->ids($next));
            $this->assertSame('', json_decode($next->body, true)['more']);
        }
    }

    /**
     * Statements stored in the same millisecond page correctly (the id breaks the tie).
     */
    public function test_more_within_one_batch(): void {
        $this->clock->bump();
        $batch = [$this->statement(), $this->statement(), $this->statement()];
        $this->assertSame(200, $this->post($batch)->status);
        $seen = [];
        $query = ['verb' => self::EXPERIENCED, 'limit' => '1', 'ascending' => 'true'];
        for ($i = 0; $i < 5 && $query !== null; $i++) {
            $response = $this->get($query);
            $seen = array_merge($seen, $this->ids($response));
            $more = json_decode($response->body, true)['more'];
            $query = $more === '' ? null : $this->more_query($more);
        }
        $this->assertSame(array_column($batch, 'id'), $seen);
    }

    /**
     * limit=9999 → at most 500; limit=0 → the maximum; no limit → 100.
     */
    public function test_limit_ceiling(): void {
        global $DB;
        $row = $DB->get_record('scormmakercmi5_stmt', ['regid' => $this->session->regid, 'verb' => vocab::VERB_INITIALIZED]);
        $payload = json_decode($row->payload, true);
        $rows = [];
        for ($i = 0; $i < 520; $i++) {
            $id = \core\uuid::generate();
            $payload['id'] = $id;
            $new = ['statementid' => $id, 'storedms' => $row->storedms + $i + 1,
                'payload' => json_encode($payload, statement_store::JSON_FLAGS)] + (array)$row;
            unset($new['id']);
            $rows[] = $new;
        }
        $DB->insert_records('scormmakercmi5_stmt', $rows);

        $this->assertCount(500, $this->ids($this->get(['limit' => '9999'])));
        $this->assertCount(500, $this->ids($this->get(['limit' => '0'])));
        $this->assertCount(100, $this->ids($this->get([])));
        $this->assertSame(400, $this->get(['limit' => '-1'])->status);
        $this->assertSame(400, $this->get(['limit' => 'x'])->status);
    }

    /**
     * Filters: verb, activity (with related_activities), since/until on stored, agent (IFI; the display name does not
     * matter).
     */
    public function test_filters(): void {
        global $CFG, $DB;
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $this->session->auid], '*', MUST_EXIST);
        [$first] = $this->post_experienced(1);
        $this->clock->bump();
        $other = $this->statement(self::EXPERIENCED, 'https://example.com/other');
        $this->post([$other]);
        $stored = json_decode($this->get(['statementId' => $first])->body, true)['stored'];

        $this->assertSame([$other['id']], $this->ids($this->get(['activity' => 'https://example.com/other'])));
        $this->assertSame([], $this->ids($this->get(['activity' => 'https://example.com/none'])));
        // The publisher id is only in contextActivities.grouping: found with related_activities alone.
        $this->assertSame([], $this->ids($this->get(['activity' => $au->publisherid])));
        $related = $this->ids($this->get(['activity' => $au->publisherid, 'related_activities' => 'true']));
        $this->assertContains($first, $related);
        $this->assertContains($other['id'], $related);
        // A prefix of an id is not the id.
        $prefix = substr($au->publisherid, 0, -1);
        $this->assertSame([], $this->ids($this->get(['activity' => $prefix, 'related_activities' => 'true'])));

        $this->assertSame([$other['id']], $this->ids($this->get(['since' => $stored, 'verb' => self::EXPERIENCED])));
        $this->assertSame([$first], $this->ids($this->get(['until' => $stored, 'verb' => self::EXPERIENCED])));
        $this->assertSame(400, $this->get(['since' => 'ontem'])->status);
        $this->assertSame(400, $this->get(['until' => 'ontem'])->status);

        $agent = ['name' => 'Qualquer', 'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]];
        // Satisfied (course, at the creation of the registration), launched, initialized, the two posted.
        $this->assertCount(5, $this->ids($this->get(['agent' => json_encode($agent)])));
        $this->assertSame([], $this->ids($this->get(['verb' => 'http://adlnet.gov/expapi/verbs/answered'])));
    }

    /**
     * Another agent or another registration → 403 with the session token; a malformed one → 400.
     */
    public function test_scope_of_the_token(): void {
        global $CFG;
        $agent = ['account' => ['homePage' => $CFG->wwwroot, 'name' => '999999']];
        $this->assertSame(403, $this->get(['agent' => json_encode($agent)])->status);
        $this->assertSame(403, $this->get(['registration' => \core\uuid::generate()])->status);
        $this->assertSame(400, $this->get(['agent' => '[1]'])->status);
        $this->assertSame(400, $this->get(['registration' => 'nao-e-uuid'])->status);
        $this->assertSame(400, $this->get(['ascending' => 'sim'])->status);
    }

    /**
     * The query never shows statements of another registration, even without any filter.
     */
    public function test_query_limited_to_own_registration(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->launch_with_token((int)$other->id);
        $theirs = $DB->get_field(
            'scormmakercmi5_stmt',
            'statementid',
            ['userid' => $other->id, 'verb' => vocab::VERB_LAUNCHED],
            MUST_EXIST
        );
        $ids = $this->ids($this->get(['limit' => '0']));
        $this->assertNotContains($theirs, $ids);
        $this->assertCount(3, $ids, 'satisfied + launched + initialized');
    }

    /**
     * format=ids keeps only the identifiers; format=canonical keeps one language, chosen by Accept-Language.
     */
    public function test_formats(): void {
        global $CFG;
        [$id] = $this->post_experienced(1);

        $ids = json_decode($this->get(['statementId' => $id, 'format' => 'ids'])->body, true);
        $this->assertSame(['id' => self::EXPERIENCED], $ids['verb']);
        $this->assertSame(['objectType', 'id'], array_keys($ids['object']));
        $this->assertSame(
            ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]],
            $ids['actor']
        );
        $this->assertSame(['objectType', 'id'], array_keys($ids['context']['contextActivities']['grouping'][0]));

        $canonical = json_decode($this->get(
            ['statementId' => $id, 'format' => 'canonical'],
            null,
            ['Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.5']
        )->body, true);
        $this->assertSame(['pt-BR' => 'fez'], $canonical['verb']['display']);
        $this->assertSame(['pt-BR' => 'Lição'], $canonical['object']['definition']['name']);
        $fallback = json_decode($this->get(['statementId' => $id, 'format' => 'canonical'])->body, true);
        $this->assertCount(1, $fallback['verb']['display']);

        $list = json_decode($this->get(['verb' => self::EXPERIENCED, 'format' => 'ids'])->body, true);
        $this->assertSame(['id' => self::EXPERIENCED], $list['statements'][0]['verb']);
    }

    /**
     * attachments=true → multipart/mixed whose first part is the JSON.
     */
    public function test_attachments_multipart(): void {
        [$id] = $this->post_experienced(1);
        $response = $this->get(['statementId' => $id, 'attachments' => 'true']);
        $this->assertSame(200, $response->status);
        $this->assertMatchesRegularExpression('~^multipart/mixed; boundary=([A-Za-z0-9]+)$~', $response->headers['Content-Type']);
        preg_match('~boundary=(\w+)~', $response->headers['Content-Type'], $m);
        $parts = explode('--' . $m[1], $response->body);
        $this->assertStringContainsString('Content-Type: application/json', $parts[1]);
        $json = substr($parts[1], strpos($parts[1], "\r\n\r\n") + 4, -2);
        $this->assertSame($id, json_decode($json, true)['id']);
        $this->assertStringStartsWith('--', $parts[2]);
    }

    /**
     * related_activities: a candidate of the LIKE that the PHP check rejects (the "id" is inside an extension) sits
     * between two matches; with limit=1 the page is read on past it, and "more" resumes after the last returned row.
     */
    public function test_related_false_candidate_between_matches(): void {
        $iri = 'https://example.com/relacionada';
        $matches = [];
        foreach (['match', 'false', 'match'] as $kind) {
            $this->clock->bump();
            $st = $this->statement();
            if ($kind === 'match') {
                $st['context']['contextActivities']['other'] = [['objectType' => 'Activity', 'id' => $iri]];
                $matches[] = $st['id'];
            } else {
                $st['result'] = ['extensions' => ['https://example.com/ext/ref' => ['id' => $iri]]];
            }
            $this->assertSame(200, $this->post([$st])->status);
        }
        $query = ['activity' => $iri, 'related_activities' => 'true', 'limit' => '1', 'ascending' => 'true'];
        $first = $this->get($query);
        $this->assertSame([$matches[0]], $this->ids($first));
        $more = json_decode($first->body, true)['more'];
        $this->assertNotSame('', $more);
        $second = $this->get($this->more_query($more));
        $this->assertSame([$matches[1]], $this->ids($second));
        $this->assertSame('', json_decode($second->body, true)['more']);
        $this->assertSame(array_reverse($matches), $this->ids($this->get(['activity' => $iri, 'related_activities' => 'true'])));
    }

    /**
     * The AU token sees only its own AU: a statement of the same registration from another AU is 404 and never listed.
     */
    public function test_other_au_of_the_registration(): void {
        global $DB;
        [$mine] = $this->post_experienced(1);
        $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $mine], '*', MUST_EXIST);
        $otherid = \core\uuid::generate();
        $payload = json_decode($row->payload, true);
        $payload['id'] = $otherid;
        unset($row->id);
        $row->statementid = $otherid;
        $row->auid = (int)$row->auid + 1000;
        $row->storedms = (int)$row->storedms + 1;
        $row->payload = json_encode($payload, statement_store::JSON_FLAGS);
        $DB->insert_record('scormmakercmi5_stmt', $row);

        $this->assertSame(404, $this->get(['statementId' => $otherid])->status);
        $ids = $this->ids($this->get(['limit' => '0']));
        $this->assertContains($mine, $ids);
        $this->assertNotContains($otherid, $ids);
    }

    /**
     * The query is ordered and paged by stored: both scopes have an index that leads with their filter and storedms.
     */
    public function test_stored_indexes(): void {
        global $DB;
        $columns = array_map(fn(array $index): array => $index['columns'], $DB->get_indexes('scormmakercmi5_stmt'));
        $this->assertContains(['regid', 'storedms'], $columns);
        $this->assertContains(['scormmakercmi5id', 'storedms'], $columns);
    }

    /**
     * A malformed cursor → 400.
     */
    public function test_bad_cursor(): void {
        $this->assertSame(400, $this->get(['cursor' => 'x'])->status);
        $this->assertSame(400, $this->get(['cursor' => '1-'])->status);
    }

    /**
     * HEAD answers as GET without a body; the query never writes.
     */
    public function test_head_and_read_only(): void {
        global $DB;
        $this->post_experienced(1);
        $count = $DB->count_records('scormmakercmi5_stmt');
        $head = router::dispatch($this->lrs_request('HEAD', 'statements', $this->token, []));
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);
        $this->assertArrayHasKey('X-Experience-API-Consistent-Through', $head->headers);
        $this->assertSame($count, $DB->count_records('scormmakercmi5_stmt'));
    }
}
