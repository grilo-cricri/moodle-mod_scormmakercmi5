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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\request::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\lrs_exception::class)]
/**
 * Tests for the parsing of an LRS request (design §2.4, §7.1; Review Focus 1).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\request
 * @covers     \mod_scormmakercmi5\local\lrs\lrs_exception
 */
final class request_test extends \advanced_testcase {
    /** @var string Content type of the alternate request syntax. */
    private const FORM = 'application/x-www-form-urlencoded';

    /**
     * Asserts that a callable throws an lrs_exception with a status and an error code.
     *
     * @param int $status Expected HTTP status.
     * @param string $errorcode Expected error code.
     * @param callable $call The call.
     * @return lrs_exception
     */
    private function assert_lrs_exception(int $status, string $errorcode, callable $call): lrs_exception {
        try {
            $call();
        } catch (lrs_exception $e) {
            $this->assertSame($status, $e->httpstatus);
            $this->assertSame($errorcode, $e->errorcode);
            return $e;
        }
        $this->fail('lrs_exception expected');
    }

    /**
     * The Authorization header as PHP sees it under mod_php (HTTP_AUTHORIZATION).
     */
    public function test_authorization_from_http_authorization(): void {
        $this->assertSame('Basic abc', request::extract_authorization(['HTTP_AUTHORIZATION' => 'Basic abc'], null));
        $this->assertSame('Basic abc', request::extract_authorization(
            ['HTTP_AUTHORIZATION' => 'Basic abc', 'REDIRECT_HTTP_AUTHORIZATION' => 'Basic other'],
            ['Authorization' => 'Basic other'],
        ));
    }

    /**
     * Under CGI with a rewrite rule the header arrives only as REDIRECT_HTTP_AUTHORIZATION.
     */
    public function test_authorization_from_redirect(): void {
        $this->assertSame('Basic abc', request::extract_authorization(['REDIRECT_HTTP_AUTHORIZATION' => 'Basic abc'], null));
        $this->assertSame('Basic abc', request::extract_authorization(
            ['HTTP_AUTHORIZATION' => '', 'REDIRECT_HTTP_AUTHORIZATION' => 'Basic abc'],
            [],
        ));
    }

    /**
     * Neither server variable: getallheaders(), whatever the case of the name.
     */
    public function test_authorization_from_getallheaders(): void {
        $this->assertSame('Basic abc', request::extract_authorization([], ['authorization' => 'Basic abc']));
        $this->assertSame('Basic abc', request::extract_authorization([], ['AUTHORIZATION' => 'Basic abc']));
        $all = ['Accept' => '*/*', 'Authorization' => 'Basic abc'];
        $this->assertSame('Basic abc', request::extract_authorization(['HTTP_HOST' => 'x'], $all));
    }

    /**
     * No header anywhere, or an empty one: null.
     */
    public function test_authorization_missing(): void {
        $this->assertNull(request::extract_authorization([], null));
        $this->assertNull(request::extract_authorization(['HTTP_HOST' => 'x'], ['accept' => '*/*']));
        $this->assertNull(request::extract_authorization(['HTTP_AUTHORIZATION' => '  '], ['authorization' => '']));
    }

    /**
     * build(): lower-case header names, the resource without slashes, the authorization from the headers.
     */
    public function test_build_plain(): void {
        $r = request::build('GET', '/activities/state/', ['stateId' => 's1'], [
            'X-Experience-API-Version' => '1.0.3',
            'Authorization' => 'Basic tok+/=',
        ], '');
        $this->assertSame('GET', $r->method);
        $this->assertSame('activities/state', $r->resource);
        $this->assertSame(['stateId' => 's1'], $r->query);
        $this->assertSame('1.0.3', $r->headers['x-experience-api-version']);
        $this->assertSame('Basic tok+/=', $r->authorization);
        $this->assertSame('', $r->body);

        $r = request::build('PUT', 'about', [], [], '{"a":1}', ['HTTP_AUTHORIZATION' => 'Basic abc']);
        $this->assertSame('about', $r->resource);
        $this->assertSame('Basic abc', $r->authorization);
        $this->assertSame('{"a":1}', $r->body);
        $this->assertSame([], $r->query);
    }

    /**
     * The alternate request syntax (xAPI 1.0.3 part 3 §1.3): method, headers, Authorization and content come from
     * the form; the other form fields are the query.
     */
    public function test_alternate_syntax(): void {
        $body = 'Authorization=Basic%20abc&X-Experience-API-Version=1.0.3&content=%7B%7D&stateId=x';
        $r = request::build('POST', '/activities/state', ['method' => 'PUT'], ['Content-Type' => self::FORM], $body);

        $this->assertSame('PUT', $r->method);
        $this->assertSame('Basic abc', $r->authorization);
        $this->assertSame('1.0.3', $r->headers['x-experience-api-version'] ?? null);
        $this->assertSame('{}', $r->body);
        $this->assertSame('x', $r->query['stateId']);
        $this->assertSame(['stateId' => 'x'], $r->query);
        $this->assertArrayNotHasKey('authorization', $r->query);
        // The form's own content type is not the content type of the document.
        $this->assertArrayNotHasKey('content-type', $r->headers);
    }

