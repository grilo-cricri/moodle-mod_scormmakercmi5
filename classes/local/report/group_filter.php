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

/**
 * The group filter of the reports as a subquery on {groups_members}: one parameter per GROUP, never per user. The
 * former list of user ids in an IN broke SQL Server above ~2090 members and Oracle above 1000 (pending item of C).
 *
 * EXISTS (not a join): a user in several of the groups still passes once, as with the former list of user ids.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class group_filter {
    /**
     * Builds a filter.
     *
     * @param int[]|null $groupids Null: everyone; [] : nobody; else the groups.
     */
    private function __construct(
        /** @var int[]|null Groups, null for everyone. */
        private readonly ?array $groupids
    ) {
    }

    /**
     * No restriction: every learner. Always explicit: an empty list of groups never means this.
     *
     * @return self
     */
    public static function everyone(): self {
        return new self(null);
    }

    /**
     * Only the members of these groups. An empty list (or the [-1] marker of basic_table::restrict_groups()) is
     * nobody, never everyone: a viewer whose allowed groups came out empty must see nothing.
     *
     * @param int[] $groupids Group ids.
     * @return self
     */
    public static function from_groups(array $groupids): self {
        $ids = array_values(array_filter(array_map('intval', $groupids), static fn(int $id): bool => $id > 0));
        return new self($ids);
    }

    /**
     * From the answer of basic_table::restrict_groups(): [] = every learner, [-1] = nobody, else the groups.
     *
     * @param int[] $restriction What restrict_groups() returned.
     * @return self
     */
    public static function from_restriction(array $restriction): self {
        return $restriction === [] ? self::everyone() : self::from_groups($restriction);
    }

    /**
     * No restriction.
     *
     * @return bool
     */
    public function unrestricted(): bool {
        return $this->groupids === null;
    }

    /**
     * No group visible.
     *
     * @return bool
     */
    public function nobody(): bool {
        return $this->groupids === [];
    }

    /**
     * The condition to append to a WHERE.
     *
     * @param string $usercolumn Column with the user id (e.g. s.userid).
     * @param string $prefix Unique prefix of the alias and the parameters in the query.
     * @return array [string sql starting with " AND " or '', array params].
     */
    public function sql(string $usercolumn, string $prefix): array {
        global $DB;
        if ($this->unrestricted()) {
            return ['', []];
        }
        if ($this->nobody()) {
            return [' AND 1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($this->groupids, SQL_PARAMS_NAMED, $prefix . 'grp');
        $alias = $prefix . 'gm';
        return [" AND EXISTS (SELECT 1 FROM {groups_members} {$alias}
                               WHERE {$alias}.userid = {$usercolumn} AND {$alias}.groupid {$insql})", $params];
    }
}
