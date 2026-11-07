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
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\iso_duration;
use mod_scormmakercmi5\local\lrs\xapi_time;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\launcher::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\lms_statements::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\runtime\launch_result::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\statement_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\state_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\agent_profile::class)]
/**
 * Tests for the registration and the cmi5 launch (design §6.1, R4.1, R4.4, R4.5).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\runtime\launcher
 * @covers     \mod_scormmakercmi5\local\runtime\lms_statements
 * @covers     \mod_scormmakercmi5\local\runtime\launch_result
 * @covers     \mod_scormmakercmi5\local\lrs\statement_store
 * @covers     \mod_scormmakercmi5\local\lrs\state_store
 * @covers     \mod_scormmakercmi5\local\lrs\agent_profile
 */
final class launcher_test extends \advanced_testcase {
    /** @var string Pattern of a v4 UUID. */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var int Frozen clock, seconds. */
    private const NOW = 1790000000;

    /** @var string[] Tables a launch writes. */
    private const LAUNCH_TABLES = [
        'scormmakercmi5_session', 'scormmakercmi5_stmt', 'scormmakercmi5_state', 'scormmakercmi5_agentprof',
    ];

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Learner (lang pt_br). */
    private \stdClass $user;

    /** @var \mod_scormmakercmi5_generator Generator. */
    private \mod_scormmakercmi5_generator $generator;

    /** @var \frozen_clock Clock. */
    private \frozen_clock $clock;

    /**
     * Course, learner and frozen clock.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Off by default on a new site: the tests of external AUs opt in, as an administrator would.
        set_config('allowexternalau', 1, 'mod_scormmakercmi5');
        $this->clock = $this->mock_clock_with_frozen(self::NOW);
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        // The pt_br pack is not installed on the test site, so user_create_user() would clean the value.
        $DB->set_field('user', 'lang', 'pt_br', ['id' => $this->user->id]);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5');
    }

    /**
     * Restores the lock timeout and the lock factory.
     */
    protected function tearDown(): void {
        (new \ReflectionProperty(launcher::class, 'locktimeout'))->setValue(null, 5);
        parent::tearDown();
    }

