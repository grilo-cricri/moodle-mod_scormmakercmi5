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

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\statement_store;
use mod_scormmakercmi5\local\package\importer;

/**
 * Satisfaction of AU, block and course and the LMS "satisfied" statements (design §6.5, §6.1 step 2; R5.4, R6.3).
 *
 * An AU is satisfied when its regau meets its moveOn (cmi5 §13.1.4: "NotApplicable – The LMS will consider the AU
 * satisfied"), or when it was waived (§9.3.2: an AU "previously issued a Waived statement" has "met the moveOn
 * criteria"). A block is satisfied when all its AUs and blocks are, the course when all its top-level AUs and blocks
 * are (§13.1.4). For each block and for the course that becomes satisfied the LMS writes one "satisfied"
 * (§9.3.9), with the session id of the AU session that triggered it, or of a new synthetic session when it happens
 * outside a launch ("MUST generate a unique session ID for all Satisfied statements triggered outside of an AU
 * launch session"). One per block and one for the course per registration ("SHOULD NOT issue multiple"): the
 * regblock row (unique (regid, blockid)) and reg.satisfied are written with the statement and checked before it.
 *
 * cmi5 has no "satisfied" statement about an AU (§9.3.9: the object is a block or the course); the satisfaction of
 * an AU is kept in regau.satisfied.
 *
 * Everything runs in the transaction of the caller, under the registration lock (or, at the creation of the
 * registration, in the transaction that inserts it, where no one else sees it yet).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class satisfaction {
    /**
     * Whether an AU of a registration is satisfied (cmi5 §13.1.4 moveOn, §9.3.2 waived).
     *
     * @param string $moveon moveOn of the AU.
     * @param \stdClass $regau regau row (completed, passed, waived).
     * @return bool
     */
    public static function is_au_satisfied(string $moveon, \stdClass $regau): bool {
        if (!empty($regau->waived)) {
            return true;
        }
        $completed = !empty($regau->completed);
        $passed = !empty($regau->passed);
        return match ($moveon) {
            'Passed' => $passed,
            'Completed' => $completed,
            'CompletedAndPassed' => $completed && $passed,
            'CompletedOrPassed' => $completed || $passed,
            'NotApplicable' => true,
            default => false,
        };
    }

    /**
     * Brings the satisfaction of a registration up to date: regau.satisfied, then the "satisfied" of every block and
     * of the course not written yet.
     *
     * @param \stdClass $reg Registration (satisfied, timesatisfied and statementcount are updated in it too).
     * @param \stdClass|null $session The AU session that triggered it; null outside a launch (a synthetic session is
     *     created when a statement is written).
     * @return string[] Ids of the LMS statements written, in order (inner blocks first, the course last).
     */
    public static function evaluate(\stdClass $reg, ?\stdClass $session): array {
        global $DB;
        $current = $DB->get_record('scormmakercmi5_reg', ['id' => $reg->id], 'id, satisfied, statementcount', MUST_EXIST);
        $reg->statementcount = $current->statementcount;
        if ((int)$current->satisfied === 1) {
            // The course is satisfied: every AU and block is too, and nothing is written twice.
            $reg->satisfied = 1;
            return [];
        }
        $now = \core\di::get(\core\clock::class)->time();
        $aus = $DB->get_records('scormmakercmi5_au', ['pkgid' => $reg->pkgid], 'sortorder', 'id, blockid, moveon');
        $blocks = $DB->get_records(
            'scormmakercmi5_block',
            ['pkgid' => $reg->pkgid],
            'sortorder',
            'id, parentid, publisherid, lmsiri'
        );
        $regaus = $DB->get_records(
            'scormmakercmi5_regau',
            ['regid' => $reg->id],
            '',
            'auid, id, completed, passed, waived, satisfied'
        );
        $done = array_flip(array_map('intval', $DB->get_fieldset_select(
            'scormmakercmi5_regblock',
            'blockid',
            'regid = :regid',
            ['regid' => $reg->id]
        )));

        // AUs, and the children of each parent (0 = the course).
        $children = [0 => []];
        $ausatisfied = [];
        $trigger = null;
        foreach ($aus as $au) {
            $regau = $regaus[$au->id] ?? null;
            $satisfied = $regau !== null && self::is_au_satisfied((string)$au->moveon, $regau);
            if ($satisfied && (int)$regau->satisfied !== 1) {
                $DB->update_record('scormmakercmi5_regau', (object)[
                    'id' => $regau->id,
                    'satisfied' => 1,
                    'timesatisfied' => $now,
                ]);
                $trigger = $trigger ?? (int)$au->id;
            }
            $ausatisfied[$au->id] = $satisfied;
            $children[(int)$au->blockid][] = ['au', (int)$au->id];
        }
        foreach ($blocks as $block) {
            $children[(int)$block->parentid][] = ['block', (int)$block->id];
        }

        // Blocks, children before parents (post-order), then the course.
        $written = [];
        $synthetic = null;
        $sessionfor = function () use (&$synthetic, $session, $reg, &$trigger, $aus): \stdClass {
            if ($session !== null) {
                return $session;
            }
            if ($synthetic === null) {
                $synthetic = launcher::create_synthetic_session($reg, $trigger ?? (int)reset($aus)->id);
            }
            return $synthetic;
        };
        $blocksatisfied = [];
        $issatisfied = function (int $parentid) use (&$issatisfied, &$blocksatisfied, $children, $ausatisfied): bool {
            $all = true;
            foreach ($children[$parentid] ?? [] as [$kind, $id]) {
                $ok = $kind === 'au' ? $ausatisfied[$id] : ($blocksatisfied[$id] ?? $issatisfied($id));
                $all = $all && $ok;
            }
            return $all;
        };
        $visit = function (int $parentid) use (&$visit, $children): array {
            $order = [];
            foreach ($children[$parentid] ?? [] as [$kind, $id]) {
                if ($kind === 'block') {
                    $order = array_merge($order, $visit($id), [$id]);
                }
            }
            return $order;
        };
        foreach ($visit(0) as $blockid) {
            $blocksatisfied[$blockid] = $issatisfied($blockid);
            if (!$blocksatisfied[$blockid] || isset($done[$blockid])) {
                continue;
            }
            $block = $blocks[$blockid];
            $DB->insert_record('scormmakercmi5_regblock', (object)[
                'regid' => $reg->id,
                'blockid' => $blockid,
                'timesatisfied' => $now,
            ]);
            $written[] = self::write($reg, $block->lmsiri, vocab::TYPE_BLOCK, $block->publisherid, $sessionfor());
        }
        if ($aus && $issatisfied(0)) {
            [$courseiri, $publisherid] = self::course_identity($reg);
            $DB->update_record('scormmakercmi5_reg', (object)[
                'id' => $reg->id,
                'satisfied' => 1,
                'timesatisfied' => $now,
                'timemodified' => $now,
            ]);
            $reg->satisfied = 1;
            $reg->timesatisfied = $now;
            $reg->timemodified = $now;
            $written[] = self::write($reg, $courseiri, vocab::TYPE_COURSE, $publisherid, $sessionfor());
        }
        return $written;
    }

    /**
     * At the creation of a registration: NotApplicable AUs are satisfied from the start and the blocks and the course
     * they complete are written at once, with a synthetic session (design §6.1 step 2, R6.3).
     *
     * @param \stdClass $reg The new registration, in the transaction that created it.
     */
    public static function initialize_registration(\stdClass $reg): void {
        self::evaluate($reg, null);
    }

    /**
     * Writes one "satisfied" through the LMS path of the store (lower-case id, stored, authority, statementcount).
     *
     * @param \stdClass $reg Registration.
     * @param string $iri Object id.
     * @param string $type Activity type.
     * @param string $publisherid Publisher id for the grouping.
     * @param \stdClass $session Session.
     * @return string Statement id.
     */
    private static function write(\stdClass $reg, string $iri, string $type, string $publisherid, \stdClass $session): string {
        $statement = lms_statements::satisfied($reg, $iri, $type, $publisherid, $session);
        return statement_store::insert_lms($statement, $reg, (int)$session->auid, (int)$session->id);
    }

    /**
     * The course object (lmscourseiri of the instance) and the publisher id of the course (cmi5.xml).
     *
     * lmscourseiri is generated by add_instance; a NULL one ("not generated yet", design §3.1) is generated here with
     * a conditional update, so two registrations at once end with the same IRI (§9.3.9: "MUST use the same course
     * object for all Satisfied statements that refer to that course").
     *
     * @param \stdClass $reg Registration.
     * @return array [string course IRI, string publisher id].
     */
    private static function course_identity(\stdClass $reg): array {
        global $DB;
        $publisherid = (string)$DB->get_field('scormmakercmi5_pkg', 'publishercourseid', ['id' => $reg->pkgid], MUST_EXIST);
        $iri = $DB->get_field('scormmakercmi5', 'lmscourseiri', ['id' => $reg->scormmakercmi5id], MUST_EXIST);
        if ($iri === null || $iri === '') {
            $DB->set_field_select(
                'scormmakercmi5',
                'lmscourseiri',
                importer::generated_iri('course'),
                'id = :id AND lmscourseiri IS NULL',
                ['id' => $reg->scormmakercmi5id]
            );
            $iri = $DB->get_field('scormmakercmi5', 'lmscourseiri', ['id' => $reg->scormmakercmi5id], MUST_EXIST);
        }
        return [(string)$iri, $publisherid];
    }
}
