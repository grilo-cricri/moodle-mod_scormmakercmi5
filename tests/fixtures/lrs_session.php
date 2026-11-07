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

/**
 * A launched AU with a real session token, for the tests of the LRS resources.
 *
 * Load with require_once(core_component::get_component_directory('mod_scormmakercmi5')
 * . '/tests/fixtures/lrs_session.php') and use the trait in an advanced_testcase.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/package_builder.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\request;
use mod_scormmakercmi5\local\lrs\response;
use mod_scormmakercmi5\local\lrs\router;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5\local\runtime\fetch_service;
use mod_scormmakercmi5\local\runtime\launcher;
use mod_scormmakercmi5_test_package_builder as builder;

/**
 * Course, learner, one-AU activity, frozen clock, a launch with its token and LRS requests.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait mod_scormmakercmi5_test_lrs_session {
    /** @var \stdClass Course. */
    protected \stdClass $course;

    /** @var \stdClass Learner. */
    protected \stdClass $user;

    /** @var \stdClass Instance (with cmid). */
    protected \stdClass $instance;

    /** @var \frozen_clock Clock. */
    protected \frozen_clock $clock;

    /**
     * Creates the course, the learner and the activity, and freezes the clock.
     *
     * @param int $now Frozen time, seconds.
     * @param string|null $cmi5xml cmi5.xml of the package (one AU by default).
     * @param array $record More fields of the instance (grade, grademethod, completion…).
     */
    protected function setup_lrs_activity(int $now, ?string $cmi5xml = null, array $record = []): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Off by default on a new site: the tests of external AUs opt in, as an administrator would.
        set_config('allowexternalau', 1, 'mod_scormmakercmi5');
        $this->clock = $this->mock_clock_with_frozen($now);
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $entries = builder::minimal_entries(['cmi5.xml' => $cmi5xml ?? builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Unidade 1'],
        ])]);
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $this->course->id, 'package_entries' => $entries] + $record);
    }

    /**
     * Launches the AU for a learner and fetches the token.
     *
     * @param int|null $userid Learner, the default one if null.
     * @param int|null $auid AU, the only one of the package if null.
     * @param string $mode Launch mode: Normal, Browse or Review.
     * @return array [\stdClass session, string token].
     */
    protected function launch_with_token(?int $userid = null, ?int $auid = null, string $mode = 'Normal'): array {
        global $DB;
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
        $au = $auid === null
            ? $DB->get_record('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], '*', MUST_EXIST)
            : $DB->get_record('scormmakercmi5_au', ['id' => $auid], '*', MUST_EXIST);
        $result = launcher::launch($cm, $instance, (int)$au->id, $userid ?? (int)$this->user->id, $mode);
        parse_str((string)parse_url($result->url->out(false), PHP_URL_QUERY), $query);
        parse_str((string)parse_url($query['fetch'], PHP_URL_QUERY), $fetch);
        $data = json_decode(fetch_service::handle('POST', $fetch['k'])->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('auth-token', $data);
        return [$result->session, $data['auth-token']];
    }

    /**
     * An authenticated xAPI 1.0.3 request.
     *
     * @param string $method Method.
     * @param string $resource Resource.
     * @param string $token Session token.
     * @param array $query Query.
     * @param string $body Body.
     * @param array $headers Extra headers.
     * @return request
     */
    protected function lrs_request(
        string $method,
        string $resource,
        string $token,
        array $query = [],
        string $body = '',
        array $headers = []
    ): request {
        $headers += ['X-Experience-API-Version' => '1.0.3', 'Authorization' => 'Basic ' . $token];
        return request::build($method, $resource, $query, $headers, $body);
    }

    /**
     * A cmi5 defined statement of a session: initialized, completed, passed or failed (with an optional score).
     *
     * @param \stdClass $session Session.
     * @param string $verb Verb IRI.
     * @param float|null $scaled result.score.scaled of a passed or failed.
     * @return array
     */
    protected function cmi5_statement(\stdClass $session, string $verb, ?float $scaled = null): array {
        global $DB, $CFG;
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $session->regid], '*', MUST_EXIST);
        $au = $DB->get_record('scormmakercmi5_au', ['id' => $session->auid], '*', MUST_EXIST);
        $st = [
            'id' => \core\uuid::generate(),
            'timestamp' => '2026-09-30T12:00:00.000Z',
            'actor' => ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => (string)$reg->userid]],
            'verb' => ['id' => $verb, 'display' => ['pt-BR' => 'verbo']],
            'object' => ['objectType' => 'Activity', 'id' => $au->lmsactivityid],
            'context' => [
                'registration' => $reg->registration,
                'contextActivities' => [
                    'grouping' => [['objectType' => 'Activity', 'id' => $au->publisherid]],
                    'category' => [['id' => vocab::CAT_CMI5]],
                ],
                'extensions' => [vocab::EXT_SESSIONID => $session->sessionuuid],
            ],
        ];
        if ($verb === vocab::VERB_COMPLETED) {
            $st['result'] = ['completion' => true, 'duration' => 'PT5M'];
        } else if ($verb === vocab::VERB_PASSED || $verb === vocab::VERB_FAILED) {
            $st['result'] = ['success' => $verb === vocab::VERB_PASSED, 'duration' => 'PT5M'];
            if ($scaled !== null) {
                $st['result']['score'] = ['scaled' => $scaled];
            }
        }
        if ($verb !== vocab::VERB_INITIALIZED) {
            $st['context']['contextActivities']['category'][] = ['id' => vocab::CAT_MOVEON];
        }
        return $st;
    }

    /**
     * Launches an AU for a learner and posts initialized followed by a completed, passed or failed.
     *
     * @param int $userid Learner.
     * @param int $auid AU.
     * @param string $verb Verb IRI of the second statement.
     * @param float|null $scaled result.score.scaled of a passed or failed.
     * @return array [\stdClass session, string token] of the launch (the session is still open).
     */
    protected function learn_au(int $userid, int $auid, string $verb, ?float $scaled = null): array {
        [$session, $token] = $this->launch_with_token($userid, $auid);
        $batch = [$this->cmi5_statement($session, vocab::VERB_INITIALIZED), $this->cmi5_statement($session, $verb, $scaled)];
        $this->assertSame(200, $this->post_statements($token, $batch)->status);
        return [$session, $token];
    }

    /**
     * POST statements through the router.
     *
     * @param string $token Session token.
     * @param array $statements Statements.
     * @return response
     */
    protected function post_statements(string $token, array $statements): response {
        $body = json_encode($statements, statement_store::JSON_FLAGS);
        return router::dispatch($this->lrs_request('POST', 'statements', $token, [], $body));
    }
}
