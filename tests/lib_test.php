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

namespace mod_scormmakercmi5;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/cm_api.php');
require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/package_builder.php');

use mod_scormmakercmi5\local\package\importer;
use mod_scormmakercmi5_test_package_builder as builder;

#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_supports')]
#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\instance_manager::class)]
/**
 * Tests for the instance callbacks of lib.php (design §4.2, §10.1, R1.1, R1.5).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::scormmakercmi5_supports
 * @covers     ::scormmakercmi5_add_instance
 * @covers     ::scormmakercmi5_update_instance
 * @covers     ::scormmakercmi5_delete_instance
 * @covers     \mod_scormmakercmi5\local\instance_manager
 */
final class lib_test extends \advanced_testcase {
    /** @var string Pattern of a v4 UUID. */
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    /** @var string Pattern of the generated course IRI. */
    private const COURSE_IRI = '#^https?://[^/]+(/[^/]+)*/mod/scormmakercmi5/xapi/course/' . self::UUID . '$#';

    /** @var string[] Every table that holds data of an instance. */
    private const TABLES = [
        'scormmakercmi5_stmt', 'scormmakercmi5_state', 'scormmakercmi5_session', 'scormmakercmi5_regblock',
        'scormmakercmi5_regau', 'scormmakercmi5_reg', 'scormmakercmi5_itemdef', 'scormmakercmi5_pkgfile',
        'scormmakercmi5_au', 'scormmakercmi5_block', 'scormmakercmi5_pkg',
    ];

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \mod_scormmakercmi5_generator Module generator. */
    private \mod_scormmakercmi5_generator $generator;

    /**
     * Creates a course, logs in as admin and loads the generator.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Off by default on a new site: the tests of external AUs opt in, as an administrator would.
        set_config('allowexternalau', 1, 'mod_scormmakercmi5');
        $this->course = $this->getDataGenerator()->create_course();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_scormmakercmi5');
    }

    /**
     * Restores the lock timeout of the importer.
     */
    protected function tearDown(): void {
        (new \ReflectionProperty(importer::class, 'locktimeout'))->setValue(null, 30);
        parent::tearDown();
    }

    /**
     * Entries of a package whose course title is "Curso X".
     *
     * @param array $aus AUs for cmi5_xml().
     * @return array path => content.
     */
    private static function entries(array $aus = [['id' => 'https://example.com/au/1', 'url' => 'index.html']]): array {
        return builder::minimal_entries([
            'cmi5.xml' => builder::cmi5_xml($aus, ['title' => 'Curso X', 'description' => 'Descrição <X>']),
            'unidade2.html' => '<!doctype html><title>2</title>',
        ]);
    }

    /**
     * Form data for update_instance(), as update_moduleinfo() passes it.
     *
     * @param \stdClass $instance Instance row.
     * @param int $draftitemid Draft item id of the package file manager.
     * @return \stdClass
     */
    private static function update_data(\stdClass $instance, int $draftitemid): \stdClass {
        $data = clone $instance;
        $data->instance = $instance->id;
        $data->coursemodule = get_coursemodule_from_instance('scormmakercmi5', $instance->id)->id;
        $data->packagefile = $draftitemid;
        unset($data->id);
        return $data;
    }

