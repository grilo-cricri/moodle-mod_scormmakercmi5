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
use mod_scormmakercmi5\local\package\analysis_structure;
use mod_scormmakercmi5\local\signature\verification_result;

/**
 * Queries of the block activity report (subproject D, design §8.3).
 *
 * Selection (rule 1 of the contract): statements of the SCORM Maker profile (smprofile 1) of one package version with
 * versao-do-perfil 2 that are a block summary (smkind bloco, verb interacted) or a decision (smkind item, verb
 * selected). The class is read through index instanceversionblock (scormmakercmi5id, smversion, smblock), one learner
 * through index regtimestamp (regid, timestampms). Learners, reached learners and time are SQL aggregates on the
 * columns (durationms is never re-read from the payload); the parts that live only in the extensions (items, media,
 * map, decisions) are read from the payload of the selected rows through ONE recordset, folded as it streams
 * (block_aggregator), never the payloads of other statements. Three queries per report, whatever the size of the class.
 *
 * Raw SQL, justified (as rich_report): COUNT(DISTINCT) and GROUP BY are not expressible with the CRUD API. {table}
 * names, named placeholders, the user filter through get_in_or_equal, fixed ORDER BY.
 *
 * Texts returned here are raw (they come from the AU): the caller escapes them on output.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_report {
    /** @var string smkind of a block summary. */
    public const KIND_BLOCK = 'bloco';

    /** @var string smkind of a decision on an item of a block. */
    public const KIND_ITEM = 'item';

    /** @var int versao-do-perfil of the rich data. */
    public const PROFILE_VERSION = 2;

    /**
     * The analysis structure of a package, only when its signature was verified at this opening (the structure is in
     * the signed manifest, so it is authentic only then). Null: the block report is unavailable.
     *
     * @param \stdClass $pkg scormmakercmi5_pkg row (richstructure).
     * @param verification_result $verification verifier::recheck() of that package.
     * @return array|null
     */
    public static function structure(\stdClass $pkg, verification_result $verification): ?array {
        if ($verification->status !== verification_result::STATUS_VERIFIED) {
            return null;
        }
        return analysis_structure::decode($pkg->richstructure ?? null);
    }

    /**
     * The WHERE of the selection (alias s).
     *
     * @param string $column 's.scormmakercmi5id' or 's.regid'.
     * @param int $id Instance or registration id.
     * @param string $smversion Package version.
     * @param group_filter|null $filter Group filter (null: one registration, no filter; never "nobody" here).
     * @return array [string sql, array params].
     */
    private static function where(string $column, int $id, string $smversion, ?group_filter $filter): array {
        $sql = "{$column} = :scopeid AND s.smversion = :smversion AND s.smblock IS NOT NULL AND s.smprofile = 1
                AND s.smprofilever = :profilever
                AND ((s.smkind = :kindblock AND s.verb = :interacted) OR (s.smkind = :kinditem AND s.verb = :selected))";
        $params = ['scopeid' => $id, 'smversion' => $smversion, 'profilever' => self::PROFILE_VERSION,
            'kindblock' => self::KIND_BLOCK, 'interacted' => vocab::VERB_INTERACTED,
            'kinditem' => self::KIND_ITEM, 'selected' => vocab::VERB_SELECTED];
        if ($filter !== null) {
            [$gsql, $gparams] = $filter->sql('s.userid', 'b');
            $sql .= $gsql;
            $params += $gparams;
        }
        return [$sql, $params];
    }

    /**
     * Reached learners and summed time per block (summaries only).
     *
     * @param string $where WHERE.
     * @param array $params Parameters.
     * @param array $structure Verified analysis structure: only its blocks are kept (bounded memory).
     * @return array smblock => ['alcancaram' => int, 'tempo' => int].
     */
    private static function groups(string $where, array $params, array $structure): array {
        global $DB;
        $groups = [];
        $rs = $DB->get_recordset_sql(
            "SELECT s.smblock, COUNT(DISTINCT s.regid) AS alcancaram, SUM(COALESCE(s.durationms, 0)) AS tempo
               FROM {scormmakercmi5_stmt} s
              WHERE {$where} AND s.smkind = :onlyblock
           GROUP BY s.smblock",
            $params + ['onlyblock' => self::KIND_BLOCK]
        );
        $blocks = block_aggregator::known($structure);
        foreach ($rs as $row) {
            if (!isset($blocks[(string)$row->smblock])) {
                continue;
            }
            $groups[(string)$row->smblock] = ['alcancaram' => (int)$row->alcancaram, 'tempo' => (int)$row->tempo];
        }
        $rs->close();
        return $groups;
    }

    /**
     * The selected rows, in the order the aggregator folds them ("last" = largest (timestampms, id)).
     *
     * @param string $where WHERE.
     * @param array $params Parameters.
     * @return \moodle_recordset
     */
    private static function rows(string $where, array $params): \moodle_recordset {
        global $DB;
        return $DB->get_recordset_sql(
            "SELECT s.id, s.regid, s.smkind, s.smblock, s.smitem, s.smblocktype, s.timestampms, s.payload
               FROM {scormmakercmi5_stmt} s
              WHERE {$where}
           ORDER BY s.regid, s.timestampms, s.id",
            $params
        );
    }

    /**
     * Aggregates of the class for one version (contract "atividade").
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param group_filter $filter Groups the viewer may see (group_filter::from_restriction(restrict_groups())).
     * @param array $structure structure() of the package of the version: only its blocks, items and scenes count.
     * @return array
     */
    public static function activity(int $instanceid, string $smversion, group_filter $filter, array $structure): array {
        global $DB;
        if ($filter->nobody()) {
            return ['alunos' => 0, 'blocos' => []];
        }
        [$where, $params] = self::where('s.scormmakercmi5id', $instanceid, $smversion, $filter);
        $learners = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT s.regid) FROM {scormmakercmi5_stmt} s WHERE {$where}",
            $params
        );
        if ($learners === 0) {
            return ['alunos' => 0, 'blocos' => []];
        }
        $groups = self::groups($where, $params, $structure);
        // The aggregator reads the rows twice (the duration of each media first): a fresh recordset each time, closed
        // when it is read to the end or dropped.
        $rows = static function () use ($where, $params): \Generator {
            $rs = self::rows($where, $params);
            try {
                yield from $rs;
            } finally {
                $rs->close();
            }
        };
        return block_aggregator::activity($learners, $groups, $rows, $structure);
    }

    /**
     * Summary of one learner for one version (contract "porAluno"): blocks, time, media watched, last decisions.
     *
     * @param int $regid Registration id.
     * @param string $smversion Package version.
     * @param array $structure structure() of the package of the version: only its blocks, items and scenes count.
     * @return array
     */
    public static function learner(int $regid, string $smversion, array $structure): array {
        [$where, $params] = self::where('s.regid', $regid, $smversion, null);
        $groups = self::groups($where, $params, $structure);
        $rows = self::rows($where, $params);
        try {
            return block_aggregator::learner($groups, $rows, $structure);
        } finally {
            $rows->close();
        }
    }

    /**
     * Whether the version has any statement of the selection (the block report is shown only then).
     *
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @return bool
     */
    public static function has_blocks(int $instanceid, string $smversion): bool {
        global $DB;
        [$where, $params] = self::where('s.scormmakercmi5id', $instanceid, $smversion, null);
        return $DB->record_exists_sql("SELECT 1 FROM {scormmakercmi5_stmt} s WHERE {$where}", $params);
    }
}
