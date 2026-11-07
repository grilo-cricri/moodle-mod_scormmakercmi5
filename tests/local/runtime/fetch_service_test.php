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

namespace mod_scormmakercmi5\local\runtime;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\lrs\response;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\fetch_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\response::class)]
/**
 * Tests for the one-time fetch of the auth token (design §6.2; cmi5 §8.2; R4.3, R4.7).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\runtime\fetch_service
 * @covers     \mod_scormmakercmi5\local\lrs\response
 */
final class fetch_service_test extends \advanced_testcase {
    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Learner. */
    private \stdClass $user;

    /** @var \stdClass Instance (with cmid). */
    private \stdClass $instance;

    /** @var \frozen_clock Clock. */
    private \frozen_clock $clock;

    /**
     * Course, learner, activity and frozen clock.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->clock = $this->mock_clock_with_frozen(self::NOW);
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $entries = builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Unidade 1'],
        ])]);
        $this->instance = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5')
            ->create_instance(['course' => $this->course->id, 'package_entries' => $entries]);
    }

    /**
     * Launches the AU for the learner and returns the session and the fetch key taken from the launch URL.
     *
     * @return array [\stdClass session, string key].
     */
    private function launch(): array {
        global $DB;
        $instance = $DB->get_record('scormmakercmi5', ['id' => $this->instance->id], '*', MUST_EXIST);
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->instance->cmid);
        $au = $DB->get_record('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], '*', MUST_EXIST);
        $result = launcher::launch($cm, $instance, (int)$au->id, (int)$this->user->id, 'Normal');
        parse_str((string)parse_url($result->url->out(false), PHP_URL_QUERY), $query);
        parse_str((string)parse_url($query['fetch'], PHP_URL_QUERY), $fetch);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $fetch['k']);
        return [$result->session, $fetch['k']];
    }

    /**
     * The session row.
     *
     * @param int $id Session id.
     * @return \stdClass
     */
    private function session(int $id): \stdClass {
        global $DB;
        return $DB->get_record('scormmakercmi5_session', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Asserts a cmi5 fetch error response (200, JSON, no-store) and returns its decoded body.
     *
     * @param string $code Expected error-code.
     * @param response $response Response.
     * @return array
     */
    private function assert_error(string $code, response $response): array {
        $this->assert_json_headers($response);
        $this->assertSame(200, $response->status);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['error-code', 'error-text'], array_keys($data));
        $this->assertSame($code, $data['error-code']);
        $this->assertIsString($data['error-text']);
        $this->assertNotSame('', $data['error-text']);
        $this->assertArrayNotHasKey('auth-token', $data);
        return $data;
    }

    /**
     * Asserts the headers every POST response carries.
     *
     * @param response $response Response.
     */
    private function assert_json_headers(response $response): void {
        $this->assertSame('application/json', $response->headers['Content-Type'] ?? null);
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        $this->assertSame(response::CSP, $response->headers['Content-Security-Policy'] ?? null);
    }

    /**
     * Asserts that a session got no token.
     *
     * @param int $id Session id.
     */
    private function assert_no_token(int $id): void {
        $session = $this->session($id);
        $this->assertNull($session->tokenhash);
        $this->assertEquals(0, $session->fetchused);
    }

    /**
     * The error codes are the ones of cmi5 §8.2.3.2.
     */
    public function test_error_codes(): void {
        $this->assertSame('1', fetch_service::ERROR_EXPIRED);
        $this->assertSame('2', fetch_service::ERROR_SECURITY);
        $this->assertSame('3', fetch_service::ERROR_INTERNAL);
    }

    /**
     * First POST: a 32-byte token, stored only as its hash, valid for the session timeout.
     */
    public function test_first_post_returns_token(): void {
        set_config('sessiontimeout', 3600, 'mod_scormmakercmi5');
        [$session, $key] = $this->launch();
        $this->clock->bump(7);

        $response = fetch_service::handle('POST', $key);

        $this->assertSame(200, $response->status);
        $this->assert_json_headers($response);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['auth-token'], array_keys($data));
        $token = $data['auth-token'];
        $this->assertIsString($token);
        $this->assertSame(32, strlen(base64_decode($token, true)));
        $row = $this->session((int)$session->id);
        $this->assertSame(hash('sha256', $token), $row->tokenhash);
        $this->assertEquals(1, $row->fetchused);
        $this->assertEquals(self::NOW + 7 + limits::session_timeout_seconds(), $row->tokenexpires);
        $this->assertEquals(self::NOW + 7 + 3600, $row->tokenexpires);
        $this->assertEquals((self::NOW + 7) * 1000, $row->lastactivityms);
        $this->assertEquals(launcher::STATUS_LAUNCHED, $row->status);
        $this->assertSame(hash('sha256', $key), $row->fetchkeyhash);
        $this->assertStringNotContainsString($key, $response->body);
    }

    /**
     * Second POST with the same key: error 1 (cmi5 §8.2.3.1) and the stored token is kept.
     */
    public function test_second_post_is_refused(): void {
        [$session, $key] = $this->launch();
        $first = json_decode(fetch_service::handle('POST', $key)->body, true);
        $hash = $this->session((int)$session->id)->tokenhash;
        $this->assertSame(hash('sha256', $first['auth-token']), $hash);

        $data = $this->assert_error(fetch_service::ERROR_EXPIRED, fetch_service::handle('POST', $key));

        $this->assertSame(get_string('fetcherror_alreadyused', 'mod_scormmakercmi5'), $data['error-text']);
        $this->assertSame($hash, $this->session((int)$session->id)->tokenhash);
    }

    /**
     * An unknown key, an empty key and a key of the right shape that matches nothing: error 2.
     */
    public function test_unknown_key(): void {
        [$session] = $this->launch();
        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', str_repeat('A', 43)));
        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', ''));
        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', 'x'));
        $this->assert_no_token((int)$session->id);
    }

    /**
     * The key of a session abandoned by a relaunch: error 1 (expired).
     */
    public function test_abandoned_session(): void {
        [$old, $oldkey] = $this->launch();
        [$new, $newkey] = $this->launch();
        $this->assertEquals(launcher::STATUS_ABANDONED, $this->session((int)$old->id)->status);

        $this->assert_error(fetch_service::ERROR_EXPIRED, fetch_service::handle('POST', $oldkey));

        $this->assert_no_token((int)$old->id);
        $this->assertSame(200, fetch_service::handle('POST', $newkey)->status);
        $this->assertNotNull($this->session((int)$new->id)->tokenhash);
    }

    /**
     * The key of a terminated session: error 1 (expired).
     */
    public function test_terminated_session(): void {
        global $DB;
        [$session, $key] = $this->launch();
        $DB->set_field('scormmakercmi5_session', 'status', launcher::STATUS_TERMINATED, ['id' => $session->id]);
        $this->assert_error(fetch_service::ERROR_EXPIRED, fetch_service::handle('POST', $key));
        $this->assert_no_token((int)$session->id);
    }

    /**
     * A learner unenrolled after the launch: error 2.
     */
    public function test_unenrolled(): void {
        global $DB;
        [$session, $key] = $this->launch();
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, (int)$this->user->id);

        $data = $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', $key));

        $this->assertSame(get_string('fetcherror_security', 'mod_scormmakercmi5'), $data['error-text']);
        $this->assert_no_token((int)$session->id);
    }

    /**
     * A suspended learner: error 2.
     */
    public function test_suspended_user(): void {
        global $DB;
        [$session, $key] = $this->launch();
        $DB->set_field('user', 'suspended', 1, ['id' => $this->user->id]);
        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', $key));
        $this->assert_no_token((int)$session->id);
    }

    /**
     * mod/scormmakercmi5:view prohibited in the module context: error 2.
     */
    public function test_view_prohibited(): void {
        global $DB;
        [$session, $key] = $this->launch();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $context = \context_module::instance($this->instance->cmid);
        assign_capability('mod/scormmakercmi5:view', CAP_PROHIBIT, $roleid, $context->id, true);

        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', $key));

        $this->assert_no_token((int)$session->id);
    }

    /**
     * The activity hidden after the launch: error 2.
     */
    public function test_hidden_activity(): void {
        [$session, $key] = $this->launch();
        set_coursemodule_visible($this->instance->cmid, 0);

        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', $key));

        $this->assert_no_token((int)$session->id);
    }

    /**
     * An access restriction by a future date: error 2.
     */
    public function test_restricted_by_date(): void {
        global $DB;
        set_config('enableavailability', 1);
        [$session, $key] = $this->launch();
        $future = max(time(), self::NOW) + 365 * DAYSECS;
        $availability = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => $future]], 'showc' => [true]]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $this->instance->cmid]);
        rebuild_course_cache($this->course->id, true);

        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', $key));

        $this->assert_no_token((int)$session->id);
    }

    /**
     * A hidden course the learner cannot see: error 2.
     */
    public function test_hidden_course(): void {
        global $DB;
        [$session, $key] = $this->launch();
        $DB->set_field('course', 'visible', 0, ['id' => $this->course->id]);
        rebuild_course_cache($this->course->id, true);

        $this->assert_error(fetch_service::ERROR_SECURITY, fetch_service::handle('POST', $key));

        $this->assert_no_token((int)$session->id);
    }

    /**
     * A busy session lock (a concurrent fetch holding it): error 3 and no token written, then the fetch works.
     */
    public function test_lock_busy(): void {
        global $CFG;
        // The database lock factories are reentrant within one connection; the file factory is not, so it stands in
        // for the second request of a real race.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        [$session, $key] = $this->launch();
        $lock = \core\lock\lock_config::get_lock_factory('mod_scormmakercmi5')->get_lock('fetch' . $session->id, 0);
        $this->assertNotFalse($lock);
        try {
            $data = $this->assert_error(fetch_service::ERROR_INTERNAL, fetch_service::handle('POST', $key, 0));
            $this->assertSame(get_string('fetcherror_internal', 'mod_scormmakercmi5'), $data['error-text']);
        } finally {
            $lock->release();
        }
        $this->assert_no_token((int)$session->id);
        $this->assertSame(['auth-token'], array_keys(json_decode(fetch_service::handle('POST', $key, 0)->body, true)));
    }

    /**
     * A relaunch that abandons the session between the checks and the write (it holds the registration lock, not
     * the fetch lock): error 1 and the token just written is revoked.
     */
    public function test_abandoned_during_fetch(): void {
        $seam = new \ReflectionProperty(fetch_service::class, 'beforewrite');
        [$session, $key] = $this->launch();
        $seam->setValue(null, function (): void {
            $this->launch();
        });
        try {
            $data = $this->assert_error(fetch_service::ERROR_EXPIRED, fetch_service::handle('POST', $key));
        } finally {
            $seam->setValue(null, null);
        }
        $this->assertSame(get_string('fetcherror_expired', 'mod_scormmakercmi5'), $data['error-text']);
        $row = $this->session((int)$session->id);
        $this->assertEquals(launcher::STATUS_ABANDONED, $row->status);
        $this->assertEquals(0, $row->tokenexpires);
        $this->assert_error(fetch_service::ERROR_EXPIRED, fetch_service::handle('POST', $key));
    }

    /**
     * An exception under the lock (a database failure, say): error 3 in JSON, no token, the failure logged without
     * its message, and the lock released — the next fetch of the same key gets the token.
     */
    public function test_exception_under_lock(): void {
        global $CFG;
        // The file lock factory is not reentrant, so a lock leaked by the failure would make the retry time out.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        $seam = new \ReflectionProperty(fetch_service::class, 'beforewrite');
        [$session, $key] = $this->launch();
        $seam->setValue(null, function () use ($key): void {
            throw new \dml_write_exception('boom ' . $key);
        });
        try {
            $response = fetch_service::handle('POST', $key, 0);
        } finally {
            $seam->setValue(null, null);
        }

        $this->assert_error(fetch_service::ERROR_INTERNAL, $response);
        $this->assertStringNotContainsString($key, $response->body);
        $this->assert_no_token((int)$session->id);
        $messages = $this->getDebuggingMessages();
        $this->resetDebugging();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('dml_write_exception', $messages[0]->message);
        $this->assertStringNotContainsString($key, $messages[0]->message);
        $this->assertStringNotContainsString('boom', $messages[0]->message);

        $this->assertSame(['auth-token'], array_keys(json_decode(fetch_service::handle('POST', $key, 0)->body, true)));
    }

    /**
     * GET (cmi5 §8.2.1 "HTTP GET is not allowed") and any other method: 405, JSON, Allow: POST, nothing written.
     */
    public function test_method_not_allowed(): void {
        [$session, $key] = $this->launch();
        foreach (['GET', 'PUT', 'HEAD', 'post', ''] as $method) {
            $response = fetch_service::handle($method, $key);
            $this->assertSame(405, $response->status, $method);
            $this->assertSame('POST', $response->headers['Allow'] ?? null);
            $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);
            $this->assertSame('application/json', $response->headers['Content-Type'] ?? null);
            $this->assertIsArray(json_decode($response->body, true));
        }
        $this->assert_no_token((int)$session->id);
        $this->assertSame(200, fetch_service::handle('POST', $key)->status);
    }

    /**
     * The response value object: JSON with unescaped slashes, no-store by default, send() writes the body only.
     */
    public function test_response(): void {
        $json = response::json(201, ['a' => 'x/y', 'b' => 'ç'], ['X-Test' => '1']);
        $this->assertSame(201, $json->status);
        $this->assertSame('{"a":"x/y","b":"ç"}', $json->body);
        $this->assertSame('application/json', $json->headers['Content-Type']);
        $this->assertSame('no-store', $json->headers['Cache-Control']);
        $this->assertSame('1', $json->headers['X-Test']);

        $plain = new response(204);
        $this->assertSame('', $plain->body);
        $this->assertSame('no-store', $plain->headers['Cache-Control']);

        $this->expectOutputString('{"a":"x/y","b":"ç"}');
        $json->send();
    }
}