    /**
     * Alternate syntax: the Content-Type of the document comes from the form, '+' is a space, the method can be
     * lower case, and an Authorization header of the real request is used when the form has none.
     */
    public function test_alternate_syntax_details(): void {
        $body = 'Content-Type=application%2Fjson&content=a+b&If-Match=%22e1%22&activityId=https%3A%2F%2Fx.y%2Fa%3Fq%3D1';
        $r = request::build(
            'POST',
            'activities/state',
            ['method' => 'post'],
            ['content-type' => self::FORM . '; charset=UTF-8'],
            $body,
            ['HTTP_AUTHORIZATION' => 'Basic real']
        );
        $this->assertSame('POST', $r->method);
        $this->assertSame('application/json', $r->headers['content-type']);
        $this->assertSame('"e1"', $r->headers['if-match']);
        $this->assertSame('a b', $r->body);
        $this->assertSame('https://x.y/a?q=1', $r->query['activityId']);
        $this->assertSame('Basic real', $r->authorization);

        $r = request::build('POST', 'agents', ['method' => 'GET'], ['content-type' => self::FORM], '');
        $this->assertSame('GET', $r->method);
        $this->assertSame('', $r->body);
        $this->assertSame([], $r->query);
    }

    /**
     * Alternate syntax misuse: not a POST, another query parameter, a form that is not form-encoded, an unknown
     * method → 400.
     */
    public function test_alternate_syntax_refused(): void {
        $form = ['content-type' => self::FORM];
        $this->assert_lrs_exception(
            400,
            'err_lrsaltsyntax',
            fn() => request::build('GET', 'agents', ['method' => 'GET'], $form, '')
        );
        $this->assert_lrs_exception(
            400,
            'err_lrsaltsyntax',
            fn() => request::build('POST', 'activities/state', ['method' => 'PUT', 'stateId' => 'x'], $form, '')
        );
        $this->assert_lrs_exception(
            400,
            'err_lrsaltsyntax',
            fn() => request::build('POST', 'agents', ['method' => 'GET'], ['content-type' => 'application/json'], '{}')
        );
        $this->assert_lrs_exception(
            400,
            'err_lrsaltsyntax',
            fn() => request::build('POST', 'agents', ['method' => 'PATCH'], $form, '')
        );
    }

    /**
     * Query strings and forms are parsed without PHP's mangling: dots kept, brackets not turned into arrays, the
     * last of two equal names wins, a name without '=' has an empty value.
     */
    public function test_parse_form(): void {
        $expected = ['a.b' => '1', 'c[]' => 'x y', 'd' => '3', 'e' => ''];
        $this->assertSame($expected, request::parse_form('a.b=1&c%5B%5D=x+y&d=2&d=3&e&&'));
        $this->assertSame([], request::parse_form(''));
    }

    /**
     * from_globals(): method, query string, headers from $_SERVER (the CLI has no getallheaders()) and the
     * authorization of the server variables.
     */
    public function test_from_globals(): void {
        $saved = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['QUERY_STRING'] = 'agent=%7B%7D&x.y=1';
            $_SERVER['HTTP_X_EXPERIENCE_API_VERSION'] = '1.0.3';
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic abc';
            unset($_SERVER['HTTP_AUTHORIZATION']);
            $r = request::from_globals('/agents');
        } finally {
            $_SERVER = $saved;
        }
        $this->assertSame('GET', $r->method);
        $this->assertSame('agents', $r->resource);
        $this->assertSame(['agent' => '{}', 'x.y' => '1'], $r->query);
        $this->assertSame('1.0.3', $r->headers['x-experience-api-version']);
        $this->assertSame('application/json', $r->headers['content-type']);
        $this->assertSame('Basic abc', $r->authorization);
        $this->assertSame('', $r->body);
    }

    /**
     * A declared body larger than any limit of the LRS is refused before it is read: 413.
     */
    public function test_from_globals_declared_too_large(): void {
        $saved = $_SERVER;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['QUERY_STRING'] = '';
            $_SERVER['CONTENT_LENGTH'] = (string)(request::max_read_bytes() + 1);
            $this->assert_lrs_exception(413, 'err_lrstoolarge', fn() => request::from_globals('/statements'));
        } finally {
            $_SERVER = $saved;
        }
    }

    /**
     * The exception: status, rule, headers and the localised message with its detail.
     */
    public function test_lrs_exception(): void {
        $e = new lrs_exception(405, 'err_lrsmethod', 'PATCH', null, ['Allow' => 'GET']);
        $this->assertSame(405, $e->httpstatus);
        $this->assertSame('err_lrsmethod', $e->errorcode);
        $this->assertNull($e->rule);
        $this->assertSame(['Allow' => 'GET'], $e->headers);
        $this->assertSame(get_string('err_lrsmethod', 'mod_scormmakercmi5', 'PATCH'), $e->getMessage());
        $this->assertStringContainsString('PATCH', $e->getMessage());
        $this->assertInstanceOf(\moodle_exception::class, $e);

        $e = new lrs_exception(403, 'err_scope', '', '9.2.0.0-2');
        $this->assertSame('9.2.0.0-2', $e->rule);
        $this->assertSame(get_string('err_scope', 'mod_scormmakercmi5'), $e->getMessage());
    }
}
