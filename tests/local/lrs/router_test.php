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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\router::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\response::class)]
/**
 * Tests for the LRS router (design §2.4, §7.1): xAPI headers, errors as JSON and never HTML, no CORS for an AU of the
 * package (the CORS of an AU outside it: cors_test).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\router
 * @covers     \mod_scormmakercmi5\local\lrs\endpoint
 * @covers     \mod_scormmakercmi5\local\lrs\response
 */
final class router_test extends \advanced_testcase {
    /** @var string A secret that must never reach a body or a log. */
    private const SECRET = 'Basic c2VjcmV0LXRva2VuLXZhbHVl';

    /**
     * Resets the test routes.
     */
    protected function tearDown(): void {
        $this->set_test_routes([]);
        parent::tearDown();
    }

    /**
     * Sets the extra routes of the router (test seam).
     *
     * @param array $routes resource => [method => callable].
     */
    private function set_test_routes(array $routes): void {
        (new \ReflectionProperty(router::class, 'testroutes'))->setValue(null, $routes);
    }

    /**
     * A request with the version header (unless given null) and the secret as authorization.
     *
     * @param string $method Method.
     * @param string $path Path info.
     * @param string|null $version X-Experience-API-Version, null for none.
     * @param array $query Query.
     * @return request
     */
    private function request(string $method, string $path, ?string $version = '1.0.3', array $query = []): request {
        $headers = ['Authorization' => self::SECRET];
        if ($version !== null) {
            $headers['X-Experience-API-Version'] = $version;
        }
        return request::build($method, $path, $query, $headers, '');
    }

