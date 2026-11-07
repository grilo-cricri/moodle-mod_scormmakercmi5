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

use mod_scormmakercmi5\local\package\display_text;

/**
 * Structural validation of xAPI 1.0.3 statements (Part Two, Data) before the cmi5 rules (design §6.4, §7.1).
 *
 * Pure: no database. Works on statements decoded with json_decode(…, true), where a JSON object is an array with
 * keys and an empty object is []. A violation is a 400 err_invalidstatement whose message names the field
 * ("context.contextActivities.parent[0].id") and whose rule is the section of xAPI 1.0.3 Part Two ("xAPI 2.4.3").
 *
 * Choices (design §7.1: structural in the fields the LMS uses and in the types; the ADL LRS suite is not a goal):
 * - properties the specification does not define are refused at every level where it lists them (2.2 recommends
 *   it); extensions are the place for anything else;
 * - durations follow the whole ISO 8601 4.4.3.2 grammar (iso_duration::is_valid); one with years or months is
 *   valid and its milliseconds are unknown (iso_duration::to_ms gives null, stored as a null durationms);
 * - a score with min equal to max is accepted (a question worth 0 points: the SCORM Maker sends min = max = 0);
 * - attachments must have a fileUrl: this LRS reads only application/json bodies, never multipart/mixed;
 * - verb.id longer than the stmt.verb column (255 characters, design §3.13) is refused here, never truncated;
 * - the id is optional here: cmi5 requires it (rule 1, 403, cmi5_rules);
 * - interaction component lists are not tied to the interactionType (the table of 2.4.4.1 is not a MUST);
 * - a timestamp needs the seconds (xapi_time's format); reduced precision such as "...T00:00Z" is refused;
 * - an empty JSON object and an empty JSON array both decode as [], so "attachments": {} passes as an empty list
 *   and "result": [] as an empty object (inherent to json_decode(..., true), harmless for storage).
 *
 * contextActivities may arrive as one Activity or a list (2.4.6.2) but the LRS MUST return lists: normalize()
 * wraps them. Everything after validate() (cmi5_rules, statement_store) works on the normalized statement.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class statement_validator {
    /** @var int Width of the stmt.verb column in characters (design §3.13). */
    public const VERB_MAX = 255;

    /** @var string[] Properties of a statement (2.4). */
    private const STATEMENT_KEYS = ['id', 'actor', 'verb', 'object', 'result', 'context', 'timestamp', 'stored',
        'authority', 'version', 'attachments'];

    /** @var string[] Properties of a SubStatement (2.4.4.3: no id, stored, version or authority). */
    private const SUBSTATEMENT_KEYS = ['objectType', 'actor', 'verb', 'object', 'result', 'context', 'timestamp',
        'attachments'];

    /** @var string[] Inverse functional identifiers (2.4.2.3). */
    private const IFIS = ['mbox', 'mbox_sha1sum', 'openid', 'account'];

    /** @var string[] Properties of an activity definition (2.4.4.1). */
    private const DEFINITION_KEYS = ['name', 'description', 'type', 'moreInfo', 'extensions', 'interactionType',
        'correctResponsesPattern', 'choices', 'scale', 'source', 'target', 'steps'];

    /** @var string[] Interaction types (2.4.4.1, interaction activities). */
    private const INTERACTION_TYPES = ['true-false', 'choice', 'fill-in', 'long-fill-in', 'matching', 'performance',
        'sequencing', 'likert', 'numeric', 'other'];

    /** @var string[] Lists of interaction components. */
    private const COMPONENT_LISTS = ['choices', 'scale', 'source', 'target', 'steps'];

    /** @var string[] Properties of a context (2.4.6). */
    private const CONTEXT_KEYS = ['registration', 'instructor', 'team', 'contextActivities', 'revision', 'platform',
        'language', 'statement', 'extensions'];

    /** @var string[] Properties of contextActivities (2.4.6.2). */
    private const CONTEXT_ACTIVITY_KEYS = ['parent', 'grouping', 'category', 'other'];

    /** @var string[] Properties of an attachment (2.4.11). */
    private const ATTACHMENT_KEYS = ['usageType', 'display', 'description', 'contentType', 'length', 'sha2', 'fileUrl'];

    /** @var string UUID in its standard string form (4.4). */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /** @var string Absolute IRI: a scheme and something after it (4.3); spaces and controls are checked apart. */
    private const IRI = '/^[A-Za-z][A-Za-z0-9+.-]*:.+$/sD';

    /** @var string RFC 5646 language tag: primary subtag of 2 to 8 letters, or x-/i- (private, grandfathered) (4.2). */
    private const LANGUAGE = '/^(?:[A-Za-z]{2,8}|[xXiI](?=-))(?:-[A-Za-z0-9]{1,8})*$/D';

    /** @var string mailto IRI of an e-mail address (2.4.2.3). */
    private const MBOX = '/^mailto:[^@\s]+@[^@\s]+$/D';

    /**
     * The statements of a decoded body: an object is a batch of one, a list stays as it is (empty is a no-op).
     *
     * @param mixed $decoded The body decoded with json_decode(…, true).
     * @return array List of statements (arrays).
     * @throws lrs_exception 400 err_invalidbatch.
     */
    public static function normalize_batch(mixed $decoded): array {
        if (!is_array($decoded)) {
            throw new lrs_exception(400, 'err_invalidbatch', '', 'xAPI 2.2');
        }
        if (!array_is_list($decoded)) {
            return [$decoded];
        }
        foreach ($decoded as $statement) {
            if (!self::is_object($statement)) {
                throw new lrs_exception(400, 'err_invalidbatch', '', 'xAPI 2.2');
            }
        }
        return $decoded;
    }

    /**
     * Checks one statement.
     *
     * @param array $statement Decoded statement.
     * @throws lrs_exception 400 err_invalidstatement, message = the field, rule = the xAPI section.
     */
    public static function validate(array $statement): void {
        if (!self::is_object($statement)) {
            self::fail('statement', '2.2');
        }
        $bad = self::bad_value_path($statement, '');
        if ($bad !== null) {
            self::fail($bad, '2.2');
        }
        self::statement($statement, '', false);
    }

    /**
     * A validated statement with every contextActivities value as a list (2.4.6.2: "The LRS MUST return every
     * value in the contextActivities Object as an array"), in its context and in the one of a SubStatement object.
     *
     * @param array $statement Statement that passed validate().
     * @return array
     */
    public static function normalize(array $statement): array {
        $activities = $statement['context']['contextActivities'] ?? null;
        if (is_array($activities)) {
            foreach ($activities as $key => $value) {
                if (is_array($value) && !array_is_list($value)) {
                    $statement['context']['contextActivities'][$key] = [$value];
                }
            }
        }
        if (is_array($statement['object'] ?? null) && ($statement['object']['objectType'] ?? null) === 'SubStatement') {
            $statement['object'] = self::normalize($statement['object']);
        }
        return $statement;
    }

    /**
     * Whether a value has U+0000 in a string or in a key, at any depth (the text column of PostgreSQL refuses it).
     *
     * @param mixed $value Decoded value.
     * @return bool
     */
    public static function contains_nul(mixed $value): bool {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (str_contains((string)$key, "\0") || self::contains_nul($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Path of the first string or key with U+0000, or number that is not finite (1e400 decodes to INF).
     *
     * @param mixed $value Value.
     * @param string $path Its path.
     * @return string|null
     */
    private static function bad_value_path(mixed $value, string $path): ?string {
        if (is_string($value)) {
            return str_contains($value, "\0") ? $path : null;
        }
        if (is_float($value)) {
            return is_finite($value) ? null : $path;
        }
        if (!is_array($value)) {
            return null;
        }
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            $itempath = $list ? $path . '[' . $key . ']' : self::join($path, (string)$key);
            if (!$list && str_contains((string)$key, "\0")) {
                return $itempath;
            }
            $bad = self::bad_value_path($item, $itempath);
            if ($bad !== null) {
                return $bad;
            }
        }
        return null;
    }

    /**
     * A statement or a SubStatement (2.4, 2.4.4.3).
     *
     * @param array $s Statement.
     * @param string $path Its path ('' at the top).
     * @param bool $sub Whether it is a SubStatement.
     */
    private static function statement(array $s, string $path, bool $sub): void {
        $rule = $sub ? '2.4.4.3' : '2.4';
        self::only_keys($s, $sub ? self::SUBSTATEMENT_KEYS : self::STATEMENT_KEYS, $path, $rule);
        foreach (['actor', 'verb', 'object'] as $required) {
            if (!array_key_exists($required, $s)) {
                self::fail(self::join($path, $required), $rule);
            }
        }
        if (array_key_exists('id', $s)) {
            self::uuid($s['id'], self::join($path, 'id'), '2.4.1');
        }
        self::agent_or_group($s['actor'], self::join($path, 'actor'), false);
        self::verb($s['verb'], self::join($path, 'verb'));
        $objecttype = self::statement_object($s['object'], self::join($path, 'object'), $sub);
        if (array_key_exists('result', $s)) {
            self::result($s['result'], self::join($path, 'result'));
        }
        if (array_key_exists('context', $s)) {
            self::context($s['context'], self::join($path, 'context'), $objecttype);
        }
        foreach (['timestamp' => '2.4.7', 'stored' => '2.4.8'] as $key => $keyrule) {
            if (array_key_exists($key, $s)) {
                self::timestamp($s[$key], self::join($path, $key), $keyrule);
            }
        }
        if (array_key_exists('authority', $s)) {
            self::agent_or_group($s['authority'], self::join($path, 'authority'), false);
        }
        $version = array_key_exists('version', $s) ? $s['version'] : '1.0.0';
        if (!is_string($version) || !preg_match('/^1\.0(?:\.\d+)?$/D', $version)) {
            self::fail(self::join($path, 'version'), '2.4.10');
        }
        if (array_key_exists('attachments', $s)) {
            self::attachments($s['attachments'], self::join($path, 'attachments'));
        }
    }

    /**
     * An Agent or a Group (2.4.2).
     *
     * @param mixed $a Value.
     * @param string $path Its path.
     * @param bool $agentonly Whether a Group is refused (members of a Group).
     * @return string 'Agent' or 'Group'.
     */
    private static function agent_or_group(mixed $a, string $path, bool $agentonly): string {
        if (!self::is_object($a)) {
            self::fail($path, '2.4.2');
        }
        $type = array_key_exists('objectType', $a) ? $a['objectType'] : 'Agent';
        if ($type !== 'Agent' && ($type !== 'Group' || $agentonly)) {
            self::fail(self::join($path, 'objectType'), $agentonly ? '2.4.2.2' : '2.4.2');
        }
        $group = $type === 'Group';
        $rule = $group ? '2.4.2.2' : '2.4.2.1';
        $keys = array_merge(['objectType', 'name'], self::IFIS, $group ? ['member'] : []);
        self::only_keys($a, $keys, $path, $rule);
        if (array_key_exists('name', $a) && !is_string($a['name'])) {
            self::fail(self::join($path, 'name'), $rule);
        }
        $ifis = array_values(array_intersect(self::IFIS, array_map('strval', array_keys($a))));
        if (count($ifis) > 1 || (!$group && count($ifis) === 0)) {
            self::fail($path, '2.4.2.3');
        }
        foreach ($ifis as $ifi) {
            self::ifi($ifi, $a[$ifi], self::join($path, $ifi));
        }
        if ($group) {
            $members = $a['member'] ?? null;
            $anonymous = count($ifis) === 0;
            $badmembers = !self::is_list($members) || ($anonymous && $members === []);
            if (($anonymous || array_key_exists('member', $a)) && $badmembers) {
                self::fail(self::join($path, 'member'), '2.4.2.2');
            }
            foreach ($members ?? [] as $i => $member) {
                self::agent_or_group($member, self::join($path, 'member') . '[' . $i . ']', true);
            }
        }
        return $type;
    }

    /**
     * One inverse functional identifier (2.4.2.3, 2.4.2.4).
     *
     * @param string $ifi Its name.
     * @param mixed $value Its value.
     * @param string $path Its path.
     */
    private static function ifi(string $ifi, mixed $value, string $path): void {
        switch ($ifi) {
            case 'mbox':
                $valid = is_string($value) && preg_match(self::MBOX, $value);
                break;
            case 'mbox_sha1sum':
                $valid = is_string($value) && preg_match('/^[0-9a-f]{40}$/iD', $value);
                break;
            case 'openid':
                $valid = self::is_iri($value);
                break;
            default:
                if (!self::is_object($value)) {
                    self::fail($path, '2.4.2.4');
                }
                self::only_keys($value, ['homePage', 'name'], $path, '2.4.2.4');
                if (!self::is_iri($value['homePage'] ?? null)) {
                    self::fail(self::join($path, 'homePage'), '2.4.2.4');
                }
                if (!is_string($value['name'] ?? null) || $value['name'] === '') {
                    self::fail(self::join($path, 'name'), '2.4.2.4');
                }
                return;
        }
        if (!$valid) {
            self::fail($path, '2.4.2.3');
        }
    }

    /**
     * The verb (2.4.3): an IRI that fits stmt.verb and an optional display.
     *
     * @param mixed $v Value.
     * @param string $path Its path.
     */
    private static function verb(mixed $v, string $path): void {
        if (!self::is_object($v)) {
            self::fail($path, '2.4.3');
        }
        self::only_keys($v, ['id', 'display'], $path, '2.4.3');
        $id = $v['id'] ?? null;
        if (!self::is_iri($id) || \core_text::strlen($id) > self::VERB_MAX) {
            self::fail(self::join($path, 'id'), '2.4.3');
        }
        if (array_key_exists('display', $v)) {
            self::language_map($v['display'], self::join($path, 'display'));
        }
    }

    /**
     * The object of a statement (2.4.4).
     *
     * @param mixed $o Value.
     * @param string $path Its path.
     * @param bool $insub Whether it is the object of a SubStatement (which cannot be another SubStatement).
     * @return string Its objectType.
     */
    private static function statement_object(mixed $o, string $path, bool $insub): string {
        if (!self::is_object($o)) {
            self::fail($path, '2.4.4');
        }
        $type = array_key_exists('objectType', $o) ? $o['objectType'] : 'Activity';
        if (!is_string($type)) {
            self::fail(self::join($path, 'objectType'), '2.4.4');
        }
        switch ($type) {
            case 'Activity':
                self::activity($o, $path);
                break;
            case 'Agent':
            case 'Group':
                self::agent_or_group($o, $path, false);
                break;
            case 'StatementRef':
                self::statement_ref($o, $path);
                break;
            case 'SubStatement':
                if ($insub) {
                    self::fail(self::join($path, 'objectType'), '2.4.4.3');
                }
                self::statement($o, $path, true);
                break;
            default:
                self::fail(self::join($path, 'objectType'), '2.4.4');
        }
        return $type;
    }

    /**
     * A StatementRef (2.4.4.3).
     *
     * @param mixed $o Value.
     * @param string $path Its path.
     */
    private static function statement_ref(mixed $o, string $path): void {
        if (!self::is_object($o) || ($o['objectType'] ?? null) !== 'StatementRef') {
            self::fail($path, '2.4.4.3');
        }
        self::only_keys($o, ['objectType', 'id'], $path, '2.4.4.3');
        self::uuid($o['id'] ?? null, self::join($path, 'id'), '2.4.4.3');
    }

    /**
     * An Activity (2.4.4.1), as object or in contextActivities.
     *
     * @param mixed $a Value.
     * @param string $path Its path.
     */
    private static function activity(mixed $a, string $path): void {
        if (!self::is_object($a)) {
            self::fail($path, '2.4.4.1');
        }
        self::only_keys($a, ['objectType', 'id', 'definition'], $path, '2.4.4.1');
        if (array_key_exists('objectType', $a) && $a['objectType'] !== 'Activity') {
            self::fail(self::join($path, 'objectType'), '2.4.4.1');
        }
        if (!self::is_iri($a['id'] ?? null)) {
            self::fail(self::join($path, 'id'), '2.4.4.1');
        }
        if (array_key_exists('definition', $a)) {
            self::definition($a['definition'], self::join($path, 'definition'));
        }
    }

    /**
     * An activity definition (2.4.4.1), with interaction components.
     *
     * @param mixed $d Value.
     * @param string $path Its path.
     */
    private static function definition(mixed $d, string $path): void {
        if (!self::is_object($d)) {
            self::fail($path, '2.4.4.1');
        }
        self::only_keys($d, self::DEFINITION_KEYS, $path, '2.4.4.1');
        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $d)) {
                self::language_map($d[$key], self::join($path, $key));
            }
        }
        foreach (['type', 'moreInfo'] as $key) {
            if (array_key_exists($key, $d) && !self::is_iri($d[$key])) {
                self::fail(self::join($path, $key), '2.4.4.1');
            }
        }
        if (array_key_exists('extensions', $d)) {
            self::extensions($d['extensions'], self::join($path, 'extensions'));
        }
        if (array_key_exists('interactionType', $d) && !in_array($d['interactionType'], self::INTERACTION_TYPES, true)) {
            self::fail(self::join($path, 'interactionType'), '2.4.4.1');
        }
        if (array_key_exists('correctResponsesPattern', $d)) {
            $pattern = $d['correctResponsesPattern'];
            if (!self::is_list($pattern) || count(array_filter($pattern, 'is_string')) !== count($pattern)) {
                self::fail(self::join($path, 'correctResponsesPattern'), '2.4.4.1');
            }
        }
        foreach (self::COMPONENT_LISTS as $key) {
            if (array_key_exists($key, $d)) {
                self::components($d[$key], self::join($path, $key));
            }
        }
    }

    /**
     * A list of interaction components: objects with a distinct string id and an optional description.
     *
     * @param mixed $list Value.
     * @param string $path Its path.
     */
    private static function components(mixed $list, string $path): void {
        if (!self::is_list($list)) {
            self::fail($path, '2.4.4.1');
        }
        $ids = [];
        foreach ($list as $i => $component) {
            $itempath = $path . '[' . $i . ']';
            if (!self::is_object($component)) {
                self::fail($itempath, '2.4.4.1');
            }
            self::only_keys($component, ['id', 'description'], $itempath, '2.4.4.1');
            $id = $component['id'] ?? null;
            if (!is_string($id) || $id === '') {
                self::fail(self::join($itempath, 'id'), '2.4.4.1');
            }
            if (isset($ids[$id])) {
                self::fail($path, '2.4.4.1');
            }
            $ids[$id] = true;
            if (array_key_exists('description', $component)) {
                self::language_map($component['description'], self::join($itempath, 'description'));
            }
        }
    }

    /**
     * The result (2.4.5) and its score (2.4.5.1).
     *
     * @param mixed $r Value.
     * @param string $path Its path.
     */
    private static function result(mixed $r, string $path): void {
        if (!self::is_object($r)) {
            self::fail($path, '2.4.5');
        }
        self::only_keys($r, ['score', 'success', 'completion', 'response', 'duration', 'extensions'], $path, '2.4.5');
        foreach (['success', 'completion'] as $key) {
            if (array_key_exists($key, $r) && !is_bool($r[$key])) {
                self::fail(self::join($path, $key), '2.4.5');
            }
        }
        if (array_key_exists('response', $r) && !is_string($r['response'])) {
            self::fail(self::join($path, 'response'), '2.4.5');
        }
        if (array_key_exists('duration', $r) && (!is_string($r['duration']) || !iso_duration::is_valid($r['duration']))) {
            self::fail(self::join($path, 'duration'), '4.6');
        }
        if (array_key_exists('extensions', $r)) {
            self::extensions($r['extensions'], self::join($path, 'extensions'));
        }
        if (array_key_exists('score', $r)) {
            self::score($r['score'], self::join($path, 'score'));
        }
    }

    /**
     * A score (2.4.5.1): numbers; scaled from -1 to 1; raw between min and max; min not above max.
     *
     * @param mixed $s Value.
     * @param string $path Its path.
     */
    private static function score(mixed $s, string $path): void {
        if (!self::is_object($s)) {
            self::fail($path, '2.4.5.1');
        }
        self::only_keys($s, ['scaled', 'raw', 'min', 'max'], $path, '2.4.5.1');
        foreach (['scaled', 'raw', 'min', 'max'] as $key) {
            if (array_key_exists($key, $s) && !self::is_number($s[$key])) {
                self::fail(self::join($path, $key), '2.4.5.1');
            }
        }
        if (isset($s['scaled']) && ($s['scaled'] < -1 || $s['scaled'] > 1)) {
            self::fail(self::join($path, 'scaled'), '2.4.5.1');
        }
        if (isset($s['min'], $s['max']) && $s['min'] > $s['max']) {
            self::fail(self::join($path, 'min'), '2.4.5.1');
        }
        if (isset($s['raw']) && ((isset($s['min']) && $s['raw'] < $s['min']) || (isset($s['max']) && $s['raw'] > $s['max']))) {
            self::fail(self::join($path, 'raw'), '2.4.5.1');
        }
    }

    /**
     * The context (2.4.6).
     *
     * @param mixed $c Value.
     * @param string $path Its path.
     * @param string $objecttype objectType of the object of the same statement (revision and platform need Activity).
     */
    private static function context(mixed $c, string $path, string $objecttype): void {
        if (!self::is_object($c)) {
            self::fail($path, '2.4.6');
        }
        self::only_keys($c, self::CONTEXT_KEYS, $path, '2.4.6');
        if (array_key_exists('registration', $c)) {
            self::uuid($c['registration'], self::join($path, 'registration'), '2.4.6.1');
        }
        if (array_key_exists('instructor', $c)) {
            self::agent_or_group($c['instructor'], self::join($path, 'instructor'), false);
        }
        if (array_key_exists('team', $c)) {
            if (self::agent_or_group($c['team'], self::join($path, 'team'), false) !== 'Group') {
                self::fail(self::join($path, 'team'), '2.4.6');
            }
        }
        if (array_key_exists('contextActivities', $c)) {
            self::context_activities($c['contextActivities'], self::join($path, 'contextActivities'));
        }
        foreach (['revision', 'platform'] as $key) {
            if (array_key_exists($key, $c) && (!is_string($c[$key]) || $objecttype !== 'Activity')) {
                self::fail(self::join($path, $key), '2.4.6');
            }
        }
        if (array_key_exists('language', $c) && !self::is_language($c['language'])) {
            self::fail(self::join($path, 'language'), '2.4.6');
        }
        if (array_key_exists('statement', $c)) {
            self::statement_ref($c['statement'], self::join($path, 'statement'));
        }
        if (array_key_exists('extensions', $c)) {
            self::extensions($c['extensions'], self::join($path, 'extensions'));
        }
    }

    /**
     * contextActivities (2.4.6.2): each key holds one Activity or a list of them.
     *
     * @param mixed $ca Value.
     * @param string $path Its path.
     */
    private static function context_activities(mixed $ca, string $path): void {
        if (!self::is_object($ca)) {
            self::fail($path, '2.4.6.2');
        }
        self::only_keys($ca, self::CONTEXT_ACTIVITY_KEYS, $path, '2.4.6.2');
        foreach ($ca as $key => $value) {
            $keypath = self::join($path, (string)$key);
            if (!is_array($value)) {
                self::fail($keypath, '2.4.6.2');
            }
            if (!array_is_list($value)) {
                self::activity($value, $keypath);
                continue;
            }
            foreach ($value as $i => $activity) {
                self::activity($activity, $keypath . '[' . $i . ']');
            }
        }
    }

    /**
     * Attachments (2.4.11). This LRS takes only JSON bodies, so every attachment must point to its fileUrl.
     *
     * @param mixed $list Value.
     * @param string $path Its path.
     */
    private static function attachments(mixed $list, string $path): void {
        if (!self::is_list($list)) {
            self::fail($path, '2.4.11');
        }
        foreach ($list as $i => $a) {
            $itempath = $path . '[' . $i . ']';
            if (!self::is_object($a)) {
                self::fail($itempath, '2.4.11');
            }
            self::only_keys($a, self::ATTACHMENT_KEYS, $itempath, '2.4.11');
            foreach (['usageType', 'fileUrl'] as $key) {
                if (!self::is_iri($a[$key] ?? null)) {
                    self::fail(self::join($itempath, $key), '2.4.11');
                }
            }
            self::language_map($a['display'] ?? null, self::join($itempath, 'display'));
            if (array_key_exists('description', $a)) {
                self::language_map($a['description'], self::join($itempath, 'description'));
            }
            if (!is_string($a['contentType'] ?? null) || $a['contentType'] === '') {
                self::fail(self::join($itempath, 'contentType'), '2.4.11');
            }
            if (!is_int($a['length'] ?? null) || $a['length'] < 0) {
                self::fail(self::join($itempath, 'length'), '2.4.11');
            }
            $sha2 = $a['sha2'] ?? null;
            if (!is_string($sha2) || !preg_match('/^[0-9a-f]+$/iD', $sha2) || !in_array(strlen($sha2), [56, 64, 96, 128], true)) {
                self::fail(self::join($itempath, 'sha2'), '2.4.11');
            }
        }
    }

    /**
     * A timestamp (4.5), the form xapi_time reads.
     *
     * @param mixed $t Value.
     * @param string $path Its path.
     * @param string $rule Section of the property.
     */
    private static function timestamp(mixed $t, string $path, string $rule): void {
        $valid = is_string($t);
        if ($valid) {
            try {
                xapi_time::to_ms($t);
            } catch (\Exception $e) {
                $valid = false;
            }
        }
        if (!$valid) {
            self::fail($path, $rule);
        }
    }

    /**
     * A language map (4.2): language tags to strings.
     *
     * @param mixed $m Value.
     * @param string $path Its path.
     */
    private static function language_map(mixed $m, string $path): void {
        if (!self::is_object($m)) {
            self::fail($path, '4.2');
        }
        foreach ($m as $tag => $text) {
            if (!self::is_language((string)$tag) || !is_string($text)) {
                self::fail($path, '4.2');
            }
        }
    }

    /**
     * Extensions (4.1): an object whose keys are IRIs; the values are any JSON.
     *
     * @param mixed $e Value.
     * @param string $path Its path.
     */
    private static function extensions(mixed $e, string $path): void {
        if (!self::is_object($e)) {
            self::fail($path, '4.1');
        }
        foreach (array_keys($e) as $key) {
            if (!self::is_iri((string)$key)) {
                self::fail($path, '4.1');
            }
        }
    }

    /**
     * A UUID (4.4).
     *
     * @param mixed $value Value.
     * @param string $path Its path.
     * @param string $rule Section of the property.
     */
    private static function uuid(mixed $value, string $path, string $rule): void {
        if (!is_string($value) || !preg_match(self::UUID, $value)) {
            self::fail($path, $rule);
        }
    }

    /**
     * Refuses properties the specification does not define for this object.
     *
     * @param array $o Object.
     * @param string[] $allowed Its properties.
     * @param string $path Its path.
     * @param string $rule Its section.
     */
    private static function only_keys(array $o, array $allowed, string $path, string $rule): void {
        foreach (array_keys($o) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                self::fail(self::join($path, (string)$key), $rule);
            }
        }
    }

    /**
     * Whether a decoded value is a JSON object ([] too: an empty object decodes as []).
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function is_object(mixed $value): bool {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Whether a decoded value is a JSON array.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function is_list(mixed $value): bool {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Whether a value is a finite JSON number (true and false are not numbers).
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function is_number(mixed $value): bool {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /**
     * Whether a value is an absolute IRI with no white space or control characters (4.3).
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function is_iri(mixed $value): bool {
        return is_string($value) && preg_match(self::IRI, $value) && !preg_match('/[\x00-\x20\x7F]/', $value);
    }

    /**
     * Whether a value is an RFC 5646 language tag.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function is_language(mixed $value): bool {
        return is_string($value) && preg_match(self::LANGUAGE, $value);
    }

    /**
     * Joins a path and a property name.
     *
     * @param string $path Path ('' at the top).
     * @param string $key Property.
     * @return string
     */
    private static function join(string $path, string $key): string {
        return $path === '' ? $key : $path . '.' . $key;
    }

    /**
     * Throws the 400 of a field.
     *
     * @param string $path Field.
     * @param string $section Section of xAPI 1.0.3 Part Two.
     * @return never
     * @throws lrs_exception
     */
    private static function fail(string $path, string $section): never {
        throw new lrs_exception(400, 'err_invalidstatement', display_text::of($path), 'xAPI ' . $section);
    }
}