    /**
     * Name and intro come from cmi5.xml when left empty; currentpkgid and lmscourseiri are set.
     */
    public function test_add_instance_from_package(): void {
        global $DB;
        $before = time();
        $instance = $this->generator->create_instance([
            'course' => $this->course->id,
            'name' => '',
            'intro' => '',
            'package_entries' => self::entries(),
        ]);
        $row = $DB->get_record('scormmakercmi5', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('Curso X', $row->name);
        $this->assertStringContainsString('Descrição &lt;X&gt;', $row->intro);
        $this->assertSame((string)FORMAT_HTML, (string)$row->introformat);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['scormmakercmi5id' => $row->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$pkg->versionnum);
        $this->assertSame((int)$pkg->id, (int)$row->currentpkgid);
        $this->assertMatchesRegularExpression(self::COURSE_IRI, $row->lmscourseiri);
        $this->assertGreaterThanOrEqual($before, (int)$row->timecreated);
        $this->assertSame('Curso X', get_coursemodule_from_instance('scormmakercmi5', $row->id)->name);
    }

    /**
     * A name and an intro typed by the teacher are kept.
     */
    public function test_add_instance_keeps_given_name(): void {
        global $DB;
        $instance = $this->generator->create_instance([
            'course' => $this->course->id,
            'name' => 'Meu nome',
            'intro' => '<p>Minha descrição</p>',
            'package_entries' => self::entries(),
        ]);
        $row = $DB->get_record('scormmakercmi5', ['id' => $instance->id]);
        $this->assertSame('Meu nome', $row->name);
        $this->assertSame('<p>Minha descrição</p>', $row->intro);
    }

    /**
     * Two instances never share the course IRI.
     */
    public function test_course_iri_is_unique(): void {
        $a = $this->generator->create_instance(['course' => $this->course->id]);
        $b = $this->generator->create_instance(['course' => $this->course->id]);
        $this->assertNotSame($a->lmscourseiri, $b->lmscourseiri);
    }

    /**
     * Without a package the instance is not created.
     */
    public function test_add_instance_without_package(): void {
        global $DB;
        $this->preventResetByRollback();
        $draftitemid = file_get_unused_draft_itemid();
        try {
            $this->getDataGenerator()->create_module('scormmakercmi5', [
                'course' => $this->course->id,
                'packagefile' => $draftitemid,
            ]);
            $this->fail('Expected err_nopackage');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_nopackage', $e->errorcode);
        }
        // The caller add_moduleinfo() runs add_instance() in its transaction; in a request the exception handler rolls it back.
        $this->assertTrue($DB->is_transaction_started());
        $DB->force_transaction_rollback();
        $this->assertSame(0, $DB->count_records('scormmakercmi5'));
    }

    /**
     * A package larger than the upload limit of the course is refused with err_toolarge, and nothing is created: no
     * instance, no package version, no stored file (Plugins Directory review, issue 3).
     */
    public function test_add_instance_package_over_course_upload_limit(): void {
        global $DB;
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course(['maxbytes' => 100]);
        $draftitemid = $this->generator->create_draft_package(self::entries());
        try {
            $this->getDataGenerator()->create_module('scormmakercmi5', [
                'course' => $course->id,
                'packagefile' => $draftitemid,
            ]);
            $this->fail('Expected err_toolarge');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_toolarge', $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
            $expected = get_string('err_toolarge', 'mod_scormmakercmi5', display_size(100));
            $this->assertStringContainsString($expected, $e->getMessage());
        }
        // The caller add_moduleinfo() runs add_instance() in its transaction; in a request the exception handler rolls it back.
        $this->assertTrue($DB->is_transaction_started());
        $DB->force_transaction_rollback();
        $this->assertSame(0, $DB->count_records('scormmakercmi5'));
        $this->assertSame(0, $DB->count_records('scormmakercmi5_pkg'));
        $this->assertSame(0, $DB->count_records('files', ['component' => 'mod_scormmakercmi5']));

        // The same package under the limit of another course is accepted.
        $instance = $this->generator->create_instance(['course' => $this->course->id, 'package_entries' => self::entries()]);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
    }

    /**
     * A package whose cmi5.xml has a blank course title gives the activity the name of the module, a language
     * string, never a literal (Plugins Directory review, issue 5).
     */
    public function test_add_instance_default_name_from_language_string(): void {
        global $DB;
        $instance = $this->generator->create_instance([
            'course' => $this->course->id,
            'name' => '',
            'package_entries' => builder::minimal_entries([
                'cmi5.xml' => builder::cmi5_xml(
                    [['id' => 'https://example.com/au/1', 'url' => 'index.html']],
                    ['title' => ' ', 'description' => 'D']
                ),
            ]),
        ]);
        $expected = get_string('modulename', 'mod_scormmakercmi5');
        $this->assertSame($expected, $DB->get_field('scormmakercmi5', 'name', ['id' => $instance->id]));
        $this->assertSame($expected, get_coursemodule_from_instance('scormmakercmi5', $instance->id)->name);
    }

    /**
     * A different zip creates version 2 and moves currentpkgid; registrations keep their version (R1.5).
     */
    public function test_update_instance_replaces_package(): void {
        global $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id, 'package_entries' => self::entries()]);
        $v1 = (int)$instance->currentpkgid;
        $user = $this->getDataGenerator()->create_user();
        $reg = $this->generator->create_registration(['scormmakercmi5id' => $instance->id, 'userid' => $user->id]);
        $this->assertSame($v1, (int)$reg->pkgid);

