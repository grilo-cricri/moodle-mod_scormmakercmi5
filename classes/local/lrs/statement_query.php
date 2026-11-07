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

use mod_scormmakercmi5\local\report\group_filter;
use mod_scormmakercmi5\local\runtime\actor;

/**
 * GET statements (design §7.1, §7.3; xAPI 1.0.3 part 3 §2.1.3; R5.1, R5.2, R5.6).
 *
 * Two callers. The AU, with its session token, reads only the statements of its own registration and AU (its actor,
 * its registration, its activity): a statementId outside that is 404, an agent or registration parameter of someone
 * else is 403. The reporting credential (auth::report_user_from_request) reads the statements of the instances where
 * its user has mod/scormmakercmi5:viewreports in the module context, and in separate groups only of the learners of the
 * user's groups unless they have moodle/site:accessallgroups — the filter is in the query, so a page never
 * holds a statement the user may not see. Neither ever writes a statement.
 *
 * The stored payload is returned as stored (format=exact), never re-encoded through arrays ({} stays {}). Results
 * are ordered by stored time, then row id (the statements of one batch share the millisecond), newest first unless
 * ascending=true; "more" is a relative IRL with the same parameters and a cursor (stored ms and row id of the last
 * statement returned), so a page never repeats or skips a statement stored meanwhile.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class statement_query {
    /** @var int Largest page (design §7.1); limit=0 means this too (xAPI: "the maximum the server will allow"). */
    public const MAX_LIMIT = 500;

    /** @var int Page without a limit parameter. */
    public const DEFAULT_LIMIT = 100;

    /** @var string Our parameter of the "more" IRL. */
    public const CURSOR = 'cursor';

    /** @var string[] Parameters allowed with statementId or voidedStatementId (xAPI 1.0.3 part 3 §2.1.3). */
    private const SINGLE_PARAMS = ['statementId', 'voidedStatementId', 'attachments', 'format'];

    /** @var string[] Values of format. */
    private const FORMATS = ['exact', 'ids', 'canonical'];

    /** @var string A UUID, any case. */
    private const UUID = '~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~iD';

    /** @var string[] Inverse functional identifiers of an Agent or Group. */
    private const IFIS = ['mbox', 'mbox_sha1sum', 'openid', 'account'];

    /** @var string[] Keys of contextActivities. */
    private const CONTEXT_ACTIVITIES = ['parent', 'grouping', 'category', 'other'];

    /** @var string[] Lists of interaction components whose items carry a description. */
    private const COMPONENTS = ['choices', 'scale', 'source', 'target', 'steps'];

    /** @var string Columns read of a statement row. */
    private const FIELDS = 's.id, s.scormmakercmi5id, s.regid, s.auid, s.userid, s.storedms, s.payload';

    /**
     * GET statements: one statement (statementId, voidedStatementId) or a query.
     *
     * @param request $r Request.
     * @param session_context|null $ctx Session of an AU token; null with the reporting credential.
     * @param int|null $reportuserid User of the reporting credential; null with an AU token.
     * @return response
     * @throws lrs_exception 400 bad parameter, 403 out of the token's scope, 404 statement not found.
     */
    public static function get_statements(request $r, ?session_context $ctx, ?int $reportuserid): response {
        if ($ctx === null && $reportuserid === null) {
            throw new \coding_exception('get_statements needs a session or a report user');
        }
        // Taken before the read: everything stored up to now is committed or waits for its lock (design §7.2).
        $headers = ['X-Experience-API-Consistent-Through' => xapi_time::from_ms(xapi_time::now_ms())];
        $format = self::format($r);
        $attachments = self::bool_param($r, 'attachments', false);
        $language = self::languages($r->headers['accept-language'] ?? '');

        if (array_key_exists('statementId', $r->query) || array_key_exists('voidedStatementId', $r->query)) {
            $row = self::single($r, $ctx, $reportuserid, $headers);
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', intdiv((int)$row->storedms, 1000)) . ' GMT';
            return self::respond(self::render($row->payload, $format, $language), $attachments, $headers);
        }

        [$rows, $more] = self::query($r, $ctx, $reportuserid);
        $rendered = array_map(fn(\stdClass $row): string => self::render($row->payload, $format, $language), $rows);
        $json = '{"statements":[' . implode(',', $rendered) . '],"more":'
            . json_encode($more, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . '}';
        return self::respond($json, $attachments, $headers);
    }

    /**
     * The row of statementId, in the scope of the caller. voidedStatementId is always 404: nothing is ever voided
     * (the verb voided is refused, design §6.3).
     *
     * @param request $r Request.
     * @param session_context|null $ctx Session.
     * @param int|null $reportuserid Report user.
     * @param array $headers Headers of the 404.
     * @return \stdClass
     * @throws lrs_exception 400, 404 err_lrsnostatement.
     */
    private static function single(request $r, ?session_context $ctx, ?int $reportuserid, array $headers): \stdClass {
        global $DB;
        foreach (array_keys($r->query) as $name) {
            if (!in_array($name, self::SINGLE_PARAMS, true)) {
                throw new lrs_exception(400, 'err_lrsparam', (string)$name);
            }
        }
        if (array_key_exists('statementId', $r->query) && array_key_exists('voidedStatementId', $r->query)) {
            throw new lrs_exception(400, 'err_lrsparam', 'voidedStatementId');
        }
        $notfound = new lrs_exception(404, 'err_lrsnostatement', '', null, $headers);
        if (array_key_exists('voidedStatementId', $r->query)) {
            self::uuid_param($r, 'voidedStatementId');
            throw $notfound;
        }
        $id = strtolower(self::uuid_param($r, 'statementId'));
        $row = $DB->get_record_sql(
            'SELECT ' . self::FIELDS . ' FROM {scormmakercmi5_stmt} s WHERE s.statementid = :id',
            ['id' => $id]
        );
        if (!$row) {
            throw $notfound;
        }
        if ($ctx !== null) {
            $visible = (int)$row->regid === (int)$ctx->reg->id && (int)$row->auid === (int)$ctx->au->id;
        } else {
            $scope = self::report_scope($reportuserid, (int)$row->scormmakercmi5id);
            $instanceid = (int)$row->scormmakercmi5id;
            // Absent = not readable; null = every learner; a list = the groups whose learners the user may read.
            $visible = array_key_exists($instanceid, $scope)
                && ($scope[$instanceid] === null || self::in_groups((int)$row->userid, $scope[$instanceid]));
        }
        if (!$visible) {
            throw $notfound;
        }
        return $row;
    }

    /**
     * One page of a query.
     *
     * @param request $r Request.
     * @param session_context|null $ctx Session.
     * @param int|null $reportuserid Report user.
     * @return array [\stdClass[] rows, string more].
     * @throws lrs_exception 400, 403.
     */
    private static function query(request $r, ?session_context $ctx, ?int $reportuserid): array {
        global $DB;
        $where = [];
        $params = [];

        $registration = array_key_exists('registration', $r->query) ? self::uuid_param($r, 'registration') : null;
        $agent = array_key_exists('agent', $r->query) ? router::json_object_param($r, 'agent') : null;
        $agentuser = $agent === null ? null : self::agent_user($agent, self::bool_param($r, 'related_agents', false));
        $verb = documents::string_param($r, 'verb', false);
        $activity = documents::string_param($r, 'activity', false);
        $related = self::bool_param($r, 'related_activities', false);
        $since = documents::since_param($r);
        $until = self::until_param($r);
        $ascending = self::bool_param($r, 'ascending', false);
        $limit = self::limit_param($r);
        $cursor = self::cursor_param($r);

        if ($ctx !== null) {
            $ctx->assert_scope($agent, null, $registration);
            $where[] = 's.regid = :scoperegid AND s.auid = :scopeauid';
            $params += ['scoperegid' => (int)$ctx->reg->id, 'scopeauid' => (int)$ctx->au->id];
        } else {
            $only = null;
            if ($registration !== null) {
                $reg = $DB->get_record('scormmakercmi5_reg', ['registration' => strtolower($registration)], 'id, scormmakercmi5id');
                if (!$reg) {
                    return [[], ''];
                }
                $only = (int)$reg->scormmakercmi5id;
                $where[] = 's.regid = :regid';
                $params['regid'] = (int)$reg->id;
            }
            $scope = self::report_scope($reportuserid, $only);
            if (!$scope) {
                return [[], ''];
            }
            // Instances the user reads whole, and each instance in separate groups only for the learners of their groups.
            $alternatives = [];
            $whole = array_keys(array_filter($scope, fn($users) => $users === null));
            if ($whole) {
                [$insql, $inparams] = $DB->get_in_or_equal($whole, SQL_PARAMS_NAMED, 'inst');
                $alternatives[] = 's.scormmakercmi5id ' . $insql;
                $params += $inparams;
            }
            foreach ($scope as $instanceid => $groupids) {
                if ($groupids === null) {
                    continue;
                }
                // One parameter per group, never per learner (group_filter: EXISTS on groups_members).
                [$groupsql, $groupparams] = group_filter::from_groups($groupids)->sql('s.userid', 'g' . (int)$instanceid . '_');
                $instancekey = 'grpinst' . (int)$instanceid;
                $alternatives[] = "(s.scormmakercmi5id = :{$instancekey}" . $groupsql . ')';
                $params += $groupparams + [$instancekey => (int)$instanceid];
            }
            $where[] = '(' . implode(' OR ', $alternatives) . ')';
        }

        if ($agentuser === 0) {
            return [[], ''];
        }
        if ($agentuser !== null) {
            $where[] = 's.userid = :agentuser';
            $params['agentuser'] = $agentuser;
        }
        if ($verb !== null) {
            $where[] = 's.verb = :verb';
            $params['verb'] = $verb;
        }
        if ($activity !== null) {
            $params['objecthash'] = sha1($activity);
            if ($related) {
                // A candidate for the check in PHP: the IRI, as the payload spells an "id", anywhere in it.
                $needle = '"id":' . json_encode($activity, statement_store::JSON_FLAGS);
                $params['related'] = '%' . $DB->sql_like_escape($needle) . '%';
                $where[] = '(s.objecthash = :objecthash OR ' . $DB->sql_like('s.payload', ':related') . ')';
            } else {
                $where[] = 's.objecthash = :objecthash';
            }
        }
        if ($since !== null) {
            $where[] = 's.storedms > :since';
            $params['since'] = $since;
        }
        if ($until !== null) {
            $where[] = 's.storedms <= :until';
            $params['until'] = $until;
        }

        $direction = $ascending ? 'ASC' : 'DESC';
        $after = $ascending ? '>' : '<';
        $rows = [];
        $more = null;
        do {
            $pagewhere = $where;
            $pageparams = $params;
            if ($cursor !== null) {
                // Leading range on storedms (the indexes (regid|scormmakercmi5id, storedms) can seek to it), then the
                // tie-break on the row id inside the cursor's millisecond.
                $pagewhere[] = "s.storedms $after= :cursorms AND (s.storedms $after :cursorms2 OR s.id $after :cursorid)";
                $pageparams += ['cursorms' => $cursor[0], 'cursorms2' => $cursor[0], 'cursorid' => $cursor[1]];
            }
            $sql = 'SELECT ' . self::FIELDS . ' FROM {scormmakercmi5_stmt} s WHERE ' . implode(' AND ', $pagewhere)
                . " ORDER BY s.storedms $direction, s.id $direction";
            $read = 0;
            $last = null;
            $recordset = $DB->get_recordset_sql($sql, $pageparams, 0, $limit + 1);
            foreach ($recordset as $row) {
                $read++;
                $last = $row;
                if ($activity !== null && !self::activity_matches($row->payload, $activity, $related)) {
                    continue;
                }
                if (count($rows) === $limit) {
                    $more = end($rows);
                    break;
                }
                $rows[] = $row;
            }
            $recordset->close();
            // A full read that the PHP check thinned out: read on from where it stopped.
            $cursor = $last === null ? null : [(int)$last->storedms, (int)$last->id];
        } while ($more === null && $read === $limit + 1);

        return [$rows, $more === null ? '' : self::more_url($r, $more)];
    }

    /**
     * What a user may read of each instance where they have mod/scormmakercmi5:viewreports in the module context.
     *
     * Like the reports of the activity (basic_table::restrict_groups): in separate groups, without
     * moodle/site:accessallgroups, only the learners who share a group with the user (the allowed groups of the
     * activity, grouping included); an instance where the user has no group is left out altogether.
     *
     * @param int $userid User.
     * @param int|null $instanceid Only this instance, if given.
     * @return array<int, int[]|null> Instance id => null for every learner, or the ids of the groups whose learners
     *     are allowed (matched in SQL by group_filter, never listed by user).
     */
    private static function report_scope(int $userid, ?int $instanceid): array {
        global $DB;
        $ctxfields = \core\context_helper::get_preload_record_columns_sql('ctx');
        $params = ['modname' => 'scormmakercmi5', 'level' => CONTEXT_MODULE];
        $sql = "SELECT cm.id AS cmid, cm.instance, cm.course, $ctxfields
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :level
                 WHERE cm.deletioninprogress = 0";
        if ($instanceid !== null) {
            $sql .= ' AND cm.instance = :instance';
            $params['instance'] = $instanceid;
        }
        $scope = [];
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $row) {
            \core\context_helper::preload_from_record($row);
            $context = \context_module::instance($row->cmid);
            if (!has_capability('mod/scormmakercmi5:viewreports', $context, $userid)) {
                continue;
            }
            $cm = get_fast_modinfo((int)$row->course, $userid)->instances['scormmakercmi5'][(int)$row->instance] ?? null;
            if (!$cm) {
                continue;
            }
            $separate = groups_get_activity_groupmode($cm) == SEPARATEGROUPS;
            if (!$separate || has_capability('moodle/site:accessallgroups', $context, $userid)) {
                $scope[(int)$row->instance] = null;
                continue;
            }
            $groupids = array_map('intval', array_keys(groups_get_activity_allowed_groups($cm, $userid)));
            if (!$groupids) {
                continue;
            }
            $scope[(int)$row->instance] = $groupids;
        }
        $recordset->close();
        return $scope;
    }

    /**
     * Whether a user belongs to any of the groups.
     *
     * @param int $userid User.
     * @param int[] $groupids Groups.
     * @return bool
     */
    private static function in_groups(int $userid, array $groupids): bool {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
        $select = "userid = :userid AND groupid {$insql}";
        return $DB->record_exists_select('groups_members', $select, $params + ['userid' => $userid]);
    }

    /**
     * The user an agent filter stands for (xAPI: Agents are equal when their identifier is; the name does not count).
     *
     * Every statement stored here has a learner of the site as actor (account homePage = wwwroot, name = user id) and
     * the LMS as authority. With related_agents the LMS authority matches every statement; other related agents
     * (instructor, team, an Agent as object) are not indexed and are not searched.
     *
     * @param array $agent Decoded agent.
     * @param bool $related related_agents.
     * @return int|null User id; null = no restriction (the authority with related_agents); 0 = matches no statement.
     * @throws lrs_exception 400 for an Agent without exactly one identifier.
     */
    private static function agent_user(array $agent, bool $related): ?int {
        global $CFG;
        $type = $agent['objectType'] ?? 'Agent';
        $ifis = array_values(array_intersect(self::IFIS, array_keys($agent)));
        if (!in_array($type, ['Agent', 'Group'], true) || ($type === 'Agent' && count($ifis) !== 1) || count($ifis) > 1) {
            throw new lrs_exception(400, 'err_lrsparam', 'agent');
        }
        $account = $agent['account'] ?? null;
        if ($type !== 'Agent' || !is_array($account) || ($account['homePage'] ?? null) !== $CFG->wwwroot) {
            return 0;
        }
        $name = $account['name'] ?? null;
        if (is_string($name) && ctype_digit($name)) {
            return (int)$name;
        }
        return $related && $name === actor::lms_authority()['account']['name'] ? null : 0;
    }

    /**
     * Whether a stored statement is about an activity: its object, or with related_activities also its
     * contextActivities and those of a SubStatement (xAPI 1.0.3 part 3 §2.1.3).
     *
     * @param string $payload Stored JSON.
     * @param string $iri Activity id.
     * @param bool $related related_activities.
     * @return bool
     */
    private static function activity_matches(string $payload, string $iri, bool $related): bool {
        $statement = json_decode($payload, true);
        if (!is_array($statement)) {
            return false;
        }
        $candidates = [$statement];
        $object = $statement['object'] ?? null;
        if ($related && is_array($object) && ($object['objectType'] ?? null) === 'SubStatement') {
            $candidates[] = $object;
        }
        foreach ($candidates as $candidate) {
            $object = is_array($candidate['object'] ?? null) ? $candidate['object'] : [];
            if (($object['objectType'] ?? 'Activity') === 'Activity' && ($object['id'] ?? null) === $iri) {
                return true;
            }
            if (!$related) {
                continue;
            }
            $lists = $candidate['context']['contextActivities'] ?? [];
            foreach (self::CONTEXT_ACTIVITIES as $key) {
                foreach (is_array($lists[$key] ?? null) ? $lists[$key] : [] as $activity) {
                    if (is_array($activity) && ($activity['id'] ?? null) === $iri) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * The payload in the requested format: exact as stored; ids and canonical re-encode the object form, so {} and
     * objects with keys "0", "1"… keep their shape.
     *
     * @param string $payload Stored JSON.
     * @param string $format exact, ids or canonical.
     * @param string[] $languages Accept-Language tags, best first.
     * @return string
     */
    private static function render(string $payload, string $format, array $languages): string {
        if ($format === 'exact') {
            return $payload;
        }
        $statement = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        if ($format === 'ids') {
            self::ids_statement($statement);
        } else {
            self::canonical_statement($statement, $languages);
        }
        return json_encode($statement, statement_store::JSON_FLAGS);
    }

    /**
     * format=ids on a statement or SubStatement: Agents, Groups, Activities and Verbs keep only what identifies them.
     *
     * @param \stdClass $statement Statement (changed in place).
     */
    private static function ids_statement(\stdClass $statement): void {
        foreach (['actor', 'authority'] as $key) {
            if (isset($statement->$key) && $statement->$key instanceof \stdClass) {
                $statement->$key = self::ids_agent($statement->$key);
            }
        }
        if (isset($statement->verb) && $statement->verb instanceof \stdClass && isset($statement->verb->id)) {
            $statement->verb = (object)['id' => $statement->verb->id];
        }
        if (isset($statement->object) && $statement->object instanceof \stdClass) {
            $type = $statement->object->objectType ?? 'Activity';
            if ($type === 'Agent' || $type === 'Group') {
                $statement->object = self::ids_agent($statement->object);
            } else if ($type === 'SubStatement') {
                self::ids_statement($statement->object);
            } else if ($type === 'Activity') {
                $statement->object = self::ids_activity($statement->object);
            }
        }
        $context = $statement->context ?? null;
        if (!$context instanceof \stdClass) {
            return;
        }
        foreach (['instructor', 'team'] as $key) {
            if (isset($context->$key) && $context->$key instanceof \stdClass) {
                $context->$key = self::ids_agent($context->$key);
            }
        }
        $lists = $context->contextActivities ?? null;
        if ($lists instanceof \stdClass) {
            foreach (self::CONTEXT_ACTIVITIES as $key) {
                if (isset($lists->$key) && is_array($lists->$key)) {
                    $lists->$key = array_map(
                        fn($a) => $a instanceof \stdClass ? self::ids_activity($a) : $a,
                        $lists->$key
                    );
                }
            }
        }
    }

    /**
     * An Agent or Group with only its objectType and identifier; an anonymous Group keeps its members, reduced.
     *
     * @param \stdClass $agent Agent or Group.
     * @return \stdClass
     */
    private static function ids_agent(\stdClass $agent): \stdClass {
        $reduced = new \stdClass();
        if (isset($agent->objectType)) {
            $reduced->objectType = $agent->objectType;
        }
        $identified = false;
        foreach (self::IFIS as $ifi) {
            if (property_exists($agent, $ifi)) {
                $reduced->$ifi = $agent->$ifi;
                $identified = true;
            }
        }
        if (!$identified && isset($agent->member) && is_array($agent->member)) {
            $reduced->member = array_map(fn($m) => $m instanceof \stdClass ? self::ids_agent($m) : $m, $agent->member);
        }
        return $reduced;
    }

    /**
     * An Activity with only its objectType (when present) and id.
     *
     * @param \stdClass $activity Activity.
     * @return \stdClass
     */
    private static function ids_activity(\stdClass $activity): \stdClass {
        $reduced = new \stdClass();
        if (isset($activity->objectType)) {
            $reduced->objectType = $activity->objectType;
        }
        if (isset($activity->id)) {
            $reduced->id = $activity->id;
        }
        return $reduced;
    }

    /**
     * format=canonical on a statement or SubStatement: every language map of verbs, activities and attachments keeps
     * the one language that best matches Accept-Language (the first one when none does). This LRS keeps no other
     * canonical definition than the one the statement carries.
     *
     * @param \stdClass $statement Statement (changed in place).
     * @param string[] $languages Accept-Language tags, best first.
     */
    private static function canonical_statement(\stdClass $statement, array $languages): void {
        if (isset($statement->verb->display) && $statement->verb->display instanceof \stdClass) {
            $statement->verb->display = self::one_language($statement->verb->display, $languages);
        }
        $activities = [];
        $object = $statement->object ?? null;
        if ($object instanceof \stdClass) {
            $type = $object->objectType ?? 'Activity';
            if ($type === 'SubStatement') {
                self::canonical_statement($object, $languages);
            } else if ($type === 'Activity') {
                $activities[] = $object;
            }
        }
        $lists = $statement->context->contextActivities ?? null;
        if ($lists instanceof \stdClass) {
            foreach (self::CONTEXT_ACTIVITIES as $key) {
                foreach (isset($lists->$key) && is_array($lists->$key) ? $lists->$key : [] as $activity) {
                    $activities[] = $activity;
                }
            }
        }
        foreach ($activities as $activity) {
            $definition = $activity instanceof \stdClass ? ($activity->definition ?? null) : null;
            if (!$definition instanceof \stdClass) {
                continue;
            }
            self::canonical_maps($definition, ['name', 'description'], $languages);
            foreach (self::COMPONENTS as $list) {
                foreach (isset($definition->$list) && is_array($definition->$list) ? $definition->$list : [] as $component) {
                    if ($component instanceof \stdClass) {
                        self::canonical_maps($component, ['description'], $languages);
                    }
                }
            }
        }
        foreach (isset($statement->attachments) && is_array($statement->attachments) ? $statement->attachments : [] as $a) {
            if ($a instanceof \stdClass) {
                self::canonical_maps($a, ['display', 'description'], $languages);
            }
        }
    }

    /**
     * Reduces the language maps of an object.
     *
     * @param \stdClass $holder Object with language maps.
     * @param string[] $keys Keys of its language maps.
     * @param string[] $languages Accept-Language tags, best first.
     */
    private static function canonical_maps(\stdClass $holder, array $keys, array $languages): void {
        foreach ($keys as $key) {
            if (isset($holder->$key) && $holder->$key instanceof \stdClass) {
                $holder->$key = self::one_language($holder->$key, $languages);
            }
        }
    }

    /**
     * The entry of a language map that best matches the preferred languages: exact tag, then same primary language;
     * "*" or no match takes the first entry.
     *
     * @param \stdClass $map Language map.
     * @param string[] $languages Accept-Language tags, lower case, best first.
     * @return \stdClass
     */
    private static function one_language(\stdClass $map, array $languages): \stdClass {
        $tags = array_keys(get_object_vars($map));
        if (!$tags) {
            return $map;
        }
        $chosen = null;
        foreach ($languages as $wanted) {
            foreach ($tags as $tag) {
                if (strtolower((string)$tag) === $wanted) {
                    $chosen = $tag;
                    break 2;
                }
            }
            $primary = explode('-', $wanted)[0];
            foreach ($tags as $tag) {
                if ($wanted !== '*' && explode('-', strtolower((string)$tag))[0] === $primary) {
                    $chosen = $tag;
                    break 2;
                }
            }
        }
        $chosen ??= $tags[0];
        $reduced = new \stdClass();
        $reduced->$chosen = $map->$chosen;
        return $reduced;
    }

    /**
     * The tags of Accept-Language, lower case, by quality (q=0 left out), best first.
     *
     * @param string $header Header.
     * @return string[]
     */
    private static function languages(string $header): array {
        $weighted = [];
        foreach (explode(',', $header) as $position => $item) {
            $parts = array_map('trim', explode(';', $item));
            $tag = strtolower($parts[0]);
            if ($tag === '') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $param) {
                if (preg_match('~^q=([01](\.\d{0,3})?)$~i', $param, $m)) {
                    $quality = (float)$m[1];
                }
            }
            if ($quality > 0) {
                $weighted[] = [$quality, $position, $tag];
            }
        }
        usort($weighted, fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        return array_column($weighted, 2);
    }

    /**
     * The "more" IRL: path without scheme, host and port (xAPI 1.0.3 part 2 §2.5), the same parameters and a cursor
     * after the last statement returned.
     *
     * @param request $r Request.
     * @param \stdClass $last Last row returned.
     * @return string
     */
    private static function more_url(request $r, \stdClass $last): string {
        $params = $r->query;
        $params[self::CURSOR] = (int)$last->storedms . '-' . (int)$last->id;
        $url = new \moodle_url('/mod/scormmakercmi5/lrs.php/statements', $params);
        return (string)preg_replace('~^[a-z][a-z0-9+.-]*://[^/]*~i', '', $url->out(false));
    }

    /**
     * The response, as JSON or, with attachments=true, as multipart/mixed whose only part is the JSON: this LRS keeps
     * no attachment content (statement_validator accepts only fileUrl attachments).
     *
     * @param string $json Body.
     * @param bool $attachments attachments=true.
     * @param array $headers Extra headers.
     * @return response
     */
    private static function respond(string $json, bool $attachments, array $headers): response {
        if (!$attachments) {
            return new response(200, $json, ['Content-Type' => 'application/json'] + $headers);
        }
        $boundary = bin2hex(random_bytes(16));
        $body = "--$boundary\r\nContent-Type: application/json\r\n\r\n$json\r\n--$boundary--\r\n";
        return new response(200, $body, ['Content-Type' => 'multipart/mixed; boundary=' . $boundary] + $headers);
    }

    /**
     * The format parameter.
     *
     * @param request $r Request.
     * @return string
     * @throws lrs_exception 400.
     */
    private static function format(request $r): string {
        $format = documents::string_param($r, 'format', false) ?? 'exact';
        if (!in_array($format, self::FORMATS, true)) {
            throw new lrs_exception(400, 'err_lrsparam', 'format');
        }
        return $format;
    }

    /**
     * A boolean parameter: "true" or "false".
     *
     * @param request $r Request.
     * @param string $name Name.
     * @param bool $default When absent.
     * @return bool
     * @throws lrs_exception 400.
     */
    private static function bool_param(request $r, string $name, bool $default): bool {
        $value = documents::string_param($r, $name, false);
        if ($value === null) {
            return $default;
        }
        if ($value !== 'true' && $value !== 'false') {
            throw new lrs_exception(400, 'err_lrsparam', $name);
        }
        return $value === 'true';
    }

    /**
     * A UUID parameter.
     *
     * @param request $r Request.
     * @param string $name Name.
     * @return string
     * @throws lrs_exception 400.
     */
    private static function uuid_param(request $r, string $name): string {
        $value = documents::string_param($r, $name, true);
        if (!preg_match(self::UUID, $value)) {
            throw new lrs_exception(400, 'err_lrsparam', $name);
        }
        return $value;
    }

    /**
     * The until parameter, in ms.
     *
     * @param request $r Request.
     * @return int|null
     * @throws lrs_exception 400.
     */
    private static function until_param(request $r): ?int {
        $until = documents::string_param($r, 'until', false);
        if ($until === null) {
            return null;
        }
        try {
            return xapi_time::to_ms($until);
        } catch (\InvalidArgumentException $e) {
            throw new lrs_exception(400, 'err_lrsparam', 'until');
        }
    }

    /**
     * The limit parameter: a whole number; 0 and anything above the ceiling mean the ceiling.
     *
     * @param request $r Request.
     * @return int
     * @throws lrs_exception 400.
     */
    private static function limit_param(request $r): int {
        $limit = documents::string_param($r, 'limit', false);
        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }
        if (!ctype_digit($limit)) {
            throw new lrs_exception(400, 'err_lrsparam', 'limit');
        }
        $value = strlen($limit) > 6 ? self::MAX_LIMIT : (int)$limit;
        return $value === 0 || $value > self::MAX_LIMIT ? self::MAX_LIMIT : $value;
    }

    /**
     * The cursor of a "more" IRL: "<stored ms>-<row id>". It only narrows the query: the scope is applied again.
     *
     * @param request $r Request.
     * @return int[]|null [stored ms, row id].
     * @throws lrs_exception 400.
     */
    private static function cursor_param(request $r): ?array {
        $cursor = documents::string_param($r, self::CURSOR, false);
        if ($cursor === null) {
            return null;
        }
        if (!preg_match('~^(\d{1,15})-(\d{1,10})$~D', $cursor, $m)) {
            throw new lrs_exception(400, 'err_lrsparam', self::CURSOR);
        }
        return [(int)$m[1], (int)$m[2]];
    }
}
