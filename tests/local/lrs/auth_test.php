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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\auth::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\session_context::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\router::class)]
/**
 * Tests for the session token authorisation of the LRS and the agents resource (design §2.4, §6.3, §7.1; R5.2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\auth
 * @covers     \mod_scormmakercmi5\local\lrs\session_context
 * @covers     \mod_scormmakercmi5\local\lrs\router
 */
final class auth_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /**
     * Course, learner, activity and frozen clock.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setup_lrs_activity(self::NOW);
    }

    /**
     * A GET request to the LRS.
     *
     * @param string $resource Resource.
     * @param string|null $authorization Authorization header, none if null.
     * @param array $query Query.
     * @return request
     */
    private function get(string $resource, ?string $authorization, array $query = []): request {
        $headers = ['X-Experience-API-Version' => '1.0.3'];
        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }
        return request::build('GET', $resource, $query, $headers, '');
    }

    /**
     * Asserts that a call throws an lrs_exception.
     *
     * @param int $status Status.
     * @param string $errorcode Error code.
     * @param callable $call Call.
     */
    private function assert_lrs_exception(int $status, string $errorcode, callable $call): void {
        try {
            $call();
        } catch (lrs_exception $e) {
            $this->assertSame($status, $e->httpstatus);
            $this->assertSame($errorcode, $e->errorcode);
            return;
        }
        $this->fail('lrs_exception expected');
    }

    /**
     * Asserts that a request is refused with 401.
     *
     * @param request $request Request.
     */
    private function assert_unauthorized(request $request): void {
        $this->assert_lrs_exception(401, 'err_unauthorized', fn() => auth::session_from_request($request));
        $response = router::dispatch(new request(
            'GET',
            'agents',
            ['agent' => json_encode(actor::for_user((int)$this->user->id))],
            $request->headers,
            '',
            $request->authorization
        ));
        $this->assertSame(401, $response->status);
        $this->assertSame('err_unauthorized', json_decode($response->body, true)['error']);
    }

    /**
     * A valid token: the context of its session, and lastactivityms updated.
     */
    public function test_valid_token(): void {
        global $DB;
        [$session, $token] = $this->launch_with_token();
        $this->clock->bump(30);

        $ctx = auth::session_from_request($this->get('agents', 'Basic ' . $token));

        $this->assertEquals($session->id, $ctx->session->id);
        $this->assertEquals($session->regid, $ctx->reg->id);
        $this->assertEquals($session->auid, $ctx->au->id);
        $this->assertEquals($this->instance->id, $ctx->instance->id);
        $this->assertEquals($this->instance->cmid, $ctx->cm->id);
        $this->assertInstanceOf(\cm_info::class, $ctx->cm);
        $this->assertEquals(\context_module::instance($this->instance->cmid)->id, $ctx->context->id);
        $this->assertSame(actor::for_user((int)$this->user->id), $ctx->actor);
        $this->assertEquals((self::NOW + 30) * 1000, $ctx->session->lastactivityms);
        $row = $DB->get_record('scormmakercmi5_session', ['id' => $session->id], '*', MUST_EXIST);
        $this->assertEquals((self::NOW + 30) * 1000, $row->lastactivityms);

        // The scheme is case-insensitive; the token is the exact string after it (standard base64, not decoded).
        $ctx = auth::session_from_request($this->get('agents', 'basic  ' . $token . ' '));
        $this->assertEquals($session->id, $ctx->session->id);
    }

    /**
     * The token of an initialized session is still valid.
     */
    public function test_initialized_session(): void {
        global $DB;
        [$session, $token] = $this->launch_with_token();
        $DB->set_field('scormmakercmi5_session', 'status', launcher::STATUS_INITIALIZED, ['id' => $session->id]);
        $this->assertEquals($session->id, auth::session_from_request($this->get('agents', 'Basic ' . $token))->session->id);
    }

    /**
     * No header, another scheme, an empty or unknown token: 401.
     */
    public function test_missing_or_bad_header(): void {
        [, $token] = $this->launch_with_token();
        $this->assert_unauthorized($this->get('agents', null));
        $this->assert_unauthorized($this->get('agents', 'Bearer x'));
        $this->assert_unauthorized($this->get('agents', 'Bearer ' . $token));
        $this->assert_unauthorized($this->get('agents', 'Basic'));
        $this->assert_unauthorized($this->get('agents', 'Basic '));
        $this->assert_unauthorized($this->get('agents', 'Basic ' . base64_encode('x:' . $token)));
        $this->assert_unauthorized($this->get('agents', $token));
    }

    /**
     * The token of a terminated or abandoned session: 401, even while tokenexpires is still in the future (the
     * relaunch race of the fetch, Task 10 review).
     */
    public function test_ended_session(): void {
        global $DB;
        foreach ([launcher::STATUS_TERMINATED, launcher::STATUS_ABANDONED] as $status) {
            [$session, $token] = $this->launch_with_token();
            $DB->set_field('scormmakercmi5_session', 'status', $status, ['id' => $session->id]);
            $this->assertGreaterThan(self::NOW, $DB->get_field('scormmakercmi5_session', 'tokenexpires', ['id' => $session->id]));
            $this->assert_unauthorized($this->get('agents', 'Basic ' . $token));
        }
    }

    /**
     * An expired token: 401.
     */
    public function test_expired_token(): void {
        global $DB;
        [$session, $token] = $this->launch_with_token();
        $expires = (int)$DB->get_field('scormmakercmi5_session', 'tokenexpires', ['id' => $session->id]);
        $this->clock->set_to($expires - 1);
        $this->assertEquals($session->id, auth::session_from_request($this->get('agents', 'Basic ' . $token))->session->id);
        $this->clock->set_to($expires);
        $this->assert_unauthorized($this->get('agents', 'Basic ' . $token));
        $DB->set_field('scormmakercmi5_session', 'tokenexpires', 0, ['id' => $session->id]);
        $this->clock->set_to(self::NOW);
        $this->assert_unauthorized($this->get('agents', 'Basic ' . $token));
    }

    /**
     * Review Focus 2: the token of tab A after tab B launched the AU again → 401; tab B's token works.
     */
    public function test_token_of_previous_tab(): void {
        global $DB;
        [$sessiona, $tokena] = $this->launch_with_token();
        $this->assertEquals($sessiona->id, auth::session_from_request($this->get('agents', 'Basic ' . $tokena))->session->id);
        [$sessionb, $tokenb] = $this->launch_with_token();

        $this->assert_unauthorized($this->get('agents', 'Basic ' . $tokena));
        $this->assertEquals(
            launcher::STATUS_ABANDONED,
            $DB->get_field('scormmakercmi5_session', 'status', ['id' => $sessiona->id])
        );
        $this->assertEquals($sessionb->id, auth::session_from_request($this->get('agents', 'Basic ' . $tokenb))->session->id);
    }

    /**
     * The activity hidden or behind an access restriction mid-session (Task 11 review Concern 2): the token is
     * refused with 403, without a cmi5 rule; shown again, it works.
     */
    public function test_activity_no_longer_visible(): void {
        global $DB;
        [, $token] = $this->launch_with_token();
        $query = ['agent' => json_encode(actor::for_user((int)$this->user->id))];
        $this->assertSame(200, router::dispatch($this->get('agents', 'Basic ' . $token, $query))->status);

        set_coursemodule_visible($this->instance->cmid, 0);
        $response = router::dispatch($this->get('agents', 'Basic ' . $token, $query));
        $this->assertSame(403, $response->status, $response->body);
        $body = json_decode($response->body, true);
        $this->assertSame('err_lrsnoaccess', $body['error']);
        $this->assertNull($body['rule']);

        set_coursemodule_visible($this->instance->cmid, 1);
        $this->assertSame(200, router::dispatch($this->get('agents', 'Basic ' . $token, $query))->status);

        $future = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + YEARSECS]],
            'showc' => [true]]);
        $DB->set_field('course_modules', 'availability', $future, ['id' => $this->instance->cmid]);
        rebuild_course_cache($this->course->id, true);
        $this->assertSame(403, router::dispatch($this->get('agents', 'Basic ' . $token, $query))->status);
    }

    /**
     * assert_scope(): the agent, activity and registration of the session pass; any other → 403 err_scope.
     */
    public function test_assert_scope(): void {
        [$session, $token] = $this->launch_with_token();
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        [$othersession] = $this->launch_with_token((int)$other->id);
        $ctx = auth::session_from_request($this->get('agents', 'Basic ' . $token));
        $registration = $ctx->reg->registration;
        $otherreg = \mod_scormmakercmi5\local\runtime\launcher::get_or_create_registration($ctx->instance, (int)$other->id);
        $this->assertNotSame($registration, $otherreg->registration);
        $this->assertNotEquals($session->regid, $othersession->regid);

        $ctx->assert_scope(null, null, null);
        $ctx->assert_scope(actor::for_user((int)$this->user->id), $ctx->au->lmsactivityid, $registration);
        $ctx->assert_scope(null, null, strtoupper($registration));
        $ctx->assert_scope(['account' => $ctx->actor['account'], 'name' => 'Display name'], null, null);

        $this->assert_lrs_exception(403, 'err_scope', fn() => $ctx->assert_scope(null, null, $otherreg->registration));
        $this->assert_lrs_exception(403, 'err_scope', fn() => $ctx->assert_scope(null, 'https://example.com/other', null));
        $this->assert_lrs_exception(
            403,
            'err_scope',
            fn() => $ctx->assert_scope(actor::for_user((int)$other->id), null, null)
        );
        $this->assert_lrs_exception(
            403,
            'err_scope',
            fn() => $ctx->assert_scope(['mbox' => 'mailto:a@b.c'] + $ctx->actor, null, null)
        );
        $this->assert_lrs_exception(403, 'err_scope', fn() => $ctx->assert_scope(null, null, ''));
    }

    /**
     * GET agents?agent=<actor of the session> → 200 Person (xAPI 1.0.3 part 3 §2.5.1).
     */
    public function test_agents(): void {
        [, $token] = $this->launch_with_token();
        $agent = actor::for_user((int)$this->user->id);

        $response = router::dispatch($this->get('agents', 'Basic ' . $token, ['agent' => json_encode($agent)]));

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame('application/json', $response->headers['Content-Type']);
        $this->assertSame('1.0.3', $response->headers['X-Experience-API-Version']);
        $this->assertSame([
            'objectType' => 'Person',
            'account' => [['homePage' => $agent['account']['homePage'], 'name' => (string)$this->user->id]],
        ], json_decode($response->body, true));
    }

    /**
     * agents with the agent of another user → 403; without agent or with invalid JSON → 400.
     */
    public function test_agents_refused(): void {
        [, $token] = $this->launch_with_token();
        $other = $this->getDataGenerator()->create_user();

        $query = ['agent' => json_encode(actor::for_user((int)$other->id))];
        $response = router::dispatch($this->get('agents', 'Basic ' . $token, $query));
        $this->assertSame(403, $response->status);
        $this->assertSame('err_scope', json_decode($response->body, true)['error']);

        foreach ([[], ['agent' => '{bad'], ['agent' => '"x"'], ['agent' => '[]']] as $query) {
            $response = router::dispatch($this->get('agents', 'Basic ' . $token, $query));
            $this->assertSame(400, $response->status, json_encode($query));
            $this->assertSame('err_lrsparam', json_decode($response->body, true)['error']);
        }
    }

    /**
     * The alternate request syntax carries the authorization in the form (Review Focus 1).
     */
    public function test_alternate_syntax_authorization(): void {
        [$session, $token] = $this->launch_with_token();
        $agent = json_encode(actor::for_user((int)$this->user->id));
        $body = 'Authorization=' . rawurlencode('Basic ' . $token) . '&X-Experience-API-Version=1.0.3'
            . '&agent=' . rawurlencode($agent);
        $request = request::build(
            'POST',
            'agents',
            ['method' => 'GET'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $body
        );

        $this->assertEquals($session->id, auth::session_from_request($request)->session->id);
        $this->assertSame(200, router::dispatch($request)->status);
    }

    /**
     * Revokes the learner's access in one way: "unenrol", "suspend" or "nocap".
     *
     * @param string $how Which.
     */
    private function revoke_access(string $how): void {
        global $DB;
        if ($how === 'unenrol') {
            $enrol = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
            enrol_get_plugin('manual')->unenrol_user($enrol, (int)$this->user->id);
        } else if ($how === 'suspend') {
            $DB->set_field('user', 'suspended', 1, ['id' => $this->user->id]);
        } else if ($how === 'nocap') {
            $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
            $context = \context_module::instance($this->instance->cmid);
            assign_capability('mod/scormmakercmi5:view', CAP_PROHIBIT, $roleid, $context->id, true);
        }
    }

    /**
     * Ways a learner loses access after the token was handed out.
     *
     * @return array
     */
    public static function revoked_access_provider(): array {
        return [
            'unenrolled' => ['unenrol'],
            'suspended' => ['suspend'],
            'view capability prohibited' => ['nocap'],
        ];
    }

    /**
     * A token whose learner was unenrolled, suspended or lost mod/scormmakercmi5:view is refused with 403 on every
     * request, and nothing is written: no activity timestamp and no statement.
     *
     * @dataProvider revoked_access_provider
     * @param string $how How the access is revoked.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('revoked_access_provider')]
    public function test_token_refused_after_access_is_revoked(string $how): void {
        global $DB;
        [$session, $token] = $this->launch_with_token();
        $before = $DB->get_field('scormmakercmi5_session', 'lastactivityms', ['id' => $session->id], MUST_EXIST);
        $stored = $DB->count_records('scormmakercmi5_stmt');
        $this->clock->bump(30);
        $this->revoke_access($how);

        $this->assert_lrs_exception(
            403,
            'err_lrsnoaccess',
            fn() => auth::session_from_request($this->get('agents', 'Basic ' . $token))
        );
        $response = $this->post_statements($token, [$this->cmi5_statement($session, vocab::VERB_INITIALIZED)]);
        $this->assertSame(403, $response->status, $response->body);
        $this->assertSame('err_lrsnoaccess', json_decode($response->body, true)['error']);

        $this->assertSame($stored, $DB->count_records('scormmakercmi5_stmt'));
        $this->assertEquals($before, $DB->get_field('scormmakercmi5_session', 'lastactivityms', ['id' => $session->id]));
    }

    /**
     * An enrolled learner who still has the capability keeps a working token.
     */
    public function test_token_works_while_access_holds(): void {
        // The cmi5 rule about reading the preferences first is not what this test is about.
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        [$session, $token] = $this->launch_with_token();
        $ctx = auth::session_from_request($this->get('agents', 'Basic ' . $token));
        $this->assertEquals($session->id, $ctx->session->id);

        $response = $this->post_statements($token, [$this->cmi5_statement($session, vocab::VERB_INITIALIZED)]);
        $this->assertSame(200, $response->status, $response->body);
    }
}