        $draft = $this->generator->create_draft_package(self::entries([
            ['id' => 'https://example.com/au/1', 'url' => 'index.html', 'title' => 'Unidade 1'],
            ['id' => 'https://example.com/au/2', 'url' => 'unidade2.html', 'title' => 'Unidade 2'],
        ]));
        $sink = $this->redirectEvents();
        $this->assertTrue(scormmakercmi5_update_instance(self::update_data($instance, $draft)));
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof \mod_scormmakercmi5\event\package_replaced
        ));
        $sink->close();

        $row = $DB->get_record('scormmakercmi5', ['id' => $instance->id]);
        $v2 = $DB->get_record('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id, 'versionnum' => 2], '*', MUST_EXIST);
        $this->assertSame((int)$v2->id, (int)$row->currentpkgid);
        $this->assertSame(2, $DB->count_records('scormmakercmi5_au', ['pkgid' => $v2->id]));
        $this->assertSame($v1, (int)$DB->get_field('scormmakercmi5_reg', 'pkgid', ['id' => $reg->id]));
        $this->assertTrue($DB->record_exists('scormmakercmi5_pkg', ['id' => $v1]), 'old version kept');

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame((int)$v2->id, (int)$event->objectid);
        $this->assertSame($v1, (int)$event->other['oldpkgid']);
        $this->assertSame(2, (int)$event->other['versionnum']);
        $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id);
        $this->assertSame(\context_module::instance($cm->id)->id, (int)$event->contextid);
        $this->assertNotEmpty($event->get_name());
        $this->assertInstanceOf(\moodle_url::class, $event->get_url());
    }

    /**
     * The same zip (same contenthash) creates no version; an empty draft keeps the package too.
     */
    public function test_update_instance_same_package(): void {
        global $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_scormmakercmi5', 'package', $instance->currentpkgid, '', false);
        $zip = make_request_directory() . '/same.zip';
        reset($files)->copy_content_to($zip);

        $sink = $this->redirectEvents();
        $data = self::update_data($instance, $this->generator->create_draft_from_path($zip));
        $data->name = 'Renomeada';
        $this->assertTrue(scormmakercmi5_update_instance($data));
        $this->assertTrue(scormmakercmi5_update_instance(self::update_data($instance, file_get_unused_draft_itemid())));
        $replaced = array_filter($sink->get_events(), static fn($e) => $e instanceof \mod_scormmakercmi5\event\package_replaced);
        $sink->close();

        $this->assertSame([], $replaced);
        $this->assertSame(1, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
        $this->assertSame((int)$instance->currentpkgid, $this->current_pkgid($instance));
    }

    /**
     * An empty name on edit keeps the saved one, or takes the title of the new package.
     */
    public function test_update_instance_empty_name(): void {
        global $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id, 'name' => 'Antes']);
        $data = self::update_data($instance, file_get_unused_draft_itemid());
        $data->name = '  ';
        scormmakercmi5_update_instance($data);
        $this->assertSame('Antes', $DB->get_field('scormmakercmi5', 'name', ['id' => $instance->id]));

        $data = self::update_data($instance, $this->generator->create_draft_package(self::entries()));
        $data->name = '';
        scormmakercmi5_update_instance($data);
        $this->assertSame('Curso X', $DB->get_field('scormmakercmi5', 'name', ['id' => $instance->id]));
    }

    /**
     * Replacing the package needs only moodle/course:manageactivities, but a new package with an AU outside it (an https
     * address the learners are sent to) also needs mod/scormmakercmi5:addinstance in the course (Task 28, B-2).
     */
    public function test_update_instance_external_au_needs_addinstance(): void {
        global $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id, 'package_entries' => self::entries()]);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $coursecontext = \context_course::instance($this->course->id);
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/scormmakercmi5:addinstance', CAP_PROHIBIT, $roleid, $coursecontext->id, true);
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/course:manageactivities', $coursecontext));
        $this->assertFalse(has_capability('mod/scormmakercmi5:addinstance', $coursecontext));

        $external = $this->generator->create_draft_package(builder::minimal_entries([
            'cmi5.xml' => builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/']]),
        ]));
        try {
            scormmakercmi5_update_instance(self::update_data($instance, $external));
            $this->fail('Expected err_externalaucapability');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_externalaucapability', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
        $this->assertSame((int)$instance->currentpkgid, $this->current_pkgid($instance));

        // A package with every AU inside it keeps the documented rule: manageactivities is enough.
        $this->assertTrue(scormmakercmi5_update_instance(self::update_data($instance, $this->generator->create_draft_package(
            self::entries([['id' => 'https://example.com/au/1', 'url' => 'unidade2.html']])
        ))));
        $this->assertSame(2, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
        $this->assertNotSame((int)$instance->currentpkgid, $this->current_pkgid($instance));

        // With addinstance the external package is accepted.
        $this->setAdminUser();
        $this->assertTrue(scormmakercmi5_update_instance(self::update_data($instance, $this->generator->create_draft_package(
            builder::minimal_entries([
                'cmi5.xml' => builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'https://cursos.exemplo.com/au/']]),
            ])
        ))));
        $this->assertSame(3, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
    }

    /**
     * An invalid new package changes nothing.
     */
    public function test_update_instance_invalid_package(): void {
        global $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id]);
        $draft = $this->generator->create_draft_package(builder::minimal_entries([
            'cmi5.xml' => builder::cmi5_xml([['id' => 'https://example.com/au/1', 'url' => 'http://example.com/au']]),
        ]));
        try {
            scormmakercmi5_update_instance(self::update_data($instance, $draft));
            $this->fail('Expected err_auabsoluteurl');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_auabsoluteurl', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
        $this->assertSame((int)$instance->currentpkgid, $this->current_pkgid($instance));
    }

    /**
     * Two imports into one instance at the same time: the second waits for the lock and, when it cannot get it,
     * fails with a clean message and creates nothing (review M5 of Task 6).
     */
    public function test_update_instance_while_another_import_runs(): void {
        global $CFG, $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id]);
        $draft = $this->generator->create_draft_package(self::entries());

        // The file lock factory blocks a second flock() of the same file even in the same process; the database
        // factories are reentrant within one connection, so they cannot show the contention here.
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        (new \ReflectionProperty(importer::class, 'locktimeout'))->setValue(null, 0);
        $lock = \core\lock\lock_config::get_lock_factory('mod_scormmakercmi5')->get_lock('import' . $instance->id, 0);
        $this->assertNotFalse($lock);
        try {
            scormmakercmi5_update_instance(self::update_data($instance, $draft));
            $this->fail('Expected err_importbusy');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_importbusy', $e->errorcode);
            $this->assertSame('mod_scormmakercmi5', $e->module);
        } finally {
            $lock->release();
        }
        $this->assertSame(1, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
        $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $this->assertCount(1, $fs->get_area_files($context->id, 'mod_scormmakercmi5', 'package', false, 'id', false));

        // With the lock free again the same draft imports.
        scormmakercmi5_update_instance(self::update_data($instance, $draft));
        $this->assertSame(2, $DB->count_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instance->id]));
    }

    /**
     * Delete removes every row of the instance, in every table, its file areas and its grade item.
     */
    public function test_delete_instance(): void {
        global $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id]);
        $other = $this->generator->create_instance(['course' => $this->course->id]);
        foreach ([$instance, $other] as $i) {
            $this->fill_user_data($i);
            grade_update('mod/scormmakercmi5', $this->course->id, 'mod', 'scormmakercmi5', $i->id, 0, null, ['itemname' => 'x']);
        }
        // A second version, so more than one pkg id is involved.
        scormmakercmi5_update_instance(self::update_data($instance, $this->generator->create_draft_package(self::entries())));
        $this->pkgids[$instance->id] = $this->pkgids_of($instance);
        $this->assertCount(2, $this->pkgids[$instance->id]);
        $counts = array_combine(self::TABLES, array_map(static fn($t) => $DB->count_records($t), self::TABLES));

        $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id);
        $context = \context_module::instance($cm->id);
        $this->assertTrue(scormmakercmi5_delete_instance((int)$instance->id));

        $this->assertFalse($DB->record_exists('scormmakercmi5', ['id' => $instance->id]));
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->instance_rows($table, (int)$instance->id), $table);
            $this->assertGreaterThan(0, $this->instance_rows($table, (int)$other->id), $table . ' of the other instance');
            $this->assertLessThan($counts[$table], $DB->count_records($table), $table);
        }
        $fs = get_file_storage();
        foreach (['content', 'package'] as $area) {
            $this->assertSame([], $fs->get_area_files($context->id, 'mod_scormmakercmi5', $area, false), $area);
        }
        $this->assertFalse(\grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scormmakercmi5',
            'iteminstance' => $instance->id, 'courseid' => $this->course->id]));
        $this->assertNotFalse(\grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scormmakercmi5',
            'iteminstance' => $other->id, 'courseid' => $this->course->id]));
        $this->assertFalse(scormmakercmi5_delete_instance((int)$instance->id), 'second delete');
    }

    /**
     * Deleting the course module through the core API works too.
     */
    public function test_course_delete_module(): void {
        global $DB;
        $instance = $this->generator->create_instance(['course' => $this->course->id]);
        $this->fill_user_data($instance);
        // With the recycle bin on (the default), which backs the activity up first (tests/backup covers the restore).
        set_config('coursebinenable', 1, 'tool_recyclebin');
        \mod_scormmakercmi5_test_cm_api::delete(get_coursemodule_from_instance('scormmakercmi5', $instance->id)->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }

    /**
     * Rows of an instance in a table, following the foreign keys.
     *
     * @param string $table Table.
     * @param int $id Instance id.
     * @return int
     */
    private function instance_rows(string $table, int $id): int {
        global $DB;
        $pkgids = $DB->get_fieldset_select('scormmakercmi5_pkg', 'id', 'scormmakercmi5id = ?', [$id]);
        $regids = $DB->get_fieldset_select('scormmakercmi5_reg', 'id', 'scormmakercmi5id = ?', [$id]);
        $count = static function (string $field, array $ids) use ($DB, $table): int {
            if (!$ids) {
                return 0;
            }
            [$in, $params] = $DB->get_in_or_equal($ids);
            return $DB->count_records_select($table, $field . ' ' . $in, $params);
        };
        switch ($table) {
            case 'scormmakercmi5_stmt':
            case 'scormmakercmi5_state':
            case 'scormmakercmi5_reg':
            case 'scormmakercmi5_pkg':
                return $DB->count_records($table, ['scormmakercmi5id' => $id]);
            case 'scormmakercmi5_session':
            case 'scormmakercmi5_regblock':
            case 'scormmakercmi5_regau':
                // Registrations are gone after the delete; the rows are counted by the ids noted before.
                return $count('regid', $regids ?: ($this->regids[$id] ?? []));
            default:
                return $count('pkgid', $pkgids ?: ($this->pkgids[$id] ?? []));
        }
    }

    /** @var array Instance id => registration ids, noted by fill_user_data(). */
    private array $regids = [];

    /** @var array Instance id => pkg ids, noted by fill_user_data(). */
    private array $pkgids = [];

    /**
     * Adds a registration and one row to every per-user table of the instance.
     *
     * @param \stdClass $instance Instance (from the generator).
     */
    private function fill_user_data(\stdClass $instance): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $reg = $this->generator->create_registration(['scormmakercmi5id' => $instance->id, 'userid' => $user->id]);
        $au = $DB->get_record('scormmakercmi5_au', ['pkgid' => $reg->pkgid], '*', MUST_EXIST);
        $block = $DB->insert_record('scormmakercmi5_block', (object)[
            'pkgid' => $reg->pkgid, 'parentid' => 0, 'sortorder' => 99, 'depth' => 0,
            'publisherid' => 'https://example.com/block', 'lmsiri' => 'https://example.com/lms/block', 'title' => '{}',
        ]);
        $session = $DB->insert_record('scormmakercmi5_session', (object)[
            'regid' => $reg->id, 'auid' => $au->id, 'userid' => $user->id, 'sessionuuid' => \core\uuid::generate(),
            'launchmode' => 'Normal',
        ]);
        $this->assertTrue($DB->record_exists('scormmakercmi5_regau', ['regid' => $reg->id, 'auid' => $au->id]));
        $DB->insert_record('scormmakercmi5_regblock', (object)['regid' => $reg->id, 'blockid' => $block]);
        $DB->insert_record('scormmakercmi5_stmt', (object)[
            'scormmakercmi5id' => $instance->id, 'pkgid' => $reg->pkgid, 'regid' => $reg->id, 'auid' => $au->id,
            'userid' => $user->id, 'sessionid' => $session, 'statementid' => \core\uuid::generate(),
            'verb' => 'http://adlnet.gov/expapi/verbs/launched', 'objecthash' => sha1('x'), 'objectid' => 'x',
            'payload' => '{}',
        ]);
        $DB->insert_record('scormmakercmi5_state', (object)[
            'scormmakercmi5id' => $instance->id, 'userid' => $user->id, 'regid' => $reg->id, 'activityhash' => sha1('x'),
            'registration' => $reg->registration, 'stateid' => 'LMS.LaunchData', 'contenttype' => 'application/json',
            'content' => '{}', 'etag' => sha1('{}'),
        ]);
        $DB->insert_record('scormmakercmi5_itemdef', (object)[
            'pkgid' => $reg->pkgid, 'smversion' => 'v', 'smkind' => 'questao', 'smitem' => 'q' . $instance->id,
            'objectid' => 'x', 'name' => 'x', 'definition' => '{}',
        ]);
        $this->regids[$instance->id] = [$reg->id];
        $this->pkgids[$instance->id] = $this->pkgids_of($instance);
    }

    /**
     * Features the module declares.
     */
    public function test_supports(): void {
        $this->assertTrue(scormmakercmi5_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(scormmakercmi5_supports(FEATURE_MOD_INTRO));
        $this->assertSame(MOD_PURPOSE_CONTENT, scormmakercmi5_supports(FEATURE_MOD_PURPOSE));
        $this->assertNull(scormmakercmi5_supports('unknownfeature'));
    }

    /**
     * The registration generator pins the current version and uses a v4 UUID.
     */
    public function test_generator_registration(): void {
        $instance = $this->generator->create_instance(['course' => $this->course->id, 'signed' => true]);
        $this->assertSame('verified', $this->current_pkg($instance)->sigstatus);
        $user = $this->getDataGenerator()->create_user();
        $reg = $this->generator->create_registration(['scormmakercmi5id' => $instance->id, 'userid' => $user->id]);
        $this->assertSame((int)$instance->currentpkgid, (int)$reg->pkgid);
        $this->assertMatchesRegularExpression('/^' . self::UUID . '$/', $reg->registration);
        $unsigned = $this->generator->create_instance(['course' => $this->course->id]);
        $this->assertSame('unsigned', $this->current_pkg($unsigned)->sigstatus);
    }

    /**
     * The currentpkgid saved for an instance.
     *
     * @param \stdClass $instance Instance.
     * @return int
     */
    private function current_pkgid(\stdClass $instance): int {
        global $DB;
        return (int)$DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $instance->id]);
    }

    /**
     * The pkg ids of an instance.
     *
     * @param \stdClass $instance Instance.
     * @return array
     */
    private function pkgids_of(\stdClass $instance): array {
        global $DB;
        return $DB->get_fieldset_select('scormmakercmi5_pkg', 'id', 'scormmakercmi5id = ?', [$instance->id]);
    }

    /**
     * The current pkg row of an instance.
     *
     * @param \stdClass $instance Instance.
     * @return \stdClass
     */
    private function current_pkg(\stdClass $instance): \stdClass {
        global $DB;
        return $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
    }
}
