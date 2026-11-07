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

namespace mod_scormmakercmi5\local\package;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\package\content_access::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('scormmakercmi5_pluginfile')]
/**
 * Tests for the file access rules of pluginfile.php (design §10.1).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\package\content_access
 * @covers     ::scormmakercmi5_pluginfile
 */
final class content_access_test extends \advanced_testcase {
    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \context_module Module context. */
    private \context_module $context;

    /** @var \stdClass Instance. */
    private \stdClass $instance;

    /** @var \stdClass Another instance of the same course. */
    private \stdClass $other;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    /** @var \stdClass Enrolled editing teacher. */
    private \stdClass $teacher;

    /**
     * Creates a course with two instances, a student and an editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $modgen = $gen->get_plugin_generator('mod_scormmakercmi5');
        $this->instance = $modgen->create_instance(['course' => $course->id]);
        $this->other = $modgen->create_instance(['course' => $course->id]);
        $this->cm = get_coursemodule_from_instance('scormmakercmi5', $this->instance->id);
        $this->context = \context_module::instance($this->cm->id);
        $this->student = $gen->create_and_enrol($course, 'student');
        $this->teacher = $gen->create_and_enrol($course, 'editingteacher');
    }

    /**
     * Checks one request.
     *
     * @param string $area File area.
     * @param int $itemid Item id.
     * @param \stdClass $user User.
     * @return bool
     */
    private function can(string $area, int $itemid, \stdClass $user): bool {
        return content_access::can_serve($this->cm, $this->context, $area, $itemid, (int)$user->id);
    }

    /**
     * Content of a package of this instance is served to an enrolled student.
     */
    public function test_content_for_student(): void {
        $this->assertTrue($this->can('content', (int)$this->instance->currentpkgid, $this->student));
        $this->assertTrue($this->can('content', (int)$this->instance->currentpkgid, $this->teacher));
    }

    /**
     * The pkg id of another instance is refused, even with access to that other instance.
     */
    public function test_content_of_other_instance(): void {
        $this->assertFalse($this->can('content', (int)$this->other->currentpkgid, $this->student));
        $this->assertFalse($this->can('content', (int)$this->other->currentpkgid, $this->teacher));
        $this->assertFalse($this->can('content', 0, $this->student));
        $this->assertFalse($this->can('content', 987654, $this->student));
    }

    /**
     * An older version of this instance stays available (registrations pinned to it, R1.5).
     */
    public function test_content_of_older_version(): void {
        global $DB;
        $v1 = (int)$this->instance->currentpkgid;
        $DB->set_field('scormmakercmi5', 'currentpkgid', 0, ['id' => $this->instance->id]);
        $this->assertTrue($this->can('content', $v1, $this->student));
    }

    /**
     * The original zip is only for those who manage activities.
     */
    public function test_package_area(): void {
        $this->assertFalse($this->can('package', (int)$this->instance->currentpkgid, $this->student));
        $this->assertTrue($this->can('package', (int)$this->instance->currentpkgid, $this->teacher));
        $this->assertFalse($this->can('package', (int)$this->other->currentpkgid, $this->teacher));
    }

    /**
     * A student whose view capability is prohibited gets nothing.
     */
    public function test_view_prohibited(): void {
        global $DB;
        $role = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability('mod/scormmakercmi5:view', CAP_PROHIBIT, $role, $this->context->id, true);
        $this->assertFalse($this->can('content', (int)$this->instance->currentpkgid, $this->student));
    }

    /**
     * A user not enrolled in the course gets nothing.
     */
    public function test_not_enrolled(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse($this->can('content', (int)$this->instance->currentpkgid, $user));
    }

    /**
     * Any other file area is refused.
     */
    public function test_other_area(): void {
        $this->assertFalse($this->can('outra', (int)$this->instance->currentpkgid, $this->teacher));
        $this->assertFalse($this->can('intro', 0, $this->teacher));
    }

    /**
     * The pluginfile callback refuses what can_serve() refuses, before looking for the file.
     */
    public function test_pluginfile_refuses(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
        $this->setUser($this->student);
        $course = $DB->get_record('course', ['id' => $this->cm->course]);
        $pkgid = (string)$this->instance->currentpkgid;
        $coursecontext = \context_course::instance($course->id);
        $cases = [
            'package area for a student' => [$this->context, 'package', [$pkgid, 'package.zip']],
            'pkg of another instance' => [$this->context, 'content', [(string)$this->other->currentpkgid, 'index.html']],
            'course context' => [$coursecontext, 'content', [$pkgid, 'index.html']],
            'item id not a number' => [$this->context, 'content', ['abc', 'index.html']],
            'no path' => [$this->context, 'content', [$pkgid]],
        ];
        foreach ($cases as $name => [$context, $area, $args]) {
            $this->assertFalse(scormmakercmi5_pluginfile($course, $this->cm, $context, $area, $args, false), $name);
        }
    }
}
