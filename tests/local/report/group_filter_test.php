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

namespace mod_scormmakercmi5\local\report;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\group_filter::class)]
/**
 * Tests for the group filter of the reports (a subquery on {groups_members}, one parameter per group).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\report\group_filter
 */
final class group_filter_test extends \advanced_testcase {
    /**
     * The parameters depend on the groups, never on how many members they have (SQL Server ~2090, Oracle 1000).
     */
    public function test_filter_params_do_not_grow_with_members(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $small = $gen->create_group(['courseid' => $course->id]);
        $big = $gen->create_group(['courseid' => $course->id]);
        for ($i = 0; $i < 300; $i++) {
            $u = $gen->create_user();
            $gen->enrol_user($u->id, $course->id);
            $gen->create_group_member(['groupid' => $big->id, 'userid' => $u->id]);
        }
        [, $p1] = group_filter::from_groups([(int)$small->id])->sql('s.userid', 't');
        [, $p2] = group_filter::from_groups([(int)$big->id])->sql('s.userid', 't');
        $this->assertCount(1, $p1);
        $this->assertCount(1, $p2);
    }

    /**
     * Everyone: no SQL; nobody: a false condition; a group: only its members pass.
     */
    public function test_semantics(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $g = $gen->create_group(['courseid' => $course->id]);
        $in = $gen->create_and_enrol($course);
        $out = $gen->create_and_enrol($course);
        $gen->create_group_member(['groupid' => $g->id, 'userid' => $in->id]);
        $this->assertSame(['', []], group_filter::everyone()->sql('u.id', 'x'));
        $this->assertTrue(group_filter::everyone()->unrestricted());
        $this->assertFalse(group_filter::everyone()->nobody());
        $this->assertTrue(group_filter::from_groups([-1])->nobody());
        $this->assertFalse(group_filter::from_groups([-1])->unrestricted());
        [$sql, $params] = group_filter::from_groups([-1])->sql('u.id', 'x');
        $count = $DB->count_records_sql("SELECT COUNT(1) FROM {user} u WHERE u.id = :me{$sql}", ['me' => $in->id] + $params);
        $this->assertSame(0, $count);
        [$sql, $params] = group_filter::from_groups([(int)$g->id])->sql('u.id', 'x');
        $ids = $DB->get_fieldset_sql(
            "SELECT u.id FROM {user} u WHERE u.id IN (:a, :b){$sql}",
            ['a' => $in->id, 'b' => $out->id] + $params
        );
        $this->assertEquals([$in->id], $ids);
    }

    /**
     * An empty list of groups is nobody, never everyone (fail closed); only everyone() lifts the restriction, and
     * from_restriction() reads the answer of basic_table::restrict_groups() ([] = everyone, [-1] = nobody).
     */
    public function test_empty_group_list_is_nobody(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $empty = group_filter::from_groups([]);
        $this->assertTrue($empty->nobody());
        $this->assertFalse($empty->unrestricted());
        [$sql, $params] = $empty->sql('u.id', 'x');
        $count = $DB->count_records_sql("SELECT COUNT(1) FROM {user} u WHERE u.id = :me{$sql}", ['me' => $user->id] + $params);
        $this->assertSame(0, $count);
        $this->assertTrue(group_filter::from_restriction([])->unrestricted());
        $this->assertTrue(group_filter::from_restriction([-1])->nobody());
        $filter = group_filter::from_restriction([7, 9]);
        $this->assertFalse($filter->unrestricted());
        $this->assertFalse($filter->nobody());
    }

    /**
     * A user in several of the groups passes once (EXISTS, never a join that would repeat the row); a group without
     * members lets nobody through; two filters in one query do not clash with distinct prefixes.
     */
    public function test_member_of_several_groups_passes_once(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $g1 = $gen->create_group(['courseid' => $course->id]);
        $g2 = $gen->create_group(['courseid' => $course->id]);
        $empty = $gen->create_group(['courseid' => $course->id]);
        $both = $gen->create_and_enrol($course);
        $one = $gen->create_and_enrol($course);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $both->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $both->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $one->id]);
        $base = "SELECT COUNT(1) FROM {user} u WHERE u.id IN (:a, :b)";
        $users = ['a' => $both->id, 'b' => $one->id];

        [$sql, $params] = group_filter::from_groups([(int)$g1->id, (int)$g2->id])->sql('u.id', 'x');
        $this->assertCount(2, $params);
        $this->assertSame(2, $DB->count_records_sql($base . $sql, $users + $params));
        [$sql, $params] = group_filter::from_groups([(int)$g1->id])->sql('u.id', 'x');
        $this->assertSame(1, $DB->count_records_sql($base . $sql, $users + $params));
        [$sql, $params] = group_filter::from_groups([(int)$empty->id])->sql('u.id', 'x');
        $this->assertSame(0, $DB->count_records_sql($base . $sql, $users + $params));

        [$sql1, $params1] = group_filter::from_groups([(int)$g2->id])->sql('u.id', 'p');
        [$sql2, $params2] = group_filter::from_groups([(int)$g1->id])->sql('u.id', 'q');
        $this->assertSame(1, $DB->count_records_sql($base . $sql1 . $sql2, $users + $params1 + $params2));
    }
}