    /**
     * Package with two AUs: AU 1 with every optional field, AU 2 with none.
     *
     * @param array $aus AUs for cmi5_xml(); default the two AUs.
     * @param array $files Extra files.
     * @param array $fields Instance fields.
     * @return \stdClass Instance row.
     */
    private function instance(array $aus = [], array $files = [], array $fields = []): \stdClass {
        $aus = $aus ?: [
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Unidade 1', 'moveOn' => 'Passed',
                'masteryScore' => '0.8', 'launchParameters' => 'p=1', 'entitlementKey' => 'chave-1'],
            ['id' => 'https://example.com/au/2', 'url' => 'dois.html', 'title' => 'Unidade 2'],
        ];
        $entries = builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml($aus), 'dois.html' => '<p>2</p>'] + $files);
        return $this->generator->create_instance(['course' => $this->course->id, 'package_entries' => $entries] + $fields);
    }

    /**
     * The course module of an instance.
     *
     * @param \stdClass $instance Instance.
     * @return \cm_info
     */
    private function cm(\stdClass $instance): \cm_info {
        return get_fast_modinfo($this->course->id)->get_cm($instance->cmid);
    }

    /**
     * An AU of the current version by sort order (1-based).
     *
     * @param \stdClass $instance Instance.
     * @param int $n Position.
     * @return \stdClass
     */
    private function au(\stdClass $instance, int $n = 1): \stdClass {
        global $DB;
        $aus = array_values($DB->get_records('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], 'sortorder'));
        return $aus[$n - 1];
    }

    /**
     * Launches an AU.
     *
     * @param \stdClass $instance Instance.
     * @param \stdClass $au AU.
     * @param string $mode Mode.
     * @param int|null $userid User (default the learner).
     * @return launch_result
     */
    private function launch(\stdClass $instance, \stdClass $au, string $mode = 'Normal', ?int $userid = null): launch_result {
        global $DB;
        $cmid = $instance->cmid;
        $instance = $DB->get_record('scormmakercmi5', ['id' => $instance->id], '*', MUST_EXIST);
        $instance->cmid = $cmid;
        return launcher::launch($this->cm($instance), $instance, (int)$au->id, $userid ?? (int)$this->user->id, $mode);
    }

    /**
     * Name/value pairs of the query of a URL, in order, decoded.
     *
     * @param string $url URL.
     * @return array list of [name, value].
     */
    private static function query_pairs(string $url): array {
        $query = parse_url(explode('#', $url, 2)[0], PHP_URL_QUERY);
        $pairs = [];
        foreach (explode('&', (string)$query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurldecode($name), rawurldecode($value)];
        }
        return $pairs;
    }

    /**
     * The decoded query as name => value.
     *
     * @param string $url URL.
     * @return array
     */
    private static function query(string $url): array {
        $out = [];
        foreach (self::query_pairs($url) as [$name, $value]) {
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Asserts that a callback throws a moodle_exception with an error code.
     *
     * @param string $code Error code.
     * @param callable $callback Callback.
     */
    private function assert_refused(string $code, callable $callback): void {
        try {
            $callback();
            $this->fail("expected {$code}");
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
        }
    }

    /**
     * Asserts that no launch data was written.
     */
    private function assert_nothing_launched(): void {
        global $DB;
        foreach (self::LAUNCH_TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }

    /**
     * Two calls give the same row; UUID v4, current version and one regau per AU.
     */
    public function test_get_or_create_registration(): void {
        global $DB;
        $instance = $this->instance();
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $again = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $this->assertEquals($reg->id, $again->id);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_reg'));
        $this->assertMatchesRegularExpression(self::UUID, $reg->registration);
        $this->assertEquals($instance->currentpkgid, $reg->pkgid);
        $this->assertEquals($this->user->id, $reg->userid);
        $this->assertEquals(0, $reg->statementcount);
        $this->assertEquals(self::NOW, $reg->timecreated);
        $auids = array_keys($DB->get_records('scormmakercmi5_au', ['pkgid' => $instance->currentpkgid], 'id'));
        $regaus = $DB->get_records('scormmakercmi5_regau', ['regid' => $reg->id], 'auid');
        $this->assertCount(2, $regaus);
        $this->assertEquals($auids, array_values(array_map(fn($r) => (int)$r->auid, $regaus)));
        $this->assertSame(2, $DB->count_records('scormmakercmi5_regau'));
    }

    /**
     * After a package replacement the old registration keeps its version; a new learner gets the new one.
     */
    public function test_registration_after_package_replaced(): void {
        global $DB;
        $instance = $this->instance();
        $old = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $v1 = (int)$instance->currentpkgid;

        $draft = $this->generator->create_draft_package(builder::minimal_entries(['cmi5.xml' => builder::cmi5_xml([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html'],
            ['id' => 'https://example.com/au/2', 'url' => 'index.html'],
            ['id' => 'https://example.com/au/3', 'url' => 'index.html'],
        ])]));
        $data = clone $instance;
        $data->instance = $instance->id;
        $data->coursemodule = $instance->cmid;
        $data->packagefile = $draft;
        unset($data->id);
        $this->assertTrue(scormmakercmi5_update_instance($data));
        $instance = $DB->get_record('scormmakercmi5', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertNotEquals($v1, (int)$instance->currentpkgid);

        $same = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $this->assertEquals($old->id, $same->id);
        $this->assertSame($v1, (int)$same->pkgid);
        $this->assertSame(2, $DB->count_records('scormmakercmi5_regau', ['regid' => $old->id]));

        $other = $this->getDataGenerator()->create_and_enrol($this->course);
        $new = launcher::get_or_create_registration($instance, (int)$other->id);
        $this->assertEquals($instance->currentpkgid, $new->pkgid);
        $this->assertSame(3, $DB->count_records('scormmakercmi5_regau', ['regid' => $new->id]));
        $this->assertNotSame($old->registration, $new->registration);
    }

    /**
     * Normal launch: session, fetch key, LaunchData, preferences, launched statement and URL.
     */
    public function test_launch_normal(): void {
        global $DB, $CFG;
        $instance = $this->instance();
        $au = $this->au($instance);
        $result = $this->launch($instance, $au);
        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $this->user->id]);
        $nowms = self::NOW * 1000;

        // Session.
        $session = $DB->get_record('scormmakercmi5_session', ['id' => $result->session->id], '*', MUST_EXIST);
        $this->assertEquals(launcher::STATUS_LAUNCHED, $session->status);
        $this->assertEquals(launcher::KIND_LAUNCH, $session->kind);
        $this->assertSame('Normal', $session->launchmode);
        $this->assertEquals($reg->id, $session->regid);
        $this->assertEquals($au->id, $session->auid);
        $this->assertEquals($this->user->id, $session->userid);
        $this->assertMatchesRegularExpression(self::UUID, $session->sessionuuid);
        $this->assertEquals($nowms, $session->timelaunchedms);
        $this->assertEquals($nowms, $session->lastactivityms);
        $this->assertNull($session->tokenhash);
        $this->assertEquals(0, $session->tokenexpires);
        $this->assertEquals(0, $session->fetchused);
        $this->assertSame($session->sessionuuid, $result->session->sessionuuid);
        $this->assertSame('AnyWindow', $result->launchmethod);

        // URL: content file, then the cmi5 parameters.
        $url = $result->url->out(false);
        $context = \context_module::instance($instance->cmid);
        $this->assertStringStartsWith(
            "{$CFG->wwwroot}/pluginfile.php/{$context->id}/mod_scormmakercmi5/content/{$instance->currentpkgid}/index.html?",
            $url
        );
        $query = self::query($url);
        $this->assertSame(['endpoint', 'fetch', 'actor', 'registration', 'activityId'], array_keys($query));
        $this->assertSame((new \moodle_url('/mod/scormmakercmi5/lrs.php/'))->out(false), $query['endpoint']);
        $this->assertStringEndsWith('/mod/scormmakercmi5/lrs.php/', $query['endpoint']);
        $this->assertMatchesRegularExpression('#^' . preg_quote($CFG->wwwroot, '#') .
            '/mod/scormmakercmi5/fetch\.php\?k=([A-Za-z0-9_-]{43})$#', $query['fetch']);
        preg_match('#k=([A-Za-z0-9_-]{43})$#', $query['fetch'], $m);
        $this->assertSame(hash('sha256', $m[1]), $session->fetchkeyhash);
        $this->assertSame(actor::for_user((int)$this->user->id), json_decode($query['actor'], true));
        $this->assertSame($reg->registration, $query['registration']);
        $this->assertSame($au->lmsactivityid, $query['activityId']);

        // LMS.LaunchData.
        $state = $DB->get_record('scormmakercmi5_state', ['stateid' => vocab::LAUNCHDATA_ID], '*', MUST_EXIST);
        $this->assertEquals(1, $state->lmsowned);
        $this->assertSame(sha1($au->lmsactivityid), $state->activityhash);
        $this->assertSame($reg->registration, $state->registration);
        $this->assertEquals($reg->id, $state->regid);
        $this->assertEquals($this->user->id, $state->userid);
        $this->assertEquals($instance->id, $state->scormmakercmi5id);
        $this->assertSame('application/json', $state->contenttype);
        $this->assertSame(sha1($state->content), $state->etag);
        $this->assertEquals($nowms, $state->updatedms);
        $data = json_decode($state->content, true);
        $this->assertSame($session->sessionuuid, $data['contextTemplate']['extensions'][vocab::EXT_SESSIONID]);
        $this->assertSame($au->publisherid, $data['contextTemplate']['contextActivities']['grouping'][0]['id']);
        $this->assertSame('Normal', $data['launchMode']);
        $this->assertSame('Passed', $data['moveOn']);
        $this->assertSame(0.8, $data['masteryScore']);
        $this->assertSame('p=1', $data['launchParameters']);
        $this->assertSame(['courseStructure' => 'chave-1'], $data['entitlementKey']);
        $returnurl = (new \moodle_url('/mod/scormmakercmi5/view.php', ['id' => $instance->cmid]))->out(false);
        $this->assertSame($returnurl, $data['returnURL']);
        $this->assertStringStartsWith('http', $data['returnURL']);
        $this->assertSame(launcher::context_template($au, $session), $data['contextTemplate']);

        // Preferences.
        $prefs = $DB->get_record('scormmakercmi5_agentprof', ['userid' => $this->user->id], '*', MUST_EXIST);
        $this->assertSame(vocab::PREFS_ID, $prefs->profileid);
        // Both properties (cmi5 11.0.0.0-5); audioPreference defaults to "on" (§11.2).
        $this->assertSame(['languagePreference' => 'pt-BR', 'audioPreference' => 'on'], json_decode($prefs->content, true));
        $this->assertSame(sha1($prefs->content), $prefs->etag);

        // Launched statement.
        $stmts = $DB->get_records('scormmakercmi5_stmt');
        $this->assertCount(1, $stmts);
        $stmt = reset($stmts);
        $this->assertEquals(1, $stmt->source);
        $this->assertEquals(2, $stmt->cmi5kind);
        $this->assertSame(vocab::VERB_LAUNCHED, $stmt->verb);
        $this->assertSame($au->lmsactivityid, $stmt->objectid);
        $this->assertSame(sha1($au->lmsactivityid), $stmt->objecthash);
        $this->assertEquals($session->id, $stmt->sessionid);
        $this->assertEquals($reg->id, $stmt->regid);
        $this->assertEquals($au->id, $stmt->auid);
        $this->assertEquals($reg->pkgid, $stmt->pkgid);
        $this->assertEquals($instance->id, $stmt->scormmakercmi5id);
        $this->assertEquals($this->user->id, $stmt->userid);
        $this->assertEquals($nowms, $stmt->timestampms);
        $this->assertEquals($nowms, $stmt->storedms);
        $this->assertNull($stmt->success);
        $this->assertNull($stmt->durationms);
        $payload = json_decode($stmt->payload, true);
        $this->assertSame($stmt->statementid, $payload['id']);
        $this->assertMatchesRegularExpression(self::UUID, $payload['id']);
        $this->assertSame(xapi_time::from_ms($nowms), $payload['timestamp']);
        $this->assertSame(xapi_time::from_ms($nowms), $payload['stored']);
        $this->assertSame(actor::lms_authority(), $payload['authority']);
        $this->assertSame('1.0.0', $payload['version']);
        $this->assertSame(actor::for_user((int)$this->user->id), $payload['actor']);
        $this->assertSame(vocab::VERB_LAUNCHED, $payload['verb']['id']);
        $this->assertSame($au->lmsactivityid, $payload['object']['id']);
        $context = $payload['context'];
        $this->assertSame($reg->registration, $context['registration']);
        $this->assertSame(vocab::CAT_CMI5, $context['contextActivities']['category'][0]['id']);
        $this->assertSame($au->publisherid, $context['contextActivities']['grouping'][0]['id']);
        $ext = $context['extensions'];
        $this->assertSame($session->sessionuuid, $ext[vocab::EXT_SESSIONID]);
        $this->assertSame('Normal', $ext[vocab::EXT_LAUNCHMODE]);
        $this->assertSame('Passed', $ext[vocab::EXT_MOVEON]);
        $this->assertSame(0.8, $ext[vocab::EXT_MASTERYSCORE]);
        $this->assertSame('p=1', $ext[vocab::EXT_LAUNCHPARAMETERS]);
        $this->assertSame(explode('?', $url)[0], $ext[vocab::EXT_LAUNCHURL], 'launch URL without the cmi5 parameters');
        $this->assertEquals(1, $DB->get_field('scormmakercmi5_reg', 'statementcount', ['id' => $reg->id]));
    }

    /**
     * Optional LaunchData fields and launched extensions are left out when the AU does not define them.
     */
    public function test_launch_without_optional_fields(): void {
        global $DB;
        $instance = $this->instance();
        $au = $this->au($instance, 2);
        $result = $this->launch($instance, $au);
        $data = json_decode($DB->get_field('scormmakercmi5_state', 'content', ['stateid' => vocab::LAUNCHDATA_ID]), true);
        $this->assertSame(['contextTemplate', 'launchMode', 'moveOn', 'returnURL'], array_keys($data));
        $this->assertSame('NotApplicable', $data['moveOn']);
        $payload = json_decode($DB->get_field('scormmakercmi5_stmt', 'payload', ['sessionid' => $result->session->id]), true);
        $this->assertSame(
            [vocab::EXT_SESSIONID, vocab::EXT_LAUNCHMODE, vocab::EXT_LAUNCHURL, vocab::EXT_MOVEON],
            array_keys($payload['context']['extensions'])
        );
    }

    /**
     * The preferences keep an existing audioPreference and follow the language of the user.
     */
    public function test_preferences_keep_audio(): void {
        global $DB;
        $DB->insert_record('scormmakercmi5_agentprof', (object)[
            'userid' => $this->user->id, 'profileid' => vocab::PREFS_ID,
            'content' => '{"languagePreference":"en-US","audioPreference":"off"}', 'etag' => 'x', 'updatedms' => 1,
        ]);
        $instance = $this->instance();
        $this->launch($instance, $this->au($instance));
        $content = $DB->get_field('scormmakercmi5_agentprof', 'content', ['userid' => $this->user->id]);
        $this->assertSame(['languagePreference' => 'pt-BR', 'audioPreference' => 'off'], json_decode($content, true));
        $this->assertSame(1, $DB->count_records('scormmakercmi5_agentprof'));
    }

    /**
     * Moodle language codes to RFC 5646 tags.
     */
    public function test_language_tags(): void {
        global $DB;
        foreach (['pt_br' => 'pt-BR', 'en' => 'en', 'es_mx' => 'es-MX', 'en_us_k12' => 'en-US'] as $lang => $tag) {
            \mod_scormmakercmi5\local\lrs\agent_profile::ensure_learner_preferences((int)$this->user->id, $lang);
            $content = $DB->get_field('scormmakercmi5_agentprof', 'content', ['userid' => $this->user->id]);
            $this->assertSame(['languagePreference' => $tag, 'audioPreference' => 'on'], json_decode($content, true), $lang);
        }
    }

    /**
     * An existing document without audioPreference, or with a value cmi5 §11.2 does not allow, gets the default "on";
     * an existing "off" is never overwritten (see test_preferences_keep_audio).
     */
    public function test_preferences_default_audio(): void {
        global $DB;
        foreach (['{"languagePreference":"en"}', '{"languagePreference":"en","audioPreference":"loud"}', 'x'] as $old) {
            $DB->delete_records('scormmakercmi5_agentprof');
            $DB->insert_record('scormmakercmi5_agentprof', (object)[
                'userid' => $this->user->id, 'profileid' => vocab::PREFS_ID,
                'content' => $old, 'etag' => 'x', 'updatedms' => 1,
            ]);
            \mod_scormmakercmi5\local\lrs\agent_profile::ensure_learner_preferences((int)$this->user->id, 'en');
            $content = $DB->get_field('scormmakercmi5_agentprof', 'content', ['userid' => $this->user->id]);
            $this->assertSame(['languagePreference' => 'en', 'audioPreference' => 'on'], json_decode($content, true), $old);
        }
    }

    /**
     * First creation race: another request inserts the row between the read and the insert; the insert is
     * refused by the unique index and becomes an update that keeps the other's audioPreference.
     */
    public function test_preferences_first_creation_race(): void {
        global $DB;
        $userid = (int)$this->user->id;
        $seam = new \ReflectionProperty(\mod_scormmakercmi5\local\lrs\agent_profile::class, 'beforeinsert');
        $seam->setValue(null, function () use ($DB, $userid) {
            $DB->insert_record('scormmakercmi5_agentprof', (object)[
                'userid' => $userid, 'profileid' => vocab::PREFS_ID,
                'content' => '{"languagePreference":"en","audioPreference":"on"}', 'etag' => 'x', 'updatedms' => 1,
            ]);
        });
        try {
            \mod_scormmakercmi5\local\lrs\agent_profile::ensure_learner_preferences($userid, 'pt_br');
        } finally {
            $seam->setValue(null, null);
        }
        $rows = $DB->get_records('scormmakercmi5_agentprof', ['userid' => $userid]);
        $this->assertCount(1, $rows);
        $this->assertSame(['languagePreference' => 'pt-BR', 'audioPreference' => 'on'], json_decode(reset($rows)->content, true));
    }

    /**
     * The same race inside a delegated transaction started like launch_locked(), and inside a real launch: the
     * refused insert must not abort the transaction (pgsql), so the writes after it and the commit succeed.
     */
    public function test_preferences_race_inside_transaction(): void {
        global $DB;
        $userid = (int)$this->user->id;
        $seam = new \ReflectionProperty(\mod_scormmakercmi5\local\lrs\agent_profile::class, 'beforeinsert');
        $competing = function (int $uid) use ($DB) {
            return function () use ($DB, $uid) {
                $DB->insert_record('scormmakercmi5_agentprof', (object)[
                    'userid' => $uid, 'profileid' => vocab::PREFS_ID,
                    'content' => '{"languagePreference":"en","audioPreference":"on"}', 'etag' => 'x', 'updatedms' => 1,
                ]);
            };
        };
        try {
            // Direct: start_delegated_transaction, conflict, then more work in the same transaction and commit.
            $seam->setValue(null, $competing($userid));
            $transaction = $DB->start_delegated_transaction();
            \mod_scormmakercmi5\local\lrs\agent_profile::ensure_learner_preferences($userid, 'pt_br');
            $this->assertSame(1, $DB->count_records('scormmakercmi5_agentprof', ['userid' => $userid]));
            $DB->set_field('scormmakercmi5_agentprof', 'updatedms', 7, ['userid' => $userid]);
            $transaction->allow_commit();
            $this->assertEquals(7, $DB->get_field('scormmakercmi5_agentprof', 'updatedms', ['userid' => $userid]));

            // Through launch(): the launched statement is written after the refused insert.
            $other = $this->getDataGenerator()->create_and_enrol($this->course);
            $seam->setValue(null, $competing((int)$other->id));
            $instance = $this->instance();
            $result = $this->launch($instance, $this->au($instance), 'Normal', (int)$other->id);
        } finally {
            $seam->setValue(null, null);
        }
        $this->assertTrue($DB->record_exists(
            'scormmakercmi5_stmt',
            ['sessionid' => $result->session->id, 'verb' => vocab::VERB_LAUNCHED]
        ));
        $content = $DB->get_field('scormmakercmi5_agentprof', 'content', ['userid' => $other->id]);
        $this->assertSame('on', json_decode($content, true)['audioPreference']);
    }

    /**
     * URL of the AU: original query and fragment kept, path encoded, subfolders, cmi5 parameters before the #.
     *
     * @dataProvider url_provider
     * @param string $auurl url of the AU in cmi5.xml.
     * @param string $file File of the package.
     * @param string $path Expected encoded path after the pkg id.
     * @param array $original Expected original query pairs.
     * @param string|null $fragment Expected fragment.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('url_provider')]
    public function test_launch_url(string $auurl, string $file, string $path, array $original, ?string $fragment): void {
        global $CFG;
        $instance = $this->instance([['id' => 'https://example.com/au/1', 'url' => $auurl]], [$file => '<p>x</p>']);
        $au = $this->au($instance);
        $url = $this->launch($instance, $au)->url->out(false);
        $context = \context_module::instance($instance->cmid);
        $base = "{$CFG->wwwroot}/pluginfile.php/{$context->id}/mod_scormmakercmi5/content/{$instance->currentpkgid}/{$path}";
        $this->assertSame($base, explode('?', $url, 2)[0]);
        $pairs = self::query_pairs($url);
        $this->assertSame($original, array_slice($pairs, 0, count($original)));
        $this->assertSame(
            ['endpoint', 'fetch', 'actor', 'registration', 'activityId'],
            array_column(array_slice($pairs, count($original)), 0)
        );
        $this->assertSame($fragment, parse_url($url, PHP_URL_FRAGMENT));
        $this->assertStringNotContainsString(' ', $url);
        if ($fragment !== null) {
            $this->assertStringEndsWith('#' . $fragment, $url);
        }
    }

    /**
     * AU URLs.
     *
     * @return array
     */
    public static function url_provider(): array {
        return [
            'query' => ['aula.html?lang=pt&x=a%20b', 'aula.html', 'aula.html', [['lang', 'pt'], ['x', 'a b']], null],
            'fragment' => ['aula.html#inicio', 'aula.html', 'aula.html', [], 'inicio'],
            'query and fragment' => ['aula.html?a=1#sec/2', 'aula.html', 'aula.html', [['a', '1']], 'sec/2'],
            'space' => ['minha aula.html', 'minha aula.html', 'minha%20aula.html', [], null],
            'encoded space' => ['minha%20aula.html?q=1', 'minha aula.html', 'minha%20aula.html', [['q', '1']], null],
            'subfolder' => ['sub/dir/aula.html?x=1', 'sub/dir/aula.html', 'sub/dir/aula.html', [['x', '1']], null],
            'dot segments' => ['sub/../aula.html', 'aula.html', 'aula.html', [], null],
            'plus in query' => ['aula.html?t=a+b', 'aula.html', 'aula.html', [['t', 'a b']], null],
            'fragment with space' => ['aula.html#a b', 'aula.html', 'aula.html', [], 'a%20b'],
        ];
    }

    /**
     * An AU outside the package launches at its own https URL: the original query, then the cmi5 parameters (each one
     * encoded), the fragment last; no pluginfile. The launched statement carries the URL without the cmi5 parameters.
     */
    public function test_launch_external_au(): void {
        global $DB;
        $auurl = 'https://cursos.exemplo.com/au/index.html?x=1#y';
        $instance = $this->instance([['id' => 'https://example.com/au/1', 'url' => $auurl]]);
        $au = $this->au($instance);
        $this->assertSame('https://cursos.exemplo.com', $au->auorigin);
        $result = $this->launch($instance, $au);
        $url = $result->url->out(false);

        $this->assertStringStartsWith('https://cursos.exemplo.com/au/index.html?x=1&endpoint=', $url);
        $this->assertStringNotContainsString('pluginfile', $url);
        $this->assertStringEndsWith('#y', $url);
        $this->assertSame(1, substr_count($url, '#'));
        $pairs = self::query_pairs($url);
        $this->assertSame(['x', 'endpoint', 'fetch', 'actor', 'registration', 'activityId'], array_column($pairs, 0));
        $query = self::query($url);
        $this->assertSame((new \moodle_url('/mod/scormmakercmi5/lrs.php/'))->out(false), $query['endpoint']);
        $this->assertSame(actor::for_user((int)$this->user->id), json_decode($query['actor'], true));
        $this->assertSame($au->lmsactivityid, $query['activityId']);
        // The JSON actor and the URLs travel encoded: no raw "{", '"', "?" or "&" of a value leaks into the query.
        $rawquery = parse_url($url, PHP_URL_QUERY);
        $this->assertStringNotContainsString('{', $rawquery);
        $this->assertStringNotContainsString('"', $rawquery);
        $this->assertCount(6, explode('&', $rawquery));
        foreach (explode('&', $rawquery) as $pair) {
            $this->assertSame(1, substr_count($pair, '='), $pair);
        }

        $where = ['sessionid' => $result->session->id, 'verb' => vocab::VERB_LAUNCHED];
        $statement = $DB->get_record('scormmakercmi5_stmt', $where, '*', MUST_EXIST);
        $payload = json_decode($statement->payload, true);
        $this->assertSame($auurl, $payload['context']['extensions'][vocab::EXT_LAUNCHURL]);
    }

    /**
     * An AU stored with a host refused today (accepted by the first rule of 0.3.0), or an absolute url without an origin
     * (restored with allowexternalau off), is not launched: the readable err_externalauoff for the learner, and no
     * session.
     */
    public function test_launch_external_au_refused_today(): void {
        global $DB;
        $instance = $this->instance([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html']]);
        $au = $this->au($instance);
        $rows = [['https://127.1/au/index.html', 'https://127.1'], ['https://cursos.exemplo.com/au/index.html', null]];
        foreach ($rows as [$url, $origin]) {
            $DB->update_record('scormmakercmi5_au', (object)['id' => $au->id, 'url' => $url, 'auorigin' => $origin]);
            try {
                $this->launch($instance, $DB->get_record('scormmakercmi5_au', ['id' => $au->id]));
                $this->fail($url);
            } catch (\moodle_exception $e) {
                $this->assertSame('err_externalauoff', $e->errorcode, $url);
            }
        }
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session', ['auid' => $au->id, 'kind' => launcher::KIND_LAUNCH]));
    }

    /**
     * Final review m-1 (ruling): turning allowexternalau off also stops the launch of an AU outside the package that is
     * already on the site, origin and all: the learner gets the readable err_externalauoff and no session is created.
     * Turned on again, the same AU launches. An AU of the package never depends on the switch.
     */
    public function test_launch_existing_external_au_when_disabled(): void {
        global $DB;
        $instance = $this->instance([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html']]);
        $au = $this->au($instance);
        $this->assertSame('https://cursos.exemplo.com', $au->auorigin);
        set_config('allowexternalau', 0, 'mod_scormmakercmi5');
        try {
            $this->launch($instance, $au);
            $this->fail('launched');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_externalauoff', $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
            $this->assertSame(get_string('err_externalauoff', 'mod_scormmakercmi5'), $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session', ['auid' => $au->id, 'kind' => launcher::KIND_LAUNCH]));

        set_config('allowexternalau', 1, 'mod_scormmakercmi5');
        $this->assertNotEmpty($this->launch($instance, $au)->session->id);

        set_config('allowexternalau', 0, 'mod_scormmakercmi5');
        $local = $this->instance();
        $this->assertNotEmpty($this->launch($local, $this->au($local))->session->id);
    }

    /**
     * A row whose url no longer gives its stored origin (changed in the database) is not launched.
     */
    public function test_launch_external_au_origin_mismatch(): void {
        global $DB;
        $instance = $this->instance([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/index.html']]);
        $au = $this->au($instance);
        $DB->set_field('scormmakercmi5_au', 'url', 'https://outro.exemplo.com/au/index.html', ['id' => $au->id]);
        $this->expectException(\coding_exception::class);
        $this->launch($instance, $DB->get_record('scormmakercmi5_au', ['id' => $au->id]));
    }

    /**
     * A launch with an active session of the registration (any AU, second tab) abandons it with the AU session time.
     *
     * @dataProvider open_status_provider
     * @param int $status Status of the open session.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('open_status_provider')]
    public function test_relaunch_abandons_open_session(int $status): void {
        global $DB;
        $instance = $this->instance();
        $au = $this->au($instance);
        $au2 = $this->au($instance, 2);
        $first = $this->launch($instance, $au)->session;
        $DB->update_record('scormmakercmi5_session', (object)[
            'id' => $first->id, 'status' => $status, 'tokenhash' => str_repeat('a', 64), 'tokenexpires' => self::NOW + 3600,
        ]);
        // Last statement of the AU 65 s after launched; a statement of another session does not count.
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $first->regid]);
        $this->insert_au_statement($reg, $au, $first, self::NOW * 1000 + 30000);
        $this->insert_au_statement($reg, $au, $first, self::NOW * 1000 + 65000);
        $this->insert_au_statement($reg, $au, launcher::create_synthetic_session($reg, (int)$au->id), self::NOW * 1000 + 99000);
        $this->clock->bump(100);

        // Launching ANOTHER AU of the same registration abandons it too (cmi5 §9.3.6).
        $second = $this->launch($instance, $au2)->session;
        $old = $DB->get_record('scormmakercmi5_session', ['id' => $first->id]);
        $this->assertEquals(launcher::STATUS_ABANDONED, $old->status);
        $this->assertEquals(0, $old->tokenexpires);
        $this->assertEquals(65000, $old->durationms);
        $this->assertEquals((self::NOW + 100) * 1000, $old->timeendedms);
        $this->assertEquals(launcher::STATUS_LAUNCHED, $DB->get_field('scormmakercmi5_session', 'status', ['id' => $second->id]));

        $abandoned = $DB->get_records('scormmakercmi5_stmt', ['verb' => vocab::VERB_ABANDONED]);
        $this->assertCount(1, $abandoned);
        $stmt = reset($abandoned);
        $this->assertEquals($first->id, $stmt->sessionid);
        $this->assertEquals($au->id, $stmt->auid);
        $this->assertEquals(1, $stmt->source);
        $this->assertEquals(65000, $stmt->durationms);
        $this->assertEquals((self::NOW + 100) * 1000, $stmt->timestampms);
        $payload = json_decode($stmt->payload, true);
        $this->assertSame('PT1M5S', $payload['result']['duration']);
        $this->assertSame($first->sessionuuid, $payload['context']['extensions'][vocab::EXT_SESSIONID]);
        $this->assertSame($reg->registration, $payload['context']['registration']);
        $this->assertSame(vocab::CAT_CMI5, $payload['context']['contextActivities']['category'][0]['id']);
        $this->assertSame($au->publisherid, $payload['context']['contextActivities']['grouping'][0]['id']);
        $this->assertSame($au->lmsactivityid, $payload['object']['id']);
        $this->assertSame(vocab::VERB_LAUNCHED, $DB->get_field('scormmakercmi5_stmt', 'verb', ['sessionid' => $second->id]));

        // Relaunching the same AU abandons the second session; a session of another registration is untouched.
        $otheruser = $this->getDataGenerator()->create_and_enrol($this->course);
        $foreign = $this->launch($instance, $au2, 'Normal', (int)$otheruser->id)->session;
        $this->launch($instance, $au2);
        $this->assertEquals(launcher::STATUS_ABANDONED, $DB->get_field('scormmakercmi5_session', 'status', ['id' => $second->id]));
        $this->assertEquals(launcher::STATUS_LAUNCHED, $DB->get_field('scormmakercmi5_session', 'status', ['id' => $foreign->id]));
    }

    /**
     * A statement timestamp in the future (the AU's clock) never makes the duration exceed now − launched.
     */
    public function test_abandoned_duration_capped_at_now(): void {
        global $DB;
        $instance = $this->instance();
        $au = $this->au($instance);
        $first = $this->launch($instance, $au)->session;
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $first->regid]);
        $this->insert_au_statement($reg, $au, $first, (self::NOW + 10 * HOURSECS) * 1000);
        $this->clock->bump(100);
        $this->launch($instance, $au);
        $this->assertEquals(100000, $DB->get_field('scormmakercmi5_session', 'durationms', ['id' => $first->id]));
        $payload = json_decode($DB->get_field(
            'scormmakercmi5_stmt',
            'payload',
            ['verb' => vocab::VERB_ABANDONED, 'sessionid' => $first->id]
        ), true);
        $this->assertSame('PT1M40S', $payload['result']['duration']);
    }

    /**
     * Open statuses.
     *
     * @return array
     */
    public static function open_status_provider(): array {
        return ['launched' => [launcher::STATUS_LAUNCHED], 'initialized' => [launcher::STATUS_INITIALIZED]];
    }

    /**
     * A session the AU never wrote to is abandoned with duration zero; a terminated one is left alone.
     */
    public function test_relaunch_duration_zero_and_terminated_untouched(): void {
        global $DB;
        $instance = $this->instance();
        $au = $this->au($instance);
        $first = $this->launch($instance, $au)->session;
        $this->clock->bump(10);
        $this->launch($instance, $au);
        $this->assertEquals(0, $DB->get_field('scormmakercmi5_session', 'durationms', ['id' => $first->id]));
        $payload = json_decode($DB->get_field(
            'scormmakercmi5_stmt',
            'payload',
            ['verb' => vocab::VERB_ABANDONED, 'sessionid' => $first->id]
        ), true);
        $this->assertSame('PT0S', $payload['result']['duration']);

        $DB->set_field('scormmakercmi5_session', 'status', launcher::STATUS_TERMINATED, []);
        $this->launch($instance, $au);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_stmt', ['verb' => vocab::VERB_ABANDONED]));
    }

    /**
     * Stores a statement of the AU in a session.
     *
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU.
     * @param \stdClass $session Session.
     * @param int $timestampms Timestamp.
     */
    private function insert_au_statement(\stdClass $reg, \stdClass $au, \stdClass $session, int $timestampms): void {
        global $DB;
        $DB->insert_record('scormmakercmi5_stmt', (object)[
            'scormmakercmi5id' => $reg->scormmakercmi5id, 'pkgid' => $reg->pkgid, 'regid' => $reg->id,
            'auid' => $au->id, 'userid' => $reg->userid, 'sessionid' => $session->id,
            'statementid' => \core\uuid::generate(), 'source' => 0, 'verb' => vocab::VERB_EXPERIENCED,
            'objecthash' => sha1($au->lmsactivityid), 'objectid' => $au->lmsactivityid, 'cmi5kind' => 1,
            'timestampms' => $timestampms, 'storedms' => $timestampms, 'payload' => '{}',
        ]);
    }

    /**
     * Browse needs browsemode; Review needs reviewmode or a satisfied AU; unknown modes are refused.
     */
    public function test_modes(): void {
        global $DB;
        $instance = $this->instance();
        $au = $this->au($instance);
        $this->assert_refused('err_mode', fn() => $this->launch($instance, $au, 'Browse'));
        $this->assert_refused('err_mode', fn() => $this->launch($instance, $au, 'Review'));
        $this->assert_refused('err_mode', fn() => $this->launch($instance, $au, 'normal'));
        $this->assert_nothing_launched();

        $reg = $DB->get_record('scormmakercmi5_reg', ['scormmakercmi5id' => $instance->id, 'userid' => $this->user->id]);
        $DB->set_field('scormmakercmi5_regau', 'satisfied', 1, ['regid' => $reg->id, 'auid' => $au->id]);
        $this->assertSame('Review', $this->launch($instance, $au, 'Review')->session->launchmode);
        // AU 2 has no moveOn (NotApplicable): satisfied since the registration was created (R6.3), so Review is offered.
        $this->assertSame('Review', $this->launch($instance, $this->au($instance, 2), 'Review')->session->launchmode);

        $DB->update_record('scormmakercmi5', (object)['id' => $instance->id, 'browsemode' => 1, 'reviewmode' => 1]);
        $this->assertSame('Browse', $this->launch($instance, $au, 'Browse')->session->launchmode);
        $this->assertSame('Review', $this->launch($instance, $this->au($instance, 2), 'Review')->session->launchmode);
        $data = json_decode($DB->get_field(
            'scormmakercmi5_state',
            'content',
            ['stateid' => vocab::LAUNCHDATA_ID, 'activityhash' => sha1($au->lmsactivityid)]
        ), true);
        $this->assertSame('Browse', $data['launchMode']);
    }

    /**
     * An AU of another instance, or of a version other than the one of the registration, is refused.
     */
    public function test_au_not_in_package(): void {
        global $DB;
        $instance = $this->instance();
        $otherinstance = $this->instance();
        $this->assert_refused('err_aunotinpkg', fn() => $this->launch($instance, $this->au($otherinstance)));
        $this->assert_refused('err_aunotinpkg', function () use ($instance) {
            $this->launch($instance, (object)['id' => 999999]);
        });
        $this->assert_nothing_launched();
        $this->assertSame(0, $DB->count_records('scormmakercmi5_reg'), 'no registration for a foreign AU');

        // The learner is pinned to v1; an AU of v2 is not in the package of the registration.
        launcher::get_or_create_registration($instance, (int)$this->user->id);
        $v1au = $this->au($instance);
        $draft = $this->generator->create_draft_package(builder::minimal_entries([
            'cmi5.xml' => builder::cmi5_xml([['id' => 'https://example.com/au/9', 'url' => 'index.html']]),
        ]));
        $data = clone $instance;
        $data->instance = $instance->id;
        $data->coursemodule = $instance->cmid;
        $data->packagefile = $draft;
        unset($data->id);
        scormmakercmi5_update_instance($data);
        $instance = $DB->get_record('scormmakercmi5', ['id' => $instance->id]);
        $instance->cmid = $data->coursemodule;
        $v2au = $this->au($instance);
        $this->assert_refused('err_aunotinpkg', fn() => $this->launch($instance, $v2au));
        $this->assertSame(0, $DB->count_records('scormmakercmi5_session'));
        $this->assertNotEmpty($this->launch($instance, $v1au)->session->id);

        $newuser = $this->getDataGenerator()->create_and_enrol($this->course);
        $this->assert_refused('err_aunotinpkg', fn() => $this->launch($instance, $v1au, 'Normal', (int)$newuser->id));
    }

    /**
     * A busy registration lock gives err_locktimeout and writes nothing.
     */
    public function test_lock_timeout(): void {
        global $CFG;
        // The database lock factories are reentrant within one connection; the file factory is not.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        (new \ReflectionProperty(launcher::class, 'locktimeout'))->setValue(null, 0);
        $instance = $this->instance();
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $lock = \core\lock\lock_config::get_lock_factory('mod_scormmakercmi5')->get_lock('reg' . $reg->id, 0);
        $this->assertNotFalse($lock);
        try {
            $this->assert_refused('err_locktimeout', fn() => $this->launch($instance, $this->au($instance)));
        } finally {
            $lock->release();
        }
        $this->assert_nothing_launched();
        $this->assertNotEmpty($this->launch($instance, $this->au($instance))->session->id);
    }

    /**
     * A synthetic session is terminated at once and has no key.
     */
    public function test_create_synthetic_session(): void {
        $instance = $this->instance();
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $au = $this->au($instance);
        $session = launcher::create_synthetic_session($reg, (int)$au->id);
        $this->assertEquals(launcher::KIND_SYNTHETIC, $session->kind);
        $this->assertEquals(launcher::STATUS_TERMINATED, $session->status);
        $this->assertMatchesRegularExpression(self::UUID, $session->sessionuuid);
        $this->assertNull($session->fetchkeyhash);
        $this->assertNull($session->tokenhash);
        $this->assertEquals(self::NOW * 1000, $session->timeendedms);
        $this->assertEquals($au->id, $session->auid);
        $this->assertEquals($this->user->id, $session->userid);
    }

    /**
     * The abandoned statement built by lms_statements has the duration in ISO 8601.
     */
    public function test_abandoned_statement(): void {
        $instance = $this->instance();
        $reg = launcher::get_or_create_registration($instance, (int)$this->user->id);
        $au = $this->au($instance);
        $session = launcher::create_synthetic_session($reg, (int)$au->id);
        $statement = lms_statements::abandoned($reg, $au, $session, launcher::context_template($au, $session), 3723500);
        $this->assertSame(vocab::VERB_ABANDONED, $statement['verb']['id']);
        $this->assertSame(iso_duration::from_ms(3723500), $statement['result']['duration']);
        $this->assertSame(['id', 'timestamp', 'actor', 'verb', 'object', 'result', 'context'], array_keys($statement));
    }
}
