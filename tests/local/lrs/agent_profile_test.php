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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\agent_profile::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\activity_profile::class)]
/**
 * Tests for the Agent Profile and Activity Profile resources of the LRS, read only (design §3.11, §3.12, §7.1;
 * cmi5 §11; xAPI 1.0.3 part 3 §2.6, §2.7; R5.1, R5.2).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\agent_profile
 * @covers     \mod_scormmakercmi5\local\lrs\activity_profile
 */
final class agent_profile_test extends \advanced_testcase {
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
     * The agent parameter of the learner.
     *
     * @return array
     */
    private function agent(): array {
        return ['agent' => json_encode(actor::for_user((int)$this->user->id))];
    }

    /**
     * Sends a request.
     *
     * @param string $method Method.
     * @param string $resource Resource.
     * @param array $query Query.
     * @param string $body Body.
     * @return response
     */
    private function send(string $method, string $resource, array $query, string $body = ''): response {
        return router::dispatch($this->lrs_request(
            $method,
            $resource,
            $this->token,
            $query,
            $body,
            $body === '' ? [] : ['Content-Type' => 'application/json']
        ));
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
     * GET profileId=cmi5LearnerPreferences → the document the LMS wrote, and the session is marked prefsread.
     */
    public function test_get_preferences(): void {
        global $DB;
        $this->assertEquals(0, $DB->get_field('scormmakercmi5_session', 'prefsread', ['id' => $this->session->id]));
        $row = $DB->get_record('scormmakercmi5_agentprof', ['userid' => $this->user->id, 'profileid' => vocab::PREFS_ID]);

        $get = $this->send('GET', 'agents/profile', $this->agent() + ['profileId' => vocab::PREFS_ID]);

        $this->assertSame(200, $get->status, $get->body);
        $this->assertSame($row->content, $get->body);
        $this->assertArrayHasKey('languagePreference', json_decode($get->body, true));
        // The document has both properties (cmi5 11.0.0.0-5).
        $this->assertSame('on', json_decode($get->body, true)['audioPreference']);
        $this->assertSame('application/json', $get->headers['Content-Type']);
        $this->assertSame('attachment', $get->headers['Content-Disposition']);
        $this->assertSame(response::CSP, $get->headers['Content-Security-Policy']);
        $this->assertSame('"' . $row->etag . '"', $get->headers['ETag']);
        $this->assertArrayHasKey('Last-Modified', $get->headers);
        $this->assertEquals(1, $DB->get_field('scormmakercmi5_session', 'prefsread', ['id' => $this->session->id]));
    }

    /**
     * GET without profileId lists the ids (not marking prefsread); another profileId → 404.
     */
    public function test_list_and_missing(): void {
        global $DB;
        $list = $this->send('GET', 'agents/profile', $this->agent());
        $this->assertSame(200, $list->status);
        $this->assertSame([vocab::PREFS_ID], json_decode($list->body, true));
        $this->assertSame([], json_decode($this->send(
            'GET',
            'agents/profile',
            $this->agent() + ['since' => xapi_time::from_ms((self::NOW + 1) * 1000)]
        )->body, true));
        $this->assertEquals(0, $DB->get_field('scormmakercmi5_session', 'prefsread', ['id' => $this->session->id]));

        $this->assert_error(404, 'err_lrsnodoc', $this->send('GET', 'agents/profile', $this->agent() + ['profileId' => 'other']));
    }

    /**
     * PUT/POST/DELETE → 403 (cmi5 §11: the LMS may refuse), nothing changed; without agent → 400; another agent → 403.
     */
    public function test_writes_refused(): void {
        global $DB;
        $before = $DB->get_records('scormmakercmi5_agentprof');
        foreach (['PUT', 'POST', 'DELETE'] as $method) {
            $this->assert_error(
                403,
                'err_lrsreadonly',
                $this->send(
                    $method,
                    'agents/profile',
                    $this->agent() + ['profileId' => vocab::PREFS_ID],
                    '{"audioPreference":"off"}'
                )
            );
            $this->assert_error(400, 'err_lrsparam', $this->send($method, 'agents/profile', ['profileId' => vocab::PREFS_ID]));
        }
        $this->assertEquals($before, $DB->get_records('scormmakercmi5_agentprof'));
    }

    /**
     * GET without agent → 400; the agent of another user → 403; a profileId over 255 characters → 400.
     */
    public function test_params_and_scope(): void {
        $body = $this->assert_error(400, 'err_lrsparam', $this->send('GET', 'agents/profile', ['profileId' => vocab::PREFS_ID]));
        $this->assertStringContainsString('agent', $body['message']);

        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $foreign = ['agent' => json_encode(actor::for_user((int)$other->id))];
        $this->assert_error(403, 'err_scope', $this->send('GET', 'agents/profile', $foreign + ['profileId' => vocab::PREFS_ID]));
        $this->assert_error(403, 'err_scope', $this->send('PUT', 'agents/profile', $foreign + ['profileId' => 'x'], '{}'));

        $body = $this->assert_error(
            400,
            'err_lrsparam',
            $this->send('GET', 'agents/profile', $this->agent() + ['profileId' => str_repeat('p', 256)])
        );
        $this->assertStringContainsString('profileId', $body['message']);
        $longest = $this->agent() + ['profileId' => str_repeat('p', 255)];
        $this->assert_error(404, 'err_lrsnodoc', $this->send('GET', 'agents/profile', $longest));
        $this->assert_error(400, 'err_lrsparam', $this->send('GET', 'agents/profile', $this->agent() + ['since' => 'x']));
    }

    /**
     * activities/profile: GET with profileId → 404, without → []; activityId required and scoped; writes → 405.
     */
    public function test_activity_profile(): void {
        global $DB;
        $activityid = $DB->get_field('scormmakercmi5_au', 'lmsactivityid', ['id' => $this->session->auid]);
        $query = ['activityId' => $activityid];

        $this->assert_error(404, 'err_lrsnodoc', $this->send('GET', 'activities/profile', $query + ['profileId' => 'p']));
        $list = $this->send('GET', 'activities/profile', $query);
        $this->assertSame(200, $list->status, $list->body);
        $this->assertSame('[]', $list->body);

        $this->assert_error(400, 'err_lrsparam', $this->send('GET', 'activities/profile', []));
        $this->assert_error(403, 'err_scope', $this->send('GET', 'activities/profile', ['activityId' => 'https://example.com/x']));
        $this->assert_error(
            400,
            'err_lrsparam',
            $this->send('GET', 'activities/profile', $query + ['profileId' => str_repeat('p', 256)])
        );
        $put = $this->send('PUT', 'activities/profile', $query + ['profileId' => 'p'], '{}');
        $this->assertSame(405, $put->status);
        $this->assertSame('GET, HEAD', $put->headers['Allow']);
    }
}