    /**
     * Asserts the headers of every LRS response and returns the decoded JSON body (null if empty).
     *
     * @param int $status Expected status.
     * @param response $response Response.
     * @return array|null
     */
    private function assert_lrs_response(int $status, response $response): ?array {
        $this->assertSame($status, $response->status, $response->body);
        $this->assertSame('1.0.3', $response->headers['X-Experience-API-Version'] ?? null);
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
        $this->assertSame(response::CSP, $response->headers['Content-Security-Policy'] ?? null);
        foreach (array_keys($response->headers) as $name) {
            $this->assertStringStartsNotWith('access-control-', strtolower($name));
            $this->assertNotSame('www-authenticate', strtolower($name));
        }
        $this->assertStringNotContainsString('<', $response->body);
        if ($response->body === '') {
            return null;
        }
        $this->assertSame('application/json', $response->headers['Content-Type'] ?? null);
        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Asserts an xAPI error body.
     *
     * @param int $status Status.
     * @param string $errorcode Error code.
     * @param response $response Response.
     * @return array
     */
    private function assert_error(int $status, string $errorcode, response $response): array {
        $data = $this->assert_lrs_response($status, $response);
        $this->assertSame(['error', 'message', 'rule'], array_keys($data));
        $this->assertSame($errorcode, $data['error']);
        $this->assertIsString($data['message']);
        $this->assertNotSame('', $data['message']);
        return $data;
    }

    /**
     * About needs neither authorization nor the version header (xAPI 1.0.3 part 3 §2.2).
     */
    public function test_about(): void {
        $response = router::dispatch(request::build('GET', '/about', [], [], ''));
        $this->assertSame(['version' => ['1.0.3']], $this->assert_lrs_response(200, $response));
        $this->assertSame('{"version":["1.0.3"]}', $response->body);

        $response = router::dispatch($this->request('GET', 'about', '0.95'));
        $this->assertSame(['version' => ['1.0.3']], $this->assert_lrs_response(200, $response));
    }

    /**
     * HEAD answers as GET without the body; a method a resource does not take → 405 with Allow.
     */
    public function test_head_and_method_not_allowed(): void {
        $response = router::dispatch(request::build('HEAD', 'about', [], [], ''));
        $this->assertNull($this->assert_lrs_response(200, $response));
        $this->assertSame('application/json', $response->headers['Content-Type']);

        $data = $this->assert_error(405, 'err_lrsmethod', router::dispatch($this->request('PUT', 'about')));
        $this->assertStringContainsString('PUT', $data['message']);
        $response = router::dispatch($this->request('DELETE', 'agents'));
        $this->assert_error(405, 'err_lrsmethod', $response);
        $this->assertSame('GET, HEAD', $response->headers['Allow']);
    }

    /**
     * Every other resource needs X-Experience-API-Version 1.0.x (xAPI 1.0.3 part 3 §3.3), checked before the
     * authorization.
     */
    public function test_version_header(): void {
        $this->assert_error(400, 'err_lrsversion', router::dispatch($this->request('GET', 'agents', null)));
        $this->assert_error(400, 'err_lrsversion', router::dispatch($this->request('GET', 'agents', '0.95')));
        $this->assert_error(400, 'err_lrsversion', router::dispatch($this->request('GET', 'agents', '1.1.0')));
        $this->assert_error(400, 'err_lrsversion', router::dispatch($this->request('GET', 'agents', '1.0.3x')));
        foreach (['1.0', '1.0.0', '1.0.3', '1.0.9'] as $version) {
            // Accepted: the request then fails on the invalid token, not on the version.
            $this->assert_error(401, 'err_unauthorized', router::dispatch($this->request('GET', 'agents', $version)));
        }
    }

    /**
     * An unknown resource → 404 in JSON.
     */
    public function test_unknown_resource(): void {
        foreach (['foo', '', 'about/x', 'statements/../about'] as $path) {
            $this->assert_error(404, 'err_lrsnotfound', router::dispatch($this->request('GET', $path)));
        }
        $this->assert_error(404, 'err_lrsnotfound', router::dispatch($this->request('GET', 'foo', null)));
    }

    /**
     * A handler that throws a RuntimeException → 500 JSON, never HTML; the message of the exception (which may carry
     * a token or SQL parameters) reaches neither the body nor the log, and the failure is logged.
     */
    public function test_handler_throws(): void {
        $this->set_test_routes(['boom' => ['GET' => function (): response {
            throw new \RuntimeException('failure with ' . self::SECRET);
        }]]);

        $response = router::dispatch(request::build('GET', 'boom', [], [], ''));

        $data = $this->assert_error(500, 'err_internal', $response);
        $this->assertNull($data['rule']);
        $this->assertStringNotContainsString(self::SECRET, $response->body);
        $this->assertStringNotContainsString('c2VjcmV0', $response->body);
        $messages = $this->getDebuggingMessages();
        $this->resetDebugging();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('RuntimeException', $messages[0]->message);
        $this->assertStringContainsString('router_test.php', $messages[0]->message);
        $this->assertStringNotContainsString('c2VjcmV0', $messages[0]->message);
        $this->assertStringNotContainsString('failure with', $messages[0]->message);
    }

    /**
     * A handler that throws a TypeError (an Error, not an Exception) is caught the same way.
     */
    public function test_handler_throws_error(): void {
        $this->set_test_routes(['boom' => ['GET' => fn(): response => strlen([])]]);
        $this->assert_error(500, 'err_internal', router::dispatch(request::build('GET', 'boom', [], [], '')));
        $this->assertDebuggingCalled();
    }

    /**
     * An lrs_exception of a handler → its status, rule and headers; the X-Experience-API-Version wins.
     */
    public function test_handler_lrs_exception(): void {
        $this->set_test_routes(['rule' => ['GET' => function (): response {
            throw new lrs_exception(403, 'err_scope', '', '9.2.0.0-2', ['Retry-After' => '2']);
        }]]);
        $response = router::dispatch(request::build('GET', 'rule', [], [], ''));
        $data = $this->assert_error(403, 'err_scope', $response);
        $this->assertSame('9.2.0.0-2', $data['rule']);
        $this->assertSame(get_string('err_scope', 'mod_scormmakercmi5'), $data['message']);
        $this->assertSame('2', $response->headers['Retry-After']);
    }

    /**
     * response::error(): the JSON shape of design §2.4.
     */
    public function test_response_error(): void {
        $response = response::error(new lrs_exception(400, 'err_lrsparam', 'agent', null, ['X-A' => '1']));
        $this->assertSame(400, $response->status);
        $this->assertSame('application/json', $response->headers['Content-Type']);
        $this->assertSame('1', $response->headers['X-A']);
        $this->assertSame([
            'error' => 'err_lrsparam',
            'message' => get_string('err_lrsparam', 'mod_scormmakercmi5', 'agent'),
            'rule' => null,
        ], json_decode($response->body, true));
    }

    /**
     * handle(): parses the globals, dispatches and sends; a request the parser refuses still answers JSON.
     */
    public function test_handle(): void {
        $saved = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['QUERY_STRING'] = '';
            unset($_SERVER['CONTENT_LENGTH']);
            $this->expectOutputString('{"version":["1.0.3"]}' . '{"error":"err_lrsaltsyntax"');
            router::handle('/about');
            $_SERVER['QUERY_STRING'] = 'method=PUT';
            ob_start();
            router::handle('/activities/state');
            echo substr(ob_get_clean(), 0, 27);
        } finally {
            $_SERVER = $saved;
        }
    }

    /**
     * endpoint::serve(): a handler that throws → the fixed fallback body, sent without get_string, and one log line
     * with the class and place of the failure only.
     */
    public function test_endpoint_fallback(): void {
        $fallback = new response(500, '{"x":1}', ['Content-Type' => 'application/json']);
        $this->expectOutputString('{"ok":true}{"x":1}');
        endpoint::serve(fn(): response => response::json(200, ['ok' => true]), $fallback, 'test');
        endpoint::serve(function (): response {
            throw new \coding_exception('secret ' . self::SECRET);
        }, $fallback, 'test');
        $messages = $this->getDebuggingMessages();
        $this->resetDebugging();
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('mod_scormmakercmi5 test: ', $messages[0]->message);
        $this->assertStringContainsString('coding_exception [codingerror] at ', $messages[0]->message);
        $this->assertStringNotContainsString('c2VjcmV0', $messages[0]->message);
    }

    /**
     * The fixed last-resort bodies are JSON of the right shape (they are written by hand, not encoded).
     */
    public function test_fallback_bodies(): void {
        $internal = json_decode(router::INTERNAL_BODY, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['error', 'message', 'rule'], array_keys($internal));
        $this->assertSame('err_internal', json_decode(router::INTERNAL_BODY, true)['error']);
        $fetch = json_decode(\mod_scormmakercmi5\local\runtime\fetch_service::FALLBACK_BODY, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['error-code', 'error-text'], array_keys($fetch));
        $this->assertSame('3', $fetch['error-code']);
    }
}
