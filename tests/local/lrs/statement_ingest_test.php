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
require_once($CFG->dirroot . '/course/lib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\runtime\actor;
use mod_scormmakercmi5\local\runtime\launcher;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\statement_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\router::class)]
/**
 * Tests for the all-or-nothing statement ingestion (design §3.9, §3.12, §3.13, §7.2, §7.4; R5.1–R5.5, R8, NF6).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\statement_store
 * @covers     \mod_scormmakercmi5\local\lrs\router
 */
final class statement_ingest_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string Base IRI of the ADL verbs. */
    private const ADL = 'http://adlnet.gov/expapi/verbs/';

    /** @var string Base IRI of a SCORM Maker course. */
    private const SMCOURSE = 'https://scormmaker.com.br/xapi/curso/c1';

    /** @var string Token of the current session. */
    private string $token;

    /** @var \stdClass Current session. */
    private \stdClass $session;

    /**
     * Activity launched, preferences not required, clock frozen.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setup_lrs_activity(self::NOW);
        // The ingestion rolls back its own transaction: it must be the outermost one, as in production.
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        [$this->session, $this->token] = $this->launch_with_token();
    }

    /**
     * Removes the test seam.
     */
    protected function tearDown(): void {
        (new \ReflectionProperty(statement_store::class, 'testhook'))->setValue(null, null);
        parent::tearDown();
    }

    /**
     * The context of the current token.
     *
     * @return session_context
     */
    private function ctx(): session_context {
        return auth::session_from_request($this->lrs_request('GET', 'about', $this->token));
    }

    /**
     * A valid cmi5 allowed statement of the current session.
     *
     * @param string $verb Verb IRI.
     * @param string|null $objectid Object id (a lesson of the AU by default).
     * @return array
     */
    private function allowed(string $verb = self::ADL . 'experienced', ?string $objectid = null): array {
        global $DB, $CFG;
        $session = $DB->get_record('scormmakercmi5_session', ['id' => $this->session->id], '*', MUST_EXIST);
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], '*', MUST_EXIST);
        return [
            'id' => \core\uuid::generate(),
            'timestamp' => '2026-09-30T12:00:00.000Z',
            'actor' => ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]],
            'verb' => ['id' => $verb, 'display' => ['pt-BR' => 'verbo']],
            'object' => ['objectType' => 'Activity', 'id' => $objectid ?? $au->lmsactivityid . '/licao/1'],
            'context' => [
                'registration' => $reg->registration,
                'contextActivities' => ['grouping' => ['objectType' => 'Activity', 'id' => $au->publisherid]],
                'extensions' => [vocab::EXT_SESSIONID => $session->sessionuuid],
            ],
        ];
    }

    /**
     * A valid cmi5 defined statement of the current session.
     *
     * @param string $verb Verb IRI.
     * @param float|null $scaled Score of passed/failed.
     * @param string $duration Duration of terminated/completed/passed/failed.
     * @return array
     */
    private function defined(string $verb, ?float $scaled = null, string $duration = 'PT5M'): array {
        global $DB;
        $st = $this->allowed($verb);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $this->session->auid], '*', MUST_EXIST);
        $st['object'] = ['objectType' => 'Activity', 'id' => $au->lmsactivityid];
        $st['context']['contextActivities']['category'] = [['id' => vocab::CAT_CMI5]];
        $moveon = ['id' => vocab::CAT_MOVEON];
        if ($verb === vocab::VERB_TERMINATED) {
            $st['result'] = ['duration' => $duration];
        } else if ($verb === vocab::VERB_COMPLETED) {
            $st['result'] = ['completion' => true, 'duration' => $duration];
            $st['context']['contextActivities']['category'][] = $moveon;
        } else if ($verb === vocab::VERB_PASSED || $verb === vocab::VERB_FAILED) {
            $st['result'] = [
                'success' => $verb === vocab::VERB_PASSED,
                'score' => ['raw' => $scaled * 100, 'min' => 0, 'max' => 100, 'scaled' => $scaled],
                'duration' => $duration,
            ];
            $st['context']['contextActivities']['category'][] = $moveon;
            if ($au->masteryscore !== null) {
                $st['context']['extensions'][vocab::EXT_MASTERYSCORE] = (float)$au->masteryscore;
            }
        }
        return $st;
    }

    /**
     * A statement of the SCORM Maker profile about a lesson or a question.
     *
     * @param string $verb Verb IRI.
     * @param string $item 'licao/<id>' or 'questao/<id>'.
     * @param string $version Package version extension.
     * @return array
     */
    private function sm(string $verb, string $item, string $version = '3'): array {
        $st = $this->allowed($verb, self::SMCOURSE . '/' . $item);
        $st['context']['contextActivities']['category'] = [['id' => vocab::SM_PROFILE]];
        $st['context']['extensions'][vocab::SM_EXT_VERSION] = $version;
        return $st;
    }

    /**
     * A statement of the analysis of blocks (subproject D): a block summary (interacted) or a decision (selected).
     *
     * @param string $verb vocab::VERB_INTERACTED or vocab::VERB_SELECTED.
     * @param string $object 'bloco/<b>' or 'bloco/<b>/item/<i>'.
     * @return array
     */
    private function analysis(string $verb = vocab::VERB_INTERACTED, string $object = 'bloco/b1'): array {
        $st = $this->sm($verb, $object);
        $st['context']['extensions'][vocab::SM_EXT_PROFILEVER] = 2;
        $st['context']['extensions'][vocab::SM_EXT_BLOCKTYPE] = 'flashcards';
        return $st;
    }

    /**
     * Number of analysis statements of the registration.
     *
     * @return int
     */
    private function analysis_count(): int {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal([vocab::VERB_INTERACTED, vocab::VERB_SELECTED], SQL_PARAMS_NAMED);
        return $DB->count_records_select(
            'scormmakercmi5_stmt',
            "regid = :regid AND verb {$insql}",
            $params + ['regid' => $this->session->regid]
        );
    }

    /**
     * POST statements through the router.
     *
     * @param array|string $body Statements or raw body.
     * @param array $headers Extra headers.
     * @return response
     */
    private function post(array|string $body, array $headers = []): response {
        $raw = is_string($body) ? $body : json_encode($body, statement_store::JSON_FLAGS);
        return router::dispatch($this->lrs_request('POST', 'statements', $this->token, [], $raw, $headers));
    }

    /**
     * PUT statements?statementId= through the router.
     *
     * @param array $query Query.
     * @param array|string $body Statement or raw body.
     * @return response
     */
    private function put(array $query, array|string $body): response {
        $raw = is_string($body) ? $body : json_encode($body, statement_store::JSON_FLAGS);
        return router::dispatch($this->lrs_request('PUT', 'statements', $this->token, $query, $raw));
    }

    /**
     * Asserts the status and, for an error, the code and the rule.
     *
     * @param int $status Expected status.
     * @param response $response Response.
     * @param string|null $error Expected error code.
     * @param string|null|false $rule Expected rule (false: not checked).
     * @return mixed Decoded body.
     */
    private function assert_response(
        int $status,
        response $response,
        ?string $error = null,
        string|null|false $rule = false
    ): mixed {
        $this->assertSame($status, $response->status, $response->body);
        $this->assertSame('1.0.3', $response->headers['X-Experience-API-Version']);
        $body = $response->body === '' ? null : json_decode($response->body, true);
        if ($error !== null) {
            $this->assertSame($error, $body['error']);
        }
        if ($rule !== false) {
            $this->assertSame($rule, $body['rule']);
        }
        return $body;
    }

    /**
     * Number of statements sent by the AU.
     *
     * @return int
     */
    private function au_count(): int {
        global $DB;
        return $DB->count_records(
            'scormmakercmi5_stmt',
            ['source' => statement_store::SOURCE_AU, 'regid' => $this->session->regid]
        );
    }

    /**
     * The registration row.
     *
     * @return \stdClass
     */
    private function reg(): \stdClass {
        global $DB;
        return $DB->get_record('scormmakercmi5_reg', ['id' => $this->session->regid], '*', MUST_EXIST);
    }

    /**
     * The current session row.
     *
     * @return \stdClass
     */
    private function session_row(): \stdClass {
        global $DB;
        return $DB->get_record('scormmakercmi5_session', ['id' => $this->session->id], '*', MUST_EXIST);
    }

    /**
     * The regau row of the AU.
     *
     * @return \stdClass
     */
    private function regau(): \stdClass {
        global $DB;
        return $DB->get_record(
            'scormmakercmi5_regau',
            ['regid' => $this->session->regid, 'auid' => $this->session->auid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Sets the test seam of statement_store.
     *
     * @param \Closure|null $hook fn(string $stage).
     */
    private function hook(?\Closure $hook): void {
        (new \ReflectionProperty(statement_store::class, 'testhook'))->setValue(null, $hook);
    }

    /**
     * A valid initialized: 200 with the id, columns extracted, payload as the GET will return it, session initialized.
     */
    public function test_post_initialized(): void {
        global $DB, $CFG;
        $before = (int)$this->reg()->statementcount;
        $st = $this->defined(vocab::VERB_INITIALIZED);
        $body = $this->assert_response(200, $this->post($st));
        $this->assertSame([$st['id']], $body);

        $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $st['id']], '*', MUST_EXIST);
        $this->assertEquals(statement_store::SOURCE_AU, $row->source);
        $this->assertEquals(statement_store::KIND_DEFINED, $row->cmi5kind);
        $this->assertSame(vocab::VERB_INITIALIZED, $row->verb);
        $this->assertSame($st['object']['id'], $row->objectid);
        $this->assertSame(sha1($st['object']['id']), $row->objecthash);
        $this->assertEquals($this->session->id, $row->sessionid);
        $this->assertEquals($this->session->regid, $row->regid);
        $this->assertEquals($this->session->auid, $row->auid);
        $this->assertEquals($this->user->id, $row->userid);
        $this->assertEquals($this->instance->id, $row->scormmakercmi5id);
        $this->assertEquals(xapi_time::to_ms('2026-09-30T12:00:00.000Z'), $row->timestampms);
        $this->assertEquals(self::NOW * 1000, $row->storedms);
        $this->assertEquals(0, $row->smprofile);
        $this->assertNull($row->smversion);

        $payload = json_decode($row->payload, true);
        $this->assertSame(xapi_time::from_ms(self::NOW * 1000), $payload['stored']);
        $this->assertSame(actor::lms_authority(), $payload['authority']);
        $this->assertSame(statement_store::DEFAULT_VERSION, $payload['version']);
        // Normalized: a single contextActivities value comes back as a list (xAPI 2.4.6.2).
        $this->assertTrue(array_is_list($payload['context']['contextActivities']['grouping']));
        $this->assertSame($CFG->wwwroot, $payload['authority']['account']['homePage']);

        $this->assertSame($before + 1, (int)$this->reg()->statementcount);
        $session = $this->session_row();
        $this->assertEquals(launcher::STATUS_INITIALIZED, $session->status);
        $this->assertEquals(self::NOW * 1000, $session->timeinitializedms);

        // An empty batch is a no-op.
        $this->assertSame([], $this->assert_response(200, $this->post('[]')));
        // A version sent by the AU is kept.
        $st = $this->allowed();
        $st['version'] = '1.0.3';
        $this->assert_response(200, $this->post([$st]));
        $payload = json_decode($DB->get_field('scormmakercmi5_stmt', 'payload', ['statementid' => $st['id']]), true);
        $this->assertSame('1.0.3', $payload['version']);
    }

    /**
     * A batch whose third statement breaks a rule stores nothing and changes nothing.
     */
    public function test_batch_refused_whole(): void {
        $count = (int)$this->reg()->statementcount;
        $session = $this->session_row();
        $batch = [$this->defined(vocab::VERB_INITIALIZED), $this->allowed(), $this->defined(vocab::VERB_INITIALIZED)];
        $body = $this->assert_response(403, $this->post($batch), 'err_cmi5rule', '9.3.0.0-5');
        $this->assertStringContainsString('3', $body['message']);
        $this->assertSame(0, $this->au_count());
        $this->assertSame($count, (int)$this->reg()->statementcount);
        $this->assertEquals($session, $this->session_row());
    }

    /**
     * Body, batch, statement and registration limits (NF6).
     */
    public function test_limits(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $start = $this->au_count();

        set_config('maxbodykb', 1, 'mod_scormmakercmi5');
        // Declared Content-Length above the limit: refused before the body is read.
        $this->assert_response(413, $this->post('[]', ['Content-Length' => '2000']), 'err_lrstoolarge');
        // A body above the limit with no Content-Length (chunked).
        $big = $this->allowed();
        $big['result'] = ['response' => str_repeat('x', 1100)];
        $this->assert_response(413, $this->post([$big]), 'err_lrstoolarge');
        unset_config('maxbodykb', 'mod_scormmakercmi5');

        set_config('maxbatch', 2, 'mod_scormmakercmi5');
        $this->assert_response(413, $this->post([$this->allowed(), $this->allowed(), $this->allowed()]), 'err_lrsbatchsize');
        $this->assert_response(200, $this->post([$this->allowed(), $this->allowed()]));
        unset_config('maxbatch', 'mod_scormmakercmi5');

        set_config('maxstatementkb', 1, 'mod_scormmakercmi5');
        $this->assert_response(413, $this->post([$this->allowed(), $big]), 'err_lrsstatementsize');
        unset_config('maxstatementkb', 'mod_scormmakercmi5');
        $this->assertSame($start + 2, $this->au_count());

        $count = (int)$this->reg()->statementcount;
        set_config('maxstatementsperreg', $count + 1, 'mod_scormmakercmi5');
        $this->assert_response(403, $this->post([$this->allowed(), $this->allowed()]), 'err_lrsreglimit', 'limit');
        $this->assertSame($count, (int)$this->reg()->statementcount);
        $this->assertSame($start + 2, $this->au_count());
        $this->assert_response(200, $this->post([$this->allowed()]));
        $this->assert_response(403, $this->post([$this->allowed()]), 'err_lrsreglimit', 'limit');
        $this->assertSame($count + 1, (int)$this->reg()->statementcount);
        $this->assertSame($count + 1, $DB->count_records('scormmakercmi5_stmt', ['regid' => $this->session->regid]));
    }

    /**
     * PUT statements has the body limit of POST: one byte over maxbodykb is a 413 with its own string and stores
     * nothing; the same statement under the limit is stored (Plugins Directory review, issue 3).
     */
    public function test_put_body_limit(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $start = $this->au_count();
        set_config('maxbodykb', 1, 'mod_scormmakercmi5');

        $st = $this->allowed();
        $st['result'] = ['response' => ''];
        $raw = json_encode($st, statement_store::JSON_FLAGS);
        $st['result']['response'] = str_repeat('x', 1024 - strlen($raw) + 1);
        $this->assertSame(1025, strlen(json_encode($st, statement_store::JSON_FLAGS)));
        $body = $this->assert_response(413, $this->put(['statementId' => $st['id']], $st), 'err_lrstoolarge');
        $this->assertSame(get_string('err_lrstoolarge', 'mod_scormmakercmi5'), $body['message']);
        $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
        $this->assertSame($start, $this->au_count());
        // A declared Content-Length over the limit is refused the same way, whatever the body.
        $small = $this->allowed();
        $response = router::dispatch($this->lrs_request(
            'PUT',
            'statements',
            $this->token,
            ['statementId' => $small['id']],
            json_encode($small, statement_store::JSON_FLAGS),
            ['Content-Length' => '1025']
        ));
        $this->assert_response(413, $response, 'err_lrstoolarge');
        $this->assertSame($start, $this->au_count());

        $st['result']['response'] = substr($st['result']['response'], 1);
        $this->assert_response(204, $this->put(['statementId' => $st['id']], $st));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
        $this->assertSame($start + 1, $this->au_count());
    }

    /**
     * Statement attachments: this LRS stores no attachment content. A multipart/mixed body (statement plus raw
     * attachment) and a JSON attachment without fileUrl are both refused with 400, and nothing is stored.
     */
    public function test_attachment_content_refused(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $start = $this->au_count();
        $files = $DB->count_records('files');

        $st = $this->allowed();
        $st['attachments'] = [[
            'usageType' => 'https://example.com/attachment',
            'display' => ['en' => 'file'],
            'contentType' => 'application/octet-stream',
            'length' => 5,
            'sha2' => hash('sha256', 'hello'),
        ]];
        $json = json_encode($st, statement_store::JSON_FLAGS);
        $multipart = "--b1\r\nContent-Type: application/json\r\n\r\n{$json}\r\n--b1\r\n" .
            "Content-Type: application/octet-stream\r\nContent-Transfer-Encoding: binary\r\n" .
            'X-Experience-API-Hash: ' . hash('sha256', 'hello') . "\r\n\r\nhello\r\n--b1--";
        $this->assert_response(400, $this->post($multipart, ['Content-Type' => 'multipart/mixed; boundary=b1']));
        $this->assert_response(400, $this->post([$st]), 'err_invalidstatement');

        $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
        $this->assertSame($start, $this->au_count());
        $this->assertSame($files, $DB->count_records('files'));
    }

    /**
     * Malformed bodies: 400, nothing stored.
     */
    public function test_bad_bodies(): void {
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $start = $this->au_count();
        $this->assert_response(400, $this->post('[{"id":'), 'err_lrsbadjson');
        $this->assert_response(400, $this->post(''), 'err_lrsbadjson');
        $valid = json_encode($this->allowed(), statement_store::JSON_FLAGS);
        $this->assert_response(
            400,
            $this->post(substr($valid, 0, -1) . ',"result":{"score":{"raw":1e400}}}'),
            'err_invalidstatement'
        );
        $this->assert_response(400, $this->post(str_replace('"verbo"', '"ver\u0000bo"', $valid)), 'err_invalidstatement');
        // The body {} decodes like [], but it is one empty statement, not an empty batch.
        $this->assert_response(400, $this->post('{}'), 'err_invalidstatement');
        $this->assert_response(400, $this->post(' {} '), 'err_invalidstatement');
        $this->assert_response(400, $this->post('5'), 'err_invalidbatch');
        $this->assert_response(400, $this->post('null'), 'err_invalidbatch');
        $this->assert_response(400, $this->post('[{}]'), 'err_invalidstatement');
        $this->assert_response(400, $this->post('[{"id":1},5]'), 'err_invalidbatch');

        $st = $this->allowed();
        $other = $this->allowed();
        $other['id'] = strtoupper($st['id']);
        $this->assert_response(400, $this->post([$st, $this->allowed(), $other]), 'err_lrsduplicateid');

        // A verb.id of 256 characters (design §3.13): 400 of the validator, never a dml_write_exception.
        $long = $this->allowed('https://example.com/' . str_repeat('v', 236));
        $this->assertSame(256, \core_text::strlen($long['verb']['id']));
        $this->assert_response(400, $this->post([$this->allowed(), $long]), 'err_invalidstatement');
        $this->assertSame($start, $this->au_count());
    }

    /**
     * Review Focus 4: an identical retry after a 5xx stores the batch once and answers 200 with the same ids.
     */
    public function test_identical_retry_after_5xx(): void {
        $batch = [$this->defined(vocab::VERB_INITIALIZED), $this->allowed(), $this->allowed()];
        $ids = array_column($batch, 'id');
        $count = (int)$this->reg()->statementcount;
        $this->hook(function (string $stage): void {
            if ($stage === 'beforecommit') {
                throw new \dml_write_exception('simulated');
            }
        });
        $this->assert_response(500, $this->post($batch));
        $this->assertDebuggingCalled();
        $this->assertSame(0, $this->au_count());
        $this->assertEquals(launcher::STATUS_LAUNCHED, $this->session_row()->status);
        $this->hook(null);

        $this->assertSame($ids, $this->assert_response(200, $this->post($batch)));
        $this->assertSame(3, $this->au_count());
        // The response was lost: the same batch again (with the initialized already stored).
        $this->assertSame($ids, $this->assert_response(200, $this->post($batch)));
        $this->assertSame(3, $this->au_count());
        $this->assertSame($count + 3, (int)$this->reg()->statementcount);
        // Part stored, part new: only the new one is judged and stored.
        $new = $this->allowed();
        $this->assertSame([$ids[1], $new['id']], $this->assert_response(200, $this->post([$batch[1], $new])));
        $this->assertSame(4, $this->au_count());
        // A retry with the contextActivities sent as a list (the stored, normalized form) is the same statement.
        $again = $batch[2];
        $again['context']['contextActivities']['grouping'] = [$again['context']['contextActivities']['grouping']];
        $this->assert_response(200, $this->post([$again]));
        $this->assertSame(4, $this->au_count());
    }

    /**
     * The same id with other content is a conflict (xAPI 1.0.3 part 3 §2.1).
     */
    public function test_conflict(): void {
        $init = $this->defined(vocab::VERB_INITIALIZED);
        $this->post([$init]);
        $st = $this->allowed();
        $this->assert_response(200, $this->post([$st]));
        $changed = $st;
        $changed['verb']['display']['pt-BR'] = 'outro';
        $this->assert_response(409, $this->post([$this->allowed(), $changed]), 'err_lrsconflict');
        $this->assertSame(2, $this->au_count());
        // The id of a statement of the LMS (launched) cannot be taken either.
        global $DB;
        $launched = $DB->get_field('scormmakercmi5_stmt', 'statementid', ['source' => statement_store::SOURCE_LMS,
            'verb' => vocab::VERB_LAUNCHED]);
        $theirs = $this->allowed();
        $theirs['id'] = $launched;
        $this->assert_response(409, $this->post([$theirs]), 'err_lrsconflict');
    }

    /**
     * PUT statements?statementId=.
     */
    public function test_put(): void {
        global $DB;
        $init = $this->defined(vocab::VERB_INITIALIZED);
        $response = $this->put(['statementId' => $init['id']], $init);
        $this->assert_response(204, $response);
        $this->assertSame('', $response->body);
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $init['id']]));
        // Identical again → 204, no new row.
        $this->assert_response(204, $this->put(['statementId' => $init['id']], $init));
        $this->assertSame(1, $this->au_count());

        $st = $this->allowed();
        $this->assert_response(400, $this->put([], $st), 'err_lrsparam');
        $this->assert_response(400, $this->put(['statementId' => \core\uuid::generate()], $st), 'err_lrsparam');
        $this->assert_response(400, $this->put(['statementId' => $st['id']], [$st]), 'err_invalidstatement');
        $this->assert_response(400, $this->put(['statementId' => $st['id']], 'x'), 'err_lrsbadjson');
        $this->assertSame(1, $this->au_count());

        // Without id in the body, the id is the one of the parameter.
        $id = $st['id'];
        unset($st['id']);
        $this->assert_response(204, $this->put(['statementId' => $id], $st));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $id]));
        $st['verb']['display']['pt-BR'] = 'outro';
        $this->assert_response(409, $this->put(['statementId' => $id], $st), 'err_lrsconflict');
    }

    /**
     * A busy registration lock gives 503 with Retry-After and stores nothing.
     */
    public function test_lock_busy(): void {
        global $CFG;
        // The database lock factories are reentrant within one connection; the file factory is not.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $lock = launcher::registration_lock((int)$this->session->regid, 0);
        $this->assertNotFalse($lock);
        try {
            statement_store::store_batch([$this->defined(vocab::VERB_INITIALIZED)], $this->ctx(), 0);
            $this->fail('Expected 503');
        } catch (lrs_exception $e) {
            $this->assertSame(503, $e->httpstatus);
            $this->assertSame('err_lrsbusy', $e->errorcode);
            $this->assertSame(['Retry-After' => '2'], $e->headers);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, $this->au_count());
        $this->assertCount(1, statement_store::store_batch([$this->defined(vocab::VERB_INITIALIZED)], $this->ctx(), 0));
    }

    /**
     * Review Focus 2: tab B relaunches between the pre-validation of tab A and its lock: A gets 401, nothing stored.
     */
    public function test_relaunch_between_check_and_lock(): void {
        $tokena = $this->token;
        $sessiona = $this->session;
        $tokenb = null;
        $this->hook(function (string $stage) use (&$tokenb): void {
            if ($stage === 'beforelock' && $tokenb === null) {
                [, $tokenb] = $this->launch_with_token();
            }
        });
        $this->assert_response(401, $this->post([$this->defined(vocab::VERB_INITIALIZED)]), 'err_unauthorized');
        $this->hook(null);
        $this->assertNotNull($tokenb);
        $this->assertSame(0, $this->au_count());
        global $DB;
        $this->assertEquals(launcher::STATUS_ABANDONED, $DB->get_field(
            'scormmakercmi5_session',
            'status',
            ['id' => $sessiona->id]
        ));
        // Tab A stays refused; tab B works.
        $this->assert_response(401, $this->post([$this->defined(vocab::VERB_INITIALIZED)]), 'err_unauthorized');
        $this->assertSame($tokena, $this->token);
        $this->token = $tokenb;
        $this->session = $DB->get_record('scormmakercmi5_session', ['tokenhash' => hash('sha256', $tokenb)]);
        $this->assert_response(200, $this->post([$this->defined(vocab::VERB_INITIALIZED)]));
    }

    /**
     * The state changes between the pre-validation and the lock (another request of the same session stored
     * initialized): the batch is judged again under the lock and refused.
     */
    public function test_rules_judged_again_under_lock(): void {
        $first = $this->defined(vocab::VERB_INITIALIZED);
        $done = false;
        $this->hook(function (string $stage) use (&$done, $first): void {
            if ($stage === 'beforelock' && !$done) {
                $done = true;
                statement_store::store_batch([$first], $this->ctx());
            }
        });
        $this->assert_response(
            403,
            $this->post([$this->defined(vocab::VERB_INITIALIZED), $this->allowed()]),
            'err_cmi5rule',
            '9.3.0.0-5'
        );
        $this->assertTrue($done);
        $this->assertSame(1, $this->au_count());
    }

    /**
     * terminated ends the session: status 2, duration, token revoked; the next request is 401.
     */
    public function test_terminated(): void {
        $batch = [$this->defined(vocab::VERB_INITIALIZED), $this->defined(vocab::VERB_TERMINATED, null, 'PT30S')];
        $this->assert_response(200, $this->post($batch));
        $session = $this->session_row();
        $this->assertEquals(launcher::STATUS_TERMINATED, $session->status);
        $this->assertEquals(30000, $session->durationms);
        $this->assertEquals(0, $session->tokenexpires);
        $this->assertEquals(self::NOW * 1000, $session->timeendedms);
        global $DB;
        $this->assertEquals(30000, $DB->get_field('scormmakercmi5_stmt', 'durationms', ['statementid' => $batch[1]['id']]));
        $this->assert_response(401, $this->post([$this->allowed()]), 'err_unauthorized');

        // A duration with years: accepted, stored without milliseconds.
        [$this->session, $this->token] = $this->launch_with_token();
        $batch = [$this->defined(vocab::VERB_INITIALIZED), $this->defined(vocab::VERB_TERMINATED, null, 'P1Y')];
        $this->assert_response(200, $this->post($batch));
        $session = $this->session_row();
        $this->assertEquals(launcher::STATUS_TERMINATED, $session->status);
        $this->assertNull($session->durationms);
        $this->assertNull($DB->get_field('scormmakercmi5_stmt', 'durationms', ['statementid' => $batch[1]['id']]));
    }

    /**
     * passed 0.9 with masteryScore 0.8: regau passed, both scores.
     */
    public function test_passed_scores(): void {
        global $DB;
        $DB->set_field('scormmakercmi5_au', 'masteryscore', 0.8, ['id' => $this->session->auid]);
        $batch = [$this->defined(vocab::VERB_INITIALIZED), $this->defined(vocab::VERB_PASSED, 0.9)];
        $this->assert_response(200, $this->post($batch));
        $regau = $this->regau();
        $this->assertEquals(1, $regau->passed);
        $this->assertEquals(0, $regau->failed);
        $this->assertEquals(0, $regau->completed);
        $this->assertEqualsWithDelta(0.9, (float)$regau->scorehighest, 0.000001);
        $this->assertEqualsWithDelta(0.9, (float)$regau->scorelast, 0.000001);
        $this->assertEquals(self::NOW * 1000, $regau->timelastscore);
        $this->assertEquals(self::NOW, $regau->timepassed);
        $this->assertEqualsWithDelta(0.9, (float)$DB->get_field(
            'scormmakercmi5_stmt',
            'scorescaled',
            ['statementid' => $batch[1]['id']]
        ), 0.000001);
        $this->assertEquals(1, $DB->get_field('scormmakercmi5_stmt', 'success', ['statementid' => $batch[1]['id']]));

        $this->assert_response(200, $this->post([$this->defined(vocab::VERB_COMPLETED)]));
        $regau = $this->regau();
        $this->assertEquals(1, $regau->completed);
        $this->assertEquals(self::NOW, $regau->timecompleted);
    }

    /**
     * failed 0.6 in one session and 0.4 in the next: highest 0.6, last 0.4.
     */
    public function test_failed_in_two_sessions(): void {
        global $DB;
        $DB->set_field('scormmakercmi5_au', 'masteryscore', 0.8, ['id' => $this->session->auid]);
        $this->assert_response(200, $this->post([
            $this->defined(vocab::VERB_INITIALIZED),
            $this->defined(vocab::VERB_FAILED, 0.6),
            $this->defined(vocab::VERB_TERMINATED),
        ]));
        [$this->session, $this->token] = $this->launch_with_token();
        $this->assert_response(200, $this->post([
            $this->defined(vocab::VERB_INITIALIZED),
            $this->defined(vocab::VERB_FAILED, 0.4),
        ]));
        $regau = $this->regau();
        $this->assertEquals(1, $regau->failed);
        $this->assertEquals(0, $regau->passed);
        $this->assertEqualsWithDelta(0.6, (float)$regau->scorehighest, 0.000001);
        $this->assertEqualsWithDelta(0.4, (float)$regau->scorelast, 0.000001);
    }

    /**
     * The SCORM Maker profile columns and the progress column.
     */
    public function test_sm_profile(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $lesson = $this->sm(self::ADL . 'experienced', 'licao/l1');
        $progress = $this->allowed(self::ADL . 'progressed');
        $progress['result'] = ['extensions' => [vocab::EXT_PROGRESS => 40]];
        $whole = $this->allowed(self::ADL . 'progressed');
        $whole['result'] = ['extensions' => [vocab::EXT_PROGRESS => 50.0]];
        $this->assert_response(200, $this->post([$lesson, $progress, $whole]));

        $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $lesson['id']], '*', MUST_EXIST);
        $this->assertEquals(1, $row->smprofile);
        $this->assertSame('3', $row->smversion);
        $this->assertSame('licao', $row->smkind);
        $this->assertSame('l1', $row->smitem);
        $this->assertEquals(statement_store::KIND_ALLOWED, $row->cmi5kind);
        $this->assertEquals(40, $DB->get_field('scormmakercmi5_stmt', 'progress', ['statementid' => $progress['id']]));
        $this->assertEquals(50, $DB->get_field('scormmakercmi5_stmt', 'progress', ['statementid' => $whole['id']]));
        $this->assertEquals(0, $DB->get_field('scormmakercmi5_stmt', 'smprofile', ['statementid' => $progress['id']]));

        // Unit: sm_columns.
        $cols = statement_store::sm_columns($this->sm(self::ADL . 'answered', 'questao/q-9'));
        $noblock = ['smblock' => null, 'smblocktype' => null, 'smprofilever' => null];
        $this->assertEquals(
            (object)(['smprofile' => 1, 'smversion' => '3', 'smkind' => 'questao', 'smitem' => 'q-9'] + $noblock),
            $cols
        );
        $none = (object)(['smprofile' => 0, 'smversion' => null, 'smkind' => null, 'smitem' => null] + $noblock);
        $this->assertEquals($none, statement_store::sm_columns($this->allowed()));
        // Profile statement about the AU itself: profile and version, no item.
        $st = $this->sm(self::ADL . 'experienced', 'licao/l1');
        $st['object']['id'] = self::SMCOURSE . '/au';
        $this->assertEquals(
            (object)(['smprofile' => 1, 'smversion' => '3', 'smkind' => null, 'smitem' => null] + $noblock),
            statement_store::sm_columns($st)
        );
        // Query or fragment is not part of the item; another kind is no item.
        $this->assertNull(statement_store::sm_columns($this->sm(self::ADL . 'experienced', 'licao/l1?x=1'))->smitem);
        $this->assertNull(statement_store::sm_columns($this->sm(self::ADL . 'experienced', 'pagina/p1'))->smkind);
        // Width limits (design §3.13): 64 is extracted, 65 drops the whole profile, never truncated.
        $this->assertSame(
            str_repeat('v', 64),
            statement_store::sm_columns($this->sm(self::ADL . 'experienced', 'licao/l1', str_repeat('v', 64)))->smversion
        );
        $this->assertEquals(
            $none,
            statement_store::sm_columns($this->sm(self::ADL . 'experienced', 'licao/l1', str_repeat('v', 65)))
        );
        $this->assertSame(
            str_repeat('é', 64),
            statement_store::sm_columns($this->sm(self::ADL . 'experienced', 'licao/' . str_repeat('é', 64)))->smitem
        );
        $this->assertEquals(
            $none,
            statement_store::sm_columns($this->sm(self::ADL . 'experienced', 'licao/' . str_repeat('i', 65)))
        );
        $numeric = $this->sm(self::ADL . 'experienced', 'licao/l1');
        $numeric['context']['extensions'][vocab::SM_EXT_VERSION] = 7;
        $this->assertSame('7', statement_store::sm_columns($numeric)->smversion);
        $numeric['context']['extensions'][vocab::SM_EXT_VERSION] = ['x' => 1];
        $this->assertEquals($none, statement_store::sm_columns($numeric));
    }

    /**
     * Values wider than the profile columns: the statement is stored without the profile and without itemdef.
     */
    public function test_sm_profile_too_wide(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $wideversion = $this->sm(self::ADL . 'experienced', 'licao/l1', str_repeat('v', 65));
        $wideversion['object']['definition'] = ['name' => ['pt-BR' => 'Lição 1']];
        $wideitem = $this->sm(self::ADL . 'experienced', 'licao/' . str_repeat('i', 65));
        $wideitem['object']['definition'] = ['name' => ['pt-BR' => 'Lição 2']];
        $ok = $this->sm(self::ADL . 'experienced', 'licao/' . str_repeat('i', 64), str_repeat('v', 64));
        $ok['object']['definition'] = ['name' => ['pt-BR' => 'Lição 3']];
        $this->assert_response(200, $this->post([$wideversion, $wideitem, $ok]));
        foreach ([$wideversion, $wideitem] as $st) {
            $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $st['id']], '*', MUST_EXIST);
            $this->assertEquals(0, $row->smprofile);
            $this->assertNull($row->smversion);
            $this->assertNull($row->smkind);
            $this->assertNull($row->smitem);
        }
        $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $ok['id']], '*', MUST_EXIST);
        $this->assertSame(str_repeat('v', 64), $row->smversion);
        $this->assertSame(str_repeat('i', 64), $row->smitem);
        $defs = $DB->get_records('scormmakercmi5_itemdef');
        $this->assertCount(1, $defs);
        $this->assertSame(str_repeat('i', 64), reset($defs)->smitem);
    }

    /**
     * The profile 2 columns are stored with the statement (subproject D).
     */
    public function test_rich_columns_stored(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $summary = $this->analysis();
        $decision = $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1');
        $wide = $this->analysis(vocab::VERB_INTERACTED, 'bloco/' . str_repeat('b', 65));
        $this->assert_response(200, $this->post([$summary, $decision, $wide]));

        $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $summary['id']], '*', MUST_EXIST);
        $this->assertSame(['3', 'bloco', 'b1', 'b1', 'flashcards'], [$row->smversion, $row->smkind, $row->smitem,
            $row->smblock, $row->smblocktype]);
        $this->assertEquals(2, $row->smprofilever);
        $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $decision['id']], '*', MUST_EXIST);
        $this->assertSame(['item', 'k1', 'b1'], [$row->smkind, $row->smitem, $row->smblock]);
        // Too long: the columns stay NULL, the statement is stored. (An id with whitespace never gets here: the
        // validator refuses it as an IRI, 400; rich_columns_test covers it in sm_columns.)
        $row = $DB->get_record('scormmakercmi5_stmt', ['statementid' => $wide['id']], '*', MUST_EXIST);
        $this->assertSame([null, null, null], [$row->smblock, $row->smblocktype, $row->smprofilever]);
        $this->assertEquals(0, $row->smprofile);
    }

    /**
     * The analysis never spends maxstatementsperreg (it guards the completion): summaries and decisions are stored
     * past it, the other statements keep the all-or-nothing ceiling (same rule as the hosted course).
     */
    public function test_analysis_outside_the_registration_limit(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $count = (int)$this->reg()->statementcount;
        set_config('maxstatementsperreg', $count + 1, 'mod_scormmakercmi5');
        set_config('maxanalysisperreg', 100, 'mod_scormmakercmi5');

        $this->assert_response(200, $this->post([$this->analysis(), $this->analysis(), $this->analysis()]));
        $this->assertSame($count, (int)$this->reg()->statementcount, 'the analysis is not counted');
        $batch = [$this->analysis(), $this->allowed(), $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1')];
        $this->assertSame(array_column($batch, 'id'), $this->assert_response(200, $this->post($batch)));
        $this->assertSame($count + 1, (int)$this->reg()->statementcount);
        $this->assertSame(5, $this->analysis_count());

        // The essential ceiling still refuses the whole batch, the analysis in it included.
        $refused = [$this->analysis(), $this->allowed()];
        $this->assert_response(403, $this->post($refused), 'err_lrsreglimit', 'limit');
        $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $refused[0]['id']]));
        $this->assertSame(5, $this->analysis_count());
        // A batch of analysis only is still stored once the essential ceiling is reached.
        $this->assert_response(200, $this->post([$this->analysis()]));
        $this->assertSame(6, $this->analysis_count());
    }

    /**
     * Over maxanalysisperreg the analysis statements are skipped in silence: the rest of the batch is stored, the
     * request succeeds with every id and the client never resends them.
     */
    public function test_analysis_over_its_own_limit_is_skipped(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        set_config('maxanalysisperreg', 3, 'mod_scormmakercmi5');
        $this->assert_response(200, $this->post([$this->analysis(), $this->analysis()]));
        $count = (int)$this->reg()->statementcount;

        $batch = [
            $this->analysis(),
            $this->allowed(),
            $this->analysis(),
            $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1'),
        ];
        $this->assertSame(array_column($batch, 'id'), $this->assert_response(200, $this->post($batch)));
        // The batch that reaches the ceiling leaves one line in the log (no learner data); the later ones do not.
        $this->assertDebuggingCalled(
            "mod_scormmakercmi5 lrs: registration {$this->session->regid} reached maxanalysisperreg (3); "
                . '2 analysis statement(s) skipped, the next ones are skipped without a log',
            DEBUG_NORMAL
        );
        $this->assertSame(3, $this->analysis_count());
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $batch[0]['id']]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $batch[1]['id']]));
        $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $batch[2]['id']]));
        $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $batch[3]['id']]));
        $this->assertSame($count + 1, (int)$this->reg()->statementcount);

        // Only skipped analysis: 200, nothing stored; a skipped one sent again is skipped again.
        $this->assertSame([$batch[2]['id']], $this->assert_response(200, $this->post([$batch[2]])));
        $this->assertSame(3, $this->analysis_count());
        // The completion still arrives.
        $done = $this->defined(vocab::VERB_COMPLETED);
        $this->assert_response(200, $this->post([$this->analysis(), $done]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => strtolower($done['id'])]));
        $this->assertEquals(1, $this->regau()->completed);
        $this->assertSame(3, $this->analysis_count());
    }

    /**
     * A block summary at the edges of every limit of analysis_bounds (audit M-D1).
     *
     * @return array result.extensions.
     */
    private static function edge_extensions(): array {
        $segments = [];
        for ($k = 0; $k < 39; $k++) {
            $segments[] = [$k * 10, $k * 10 + 5.5];
        }
        $segments[] = [86000, 86400];
        return [
            vocab::SM_EXT_SUMMARY => ['cliques' => 100000, 'aberturas' => 0, 'itensVistos' => array_map('strval', range(1, 500)),
                'itensTotal' => 500],
            vocab::SM_EXT_VIDEO => ['duracaoMidia' => 86400, 'trechosAssistidos' => $segments, 'pulos' => 100000,
                'pausas' => 100000.0, 'percentualAssistido' => 1],
            vocab::SM_EXT_HEATMAP => ['grade' => 10, 'celulas' => ['0,0' => 100000, '9,9' => 1],
                'cenas' => ['cA' => ['9,0' => 3]]],
        ];
    }

    /**
     * A block summary of the analysis with these result extensions.
     *
     * @param array $extensions result.extensions.
     * @return array
     */
    private function summary(array $extensions): array {
        $st = $this->analysis();
        $st['result'] = ['duration' => 'PT5S', 'extensions' => $extensions];
        return $st;
    }

    /**
     * Every limit of a block summary: on the edge it is stored; one step outside, the whole batch is refused with a
     * 400 that names the statement and the field, and nothing of it is stored.
     */
    public function test_summary_limits(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $edge = self::edge_extensions();
        $this->assertLessThanOrEqual(8192, strlen(json_encode($edge, statement_store::JSON_FLAGS)));
        $ok = $this->summary($edge);
        $this->assert_response(200, $this->post([$ok]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $ok['id']]));
        // A summary without extensions, or with an empty heatmap, is inside the limits.
        $this->assert_response(200, $this->post([$this->analysis()]));
        $this->assert_response(200, $this->post('[' . json_encode($this->summary([
            vocab::SM_EXT_HEATMAP => ['grade' => 10],
        ]), statement_store::JSON_FLAGS) . ']'));
        // A media of unknown duration (streaming: the player sends 0) is accepted with no segments, never with one.
        $unknown = [
            $this->summary([vocab::SM_EXT_VIDEO => ['duracaoMidia' => 0, 'trechosAssistidos' => [], 'pulos' => 1]]),
            $this->summary([vocab::SM_EXT_VIDEO => ['duracaoMidia' => 0.0, 'pausas' => 2]]),
        ];
        $this->assert_response(200, $this->post($unknown));
        foreach ($unknown as $st) {
            $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
        }
        $withsegment = $this->summary([vocab::SM_EXT_VIDEO => ['duracaoMidia' => 0, 'trechosAssistidos' => [[0, 0]]]]);
        $body = $this->assert_response(400, $this->post([$withsegment]), 'err_lrsanalysislimit', null);
        $this->assertStringContainsString('1 (video.duracaoMidia)', $body['message']);

        $s = vocab::SM_EXT_SUMMARY;
        $v = vocab::SM_EXT_VIDEO;
        $m = vocab::SM_EXT_HEATMAP;
        $cases = [
            'video.duracaoMidia' => [
                [$v => ['duracaoMidia' => 86400.1]],
                [$v => ['duracaoMidia' => 10000000]],
                [$v => ['duracaoMidia' => 0]],
                [$v => ['duracaoMidia' => 'Infinity']],
                [$v => ['duracaoMidia' => 1e300]],
            ],
            'video.trechosAssistidos' => [
                [$v => ['trechosAssistidos' => array_merge($edge[$v]['trechosAssistidos'], [[1, 2]])]],
                [$v => ['trechosAssistidos' => [[86000, 86400.1]]]],
                [$v => ['trechosAssistidos' => [[-1, 2]]]],
                [$v => ['trechosAssistidos' => [[3, 2]]]],
                [$v => ['trechosAssistidos' => [[1, 2, 3]]]],
                [$v => ['trechosAssistidos' => [['0', 2]]]],
            ],
            'video.pulos' => [[$v => ['pulos' => 100001]], [$v => ['pulos' => 1.5]], [$v => ['pulos' => -1]]],
            'resumo-do-bloco.cliques' => [[$s => ['cliques' => 100001]]],
            'resumo-do-bloco.itensVistos' => [[$s => ['itensVistos' => array_map('strval', range(1, 501))]]],
            'resumo-do-bloco.itensTotal' => [[$s => ['itensTotal' => 501]]],
            'mapa-de-calor.grade' => [[$m => ['grade' => 1000]]],
            'mapa-de-calor.celulas' => [[$m => ['celulas' => ['10,0' => 1]]], [$m => ['celulas' => ['1,1' => 100001]]]],
            'mapa-de-calor.cenas' => [[$m => ['cenas' => ['cA' => ['0,10' => 1]]]]],
            'bytes' => [[$s => ['itensVistos' => array_fill(0, 500, str_repeat('i', 20))]]],
        ];
        foreach ($cases as $field => $changes) {
            foreach ($changes as $n => $change) {
                $bad = $this->summary(array_replace_recursive($edge, $change));
                if ($field === 'video.trechosAssistidos' || $field === 'resumo-do-bloco.itensVistos' || $field === 'bytes') {
                    $key = array_key_first($change);
                    $bad['result']['extensions'][$key][array_key_first($change[$key])] = reset($change[$key]);
                }
                $batch = [$this->allowed(), $bad];
                $body = $this->assert_response(400, $this->post($batch), 'err_lrsanalysislimit', null);
                $this->assertStringContainsString("2 ({$field})", $body['message'], "{$field} {$n}");
                foreach ($batch as $st) {
                    $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]), "{$field} {$n}");
                }
            }
        }
        // PUT goes through the same check.
        $bad = $this->summary([$v => ['duracaoMidia' => 10000000]]);
        $this->assert_response(400, $this->put(['statementId' => $bad['id']], $bad), 'err_lrsanalysislimit', null);
    }

    /**
     * The answer of a decision of the analysis has at most 500 characters (Unicode characters, not bytes): 500 with
     * emoji is stored, 501 refuses the batch with a 400; a decision of any other content is not limited.
     */
    public function test_decision_answer_limit(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $decision = function (string $answer): array {
            $st = $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1');
            $st['result'] = ['response' => $answer];
            return $st;
        };
        $ok = $decision(str_repeat('á', 498) . '🙂🙂');
        $this->assert_response(200, $this->post([$ok]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $ok['id']]));
        $long = $decision(str_repeat('a', 501));
        $batch = [$this->allowed(), $long];
        $body = $this->assert_response(400, $this->post($batch), 'err_lrsanalysislimit', null);
        $this->assertStringContainsString('2 (response)', $body['message']);
        foreach ($batch as $st) {
            $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
        }
        $thirdparty = $this->allowed(vocab::VERB_SELECTED, 'https://example.com/xapi/activities/choice-1');
        $thirdparty['result'] = ['response' => str_repeat('a', 1000)];
        $this->assert_response(200, $this->post([$thirdparty]));
    }

    /**
     * The 8192-byte edge of the summary extensions: exactly 8192 is stored, 8193 is refused.
     */
    public function test_summary_bytes_edge(): void {
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $extensions = static fn(int $n): array => [
            vocab::SM_EXT_SUMMARY => ['cliques' => 0, 'aberturas' => 0, 'itensVistos' => [str_repeat('i', $n)], 'itensTotal' => 1],
        ];
        $size = static fn(int $n): int => strlen(json_encode($extensions($n), statement_store::JSON_FLAGS));
        $n = 8192 - $size(0);
        $this->assertSame(8192, $size($n));
        $this->assert_response(200, $this->post([$this->summary($extensions($n))]));
        $body = $this->assert_response(400, $this->post([$this->summary($extensions($n + 1))]), 'err_lrsanalysislimit', null);
        $this->assertStringContainsString('1 (bytes)', $body['message']);
    }

    /**
     * Final wave W2a (M-2): the duration of a block summary is only PT<n>S or PT<n>.<d>S up to 86400 s; anything else
     * refuses the batch with a 400 naming "duration". Its time column comes from the decimal text, half up (PT1.0005S
     * is 1001 ms, the float of iso_duration would depend on the rounding); an absent duration is accepted with no time.
     */
    public function test_summary_duration_rule(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $summary = function (?string $duration): array {
            $st = $this->summary([]);
            if ($duration === null) {
                unset($st['result']['duration']);
            } else {
                $st['result']['duration'] = $duration;
            }
            return $st;
        };
        $columns = ['PT0S' => 0, 'PT86400S' => 86400000, 'PT1.0005S' => 1001, 'PT0.0004S' => 0, 'PT3723.46S' => 3723460,
            // The float of iso_duration reads 0.0005 here and gives 1 ms; the decimal text gives 0.
            'PT0.0004999999999999999999S' => 0];
        foreach ($columns as $d => $ms) {
            $st = $summary($d);
            $this->assert_response(200, $this->post([$st]));
            $this->assertEquals($ms, $DB->get_field('scormmakercmi5_stmt', 'durationms', ['statementid' => $st['id']]), $d);
        }
        $st = $summary(null);
        $this->assert_response(200, $this->post([$st]));
        $this->assertNull($DB->get_field('scormmakercmi5_stmt', 'durationms', ['statementid' => $st['id']]));
        foreach (['P1D', 'PT1H', 'PT1M', 'PT0.5M', 'PT1,5S', 'PT86400.001S', 'PT86401S', 'P0DT5S'] as $d) {
            $bad = $summary($d);
            $body = $this->assert_response(400, $this->post([$this->allowed(), $bad]), 'err_lrsanalysislimit', null);
            $this->assertStringContainsString('2 (duration)', $body['message'], $d);
            $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $bad['id']]), $d);
        }
        // A decision and any other statement keep the ISO 8601 durations.
        $decision = $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1');
        $decision['result'] = ['response' => 'sei', 'duration' => 'PT1H'];
        $other = $this->allowed();
        $other['result'] = ['duration' => 'P1D'];
        $this->assert_response(200, $this->post([$decision, $other]));
        $this->assertEquals(86400000, $DB->get_field('scormmakercmi5_stmt', 'durationms', ['statementid' => $other['id']]));
    }

    /**
     * Final wave W2a (M-1): percentualAssistido, when present, is a finite number from 0 to 100.
     */
    public function test_summary_percent_limit(): void {
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $video = fn(mixed $pct): array => $this->summary([vocab::SM_EXT_VIDEO => [
            'duracaoMidia' => 20, 'trechosAssistidos' => [[0, 10]], 'percentualAssistido' => $pct,
        ]]);
        foreach ([0, 100, 50.5, 100.0] as $pct) {
            $this->assert_response(200, $this->post([$video($pct)]), null);
        }
        foreach ([100.5, -0.5, 1.5e19, '50', true, null] as $pct) {
            $body = $this->assert_response(400, $this->post([$video($pct)]), 'err_lrsanalysislimit', null);
            $this->assertStringContainsString('1 (video.percentualAssistido)', $body['message'], var_export($pct, true));
        }
    }

    /**
     * Final wave W2a (M-3): a summary or a decision of the analysis without a timestamp is refused with the 400 of the
     * limits (the "last" decision depends on it), before the cmi5 rule 2 would answer 403.
     */
    public function test_analysis_needs_a_timestamp(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $decision = $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1');
        $decision['result'] = ['response' => 'sei'];
        foreach ([$this->summary([]), $decision] as $st) {
            unset($st['timestamp']);
            $body = $this->assert_response(400, $this->post([$st]), 'err_lrsanalysislimit', null);
            $this->assertStringContainsString('1 (timestamp)', $body['message']);
            $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
        }
    }

    /**
     * Final wave W2a (M-6): a summary or a decision of the analysis is at most 16384 bytes as a whole (the player
     * sends at most 4096); one byte more is refused with the 413 of a statement too large. Other statements keep the
     * general limit.
     */
    public function test_analysis_statement_size_edge(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $padded = function (int $bytes, ?array $st = null): array {
            $st ??= $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1');
            $st['result'] = ['response' => 'sei'];
            $st['context']['extensions']['https://example.com/pad'] = '';
            $empty = strlen(json_encode($st, statement_store::JSON_FLAGS));
            $st['context']['extensions']['https://example.com/pad'] = str_repeat('x', $bytes - $empty);
            $this->assertSame($bytes, strlen(json_encode($st, statement_store::JSON_FLAGS)));
            return $st;
        };
        $ok = $padded(16384);
        $this->assert_response(200, $this->post([$ok]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $ok['id']]));
        $big = $padded(16385);
        $this->assert_response(413, $this->post([$this->allowed(), $big]), 'err_lrsstatementsize', null);
        $this->assertFalse($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $big['id']]));
        $other = $padded(30000, $this->allowed());
        $this->assert_response(200, $this->post([$other]));
    }

    /**
     * The limits are those of the block summaries of the analysis only: a decision, a third-party interacted, a
     * statement outside the profile 2 and one about a lesson with the same extensions are stored as before.
     */
    public function test_summary_limits_only_for_the_analysis(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $huge = ['result' => ['extensions' => [
            vocab::SM_EXT_VIDEO => ['duracaoMidia' => 10000000, 'trechosAssistidos' => [[0, 1e7]]],
            vocab::SM_EXT_HEATMAP => ['grade' => 1000, 'celulas' => ['999,999' => 1]],
        ]]];
        $decision = $this->analysis(vocab::VERB_SELECTED, 'bloco/b1/item/k1') + $huge;
        $thirdparty = $this->allowed(vocab::VERB_INTERACTED, 'https://example.com/xapi/activities/button-1') + $huge;
        $noprofilever = $this->sm(vocab::VERB_INTERACTED, 'bloco/b2') + $huge;
        $lesson = $this->sm(vocab::VERB_INTERACTED, 'licao/l1') + $huge;
        $lesson['context']['extensions'][vocab::SM_EXT_PROFILEVER] = 2;
        $batch = [$decision, $thirdparty, $noprofilever, $lesson];
        $this->assert_response(200, $this->post($batch));
        foreach ($batch as $st) {
            $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]));
        }
    }

    /**
     * Only the analysis of SCORM Maker (profile 2, block or item) is exempted: the same verbs from any other AU, or
     * outside the profile 2, are ordinary statements — counted in maxstatementsperreg and never skipped.
     */
    public function test_third_party_interacted_is_not_analysis(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        set_config('maxanalysisperreg', 1, 'mod_scormmakercmi5');
        $this->assert_response(200, $this->post([$this->analysis()]));
        $count = (int)$this->reg()->statementcount;

        $thirdparty = $this->allowed(vocab::VERB_INTERACTED, 'https://example.com/xapi/activities/button-1');
        $selected = $this->allowed(vocab::VERB_SELECTED, 'https://example.com/xapi/activities/choice-1');
        $lesson = $this->sm(vocab::VERB_INTERACTED, 'licao/l1');
        $lesson['context']['extensions'][vocab::SM_EXT_PROFILEVER] = 2;
        $noprofilever = $this->sm(vocab::VERB_INTERACTED, 'bloco/b2');
        $batch = [$thirdparty, $selected, $lesson, $noprofilever];
        $this->assert_response(200, $this->post($batch));
        foreach ($batch as $st) {
            $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => $st['id']]), 'stored, not skipped');
        }
        $this->assertSame($count + 4, (int)$this->reg()->statementcount, 'counted in maxstatementsperreg');

        // And they hit the essential ceiling like any statement.
        set_config('maxstatementsperreg', $count + 4, 'mod_scormmakercmi5');
        $more = $this->allowed(vocab::VERB_INTERACTED, 'https://example.com/xapi/activities/button-2');
        $this->assert_response(403, $this->post([$more]), 'err_lrsreglimit', 'limit');
        // The SCORM Maker analysis is still outside it (and over its own ceiling: skipped, 200).
        $this->assert_response(200, $this->post([$this->analysis()]));
    }

    /**
     * The analysis is still judged by the cmi5 rules: one that breaks them refuses the whole batch.
     */
    public function test_analysis_keeps_the_cmi5_rules(): void {
        $this->assert_response(403, $this->post([$this->analysis()]), 'err_cmi5rule', '8.1.2.0-5');
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        set_config('maxanalysisperreg', 1, 'mod_scormmakercmi5');
        $this->assert_response(200, $this->post([$this->analysis()]));
        $bad = $this->analysis();
        $bad['context']['registration'] = \core\uuid::generate();
        $this->assert_response(403, $this->post([$this->allowed(), $bad]), 'err_cmi5rule', '9.6.1');
        $this->assertSame(1, $this->analysis_count());
    }

    /**
     * itemdef keeps the definition of the first statement that brings one.
     */
    public function test_itemdef_first_wins(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $definition = [
            'type' => 'http://adlnet.gov/expapi/activities/cmi.interaction',
            'name' => ['pt-BR' => 'Questão 1'],
            'description' => ['pt-BR' => 'Quanto é 1 + 1?'],
            'interactionType' => 'choice',
            'correctResponsesPattern' => ['b'],
            'choices' => [['id' => 'a', 'description' => ['pt-BR' => '1']], ['id' => 'b', 'description' => ['pt-BR' => '2']]],
        ];
        $plain = $this->sm(self::ADL . 'answered', 'questao/q1');
        $first = $this->sm(self::ADL . 'answered', 'questao/q1');
        $first['object']['definition'] = $definition;
        $first['result'] = ['response' => 'b', 'success' => true];
        $second = $this->sm(self::ADL . 'answered', 'questao/q1');
        $second['object']['definition'] = ['description' => ['pt-BR' => 'Outra']] + $definition;
        $this->assert_response(200, $this->post([$plain, $first, $second]));
        $third = $this->sm(self::ADL . 'answered', 'questao/q1');
        $third['object']['definition'] = ['description' => ['pt-BR' => 'Mais uma']] + $definition;
        $this->assert_response(200, $this->post([$third]));

        $defs = $DB->get_records('scormmakercmi5_itemdef');
        $this->assertCount(1, $defs);
        $def = reset($defs);
        $reg = $this->reg();
        $this->assertEquals($reg->pkgid, $def->pkgid);
        $this->assertSame('3', $def->smversion);
        $this->assertSame('questao', $def->smkind);
        $this->assertSame('q1', $def->smitem);
        $this->assertSame(self::SMCOURSE . '/questao/q1', $def->objectid);
        $this->assertSame(['pt-BR' => 'Questão 1'], json_decode($def->name, true));
        $this->assertSame($definition, json_decode($def->definition, true));
        $this->assertEquals(self::NOW * 1000, $def->firstseenms);

        // Another package version is another item.
        $v4 = $this->sm(self::ADL . 'answered', 'questao/q1', '4');
        $v4['object']['definition'] = $definition;
        $this->assert_response(200, $this->post([$v4]));
        $this->assertSame(2, $DB->count_records('scormmakercmi5_itemdef'));
    }

    /**
     * An empty object in the statement comes back as an object, not as a list.
     */
    public function test_payload_keeps_empty_objects(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $st = $this->allowed();
        $st['result'] = ['extensions' => new \stdClass()];
        $st['object']['definition'] = ['name' => new \stdClass(), 'extensions' => ['https://example.com/x' => []]];
        $this->assert_response(200, $this->post([$st]));
        $payload = $DB->get_field('scormmakercmi5_stmt', 'payload', ['statementid' => $st['id']]);
        $this->assertStringContainsString('"result":{"extensions":{}}', $payload);
        $this->assertStringContainsString('"name":{}', $payload);
        // Inside an extension value the JSON is the AU's: a list stays a list.
        $this->assertStringContainsString('"https://example.com/x":[]', $payload);
        // Identical retry still recognised.
        $this->assert_response(200, $this->post([$st]));
        $this->assertSame(2, $this->au_count());
    }

    /**
     * The payload is the statement as sent (objects stay objects, at any depth), plus what the LRS sets; a single
     * contextActivities value is stored as a list (xAPI 2.4.6.2).
     */
    public function test_payload_fidelity(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $st = $this->allowed();
        $st['result'] = ['extensions' => [
            'https://example.com/k' => (object)['k' => new \stdClass()],
            'https://example.com/r' => (object)['0' => 'a', '1' => 'b'],
            'https://example.com/l' => ['a', 'b'],
        ]];
        $st['object']['definition'] = [
            'name' => ['pt-BR' => 'Lição'],
            'extensions' => ['https://example.com/e' => new \stdClass()],
        ];
        $body = json_encode($st, statement_store::JSON_FLAGS);
        $this->assertStringContainsString('{"k":{}}', $body);
        $this->assertStringContainsString('{"0":"a","1":"b"}', $body);
        $this->assert_response(200, $this->post($body));

        $raw = $DB->get_field('scormmakercmi5_stmt', 'payload', ['statementid' => $st['id']], MUST_EXIST);
        $payload = json_decode($raw, false);
        $sent = json_decode($body, false);
        $this->assertEquals($sent->result, $payload->result);
        $this->assertSame(json_encode($sent->result), json_encode($payload->result));
        $this->assertSame(json_encode($sent->object), json_encode($payload->object));
        $this->assertSame(json_encode($sent->actor), json_encode($payload->actor));
        $this->assertTrue(is_array($payload->context->contextActivities->grouping));
        $this->assertEquals([$sent->context->contextActivities->grouping], $payload->context->contextActivities->grouping);
        foreach (['id', 'timestamp', 'verb'] as $key) {
            $this->assertEquals($sent->{$key}, $payload->{$key});
        }
        $this->assertSame(statement_store::DEFAULT_VERSION, $payload->version);
        $this->assertSame(json_encode(actor::lms_authority()), json_encode($payload->authority));

        // The identical retry is still recognised.
        $this->assert_response(200, $this->post($body));
        $this->assertSame(2, $this->au_count());
        // An object with keys "0", "1"… as the whole body is not a statement.
        $this->assert_response(400, $this->post('{"0":' . $body . '}'), 'err_invalidstatement');

        // PUT keeps the object form too, with the id of the parameter when the body has none.
        $put = $this->allowed();
        $put['result'] = ['extensions' => ['https://example.com/r' => (object)['0' => 'x']]];
        $id = $put['id'];
        unset($put['id']);
        $this->assert_response(204, $this->put(['statementId' => $id], $put));
        $payload = json_decode($DB->get_field('scormmakercmi5_stmt', 'payload', ['statementid' => $id]), false);
        $this->assertSame('{"0":"x"}', json_encode($payload->result->extensions->{'https://example.com/r'}));
        $this->assertSame($id, $payload->id);
    }

    /**
     * Retries that are the same statement in another spelling (xAPI part two §2.3.1): id in upper case, timestamp
     * with +00:00, Group members in another order.
     */
    public function test_retry_equivalence(): void {
        global $DB;
        $this->post([$this->defined(vocab::VERB_INITIALIZED)]);
        $st = $this->allowed();
        $st['id'] = strtoupper($st['id']);
        $st['timestamp'] = '2026-09-30T12:00:00Z';
        $st['context']['team'] = ['objectType' => 'Group', 'member' => [
            ['objectType' => 'Agent', 'mbox' => 'mailto:a@example.com'],
            ['objectType' => 'Agent', 'mbox' => 'mailto:b@example.com'],
        ]];
        $this->assertSame([$st['id']], $this->assert_response(200, $this->post([$st])));
        $this->assertTrue($DB->record_exists('scormmakercmi5_stmt', ['statementid' => strtolower($st['id'])]));
        $payload = json_decode($DB->get_field('scormmakercmi5_stmt', 'payload', ['statementid' => strtolower($st['id'])]));
        $this->assertSame($st['id'], $payload->id);

        $again = $st;
        $again['id'] = strtolower($st['id']);
        $again['timestamp'] = '2026-09-30T12:00:00.000+00:00';
        $again['context']['team']['member'] = array_reverse($st['context']['team']['member']);
        $this->assertSame([$again['id']], $this->assert_response(200, $this->post([$again])));
        $this->assert_response(204, $this->put(['statementId' => $again['id']], $again));
        $this->assertSame(2, $this->au_count());

        $other = $again;
        $other['timestamp'] = '2026-09-30T12:00:01Z';
        $this->assert_response(409, $this->post([$other]), 'err_lrsconflict');
    }

    /**
     * A failure after the commit (derived rows) is logged; the stored batch still gets its success.
     */
    public function test_failure_after_commit(): void {
        $init = $this->defined(vocab::VERB_INITIALIZED);
        $this->hook(function (string $stage): void {
            if ($stage === 'aftercommit') {
                throw new \RuntimeException('simulated');
            }
        });
        $this->assertSame([$init['id']], $this->assert_response(200, $this->post([$init])));
        $this->assertDebuggingCalled();
        $this->assertSame(1, $this->au_count());
        $this->assertEquals(launcher::STATUS_INITIALIZED, $this->session_row()->status);
    }
}
