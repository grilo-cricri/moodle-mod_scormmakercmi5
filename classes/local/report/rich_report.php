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

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\package\course_structure;
use mod_scormmakercmi5\local\runtime\launcher;

/**
 * Figures of the rich report of a verified SCORM Maker package (R8, design §8.2), from the statements only (R8.3).
 *
 * Every query is filtered by the package version of the statements (smversion, R8.2) and reads indexed columns only:
 * per learner by regid (index regtimestamp: regid, timestampms); per activity by scormmakercmi5id + smversion + smkind
 * (index instanceversionkind), with the verb, the user filter and the result columns read from the rows found. The
 * payload is read only for the last answer of each question of ONE learner (the response). The texts of the lessons
 * and questions come from itemdef (design §3.12), never from the payloads.
 *
 * Raw SQL, justified (global constraints, design §8.2, §16): GROUP BY aggregates, the average of per-registration
 * sums and the "last per registration" subquery (NOT EXISTS of a later row; ties broken by id) cannot be written with
 * the CRUD API. Every query uses {table} names and named placeholders; the user filter goes through get_in_or_equal;
 * there is no caller-chosen ordering (fixed ORDER BY clauses only).
 *
 * Texts returned here are raw (they come from the AU): the caller escapes them on output (Mustache {{ }}).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rich_report {
    /** @var string smkind of a lesson. */
    public const KIND_LESSON = 'licao';

    /** @var string smkind of a question. */
    public const KIND_QUESTION = 'questao';

    /** @var string[] Component lists of an interaction definition shown as alternatives (xAPI interaction types). */
    private const COMPONENT_LISTS = ['choices', 'scale', 'source', 'target', 'steps'];

    /**
     * The package versions with statements of the SCORM Maker profile, with the date of their package (R8.2).
     *
     * The package of a version is the pkg whose signed manifest has that version; otherwise the newest package the
     * statements of that version were stored under. Newest first.
     *
     * @param int $instanceid Instance id.
     * @return array smversion => timecreated of the package.
     */
    public static function versions(int $instanceid): array {
        global $DB;
        $sql = "SELECT s.smversion, MAX(s.pkgid) AS pkgid
                  FROM {scormmakercmi5_stmt} s
                 WHERE s.scormmakercmi5id = :instanceid AND s.smprofile = 1 AND s.smversion IS NOT NULL
              GROUP BY s.smversion";
        $rows = $DB->get_records_sql($sql, ['instanceid' => $instanceid]);
        $pkgs = $DB->get_records(
            'scormmakercmi5_pkg',
            ['scormmakercmi5id' => $instanceid],
            'versionnum',
            'id, smversion, timecreated'
        );
        $byversion = [];
        foreach ($pkgs as $pkg) {
            if ($pkg->smversion !== null) {
                $byversion[(string)$pkg->smversion] = (int)$pkg->timecreated;
            }
        }
        $versions = [];
        foreach ($rows as $row) {
            $version = (string)$row->smversion;
            $versions[$version] = $byversion[$version] ?? (int)($pkgs[$row->pkgid]->timecreated ?? 0);
        }
        // Ties by version in code point order (strcmp): <=> would compare "10" and "9" as numbers.
        uksort($versions, static fn($a, $b): int => ($versions[$b] <=> $versions[$a]) ?: strcmp((string)$a, (string)$b));
        return $versions;
    }

    /**
     * The package of a version (the one whose signature the rich report rechecks).
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @return \stdClass|null scormmakercmi5_pkg row.
     */
    public static function package_for_version(int $instanceid, string $smversion): ?\stdClass {
        global $DB;
        $pkgs = $DB->get_records(
            'scormmakercmi5_pkg',
            ['scormmakercmi5id' => $instanceid, 'smversion' => $smversion],
            'versionnum DESC',
            '*',
            0,
            1
        );
        if ($pkgs) {
            return reset($pkgs);
        }
        $pkgid = $DB->get_field_sql(
            "SELECT MAX(s.pkgid)
               FROM {scormmakercmi5_stmt} s
              WHERE s.scormmakercmi5id = :instanceid AND s.smversion = :smversion",
            ['instanceid' => $instanceid, 'smversion' => $smversion]
        );
        return $pkgid ? ($DB->get_record('scormmakercmi5_pkg', ['id' => $pkgid]) ?: null) : null;
    }

    /**
     * Lessons a learner visited: number of "experienced" and the summed duration, in the order of the first visit.
     *
     * @param int $regid Registration id.
     * @param string $smversion Package version.
     * @return \stdClass[] smitem, name, visitas, tempo (ms).
     */
    public static function lessons_for_registration(int $regid, string $smversion): array {
        global $DB;
        $sql = "SELECT s.smitem, COUNT(1) AS visitas, SUM(s.durationms) AS tempo, MIN(s.timestampms) AS firstms
                  FROM {scormmakercmi5_stmt} s
                 WHERE s.regid = :regid AND s.smprofile = 1 AND s.smversion = :smversion AND s.smkind = :kind
                       AND s.verb = :verb
              GROUP BY s.smitem
              ORDER BY firstms, s.smitem";
        $rows = $DB->get_records_sql($sql, ['regid' => $regid, 'smversion' => $smversion,
            'kind' => self::KIND_LESSON, 'verb' => vocab::VERB_EXPERIENCED]);
        $names = self::items(self::instance_of($regid), $smversion, self::KIND_LESSON);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (object)[
                'smitem' => (string)$row->smitem,
                'name' => $names[(string)$row->smitem]->name ?? '',
                'visitas' => (int)$row->visitas,
                'tempo' => (int)$row->tempo,
            ];
        }
        return $out;
    }

    /**
     * The progress of a learner over time ("progressed" with the progress extension), oldest first.
     *
     * @param int $regid Registration id.
     * @param string $smversion Package version.
     * @return \stdClass[] timestampms, progress (0–100).
     */
    public static function progress_timeline(int $regid, string $smversion): array {
        global $DB;
        $sql = "SELECT s.id, s.timestampms, s.progress
                  FROM {scormmakercmi5_stmt} s
                 WHERE s.regid = :regid AND s.smprofile = 1 AND s.smversion = :smversion AND s.verb = :verb
                       AND s.progress IS NOT NULL
              ORDER BY s.timestampms, s.id";
        $rows = $DB->get_records_sql($sql, ['regid' => $regid, 'smversion' => $smversion,
            'verb' => vocab::VERB_PROGRESSED]);
        return array_values(array_map(
            static fn($row) => (object)['timestampms' => (int)$row->timestampms, 'progress' => (int)$row->progress],
            $rows
        ));
    }

    /**
     * The last answer of a learner to each question: success and response (read from the payload of those rows only).
     *
     * @param int $regid Registration id.
     * @param string $smversion Package version.
     * @return \stdClass[] smitem, question, success (?bool), response (?string, readable: the texts of the
     *     alternatives), rawresponse (?string, as sent), timestampms.
     */
    public static function answers_for_registration(int $regid, string $smversion): array {
        global $DB;
        $params = ['regid' => $regid, 'smversion' => $smversion, 'kind' => self::KIND_QUESTION,
            'verb' => vocab::VERB_ANSWERED];
        $sql = "SELECT s.id, s.smitem, s.success, s.timestampms
                  FROM {scormmakercmi5_stmt} s
                 WHERE s.regid = :regid AND s.smprofile = 1 AND s.smversion = :smversion AND s.smkind = :kind
                       AND s.verb = :verb AND " . self::is_last('s') . "
              ORDER BY s.smitem";
        $rows = $DB->get_records_sql($sql, $params);
        $payloads = $rows ? $DB->get_records_list('scormmakercmi5_stmt', 'id', array_keys($rows), '', 'id, payload') : [];
        $items = self::items(self::instance_of($regid), $smversion, self::KIND_QUESTION);
        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string)($payloads[$row->id]->payload ?? ''), true);
            $response = is_array($payload) ? ($payload['result']['response'] ?? null) : null;
            $response = is_scalar($response) ? (string)$response : null;
            $item = $items[(string)$row->smitem] ?? null;
            $out[] = (object)[
                'smitem' => (string)$row->smitem,
                'question' => $item->question ?? '',
                'success' => $row->success === null ? null : (bool)$row->success,
                'response' => $response === null ? null : self::readable_response($item->type ?? '', $item->texts ?? [], $response),
                'rawresponse' => $response,
                'timestampms' => (int)$row->timestampms,
            ];
        }
        return $out;
    }

    /**
     * Average time per lesson: the average over the registrations of the time each spent in the lesson.
     *
     * "learners" is the base of that average: registrations with a known time (a visit without duration, or with a
     * duration in years/months, has a null durationms and is left out of both).
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param group_filter $filter Group filter.
     * @return \stdClass[] smitem, name, learners, average (ms).
     */
    public static function avg_time_per_lesson(int $instanceid, string $smversion, group_filter $filter): array {
        global $DB;
        if ($filter->nobody()) {
            return [];
        }
        [$where, $params] = self::activity_where($instanceid, $smversion, self::KIND_LESSON, vocab::VERB_EXPERIENCED, $filter);
        $sql = "SELECT x.smitem, AVG(x.t) AS average, COUNT(x.t) AS learners
                  FROM (SELECT s.regid, s.smitem, SUM(s.durationms) AS t
                          FROM {scormmakercmi5_stmt} s
                         WHERE {$where}
                      GROUP BY s.regid, s.smitem) x
              GROUP BY x.smitem
              ORDER BY x.smitem";
        $names = self::items($instanceid, $smversion, self::KIND_LESSON);
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[] = (object)[
                'smitem' => (string)$row->smitem,
                'name' => $names[(string)$row->smitem]->name ?? '',
                'learners' => (int)$row->learners,
                'average' => (int)round((float)$row->average),
            ];
        }
        return $out;
    }

    /**
     * Where learners stop: for each registration that has an AU neither completed nor waived, a launch session that
     * ended (terminated or abandoned) and no launch session still open, the lesson of its last "experienced".
     *
     * A learner whose session is still open is not counted: they have not stopped yet (the cleanup task abandons idle
     * sessions after sessiontimeout).
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param group_filter $filter Group filter.
     * @return \stdClass[] smitem, name, learners, percent (of the learners who stopped), most first.
     */
    public static function dropoff_by_lesson(int $instanceid, string $smversion, group_filter $filter): array {
        global $DB;
        if ($filter->nobody()) {
            return [];
        }
        [$where, $params] = self::activity_where($instanceid, $smversion, self::KIND_LESSON, vocab::VERB_EXPERIENCED, $filter);
        $sql = "SELECT s.smitem, COUNT(1) AS learners
                  FROM {scormmakercmi5_stmt} s
                 WHERE {$where} AND " . self::is_last('s', false) . "
                       AND EXISTS (SELECT 1 FROM {scormmakercmi5_regau} ra
                                    WHERE ra.regid = s.regid AND ra.completed = 0 AND ra.waived = 0)
                       AND EXISTS (SELECT 1 FROM {scormmakercmi5_session} se
                                    WHERE se.regid = s.regid AND se.kind = :kindended
                                          AND se.status IN (:terminated, :abandoned))
                       AND NOT EXISTS (SELECT 1 FROM {scormmakercmi5_session} so
                                        WHERE so.regid = s.regid AND so.kind = :kindopen
                                              AND so.status IN (:launched, :initialized))
              GROUP BY s.smitem";
        $params += [
            'kindended' => launcher::KIND_LAUNCH, 'terminated' => launcher::STATUS_TERMINATED,
            'abandoned' => launcher::STATUS_ABANDONED, 'kindopen' => launcher::KIND_LAUNCH,
            'launched' => launcher::STATUS_LAUNCHED, 'initialized' => launcher::STATUS_INITIALIZED,
        ];
        $rows = $DB->get_records_sql($sql, $params);
        $total = array_sum(array_map(static fn($row): int => (int)$row->learners, $rows));
        $names = self::items($instanceid, $smversion, self::KIND_LESSON);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (object)[
                'smitem' => (string)$row->smitem,
                'name' => $names[(string)$row->smitem]->name ?? '',
                'learners' => (int)$row->learners,
                'percent' => round(100 * (int)$row->learners / $total, 1),
            ];
        }
        // Ties by lesson id in code point order (strcmp): <=> would compare "10" and "9" as numbers.
        usort($out, static fn($a, $b): int => ($b->learners <=> $a->learners) ?: strcmp($a->smitem, $b->smitem));
        return $out;
    }

    /**
     * Success rate per question: learners who answered, answers, and right answers counting only the LAST answer of
     * each registration; with the question text, the alternatives and the correct response from itemdef.
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param group_filter $filter Group filter.
     * @return \stdClass[] smitem, question, choices ([id, text]), correct, respondents, attempts, correctlast, rate (%).
     */
    public static function question_success_rates(int $instanceid, string $smversion, group_filter $filter): array {
        global $DB;
        if ($filter->nobody()) {
            return [];
        }
        [$where, $params] = self::activity_where($instanceid, $smversion, self::KIND_QUESTION, vocab::VERB_ANSWERED, $filter);
        $counts = $DB->get_records_sql(
            "SELECT s.smitem, COUNT(DISTINCT s.regid) AS respondents, COUNT(1) AS attempts
               FROM {scormmakercmi5_stmt} s
              WHERE {$where}
           GROUP BY s.smitem
           ORDER BY s.smitem",
            $params
        );
        $right = $DB->get_records_sql_menu(
            "SELECT s.smitem, SUM(CASE WHEN s.success = 1 THEN 1 ELSE 0 END) AS correctlast
               FROM {scormmakercmi5_stmt} s
              WHERE {$where} AND " . self::is_last('s') . "
           GROUP BY s.smitem",
            $params
        );
        $items = self::items($instanceid, $smversion, self::KIND_QUESTION);
        $out = [];
        foreach ($counts as $row) {
            $smitem = (string)$row->smitem;
            $respondents = (int)$row->respondents;
            $correctlast = (int)($right[$smitem] ?? 0);
            $out[] = (object)[
                'smitem' => $smitem,
                'question' => $items[$smitem]->question ?? '',
                'choices' => $items[$smitem]->choices ?? [],
                'correct' => $items[$smitem]->correct ?? '',
                'respondents' => $respondents,
                'attempts' => (int)$row->attempts,
                'correctlast' => $correctlast,
                'rate' => $respondents ? round(100 * $correctlast / $respondents, 1) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * Learners with statements of a version, a page of them, by name.
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param group_filter $filter Group filter.
     * @param int $page Page (from 0).
     * @param int $perpage Rows per page.
     * @return array [\stdClass[] users with regid and the name fields, int total].
     */
    public static function learners(int $instanceid, string $smversion, group_filter $filter, int $page, int $perpage): array {
        global $DB;
        if ($filter->nobody()) {
            return [[], 0];
        }
        [$users, $gparams] = $filter->sql('r.userid', 'l');
        $params = ['instanceid' => $instanceid, 'smversion' => $smversion] + $gparams;
        $from = "FROM {scormmakercmi5_reg} r
                 JOIN {user} u ON u.id = r.userid
                WHERE r.scormmakercmi5id = :instanceid AND u.deleted = 0{$users}
                      AND EXISTS (SELECT 1 FROM {scormmakercmi5_stmt} s
                                   WHERE s.regid = r.id AND s.smprofile = 1 AND s.smversion = :smversion)";
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $rows = $DB->get_records_sql(
            "SELECT u.id, r.id AS regid, {$namefields} {$from} ORDER BY u.lastname, u.firstname, u.id",
            $params,
            $page * $perpage,
            $perpage
        );
        return [array_values($rows), (int)$DB->count_records_sql("SELECT COUNT(1) {$from}", $params)];
    }

    /**
     * The WHERE of the per-activity queries (alias s).
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param string $kind smkind.
     * @param string $verb Verb IRI.
     * @param group_filter $filter Group filter.
     * @return array [string sql, array params].
     */
    private static function activity_where(
        int $instanceid,
        string $smversion,
        string $kind,
        string $verb,
        group_filter $filter
    ): array {
        $where = 's.scormmakercmi5id = :instanceid AND s.smprofile = 1 AND s.smversion = :smversion AND s.smkind = :kind
                  AND s.verb = :verb';
        $params = ['instanceid' => $instanceid, 'smversion' => $smversion, 'kind' => $kind, 'verb' => $verb];
        [$gsql, $gparams] = $filter->sql('s.userid', 'f');
        return [$where . $gsql, $params + $gparams];
    }

    /**
     * Condition: no later row of the same registration, version, kind and verb — and item, when $peritem — (ties
     * broken by id).
     *
     * Correlated on the row's own columns, so it adds no parameter; it walks index regtimestamp (regid, timestampms).
     *
     * @param string $alias Alias of the outer row.
     * @param bool $peritem Whether "last" is per item (last answer of each question) or over every item (last lesson).
     * @return string SQL.
     */
    private static function is_last(string $alias, bool $peritem = true): string {
        $item = $peritem ? " AND n.smitem = {$alias}.smitem" : '';
        return "NOT EXISTS (SELECT 1 FROM {scormmakercmi5_stmt} n
                             WHERE n.regid = {$alias}.regid AND n.timestampms >= {$alias}.timestampms
                                   AND n.smversion = {$alias}.smversion AND n.smkind = {$alias}.smkind
                                   AND n.verb = {$alias}.verb{$item}
                                   AND (n.timestampms > {$alias}.timestampms OR n.id > {$alias}.id))";
    }

    /**
     * The instance of a registration.
     *
     * @param int $regid Registration id.
     * @return int
     */
    private static function instance_of(int $regid): int {
        global $DB;
        return (int)$DB->get_field('scormmakercmi5_reg', 'scormmakercmi5id', ['id' => $regid], MUST_EXIST);
    }

    /**
     * The texts of the items of a version from itemdef, in the language of the viewer (the newest package wins).
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param string $kind smkind.
     * @return \stdClass[] smitem => name, question, choices ([id, text]), correct (readable), type (xAPI interaction
     *     type), texts (component list => id => text).
     */
    private static function items(int $instanceid, string $smversion, string $kind): array {
        global $DB;
        $sql = "SELECT d.id, d.smitem, d.name, d.definition
                  FROM {scormmakercmi5_itemdef} d
                  JOIN {scormmakercmi5_pkg} p ON p.id = d.pkgid
                 WHERE p.scormmakercmi5id = :instanceid AND d.smversion = :smversion AND d.smkind = :kind
              ORDER BY d.pkgid DESC";
        $lang = current_language();
        $items = [];
        $rs = $DB->get_recordset_sql($sql, ['instanceid' => $instanceid, 'smversion' => $smversion, 'kind' => $kind]);
        foreach ($rs as $row) {
            $smitem = (string)$row->smitem;
            if (isset($items[$smitem])) {
                continue;
            }
            $definition = json_decode((string)$row->definition, true);
            $definition = is_array($definition) ? $definition : [];
            $namemap = $row->name !== null ? json_decode((string)$row->name, true) : ($definition['name'] ?? null);
            $name = course_structure::pick(self::langmap($namemap), $lang);
            $description = course_structure::pick(self::langmap($definition['description'] ?? null), $lang);
            $choices = [];
            $texts = [];
            foreach (self::COMPONENT_LISTS as $list) {
                foreach (is_array($definition[$list] ?? null) ? $definition[$list] : [] as $component) {
                    if (is_array($component) && is_scalar($component['id'] ?? null)) {
                        $id = (string)$component['id'];
                        $text = course_structure::pick(self::langmap($component['description'] ?? null), $lang);
                        $choices[] = ['id' => $id, 'text' => $text];
                        $texts[$list][$id] = $text;
                    }
                }
            }
            $type = is_string($definition['interactionType'] ?? null) ? $definition['interactionType'] : '';
            $pattern = is_array($definition['correctResponsesPattern'] ?? null) ? $definition['correctResponsesPattern'] : [];
            $items[$smitem] = (object)[
                'name' => $name,
                'question' => $description !== '' ? $description : $name,
                'choices' => $choices,
                'correct' => implode(' | ', array_map(
                    static fn($value): string => self::readable_response($type, $texts, (string)$value),
                    array_filter($pattern, 'is_scalar')
                )),
                'type' => $type,
                'texts' => $texts,
            ];
        }
        $rs->close();
        return $items;
    }

    /**
     * An xAPI response (or correct response pattern) as the teacher reads it: the ids replaced by the texts of the
     * alternatives in itemdef (choice, likert: "A, B"; true-false: the True/False strings; matching: "source → target;
     * …"; sequencing: "1. A, 2. B, …"). An id that itemdef does not know, an empty text and the other interaction types
     * (fill-in, numeric, performance, other…) keep the raw value. The text is raw (it comes from the AU): the caller
     * escapes it on output.
     *
     * @param string $type xAPI interaction type.
     * @param array $texts Component list (choices, scale, source, target, steps) => id => text.
     * @param string $value Raw response.
     * @return string
     */
    public static function readable_response(string $type, array $texts, string $value): string {
        if ($value === '') {
            return '';
        }
        $text = static function (string $list, string $id) use ($texts): string {
            $found = $texts[$list][$id] ?? '';
            return is_string($found) && $found !== '' ? $found : $id;
        };
        switch ($type) {
            case 'true-false':
                if ($value === 'true' || $value === 'false') {
                    return get_string($value === 'true' ? 'richtrue' : 'richfalse', 'mod_scormmakercmi5');
                }
                return $value;
            case 'choice':
            case 'likert':
                $list = $type === 'choice' ? 'choices' : 'scale';
                return implode(', ', array_map(static fn($id): string => $text($list, $id), explode('[,]', $value)));
            case 'sequencing':
                $out = [];
                foreach (explode('[,]', $value) as $n => $id) {
                    $out[] = ($n + 1) . '. ' . $text('choices', $id);
                }
                return implode(', ', $out);
            case 'matching':
                $out = [];
                foreach (explode('[,]', $value) as $pair) {
                    $parts = explode('[.]', $pair, 2);
                    $out[] = count($parts) === 2 ? $text('source', $parts[0]) . ' → ' . $text('target', $parts[1]) : $pair;
                }
                return implode('; ', $out);
            default:
                return $value;
        }
    }

    /**
     * A language map with string values only.
     *
     * @param mixed $value Decoded JSON.
     * @return array lang => text.
     */
    private static function langmap(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        return array_filter($value, static fn($text, $tag): bool => is_string($text) && is_string($tag), ARRAY_FILTER_USE_BOTH);
    }
}
