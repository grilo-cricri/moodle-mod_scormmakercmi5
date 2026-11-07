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

namespace mod_scormmakercmi5\local\grading;

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\statement_store;

/**
 * The grade of a learner in the gradebook (design §6.6; R6.1).
 *
 * Per AU, the passed/failed statements with score.scaled leave scorehighest and scorelast in regau (the ingestion,
 * Task 15). The grade of the registration is the average, over the AUs that have a score, of scorehighest
 * (grademethod 0, the default) or scorelast (1), on the 0–1 scale; the gradebook gets it times the maximum grade.
 * There is one registration per learner and instance, so "highest" and "last" are taken across the sessions of it.
 * An AU with no such score and no masteryScore (a SCORM Maker course with no pass score, Task 4 of the list after D;
 * its player sends "scored" only then) is graded by its "scored" statements: allowed statements about the AU itself
 * with score.scaled 0–1, from sessions launched in Normal mode (a Browse or Review session never grades, also from an
 * older or third-party package). An AU with a masteryScore is graded by passed/failed only. The grade of a session
 * is its latest "scored" by timestamp (a learner who restarts inside the course gets the new grade); across the
 * sessions the grading method applies as for passed/failed: the highest session grade, or the one of the session
 * whose latest "scored" is the newest.
 * No AU with a score → rawgrade null. Everything is recomputed from regau, so repair_user() (on view.php and launch)
 * and scormmakercmi5_update_grades() repair a grade lost when the step after the commit failed.
 *
 * A scale or "no grade" (instance grade ≤ 0) gets no automatic grade, as in mod_h5pactivity: a score has no
 * meaning on a scale.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_calculator {
    /** @var int Grading method: highest score of each AU. */
    public const METHOD_HIGHEST = 0;

    /** @var int Grading method: last score of each AU. */
    public const METHOD_LAST = 1;

    /** @var int Decimals kept, those of reg.grade and of the gradebook. */
    private const DECIMALS = 5;

    /**
     * The grade of a registration, 0–1, or null when no AU has a score.
     *
     * @param \stdClass $reg Registration (id).
     * @param int $grademethod METHOD_HIGHEST or METHOD_LAST.
     * @return float|null
     */
    public static function registration_grade(\stdClass $reg, int $grademethod): ?float {
        global $DB;
        $rows = array_values($DB->get_records_select(
            'scormmakercmi5_regau',
            'regid = :regid AND scorehighest IS NOT NULL',
            ['regid' => $reg->id],
            '',
            'id, scorehighest, scorelast'
        ));
        foreach (self::scored('s.regid = :regid', ['regid' => $reg->id]) as $byau) {
            foreach ($byau as $score) {
                $rows[] = $score;
            }
        }
        return self::average($rows, $grademethod);
    }

    /**
     * The grades of an instance for the gradebook, by user: rawgrade = grade × maximum grade, or null (no AU with a
     * score, or a scale / no grade, whose items get no automatic grade).
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid One learner, or 0 for every learner with a registration.
     * @return array userid => object with userid and rawgrade.
     */
    public static function user_grades(\stdClass $instance, int $userid = 0): array {
        $grades = [];
        foreach (self::compute($instance, $userid) as $uid => $row) {
            $grades[$uid] = self::gradebook_grade($instance, $uid, $row->grade);
        }
        return $grades;
    }

    /**
     * Recomputes the grade of a learner: reg.grade and the gradebook (design §6.6). Called after the commit of a
     * batch with a scored passed/failed.
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid Learner.
     */
    public static function update_user(\stdClass $instance, int $userid): void {
        self::update($instance, $userid);
    }

    /**
     * Repairs a grade left behind (final review N-1): the grade update after the commit of a batch failed, so reg.grade
     * or the raw grade of the gradebook differs from what regau gives. Cheap when nothing is wrong (the regau read,
     * the grade item and the grade), so view.php and launch call it on every visit, as custom_completion::repair_user()
     * does for the completion. Without a registration (an attempt deleted, a privacy purge or a reset whose grade step
     * failed) the expected grade is none. A scale or no grade and a locked grade are left alone.
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid Learner.
     */
    public static function repair_user(\stdClass $instance, int $userid): void {
        global $CFG;
        if ((int)$instance->grade <= 0) {
            return;
        }
        $row = self::compute($instance, $userid)[$userid];
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch([
            'courseid' => $instance->course,
            'itemtype' => 'mod',
            'itemmodule' => 'scormmakercmi5',
            'iteminstance' => $instance->id,
            'itemnumber' => 0,
        ]);
        if (!$item) {
            if ($row->regid !== null) {
                self::update($instance, $userid);
            }
            return;
        }
        if ($item->is_locked()) {
            return;
        }
        $stale = $row->regid !== null && $row->grade !== $row->stored;
        if (!$stale) {
            $gradegrade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            if ($gradegrade && $gradegrade->is_locked()) {
                return;
            }
            $expected = self::gradebook_grade($instance, $userid, $row->grade)->rawgrade;
            $actual = $gradegrade && $gradegrade->rawgrade !== null ? (float)$gradegrade->rawgrade : null;
            $stale = ($expected === null) !== ($actual === null)
                || ($expected !== null && abs($expected - $actual) >= 10 ** -self::DECIMALS);
        }
        if ($stale) {
            self::update($instance, $userid);
        }
    }

    /**
     * Recomputes the grades of one learner or of all (userid 0) from one read of regau, writes reg.grade where it
     * changed and pushes the grades to the gradebook (scormmakercmi5_update_grades(), settings change, rescale).
     *
     * @param \stdClass $instance Instance row (cmidnumber optional).
     * @param int $userid Learner, or 0 for all.
     */
    public static function update(\stdClass $instance, int $userid = 0): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
        $grades = [];
        foreach (self::compute($instance, $userid) as $uid => $row) {
            if ($row->regid !== null && $row->grade !== $row->stored) {
                $DB->set_field('scormmakercmi5_reg', 'grade', $row->grade, ['id' => $row->regid]);
            }
            $grades[$uid] = self::gradebook_grade($instance, $uid, $row->grade);
        }
        if ((int)$instance->grade <= 0) {
            // A scale or no grade: the item is kept up to date, with no automatic grade.
            scormmakercmi5_grade_item_update($instance);
            return;
        }
        scormmakercmi5_grade_item_update($instance, $grades ?: null);
    }

    /**
     * The registration grades of an instance, from one query over reg and the scored regau rows.
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid One learner, or 0 for all.
     * @return array userid => object with regid (null: no registration), grade (0–1 or null) and stored (reg.grade).
     */
    private static function compute(\stdClass $instance, int $userid): array {
        global $DB;
        $params = ['instanceid' => $instance->id];
        $where = 'r.scormmakercmi5id = :instanceid';
        if ($userid) {
            $where .= ' AND r.userid = :userid';
            $params['userid'] = $userid;
        }
        $sql = "SELECT r.id AS regid, r.userid, r.grade AS stored, ra.id AS regauid, ra.scorehighest, ra.scorelast
                  FROM {scormmakercmi5_reg} r
             LEFT JOIN {scormmakercmi5_regau} ra ON ra.regid = r.id AND ra.scorehighest IS NOT NULL
                 WHERE $where";
        $rows = [];
        $regs = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $uid = (int)$row->userid;
            $regs[$uid] = $regs[$uid] ?? $row;
            $rows[$uid] = $rows[$uid] ?? [];
            if ($row->regauid !== null) {
                $rows[$uid][] = $row;
            }
        }
        $rs->close();
        $where = 's.scormmakercmi5id = :instanceid' . ($userid ? ' AND s.userid = :userid' : '');
        $userofreg = [];
        foreach ($regs as $uid => $reg) {
            $userofreg[(int)$reg->regid] = $uid;
        }
        foreach (self::scored($where, $params) as $regid => $byau) {
            if (!isset($userofreg[$regid])) {
                continue;
            }
            foreach ($byau as $score) {
                $rows[$userofreg[$regid]][] = $score;
            }
        }
        $result = [];
        foreach ($regs as $uid => $reg) {
            $grade = self::average($rows[$uid], (int)$instance->grademethod);
            $result[$uid] = (object)[
                'regid' => (int)$reg->regid,
                'grade' => $grade === null ? null : round($grade, self::DECIMALS),
                'stored' => $reg->stored === null ? null : round((float)$reg->stored, self::DECIMALS),
            ];
        }
        if ($userid && !$result) {
            // No registration: nothing to grade, and an existing grade is cleared.
            $result[$userid] = (object)['regid' => null, 'grade' => null, 'stored' => null];
        }
        return $result;
    }

    /**
     * The "scored" grades of each AU with no masteryScore and no passed/failed score (scorehighest null in its regau;
     * an AU with a cut-off score is graded by passed/failed only, so a "scored" sent to it is ignored), from the sessions
     * launched in Normal mode: allowed statements of the AU about the AU itself (its activityId), with score.scaled
     * from 0 to 1. The grade of a session is its latest "scored" by timestamp (ties: the one stored last); scorehighest
     * is the highest session grade, scorelast the grade of the session whose latest "scored" is the newest.
     *
     * @param string $where Condition on the statements (alias s).
     * @param array $params Its parameters.
     * @return array regid => [auid => object with scorehighest and scorelast].
     */
    private static function scored(string $where, array $params): array {
        global $DB;
        $sql = "SELECT s.id, s.regid, s.auid, s.sessionid, s.objecthash, s.scorescaled, a.lmsactivityid
                  FROM {scormmakercmi5_stmt} s
                  JOIN {scormmakercmi5_regau} ra ON ra.regid = s.regid AND ra.auid = s.auid AND ra.scorehighest IS NULL
                  JOIN {scormmakercmi5_session} se ON se.id = s.sessionid AND se.launchmode = :normal
                  JOIN {scormmakercmi5_au} a ON a.id = s.auid AND a.masteryscore IS NULL
                 WHERE $where AND s.verb = :scored AND s.source = :source AND s.cmi5kind = :allowed
                       AND s.scorescaled IS NOT NULL
              ORDER BY s.timestampms DESC, s.storedms DESC, s.id DESC";
        $params += ['normal' => 'Normal', 'scored' => vocab::VERB_SCORED, 'source' => statement_store::SOURCE_AU,
            'allowed' => statement_store::KIND_ALLOWED];
        $scores = [];
        $seen = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $regid = (int)$row->regid;
            $auid = (int)$row->auid;
            $sessionid = (int)$row->sessionid;
            $scaled = (float)$row->scorescaled;
            if (
                isset($seen[$sessionid][$auid]) || $row->objecthash !== sha1((string)$row->lmsactivityid)
                    || $scaled < 0 || $scaled > 1
            ) {
                continue;
            }
            // Newest first: the first row of a session is its grade, the first session of an AU is the last one.
            $seen[$sessionid][$auid] = true;
            if (!isset($scores[$regid][$auid])) {
                $scores[$regid][$auid] = (object)['scorehighest' => $scaled, 'scorelast' => $scaled];
            } else {
                $scores[$regid][$auid]->scorehighest = max($scores[$regid][$auid]->scorehighest, $scaled);
            }
        }
        $rs->close();
        return $scores;
    }

    /**
     * One gradebook grade.
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid Learner.
     * @param float|null $grade Registration grade, 0–1.
     * @return \stdClass userid, rawgrade (null without a grade or for a scale / no grade).
     */
    private static function gradebook_grade(\stdClass $instance, int $userid, ?float $grade): \stdClass {
        $max = (float)$instance->grade;
        return (object)[
            'userid' => $userid,
            'rawgrade' => $grade === null || $max <= 0 ? null : round($grade * $max, self::DECIMALS),
        ];
    }

    /**
     * Average of the AU scores by the grading method.
     *
     * @param \stdClass[] $rows regau rows with a score (scorehighest, scorelast).
     * @param int $grademethod METHOD_HIGHEST or METHOD_LAST.
     * @return float|null
     */
    private static function average(array $rows, int $grademethod): ?float {
        if (!$rows) {
            return null;
        }
        $field = $grademethod === self::METHOD_LAST ? 'scorelast' : 'scorehighest';
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float)($row->$field ?? $row->scorehighest);
        }
        return $sum / count($rows);
    }
}
