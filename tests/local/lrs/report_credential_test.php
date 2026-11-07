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
use mod_scormmakercmi5\local\runtime\launcher;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\auth::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\statement_query::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\router::class)]
/**
 * Tests for the read-only reporting credential "Basic base64(wstoken:<token>)" (design §7.3; cmi5 4.2.0.0-2; R5.6, NF1).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\auth
 * @covers     \mod_scormmakercmi5\local\lrs\statement_query
 * @covers     \mod_scormmakercmi5\local\lrs\router
 */
final class report_credential_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen time, seconds. */
    private const NOW = 1790000000;

    /** @var string Session token of the learner of course A. */
    private string $autoken;

    /** @var \stdClass Registration of course A. */
    private \stdClass $rega;

    /** @var \stdClass Registration of course B. */
    private \stdClass $regb;

    /** @var \stdClass Teacher of course A. */
    private \stdClass $teacher;

    /** @var \stdClass The reporting service. */
    private \stdClass $service;

    /** @var string Web service token of the teacher. */
    private string $wstoken;

    /**
     * Course A (the teacher's) and course B, each with one launched registration; the service enabled for the teacher.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->setup_lrs_activity(self::NOW);
        $this->preventResetByRollback();
        set_config('requireprefsread', 0, 'mod_scormmakercmi5');
        [$session, $this->autoken] = $this->launch_with_token();
        $this->rega = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);

        $courseb = $this->getDataGenerator()->create_course();
        $learnerb = $this->getDataGenerator()->create_and_enrol($courseb, 'student');
        $instanceb = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')->create_instance([
            'course' => $courseb->id,
            'package_entries' => builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml([
                ['id' => 'https://example.com/au/b', 'url' => 'index.html', 'title' => 'Unidade B'],
            ])]),
        ]);
        $rowb = $DB->get_record('scormmakercmi5', ['id' => $instanceb->id], '*', MUST_EXIST);
        $aub = $DB->get_record('scormmakercmi5_au', ['pkgid' => $rowb->currentpkgid], '*', MUST_EXIST);
        $cmb = get_fast_modinfo($courseb->id)->get_cm($instanceb->cmid);
        $result = launcher::launch($cmb, $rowb, (int)$aub->id, (int)$learnerb->id, 'Normal');
        $this->regb = $DB->get_record('scormmakercmi5_reg', ['id' => $result->session->regid], '*', MUST_EXIST);

        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        set_config('enablewebservices', 1);
        $this->service = $DB->get_record('external_services', ['shortname' => 'mod_scormmakercmi5_lrs'], '*', MUST_EXIST);
        $DB->set_field('external_services', 'enabled', 1, ['id' => $this->service->id]);
        $this->service->enabled = 1;
        $DB->insert_record('external_services_users', (object)[
            'externalserviceid' => $this->service->id,
            'userid' => $this->teacher->id,
            'timecreated' => time(),
        ]);
        $this->wstoken = $this->token_for((int)$this->teacher->id);
    }

    /**
     * A permanent token of the reporting service.
     *
     * @param int $userid User.
     * @param int $validuntil Expiry, 0 for none.
     * @param string $iprestriction IP restriction.
     * @return string
     */
    private function token_for(int $userid, int $validuntil = 0, string $iprestriction = ''): string {
        return \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $this->service,
            $userid,
            \context_system::instance(),
            $validuntil,
            $iprestriction
        );
    }

    /**
     * The Authorization value of a web service token.
     *
     * @param string $token Token.
     * @return string
     */
    private static function basic(string $token): string {
        return 'Basic ' . base64_encode('wstoken:' . $token);
    }

    /**
     * A request with the reporting credential.
     *
     * @param string $method Method.
     * @param string $resource Resource.
     * @param array $query Query.
     * @param string|null $token Token (the teacher's if null).
     * @param string $body Body.
     * @return response
     */
    private function call(string $method, string $resource, array $query = [], ?string $token = null, string $body = ''): response {
        $headers = ['X-Experience-API-Version' => '1.0.3', 'Authorization' => self::basic($token ?? $this->wstoken)];
        return router::dispatch(request::build($method, $resource, $query, $headers, $body));
    }

    /**
     * The registrations of the statements of a response.
     *
     * @param response $response Response.
     * @return string[]
     */
    private function registrations(response $response): array {
        $this->assertSame(200, $response->status, $response->body);
        $statements = json_decode($response->body, true)['statements'];
        return array_values(array_unique(array_column(array_column($statements, 'context'), 'registration')));
    }

    /**
     * Asserts a 401 err_unauthorized.
     *
     * @param response $response Response.
     */
    private function assert_unauthorized(response $response): void {
        $this->assertSame(401, $response->status, $response->body);
        $this->assertSame('err_unauthorized', json_decode($response->body, true)['error']);
        $this->assertArrayNotHasKey('WWW-Authenticate', $response->headers);
    }

    /**
     * The service is declared disabled, user-restricted and without functions.
     */
    public function test_service_declaration(): void {
        global $DB;
        $declared = $DB->get_record('external_services', ['id' => $this->service->id], '*', MUST_EXIST);
        $this->assertSame('mod_scormmakercmi5', $declared->component);
        $this->assertEquals(1, $declared->restrictedusers);
        $this->assertSame(0, $DB->count_records('external_services_functions', ['externalserviceid' => $declared->id]));
        require(\core_component::get_component_directory('mod_scormmakercmi5') . '/db/services.php');
        $this->assertSame(0, $services['mod_scormmakercmi5_lrs']['enabled']);
        $this->assertArrayHasKey('mod_scormmakercmi5_get_session_state', $functions);
    }

    /**
     * The teacher with viewreports in course A sees the statements of A and not those of B.
     */
    public function test_sees_only_permitted_instances(): void {
        $mine = [$this->rega->registration];
        $this->assertSame($mine, $this->registrations($this->call('GET', 'statements')));
        $query = ['registration' => $this->rega->registration, 'ascending' => 'true'];
        $this->assertSame($mine, $this->registrations($this->call('GET', 'statements', $query)));
        $query = ['registration' => $this->regb->registration];
        $this->assertSame([], $this->registrations($this->call('GET', 'statements', $query)));
    }

    /**
     * statementId: one of A → 200; one of B → 404.
     */
    public function test_statement_id(): void {
        global $DB;
        // Each registration also holds the satisfied of its course (the only AU is NotApplicable, Task 17).
        $launched = ['verb' => vocab::VERB_LAUNCHED];
        $ida = $DB->get_field('scormmakercmi5_stmt', 'statementid', ['regid' => $this->rega->id] + $launched, MUST_EXIST);
        $idb = $DB->get_field('scormmakercmi5_stmt', 'statementid', ['regid' => $this->regb->id] + $launched, MUST_EXIST);
        $response = $this->call('GET', 'statements', ['statementId' => $ida]);
        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame($ida, strtolower(json_decode($response->body, true)['id']));
        $this->assertSame(404, $this->call('GET', 'statements', ['statementId' => $idb])->status);
    }

    /**
     * A prohibition of viewreports in the module context of A hides A; a user without it anywhere sees nothing.
     */
    public function test_capability_in_the_activity_context(): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        $contexta = \context_module::instance($this->instance->cmid);
        assign_capability('mod/scormmakercmi5:viewreports', CAP_PROHIBIT, $roleid, $contexta->id, true);
        $this->assertSame([], $this->registrations($this->call('GET', 'statements')));

        $learner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $DB->insert_record('external_services_users', (object)[
            'externalserviceid' => $this->service->id, 'userid' => $learner->id, 'timecreated' => time(),
        ]);
        $token = $this->token_for((int)$learner->id);
        $this->assertSame([], $this->registrations($this->call('GET', 'statements', [], $token)));
    }

    /**
     * The service disabled, or web services disabled on the site → 401.
     */
    public function test_disabled(): void {
        global $DB;
        $DB->set_field('external_services', 'enabled', 0, ['id' => $this->service->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
        $DB->set_field('external_services', 'enabled', 1, ['id' => $this->service->id]);
        $this->assertSame(200, $this->call('GET', 'statements')->status);
        set_config('enablewebservices', 0);
        $this->assert_unauthorized($this->call('GET', 'statements'));
    }

    /**
     * validuntil in the past → 401 (also on the authorised-user row); an IP restriction that does not match → 401.
     */
    public function test_expiry_and_ip(): void {
        global $DB;
        $teacherid = (int)$this->teacher->id;
        $this->assert_unauthorized($this->call('GET', 'statements', [], $this->token_for($teacherid, self::NOW - 10)));
        $this->assertSame(200, $this->call('GET', 'statements', [], $this->token_for($teacherid, self::NOW + 60))->status);
        $this->assert_unauthorized($this->call('GET', 'statements', [], $this->token_for($teacherid, 0, '10.0.0.0/8')));

        $DB->set_field('external_services_users', 'validuntil', self::NOW - 10, ['userid' => $this->teacher->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
        $DB->set_field('external_services_users', 'validuntil', 0, ['userid' => $this->teacher->id]);
        $DB->set_field('external_services_users', 'iprestriction', '10.0.0.0/8', ['userid' => $this->teacher->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
    }

    /**
     * The user suspended, deleted, with the nologin auth, or not an authorised user of the service → 401.
     */
    public function test_user_checks(): void {
        global $DB;
        $DB->set_field('user', 'suspended', 1, ['id' => $this->teacher->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
        $DB->set_field('user', 'suspended', 0, ['id' => $this->teacher->id]);
        $DB->set_field('user', 'auth', 'nologin', ['id' => $this->teacher->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
        $DB->set_field('user', 'auth', 'manual', ['id' => $this->teacher->id]);
        $this->assertSame(200, $this->call('GET', 'statements')->status);
        $DB->delete_records('external_services_users', ['userid' => $this->teacher->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
    }

    /**
     * An unknown token, an embedded token, a token of another service, or a malformed credential → 401.
     */
    public function test_bad_tokens(): void {
        global $DB;
        $this->assert_unauthorized($this->call('GET', 'statements', [], 'nao-existe'));
        $this->assert_unauthorized($this->call('GET', 'statements', [], ''));
        $DB->set_field('external_tokens', 'tokentype', EXTERNAL_TOKEN_EMBEDDED, ['token' => $this->wstoken]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
        $DB->set_field('external_tokens', 'tokentype', EXTERNAL_TOKEN_PERMANENT, ['token' => $this->wstoken]);

        $otherid = $DB->insert_record('external_services', (object)[
            'name' => 'Outro', 'shortname' => 'outro', 'enabled' => 1, 'restrictedusers' => 0, 'timecreated' => time(),
        ]);
        $DB->set_field('external_tokens', 'externalserviceid', $otherid, ['token' => $this->wstoken]);
        $this->assert_unauthorized($this->call('GET', 'statements'));

        $headers = ['X-Experience-API-Version' => '1.0.3', 'Authorization' => 'Basic ' . base64_encode('wstoken')];
        $this->assert_unauthorized(router::dispatch(request::build('GET', 'statements', [], $headers, '')));
    }

    /**
     * POST or PUT statements with the credential → 401 and nothing stored; State, agents and profiles → 401.
     */
    public function test_never_writes(): void {
        global $DB, $CFG;
        $count = $DB->count_records('scormmakercmi5_stmt');
        $states = $DB->count_records('scormmakercmi5_state');
        $statement = json_encode([
            'actor' => ['account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]],
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/experienced'],
            'object' => ['id' => 'https://example.com/x'],
            'context' => ['registration' => $this->rega->registration],
        ]);
        $this->assert_unauthorized($this->call('POST', 'statements', [], null, $statement));
        $this->assert_unauthorized($this->call('PUT', 'statements', ['statementId' => \core\uuid::generate()], null, $statement));
        $this->assertSame($count, $DB->count_records('scormmakercmi5_stmt'));

        $agent = json_encode(['account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]]);
        $state = ['activityId' => 'https://example.com/au/1', 'agent' => $agent, 'stateId' => 'x'];
        $this->assert_unauthorized($this->call('GET', 'activities/state', $state));
        $this->assert_unauthorized($this->call('PUT', 'activities/state', $state, null, '{}'));
        $this->assert_unauthorized($this->call('GET', 'agents', ['agent' => $agent]));
        $this->assert_unauthorized($this->call('GET', 'agents/profile', ['agent' => $agent]));
        $this->assertSame($states, $DB->count_records('scormmakercmi5_state'));
    }

    /**
     * The session token of the AU still works for its own statements alongside the credential.
     */
    public function test_session_token_unchanged(): void {
        $response = router::dispatch($this->lrs_request('GET', 'statements', $this->autoken, []));
        $this->assertSame([$this->rega->registration], $this->registrations($response));
        $this->assertSame(vocab::VERB_LAUNCHED, json_decode($response->body, true)['statements'][0]['verb']['id']);
    }

    /**
     * Paging with the credential, without registration: "more" stays within course A, and a tampered cursor only
     * narrows (the scope is applied again on every page).
     */
    public function test_more_with_the_credential(): void {
        global $DB, $CFG;
        $key = ['regid' => $this->rega->id, 'kind' => launcher::KIND_LAUNCH];
        $session = $DB->get_record('scormmakercmi5_session', $key, '*', MUST_EXIST);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], '*', MUST_EXIST);
        $this->clock->bump();
        $initialized = [
            'id' => \core\uuid::generate(),
            'timestamp' => xapi_time::from_ms(xapi_time::now_ms()),
            'actor' => ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$this->user->id]],
            'verb' => ['id' => vocab::VERB_INITIALIZED],
            'object' => ['objectType' => 'Activity', 'id' => $au->lmsactivityid],
            'context' => [
                'registration' => $this->rega->registration,
                'contextActivities' => [
                    'grouping' => [['objectType' => 'Activity', 'id' => $au->publisherid]],
                    'category' => [['id' => vocab::CAT_CMI5]],
                ],
                'extensions' => [vocab::EXT_SESSIONID => $session->sessionuuid],
            ],
        ];
        $post = request::build('POST', 'statements', [], [
            'X-Experience-API-Version' => '1.0.3',
            'Authorization' => 'Basic ' . $this->autoken,
        ], json_encode([$initialized]));
        $this->assertSame(200, router::dispatch($post)->status);

        // First the satisfied of the course (written at the creation of the registration, Task 17) and launched.
        $first = $this->call('GET', 'statements', ['limit' => '2', 'ascending' => 'true']);
        $this->assertSame([$this->rega->registration], $this->registrations($first));
        $more = json_decode($first->body, true)['more'];
        $this->assertNotSame('', $more);
        parse_str((string)parse_url($more, PHP_URL_QUERY), $query);
        $second = $this->call('GET', 'statements', $query);
        $this->assertSame($initialized['id'], json_decode($second->body, true)['statements'][0]['id']);
        $this->assertSame('', json_decode($second->body, true)['more']);

        $tampered = $this->call('GET', 'statements', ['cursor' => '0-0', 'ascending' => 'true', 'limit' => '0']);
        $this->assertSame([$this->rega->registration], $this->registrations($tampered));
        $this->assertCount(3, json_decode($tampered->body, true)['statements']);
    }

    /**
     * A deleted user, maintenance without maintenanceaccess, a required capability the user lacks → 401.
     */
    public function test_more_user_and_site_checks(): void {
        global $DB, $CFG;
        $DB->set_field('external_services', 'requiredcapability', 'moodle/site:config', ['id' => $this->service->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
        $DB->set_field('external_services', 'requiredcapability', null, ['id' => $this->service->id]);
        $this->assertSame(200, $this->call('GET', 'statements')->status);

        $CFG->maintenance_enabled = 1;
        $this->assert_unauthorized($this->call('GET', 'statements'));
        $CFG->maintenance_enabled = 0;

        $DB->set_field('user', 'deleted', 1, ['id' => $this->teacher->id]);
        $this->assert_unauthorized($this->call('GET', 'statements'));
    }

    /**
     * A refused credential is audited like a refused web service token, without the token.
     */
    public function test_refusal_is_audited(): void {
        $sink = $this->redirectEvents();
        $this->assert_unauthorized($this->call('GET', 'statements', [], 'nao-existe'));
        $this->assert_unauthorized($this->call('GET', 'statements', [], $this->token_for((int)$this->teacher->id, self::NOW - 10)));
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \core\event\webservice_login_failed
        ));
        $sink->close();
        $this->assertCount(2, $events);
        $this->assertSame('invalid_token', $events[0]->other['reason']);
        $this->assertArrayNotHasKey('tokenid', $events[0]->other);
        $this->assertSame('token_expired_or_ip_restricted', $events[1]->other['reason']);
        $this->assertArrayHasKey('tokenid', $events[1]->other);
        foreach ($events as $event) {
            $this->assertStringNotContainsString($this->wstoken, json_encode($event->get_data()));
            $this->assertStringNotContainsString('nao-existe', json_encode($event->get_data()));
        }
        $this->assertSame(200, $this->call('GET', 'statements')->status);
    }

    /**
     * The activity of course A in separate groups (or none), a second learner launched in it, and groups: group A has
     * the first learner (and the teacher when asked), group B the second learner.
     *
     * @param int $groupmode NOGROUPS or SEPARATEGROUPS.
     * @param bool $teacheringroup Whether the teacher belongs to group A.
     * @return \stdClass Registration of the second learner.
     */
    private function setup_groups(int $groupmode, bool $teacheringroup): \stdClass {
        global $DB;
        $DB->set_field('course_modules', 'groupmode', $groupmode, ['id' => $this->instance->cmid]);
        rebuild_course_cache($this->course->id, true);
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        [$session] = $this->launch_with_token((int)$other->id);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $this->user->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $other->id]);
        if ($teacheringroup) {
            $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $this->teacher->id]);
        }
        return $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);
    }

    /**
     * Separate groups: a teacher without moodle/site:accessallgroups reads the statements of the learners of their own
     * groups only, in a query, by registration and by statementId; a learner of another group is as if absent.
     */
    public function test_separate_groups_limit_the_reading(): void {
        global $DB;
        $regother = $this->setup_groups(SEPARATEGROUPS, true);
        $this->assertFalse(has_capability(
            'moodle/site:accessallgroups',
            \context_module::instance($this->instance->cmid),
            $this->teacher
        ));

        $this->assertSame([$this->rega->registration], $this->registrations($this->call('GET', 'statements')));
        $ascending = $this->call('GET', 'statements', ['ascending' => 'true', 'limit' => '1']);
        $this->assertSame([$this->rega->registration], $this->registrations($ascending));
        $query = ['registration' => $regother->registration];
        $this->assertSame([], $this->registrations($this->call('GET', 'statements', $query)));
        $query = ['agent' => json_encode(\mod_scormmakercmi5\local\runtime\actor::for_user((int)$regother->userid))];
        $this->assertSame([], $this->registrations($this->call('GET', 'statements', $query)));

        $launched = ['verb' => vocab::VERB_LAUNCHED];
        $idown = $DB->get_field('scormmakercmi5_stmt', 'statementid', ['regid' => $this->rega->id] + $launched, MUST_EXIST);
        $idother = $DB->get_field('scormmakercmi5_stmt', 'statementid', ['regid' => $regother->id] + $launched, MUST_EXIST);
        $this->assertSame(200, $this->call('GET', 'statements', ['statementId' => $idown])->status);
        $this->assertSame(404, $this->call('GET', 'statements', ['statementId' => $idother])->status);
    }

    /**
     * Separate groups, and the teacher is in no group: no statement at all.
     */
    public function test_separate_groups_without_a_group_sees_nothing(): void {
        global $DB;
        $regother = $this->setup_groups(SEPARATEGROUPS, false);
        $this->assertSame([], $this->registrations($this->call('GET', 'statements')));
        $id = $DB->get_field('scormmakercmi5_stmt', 'statementid', ['regid' => $regother->id, 'verb' => vocab::VERB_LAUNCHED]);
        $this->assertSame(404, $this->call('GET', 'statements', ['statementId' => $id])->status);
    }

    /**
     * Separate groups with moodle/site:accessallgroups: every learner, as before; and with no groups mode the groups
     * are ignored altogether.
     */
    public function test_accessallgroups_and_no_groups_see_every_learner(): void {
        global $DB;
        $regother = $this->setup_groups(SEPARATEGROUPS, false);
        $both = [$this->rega->registration, $regother->registration];
        sort($both);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        $context = \context_module::instance($this->instance->cmid);
        assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $roleid, $context->id, true);
        $seen = $this->registrations($this->call('GET', 'statements'));
        sort($seen);
        $this->assertSame($both, $seen);

        assign_capability('moodle/site:accessallgroups', CAP_PREVENT, $roleid, $context->id, true);
        $this->assertSame([], $this->registrations($this->call('GET', 'statements')));
        $DB->set_field('course_modules', 'groupmode', NOGROUPS, ['id' => $this->instance->cmid]);
        rebuild_course_cache($this->course->id, true);
        $seen = $this->registrations($this->call('GET', 'statements'));
        sort($seen);
        $this->assertSame($both, $seen);
    }

    /**
     * A group mode forced by the course (the activity itself in no groups) counts as separate groups.
     */
    public function test_course_forced_separate_groups(): void {
        global $DB;
        $regother = $this->setup_groups(NOGROUPS, true);
        $DB->update_record('course', (object)['id' => $this->course->id, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        rebuild_course_cache($this->course->id, true);
        $this->assertSame([$this->rega->registration], $this->registrations($this->call('GET', 'statements')));
        $id = $DB->get_field('scormmakercmi5_stmt', 'statementid', ['regid' => $regother->id, 'verb' => vocab::VERB_LAUNCHED]);
        $this->assertSame(404, $this->call('GET', 'statements', ['statementId' => $id])->status);
    }

    /**
     * A group of thousands of members does not turn into thousands of bound parameters: the groups are matched in SQL,
     * so the query still works (and the learner of another group is still out).
     */
    public function test_large_group_is_matched_in_sql(): void {
        global $DB;
        $regother = $this->setup_groups(SEPARATEGROUPS, true);
        $groupid = (int)$DB->get_field('groups_members', 'groupid', ['userid' => $this->teacher->id], MUST_EXIST);
        $rows = [];
        for ($i = 1; $i <= 3000; $i++) {
            $rows[] = (object)[
                'groupid' => $groupid, 'userid' => 900000 + $i, 'timeadded' => time(), 'component' => '', 'itemid' => 0,
            ];
        }
        $DB->insert_records('groups_members', $rows);
        $response = $this->call('GET', 'statements');
        $this->assertSame([$this->rega->registration], $this->registrations($response));
        $this->assertNotContains($regother->registration, $this->registrations($response));
    }
}
