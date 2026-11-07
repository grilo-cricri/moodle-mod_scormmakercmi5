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
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/lrs_session.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\runtime\actor;
use mod_scormmakercmi5\local\runtime\fetch_service;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\cors::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\router::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\fetch_service::class)]
/**
 * Tests for the CORS of lrs.php and fetch.php for an AU outside the package: only the exact origin of the AU, never
 * "*", never an echo of any Origin, Vary: Origin, no credentials; the token stays the authorisation.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\cors
 * @covers     \mod_scormmakercmi5\local\lrs\router
 * @covers     \mod_scormmakercmi5\local\runtime\fetch_service
 */
final class cors_test extends \advanced_testcase {
    use \mod_scormmakercmi5_test_lrs_session;

    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /** @var string Origin of the AU outside the package. */
    private const ORIGIN = 'https://cursos.exemplo.com';

    /** @var string Another outside origin. */
    private const OTHER = 'https://malicioso.exemplo';

    /**
     * An activity whose only AU is at an origin outside the package.
     *
     * @param string $origin Origin of the AU.
     */
    private function create_external_au(string $origin): void {
        $this->setup_lrs_activity(self::NOW, builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => $origin . '/au/index.html?x=1', 'title' => 'Unidade externa'],
        ]));
    }

    /**
     * The origin of the Moodle site, as a browser sends it.
     *
     * @return string
     */
    private static function site_origin(): string {
        global $CFG;
        $p = parse_url($CFG->wwwroot);
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * GET agents of the learner of a token.
     *
     * @param string $token Token.
     * @param array|null $agent Agent asked for (the learner by default).
     * @return request
     */
    private function agents_request(string $token, ?array $agent = null): request {
        $agent = $agent ?? actor::for_user((int)$this->user->id);
        return $this->lrs_request('GET', 'agents', $token, ['agent' => json_encode($agent)]);
    }

    /**
     * Asserts that a response carries no CORS header at all.
     *
     * @param response $response Response.
     */
    private function assert_no_cors(response $response): void {
        foreach (array_keys($response->headers) as $name) {
            $this->assertStringStartsNotWith('access-control-', strtolower($name), $name);
        }
    }

    /**
     * Preflight: only for the origin of an AU of the site.
     */
    public function test_preflight(): void {
        $this->create_external_au(self::ORIGIN);
        $ok = cors::preflight(self::ORIGIN);
        $this->assertSame(204, $ok->status);
        $this->assertSame('', $ok->body);
        $this->assertSame(self::ORIGIN, $ok->headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $ok->headers['Vary']);
        $this->assertSame('GET, PUT, POST, DELETE', $ok->headers['Access-Control-Allow-Methods']);
        $allowed = 'Authorization, Content-Type, X-Experience-API-Version, If-Match, If-None-Match';
        $this->assertSame($allowed, $ok->headers['Access-Control-Allow-Headers']);
        $this->assertSame('600', $ok->headers['Access-Control-Max-Age']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $ok->headers);
        $this->assertSame('no-store', $ok->headers['Cache-Control']);
        $this->assertNull(cors::preflight(self::OTHER));
        $this->assertNull(cors::preflight(null));
        $this->assertNull(cors::preflight(''));
        // Only the exact form a browser sends: never a variant echoed back, never "*" or "null".
        $variants = ['*', 'null', 'https://CURSOS.exemplo.com', self::ORIGIN . ' ', self::ORIGIN . '/', self::ORIGIN . ':443',
            'http://cursos.exemplo.com', self::ORIGIN . '.malicioso.exemplo', 'https://exemplo.com',
            "https://cursos.exemplo.com\r\nX-Injected: 1", self::site_origin()];
        foreach ($variants as $origin) {
            $this->assertNull(cors::preflight($origin), $origin);
        }
    }

    /**
     * Authenticated answers: the header only for the AU of the session; another outside origin is 403.
     */
    public function test_for_au(): void {
        $this->resetAfterTest();
        $au = (object)['auorigin' => self::ORIGIN];
        $headers = cors::for_au($au, self::ORIGIN);
        $this->assertSame(self::ORIGIN, $headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $headers['Vary']);
        $this->assertStringContainsString('ETag', $headers['Access-Control-Expose-Headers']);
        $this->assertStringContainsString('X-Experience-API-Version', $headers['Access-Control-Expose-Headers']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
        $this->assertSame([], cors::for_au((object)['auorigin' => null], null));
        $this->assertSame([], cors::for_au($au, null));
        $this->assertSame([], cors::for_au($au, ''));
        // The value "null" (a no-cors POST under Referrer-Policy: no-referrer) is no origin for an AU of the package: no header.
        $this->assertSame([], cors::for_au((object)['auorigin' => null], 'null'));
        // The origin of Moodle itself: nothing changes, for an AU of the package and for one outside it.
        $this->assertSame([], cors::for_au($au, self::site_origin()));
        $this->assertSame([], cors::for_au((object)['auorigin' => null], self::site_origin()));
        $refused = [[$au, self::OTHER], [$au, 'null'], [$au, '*'], [$au, 'https://CURSOS.exemplo.com'],
            [(object)['auorigin' => null], self::ORIGIN], [(object)['auorigin' => ''], self::OTHER]];
        foreach ($refused as [$row, $origin]) {
            try {
                cors::for_au($row, $origin);
                $this->fail($origin);
            } catch (lrs_exception $e) {
                $this->assertSame(403, $e->httpstatus, $origin);
                $this->assertSame('err_corsorigin', $e->errorcode, $origin);
            }
        }
        $this->expectException(lrs_exception::class);
        cors::for_au($au, 'https://outro.exemplo');
    }

    /**
     * The same origin as Moodle, with the default port written in wwwroot or not, upper case or not.
     */
    public function test_same_origin_forms(): void {
        global $CFG;
        $this->resetAfterTest();
        $au = (object)['auorigin' => self::ORIGIN];
        $CFG->wwwroot = 'https://moodle.exemplo:443/sub';
        $this->assertSame([], cors::for_au($au, 'https://moodle.exemplo'));
        $CFG->wwwroot = 'https://Moodle.Exemplo/sub';
        $this->assertSame([], cors::for_au($au, 'https://moodle.exemplo'));
        $CFG->wwwroot = 'http://moodle.exemplo:8130';
        $this->assertSame([], cors::for_au($au, 'http://moodle.exemplo:8130'));
        $this->expectException(lrs_exception::class);
        cors::for_au($au, 'http://moodle.exemplo');
    }

    /**
     * lrs.php: the AU of the session reads its answers from its origin; another origin is refused before the handler
     * runs (nothing is stored); an error after the authentication can be read by the AU; no token, no header.
     */
    public function test_router(): void {
        global $DB;
        $this->create_external_au(self::ORIGIN);
        [$session, $token] = $this->launch_with_token();

        $ok = router::dispatch($this->agents_request($token), self::ORIGIN);
        $this->assertSame(200, $ok->status);
        $this->assertSame(self::ORIGIN, $ok->headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $ok->headers['Vary']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $ok->headers);
        $this->assertSame(router::VERSION, $ok->headers['X-Experience-API-Version']);

        // Same origin or no Origin: as before, no header.
        $this->assert_no_cors(router::dispatch($this->agents_request($token), self::site_origin()));
        $this->assert_no_cors(router::dispatch($this->agents_request($token)));

        // An error of the handler, after the authentication: the AU can read it.
        $scope = router::dispatch($this->agents_request($token, ['mbox' => 'mailto:outro@exemplo.com']), self::ORIGIN);
        $this->assertSame(403, $scope->status);
        $this->assertSame('err_scope', json_decode($scope->body, true)['error']);
        $this->assertSame(self::ORIGIN, $scope->headers['Access-Control-Allow-Origin']);

        // Another outside origin with the right token: 403, and the statement is not stored.
        $before = $DB->count_records('scormmakercmi5_stmt');
        $statement = json_encode($this->cmi5_statement($session, vocab::VERB_INITIALIZED));
        $refused = router::dispatch(
            $this->lrs_request('POST', 'statements', $token, [], $statement, ['Content-Type' => 'application/json']),
            self::OTHER
        );
        $this->assertSame(403, $refused->status);
        $this->assertSame('err_corsorigin', json_decode($refused->body, true)['error']);
        $this->assert_no_cors($refused);
        $this->assertSame($before, $DB->count_records('scormmakercmi5_stmt'));

        // The origin never replaces the token.
        $noauth = router::dispatch($this->agents_request('bm90LWEtdG9rZW4='), self::ORIGIN);
        $this->assertSame(401, $noauth->status);
        $this->assert_no_cors($noauth);
        $query = ['agent' => json_encode(actor::for_user((int)$this->user->id))];
        $notoken = request::build('GET', 'agents', $query, ['X-Experience-API-Version' => '1.0.3'], '');
        $missing = router::dispatch($notoken, self::ORIGIN);
        $this->assertSame(401, $missing->status);

        // From the AU origin, the statement goes in (the AU read its preferences first, cmi5 rule 11.0.0.0-3).
        $DB->set_field('scormmakercmi5_session', 'prefsread', 1, ['id' => $session->id]);
        $stored = router::dispatch(
            $this->lrs_request('POST', 'statements', $token, [], $statement, ['Content-Type' => 'application/json']),
            self::ORIGIN
        );
        $this->assertSame(200, $stored->status, $stored->body);
        $this->assertSame(self::ORIGIN, $stored->headers['Access-Control-Allow-Origin']);
        $this->assertSame($before + 1, $DB->count_records('scormmakercmi5_stmt'));
    }

    /**
     * lrs.php OPTIONS: 204 for the origin of an AU, whatever the resource; any other origin is routed as before.
     */
    public function test_router_preflight(): void {
        $this->create_external_au(self::ORIGIN);
        $preflight = router::dispatch(request::build('OPTIONS', 'statements', [], [], ''), self::ORIGIN);
        $this->assertSame(204, $preflight->status);
        $this->assertSame(self::ORIGIN, $preflight->headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $preflight->headers['Vary']);
        $this->assertSame('', $preflight->body);

        foreach ([self::OTHER, null, self::site_origin()] as $origin) {
            $other = router::dispatch(request::build('OPTIONS', 'statements', [], [], ''), $origin);
            $this->assertSame(405, $other->status, (string)$origin);
            $this->assert_no_cors($other);
        }
    }

    /**
     * An AU inside the package: a token used from an outside origin is refused (nothing changes from Moodle).
     */
    public function test_router_package_au(): void {
        $this->setup_lrs_activity(self::NOW);
        [, $token] = $this->launch_with_token();
        $this->assertSame(200, router::dispatch($this->agents_request($token), self::site_origin())->status);
        $this->assertSame(200, router::dispatch($this->agents_request($token))->status);
        $null = router::dispatch($this->agents_request($token), 'null');
        $this->assertSame(200, $null->status);
        $this->assert_no_cors($null);
        $refused = router::dispatch($this->agents_request($token), self::ORIGIN);
        $this->assertSame(403, $refused->status);
        $this->assert_no_cors($refused);
        $this->assertNull(cors::preflight(self::ORIGIN));
    }

    /**
     * fetch.php: the token goes to the AU origin with the header; another origin gets the security error and the key
     * is not spent; OPTIONS answers only the AU origin.
     */
    public function test_fetch(): void {
        global $DB;
        $this->create_external_au(self::ORIGIN);
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
        $au = $DB->get_record('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], '*', MUST_EXIST);
        $userid = (int)$this->user->id;
        $result = \mod_scormmakercmi5\local\runtime\launcher::launch($cm, $instance, (int)$au->id, $userid, 'Normal');
        parse_str((string)parse_url($result->url->out(false), PHP_URL_QUERY), $query);
        parse_str((string)parse_url($query['fetch'], PHP_URL_QUERY), $fetch);

        $refused = fetch_service::handle('POST', $fetch['k'], 5, self::OTHER);
        $this->assertSame(200, $refused->status);
        $this->assertSame(fetch_service::ERROR_SECURITY, json_decode($refused->body, true)['error-code']);
        $this->assert_no_cors($refused);
        $this->assertEquals(0, $DB->get_field('scormmakercmi5_session', 'fetchused', ['id' => $result->session->id]));

        $ok = fetch_service::handle('POST', $fetch['k'], 5, self::ORIGIN);
        $this->assertArrayHasKey('auth-token', json_decode($ok->body, true));
        $this->assertSame(self::ORIGIN, $ok->headers['Access-Control-Allow-Origin']);
        $this->assertSame('Origin', $ok->headers['Vary']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $ok->headers);

        // The second fetch from the AU origin: error 1, readable by the AU.
        $again = fetch_service::handle('POST', $fetch['k'], 5, self::ORIGIN);
        $this->assertSame(fetch_service::ERROR_EXPIRED, json_decode($again->body, true)['error-code']);
        $this->assertSame(self::ORIGIN, $again->headers['Access-Control-Allow-Origin']);

        $preflight = fetch_service::handle('OPTIONS', '', 5, self::ORIGIN);
        $this->assertSame(204, $preflight->status);
        $this->assertSame(self::ORIGIN, $preflight->headers['Access-Control-Allow-Origin']);
        foreach ([self::OTHER, null] as $origin) {
            $other = fetch_service::handle('OPTIONS', '', 5, $origin);
            $this->assertSame(405, $other->status);
            $this->assert_no_cors($other);
        }
    }
}
