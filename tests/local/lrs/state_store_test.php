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
use mod_scormmakercmi5\local\runtime\actor;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\state_store::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(\mod_scormmakercmi5\local\lrs\request::class, 'assert_body_max')]
/**
 * Tests for the State resource of the LRS (design §3.10, §7.1; xAPI 1.0.3 part 3 §2.3; R4.4, R5.1, R5.2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\state_store
 * @covers     \mod_scormmakercmi5\local\lrs\request::assert_body_max
 */
final class state_store_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /** @var string Token of the launch. */
    private string $token;

    /** @var \stdClass Session of the launch. */
    private \stdClass $session;

    /**
     * Activity, launch and token.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setup_lrs_activity(self::NOW);
        [$this->session, $this->token] = $this->launch_with_token();
    }

    /**
     * The document parameters of the session.
     *
     * @param string|null $stateid stateId, none if null.
     * @return array
     */
    private function params(?string $stateid = 's1'): array {
        global $DB;
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $this->session->regid], '*', MUST_EXIST);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $this->session->auid], '*', MUST_EXIST);
        $params = [
            'activityId' => $au->lmsactivityid,
            'agent' => json_encode(actor::for_user((int)$this->user->id)),
            'registration' => $reg->registration,
        ];
        if ($stateid !== null) {
            $params['stateId'] = $stateid;
        }
        return $params;
    }

    /**
     * Sends a State request.
     *
     * @param string $method Method.
     * @param array $query Query.
     * @param string $body Body.
     * @param array $headers Headers.
     * @return response
     */
    private function state(string $method, array $query, string $body = '', array $headers = []): response {
        return router::dispatch($this->lrs_request($method, 'activities/state', $this->token, $query, $body, $headers));
    }

    /**
     * Asserts an xAPI error response.
     *
     * @param int $status Status.
     * @param string $errorcode Error code.
     * @param response $response Response.
     * @return array Decoded body.
     */
    private function assert_error(int $status, string $errorcode, response $response): array {
        $this->assertSame($status, $response->status, $response->body);
        $body = json_decode($response->body, true);
        $this->assertSame($errorcode, $body['error']);
        return $body;
    }

    /**
     * Number of documents of the learner that the AU wrote (LMS.LaunchData excluded).
     *
     * @return int
     */
    private function au_rows(): int {
        global $DB;
        return $DB->count_records('scormmakercmi5_state', ['userid' => $this->user->id, 'lmsowned' => 0]);
    }

    /**
     * PUT stores the document; GET gives it back with ETag, Last-Modified and its Content-Type.
     */
    public function test_put_and_get(): void {
        $put = $this->state('PUT', $this->params(), '{"a":1}', ['Content-Type' => 'application/json; charset=utf-8']);
        $this->assertSame(204, $put->status, $put->body);
        $this->assertSame('', $put->body);
        $this->assertSame('1.0.3', $put->headers['X-Experience-API-Version']);

        $get = $this->state('GET', $this->params());
        $this->assertSame(200, $get->status);
        $this->assertSame('{"a":1}', $get->body);
        $this->assertSame('"' . sha1('{"a":1}') . '"', $get->headers['ETag']);
        $this->assertSame(gmdate('D, d M Y H:i:s', self::NOW) . ' GMT', $get->headers['Last-Modified']);
        $this->assertSame('application/json; charset=utf-8', $get->headers['Content-Type']);
        $this->assertSame('no-store', $get->headers['Cache-Control']);

        // HEAD answers as GET, without the body.
        $head = $this->state('HEAD', $this->params());
        $this->assertSame(200, $head->status);
        $this->assertSame('', $head->body);
        $this->assertSame($get->headers['ETag'], $head->headers['ETag']);
    }

    /**
     * A second PUT without If-Match replaces the document: 204, never 409 (the lrsql lesson, design §7.1).
     */
    public function test_second_put_without_if_match(): void {
        $this->assertSame(204, $this->state('PUT', $this->params(), '{"a":1}', ['Content-Type' => 'application/json'])->status);
        $this->clock->bump(5);
        $put = $this->state('PUT', $this->params(), '{"z":9}', ['Content-Type' => 'application/json']);
        $this->assertSame(204, $put->status, $put->body);
        $get = $this->state('GET', $this->params());
        $this->assertSame('{"z":9}', $get->body);
        $this->assertSame(gmdate('D, d M Y H:i:s', self::NOW + 5) . ' GMT', $get->headers['Last-Modified']);
        $this->assertSame(1, $this->au_rows());
    }

    /**
     * POST merges JSON objects; POST creates a missing document; POST JSON over a text document → 400.
     */
    public function test_post_merges(): void {
        $json = ['Content-Type' => 'application/json'];
        $this->assertSame(204, $this->state('POST', $this->params(), '{"a":1,"n":{"x":1}}', $json)->status);
        $post = $this->state('POST', $this->params(), '{"b":2,"n":{"y":2},"f":1.0,"0":"zero","":"vazio","e":{}}', $json);
        $this->assertSame(204, $post->status, $post->body);
        $get = $this->state('GET', $this->params());
        $this->assertSame(
            ['a' => 1, 'n' => ['y' => 2], 'b' => 2, 'f' => 1.0, '0' => 'zero', '' => 'vazio', 'e' => []],
            json_decode($get->body, true)
        );
        $this->assertStringContainsString('"f":1.0', $get->body);
        $this->assertStringContainsString('"0":"zero"', $get->body);
        $this->assertStringContainsString('"e":{}', $get->body);
        $this->assertSame('"' . sha1($get->body) . '"', $get->headers['ETag']);
        $this->assertSame('application/json', $get->headers['Content-Type']);

        // A text document cannot be merged, in either direction.
        $this->assertSame(204, $this->state('PUT', $this->params('t'), 'plain text', ['Content-Type' => 'text/plain'])->status);
        $this->assert_error(400, 'err_lrsmerge', $this->state('POST', $this->params('t'), '{"b":2}', $json));
        $this->assertSame('plain text', $this->state('GET', $this->params('t'))->body);
        $this->assert_error(400, 'err_lrsmerge', $this->state('POST', $this->params(), 'x', ['Content-Type' => 'text/plain']));
        $this->assert_error(400, 'err_lrsmerge', $this->state('POST', $this->params(), '[1]', $json));
        $this->assert_error(400, 'err_lrsmerge', $this->state('POST', $this->params(), '{bad', $json));
        $this->assert_error(400, 'err_lrsmerge', $this->state('POST', $this->params('new'), '{bad', $json));
    }

    /**
     * If-Match that does not match → 412; If-None-Match: * over an existing document → 412; matching ones pass.
     */
    public function test_preconditions(): void {
        $json = ['Content-Type' => 'application/json'];
        $this->assertSame(204, $this->state('PUT', $this->params(), '{"a":1}', $json + ['If-None-Match' => '*'])->status);
        $etag = '"' . sha1('{"a":1}') . '"';

        foreach (['PUT', 'POST', 'DELETE'] as $method) {
            $this->assert_error(
                412,
                'err_lrsprecondition',
                $this->state($method, $this->params(), '{"b":2}', $json + ['If-Match' => '"' . sha1('other') . '"'])
            );
        }
        $put = $this->state('PUT', $this->params(), '{"b":2}', $json + ['If-None-Match' => '*']);
        $this->assert_error(412, 'err_lrsprecondition', $put);
        $put = $this->state('PUT', $this->params('none'), '{}', $json + ['If-Match' => '*']);
        $this->assert_error(412, 'err_lrsprecondition', $put);
        $this->assertSame('{"a":1}', $this->state('GET', $this->params())->body);

        $this->assertSame(204, $this->state('PUT', $this->params(), '{"b":2}', $json + ['If-Match' => 'W/"x", ' . $etag])->status);
        $this->assertSame(204, $this->state('POST', $this->params(), '{"c":3}', $json + ['If-Match' => '*'])->status);
        $etag = $this->state('GET', $this->params())->headers['ETag'];
        $this->assertSame(204, $this->state('DELETE', $this->params(), '', ['If-Match' => $etag])->status);
        $this->assertSame(0, $this->au_rows());
    }

    /**
     * GET of a missing document → 404; missing or invalid parameters → 400.
     */
    public function test_get_missing_and_bad_params(): void {
        $this->assert_error(404, 'err_lrsnodoc', $this->state('GET', $this->params('nothing')));

        $params = $this->params();
        foreach (['activityId', 'agent'] as $name) {
            $query = $params;
            unset($query[$name]);
            $body = $this->assert_error(400, 'err_lrsparam', $this->state('GET', $query));
            $this->assertStringContainsString($name, $body['message']);
        }
        $this->assert_error(400, 'err_lrsparam', $this->state('GET', ['agent' => '{bad'] + $params));
        foreach (['PUT', 'POST'] as $method) {
            $body = $this->assert_error(400, 'err_lrsparam', $this->state($method, $this->params(null), '{}'));
            $this->assertStringContainsString('stateId', $body['message']);
        }
        $this->assert_error(400, 'err_lrsparam', $this->state('GET', ['since' => 'yesterday'] + $this->params(null)));
    }

    /**
     * GET without stateId lists the ids; since keeps only those changed after it.
     */
    public function test_list_since(): void {
        $this->assertSame(204, $this->state('PUT', $this->params('old'), '{}')->status);
        $this->clock->bump(10);
        $this->assertSame(204, $this->state('PUT', $this->params('new'), '{}')->status);

        $all = $this->state('GET', $this->params(null));
        $this->assertSame(200, $all->status);
        $this->assertSame('application/json', $all->headers['Content-Type']);
        $ids = json_decode($all->body, true);
        sort($ids);
        $this->assertSame([vocab::LAUNCHDATA_ID, 'new', 'old'], $ids);

        $since = xapi_time::from_ms((self::NOW + 5) * 1000);
        $this->assertSame(['new'], json_decode($this->state('GET', $this->params(null) + ['since' => $since])->body, true));
    }

    /**
     * DELETE without stateId removes every document but LMS.LaunchData; DELETE of one document; DELETE of a missing
     * document is still 204.
     */
    public function test_delete(): void {
        global $DB;
        foreach (['a', 'b', 'c'] as $id) {
            $this->assertSame(204, $this->state('PUT', $this->params($id), '{}')->status);
        }
        $this->assertSame(204, $this->state('DELETE', $this->params('a'))->status);
        $this->assertSame(204, $this->state('DELETE', $this->params('a'))->status);
        $this->assertSame(2, $this->au_rows());

        $this->assertSame(204, $this->state('DELETE', $this->params(null))->status);
        $this->assertSame(0, $this->au_rows());
        $launchdata = ['userid' => $this->user->id, 'stateid' => vocab::LAUNCHDATA_ID];
        $this->assertTrue($DB->record_exists('scormmakercmi5_state', $launchdata));
        $this->assertSame(200, $this->state('GET', $this->params(vocab::LAUNCHDATA_ID))->status);
    }

    /**
     * The AU must not modify or delete LMS.LaunchData (cmi5 §10.2.1): 403 with rule 10.2.1.0-5, document unchanged.
     */
    public function test_launchdata_protected(): void {
        global $DB;
        $before = $DB->get_record('scormmakercmi5_state', ['userid' => $this->user->id, 'stateid' => vocab::LAUNCHDATA_ID]);
        foreach (['PUT', 'POST', 'DELETE'] as $method) {
            $body = $this->assert_error(
                403,
                'err_lrslaunchdata',
                $this->state($method, $this->params(vocab::LAUNCHDATA_ID), '{"x":1}', ['Content-Type' => 'application/json'])
            );
            $this->assertSame('10.2.1.0-5', $body['rule']);
        }
        $after = $DB->get_record('scormmakercmi5_state', ['userid' => $this->user->id, 'stateid' => vocab::LAUNCHDATA_ID]);
        $this->assertEquals($before, $after);
    }

    /**
     * GET of LMS.LaunchData before initialized (design §6.3): 200 with the document the LMS wrote.
     */
    public function test_launchdata_before_initialized(): void {
        global $DB;
        $this->assertEquals(0, $DB->get_field('scormmakercmi5_session', 'status', ['id' => $this->session->id]));
        $get = $this->state('GET', $this->params(vocab::LAUNCHDATA_ID));
        $this->assertSame(200, $get->status, $get->body);
        $data = json_decode($get->body, true);
        $this->assertSame('Normal', $data['launchMode']);
        $this->assertSame('application/json', $get->headers['Content-Type']);
    }

    /**
     * activityId, agent or registration of another session → 403, nothing written.
     */
    public function test_scope(): void {
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        [$othersession] = $this->launch_with_token((int)$other->id);
        global $DB;
        $otherreg = $DB->get_field('scormmakercmi5_reg', 'registration', ['id' => $othersession->regid]);
        $cases = [
            ['activityId' => 'https://example.com/other'],
            ['agent' => json_encode(actor::for_user((int)$other->id))],
            ['registration' => $otherreg],
        ];
        foreach ($cases as $change) {
            foreach (['GET', 'PUT', 'POST', 'DELETE'] as $method) {
                $this->assert_error(403, 'err_scope', $this->state($method, $change + $this->params(), '{}'));
            }
        }
        $this->assertSame(0, $this->au_rows());
    }

    /**
     * At most maxstatedocs documents per registration, AU and learner (default 100): the 101st new stateId is refused
     * with 403 and an xAPI error body; replacing or merging into an existing one, and deleting, still work.
     */
    public function test_new_document_above_the_quota_is_refused(): void {
        $this->assertSame(100, \mod_scormmakercmi5\local\limits::max_state_docs());
        $json = ['Content-Type' => 'application/json'];
        for ($i = 1; $i <= 100; $i++) {
            $put = $this->state('PUT', $this->params('doc' . $i), '{"n":' . $i . '}');
            $this->assertSame(204, $put->status, "doc{$i}: " . $put->body);
        }
        $this->assertSame(100, $this->au_rows());

        $body = $this->assert_error(403, 'err_lrsstatelimit', $this->state('PUT', $this->params('doc101'), '{"n":101}'));
        $this->assertStringContainsString('100', $body['message']);
        $this->assert_error(403, 'err_lrsstatelimit', $this->state('POST', $this->params('doc101'), '{"n":101}', $json));
        $this->assertSame(100, $this->au_rows());
        $this->assertSame(404, $this->state('GET', $this->params('doc101'))->status);

        // The 1st document can still be rewritten and merged; a deleted one frees its place.
        $this->assertSame(204, $this->state('PUT', $this->params('doc1'), '{"n":"again"}', $json)->status);
        $this->assertSame(204, $this->state('POST', $this->params('doc1'), '{"m":1}', $json)->status);
        $this->assertSame('{"n":"again","m":1}', $this->state('GET', $this->params('doc1'))->body);
        $this->assertSame(204, $this->state('DELETE', $this->params('doc2'))->status);
        $this->assertSame(204, $this->state('PUT', $this->params('doc101'), '{"n":101}')->status);
        $this->assertSame(100, $this->au_rows());
    }

    /**
     * The quota counts only the AU's documents of that registration: LMS.LaunchData does not count, and a document
     * written without a registration is another scope.
     */
    public function test_state_quota_scope(): void {
        set_config('maxstatedocs', 2, 'mod_scormmakercmi5');
        $this->assertSame(204, $this->state('PUT', $this->params('a'), '{}')->status);
        $this->assertSame(204, $this->state('PUT', $this->params('b'), '{}')->status);
        $this->assert_error(403, 'err_lrsstatelimit', $this->state('PUT', $this->params('c'), '{}'));

        $noreg = $this->params('c');
        unset($noreg['registration']);
        $this->assertSame(204, $this->state('PUT', $noreg, '{}')->status);
    }

    /**
     * A document above maxstatekb → 413, also when no Content-Length is declared (a chunked body, Task 11 review
     * Minor 2), and when the declared length is above it; a merge that would grow above it → 413.
     */
    public function test_too_large(): void {
        set_config('maxstatekb', 1, 'mod_scormmakercmi5');
        $big = '{"a":"' . str_repeat('x', 1100) . '"}';
        foreach (['PUT', 'POST'] as $method) {
            $this->assert_error(413, 'err_lrstoolarge', $this->state($method, $this->params(), $big));
            $this->assert_error(
                413,
                'err_lrstoolarge',
                $this->state($method, $this->params(), '{}', ['Content-Length' => '2000'])
            );
        }
        $this->assertSame(0, $this->au_rows());

        $exact = '{"a":"' . str_repeat('x', 1024 - 8) . '"}';
        $this->assertSame(1024, strlen($exact));
        $this->assertSame(204, $this->state('PUT', $this->params(), $exact, ['Content-Type' => 'application/json'])->status);
        $post = $this->state('POST', $this->params(), '{"b":2}', ['Content-Type' => 'application/json']);
        $this->assert_error(413, 'err_lrstoolarge', $post);
        $this->assertSame($exact, $this->state('GET', $this->params())->body);
    }

    /**
     * A body that is not UTF-8 (or has NUL bytes) is stored as base64 and given back byte for byte.
     */
    public function test_binary(): void {
        global $DB;
        foreach (['bin' => random_bytes(64) . "\xff\xfe", 'nul' => "a\0b"] as $id => $bytes) {
            $put = $this->state('PUT', $this->params($id), $bytes, ['Content-Type' => 'application/octet-stream']);
            $this->assertSame(204, $put->status, $put->body);
            $row = $DB->get_record('scormmakercmi5_state', ['userid' => $this->user->id, 'stateid' => $id], '*', MUST_EXIST);
            $this->assertEquals(1, $row->isbase64);
            $this->assertSame(sha1($bytes), $row->etag);
            $get = $this->state('GET', $this->params($id));
            $this->assertSame($bytes, $get->body);
            $this->assertSame('application/octet-stream', $get->headers['Content-Type']);
        }
        // No Content-Type: stored as application/octet-stream.
        $this->assertSame(204, $this->state('PUT', $this->params('plain'), 'texto')->status);
        $this->assertSame('application/octet-stream', $this->state('GET', $this->params('plain'))->headers['Content-Type']);
        $this->assertEquals(0, $DB->get_field('scormmakercmi5_state', 'isbase64', ['stateid' => 'plain']));
    }

    /**
     * Column widths (design §3.13): stateId over 254 characters and a Content-Type over 100 → 400, nothing written,
     * never a dml_write_exception; at the limit → 204.
     */
    public function test_column_widths(): void {
        foreach (['PUT', 'POST'] as $method) {
            $body = $this->assert_error(400, 'err_lrsparam', $this->state($method, $this->params(str_repeat('s', 255)), '{}'));
            $this->assertStringContainsString('stateId', $body['message']);
        }
        $this->assert_error(400, 'err_lrsparam', $this->state('GET', $this->params(str_repeat('s', 255))));
        // Multibyte: 254 characters but 508 bytes still fits the column.
        $this->assertSame(204, $this->state('PUT', $this->params(str_repeat('é', 254)), '{}')->status);
        $this->assertSame(204, $this->state('PUT', $this->params(str_repeat('s', 254)), '{}')->status);
        $this->assertSame(200, $this->state('GET', $this->params(str_repeat('s', 254)))->status);

        $type = 'application/' . str_repeat('x', 101 - strlen('application/'));
        $this->assertSame(101, strlen($type));
        foreach (['PUT', 'POST'] as $method) {
            $response = $this->state($method, $this->params('ct'), '{}', ['Content-Type' => $type]);
            $body = $this->assert_error(400, 'err_lrsheader', $response);
            $this->assertStringContainsString('Content-Type', $body['message']);
        }
        $this->assertSame(2, $this->au_rows());
        $this->assertSame(204, $this->state('PUT', $this->params('ct'), '{}', ['Content-Type' => substr($type, 0, 100)])->status);
    }

    /**
     * Multiple-document GET and DELETE without registration cover every registration (xAPI 1.0.3 part 3 §2.3): the
     * session's and the documents written without one; with registration, only that one.
     */
    public function test_multiple_without_registration(): void {
        global $DB;
        $noreg = $this->params('semreg');
        unset($noreg['registration']);
        $this->assertSame(204, $this->state('PUT', $noreg, '{}')->status);
        $this->assertSame(204, $this->state('PUT', $this->params('comreg'), '{}')->status);
        $this->assertSame(204, $this->state('PUT', $this->params('semreg'), '{}')->status);

        $all = $this->params(null);
        unset($all['registration']);
        $ids = json_decode($this->state('GET', $all)->body, true);
        sort($ids);
        $this->assertSame([vocab::LAUNCHDATA_ID, 'comreg', 'semreg'], $ids);
        $ids = json_decode($this->state('GET', $this->params(null))->body, true);
        sort($ids);
        $this->assertSame([vocab::LAUNCHDATA_ID, 'comreg', 'semreg'], $ids);
        $ids = json_decode($this->state('GET', $all + ['since' => xapi_time::from_ms((self::NOW + 1) * 1000)])->body, true);
        $this->assertSame([], $ids);

        // DELETE with registration leaves the documents without one.
        $this->assertSame(204, $this->state('DELETE', $this->params(null))->status);
        $this->assertSame(1, $this->au_rows());
        $this->assertSame(204, $this->state('PUT', $this->params('comreg'), '{}')->status);
        $this->assertSame(204, $this->state('DELETE', $all)->status);
        $this->assertSame(0, $this->au_rows());
        $this->assertTrue($DB->record_exists('scormmakercmi5_state', ['stateid' => vocab::LAUNCHDATA_ID]));
    }

    /**
     * Without registration the document is kept apart from the one of the registration (xAPI key includes it).
     */
    public function test_without_registration(): void {
        global $DB;
        $params = $this->params();
        unset($params['registration']);
        $this->assertSame(204, $this->state('PUT', $params, 'sem', ['Content-Type' => 'text/plain'])->status);
        $this->assertSame(204, $this->state('PUT', $this->params(), 'com', ['Content-Type' => 'text/plain'])->status);
        $this->assertSame('sem', $this->state('GET', $params)->body);
        $this->assertSame('com', $this->state('GET', $this->params())->body);
        $row = $DB->get_record('scormmakercmi5_state', ['stateid' => 's1', 'registration' => ''], '*', MUST_EXIST);
        $this->assertEquals(0, $row->regid);
        $this->assertEquals($this->instance->id, $row->scormmakercmi5id);
    }

    /** @var string Content-Security-Policy of every LRS response (security review item A-1). */
    private const CSP = "default-src 'none'; frame-ancestors 'none'; sandbox";

    /**
     * A State request in the alternate request syntax (xAPI 1.0.3 part 3 §1.3): a plain cross-site form POST, the
     * token and the version as form fields.
     *
     * @param string $method Intended method.
     * @param array $params Document parameters.
     * @param array $fields Extra form fields (headers, content).
     * @return response
     */
    private function alternate(string $method, array $params, array $fields = []): response {
        $form = http_build_query($params + $fields + [
            'Authorization' => 'Basic ' . $this->token,
            'X-Experience-API-Version' => '1.0.3',
        ], '', '&');
        return router::dispatch(request::build(
            'POST',
            'activities/state',
            ['method' => $method],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $form
        ));
    }

    /**
     * A-1: a document stored as text/html is given back with its type (xAPI 1.0.3 part 3 §3.1) but sandboxed and as
     * an attachment, also through the alternate syntax a cross-site form reaches, so the browser never renders it on
     * the Moodle origin.
     */
    public function test_document_is_never_rendered_as_page(): void {
        $script = '<script>alert(1)</script>';
        $put = $this->state('PUT', $this->params(), $script, ['Content-Type' => 'text/html']);
        $this->assertSame(204, $put->status, $put->body);
        $this->assertSame(self::CSP, $put->headers['Content-Security-Policy']);

        foreach ([$this->alternate('GET', $this->params()), $this->state('GET', $this->params())] as $get) {
            $this->assertSame(200, $get->status, $get->body);
            $this->assertSame($script, $get->body);
            $this->assertSame('text/html', $get->headers['Content-Type']);
            $this->assertSame(self::CSP, $get->headers['Content-Security-Policy']);
            $this->assertSame('attachment', $get->headers['Content-Disposition']);
            $this->assertSame('nosniff', $get->headers['X-Content-Type-Options']);
        }
        $head = $this->state('HEAD', $this->params());
        $this->assertSame('attachment', $head->headers['Content-Disposition']);
        $this->assertSame(self::CSP, $head->headers['Content-Security-Policy']);
    }

    /**
     * A-1, variant: a Content-Type outside the media type grammar (RFC 9110 §8.3) — a line break that would make PHP
     * drop the header and fall back to text/html, a control, no subtype — is refused with 400, nothing written.
     */
    public function test_content_type_outside_grammar_refused(): void {
        $put = $this->alternate('PUT', $this->params(), ['Content-Type' => "text/html\nX-A: b", 'content' => '<b>x</b>']);
        $body = $this->assert_error(400, 'err_lrsheader', $put);
        $this->assertStringContainsString('Content-Type', $body['message']);
        $this->assertSame(self::CSP, $put->headers['Content-Security-Policy']);
        // Surrounding whitespace (NUL included) is trimmed before the check, so the controls are inside the value.
        $bad = ["text/html\r\nX-A: b", "text/\x00html", "text/html\x7F;a=b", "text/html;\tcharset=utf-8", 'text/html x',
            'texthtml', 'text/', '/html', 'text/html; charset', 'text/html; =x', 'text/ht(ml', 'text/html; a="b'];
        foreach (['PUT', 'POST'] as $method) {
            foreach ($bad as $type) {
                $response = $this->state($method, $this->params(), '{}', ['Content-Type' => $type]);
                $this->assertSame(400, $response->status, $method . ' ' . json_encode($type));
                $this->assertSame('err_lrsheader', json_decode($response->body, true)['error']);
            }
        }
        $this->assertSame(0, $this->au_rows());

        $good = ['application/json', 'application/json; charset=utf-8', 'text/plain;charset=UTF-8',
            'multipart/form-data; boundary="a b;c"', 'application/vnd.x+json ; q=1 ;'];
        foreach ($good as $i => $type) {
            $this->assertSame(204, $this->state('PUT', $this->params('g' . $i), '{}', ['Content-Type' => $type])->status, $type);
            $this->assertSame($type, $this->state('GET', $this->params('g' . $i))->headers['Content-Type']);
        }
    }

    /**
     * A-1, rows written before the check: a stored Content-Type outside the grammar is served as
     * application/octet-stream, never sent to header().
     */
    public function test_stored_bad_content_type_served_as_octet_stream(): void {
        global $DB;
        $this->assertSame(204, $this->state('PUT', $this->params(), 'x', ['Content-Type' => 'text/plain'])->status);
        $DB->set_field('scormmakercmi5_state', 'contenttype', "text/html\nX-A: b", ['stateid' => 's1']);
        $get = $this->state('GET', $this->params());
        $this->assertSame(200, $get->status);
        $this->assertSame('application/octet-stream', $get->headers['Content-Type']);
        $this->assertSame('attachment', $get->headers['Content-Disposition']);
    }

    /**
     * A-1: the JSON errors of the router are sandboxed too.
     */
    public function test_router_error_is_sandboxed(): void {
        $missing = router::dispatch($this->lrs_request('GET', 'nope', $this->token));
        $this->assertSame(404, $missing->status, $missing->body);
        $this->assertSame(self::CSP, $missing->headers['Content-Security-Policy']);
        $unauth = router::dispatch($this->lrs_request('GET', 'activities/state', 'bad', $this->params()));
        $this->assertSame(401, $unauth->status, $unauth->body);
        $this->assertSame(self::CSP, $unauth->headers['Content-Security-Policy']);
    }
}
