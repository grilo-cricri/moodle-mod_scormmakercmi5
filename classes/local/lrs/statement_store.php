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

use mod_scormmakercmi5\completion\custom_completion;
use mod_scormmakercmi5\local\analysis_bounds;
use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\grading\grade_calculator;
use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\runtime\actor;
use mod_scormmakercmi5\local\runtime\launcher;
use mod_scormmakercmi5\local\runtime\satisfaction;

/**
 * Storage of statements in scormmakercmi5_stmt (design §3.9, §3.12, §7.2; R5.5, R8).
 *
 * The ingestion of the AU is all or nothing. Per statement: validate() (400) → size (413) → normalize(), and the
 * values of a block summary of the analysis within analysis_bounds (400); then,
 * outside any transaction, the ids already stored are set aside (identical → nothing to do, the retry of a batch
 * whose response was lost; different → 409) and the rest is judged by cmi5_rules over the state of the session
 * (403) — so a refused batch never waits for the lock. Under the registration lock the session is read again
 * (a relaunch in another tab may have abandoned it: 401), the stored ids and the rules are checked again over the
 * fresh state, the per-registration ceiling is applied (403 "limit") and, in one transaction, the statements are
 * inserted and the session, the regau and the counter of the registration are updated.
 *
 * The analysis of blocks (subproject D: block summaries and decisions, see is_analysis()) is judged by the same rules
 * but never counts in the per-registration ceiling, which guards the completion: it has its own ceiling
 * (limits::max_analysis_per_reg()), above which it is skipped in silence while the rest of the batch is stored and
 * the request succeeds with every id — the rule of the hosted course (backend/publicacoes/lrs.py, ingerir).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class statement_store {
    /** @var int stmt.source of a statement sent by the AU. */
    public const SOURCE_AU = 0;

    /** @var int stmt.source of a statement written by the LMS. */
    public const SOURCE_LMS = 1;

    /** @var int stmt.cmi5kind of a cmi5 allowed statement. */
    public const KIND_ALLOWED = 1;

    /** @var int stmt.cmi5kind of a cmi5 defined statement. */
    public const KIND_DEFINED = 2;

    /** @var string xAPI version recorded when the statement has none. */
    public const DEFAULT_VERSION = '1.0.0';

    /** @var int JSON flags of the stored payload. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_THROW_ON_ERROR;

    /**
     * @var string Object IRI of a SCORM Maker course item (design §3.9): a lesson or question (C) — groups 1, 2 — or a
     *     block — group 3 — with an optional item of the block — group 4 — (subproject D). "~" as delimiter ("#" is
     *     inside the character classes), D (no trailing newline) and u (characters, and \s is any Unicode space). The
     *     block branch is the one of the hosted course (backend/publicacoes/lrs.py, _OBJETO_DO_BLOCO, fullmatch): no
     *     whitespace in the course, the block or the item.
     */
    public const SM_OBJECT = '~^https://scormmaker\.com\.br/xapi/curso/'
        . '(?:[^/]+/(licao|questao)/([^/?#]+)|[^/\s]+/bloco/([^/?#\s]+)(?:/item/([^/?#\s]+))?)$~Du';

    /** @var int Width of stmt.smversion, stmt.smitem and stmt.smblock (design §3.13). */
    private const SM_WIDTH = 64;

    /** @var int Width of stmt.smblocktype (design §3.13). */
    private const SM_TYPE_WIDTH = 32;

    /** @var string[] Verbs of the analysis of blocks (subproject D): block summary, decision. */
    private const ANALYSIS_VERBS = [vocab::VERB_INTERACTED, vocab::VERB_SELECTED];

    /** @var string[] smkind of the analysis of blocks (subproject D). */
    private const ANALYSIS_KINDS = ['bloco', 'item'];

    /** @var int Depth accepted by json_decode (design §7.2 step 3). */
    private const JSON_DEPTH = 64;

    /** @var string[] Properties the LRS sets and the replay comparison leaves out. */
    private const LRS_KEYS = ['stored', 'authority', 'version'];

    /**
     * @var \Closure|null Test seam: fn(string $stage), called at 'beforelock', 'beforecommit', 'aftercommit', 'grade'
     *     and 'completion'.
     */
    private static ?\Closure $testhook = null;

    /**
     * POST statements (xAPI 1.0.3 part 3 §2.1): one statement or a list; 200 with the ids, in the order sent.
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response
     * @throws lrs_exception 400, 401, 403, 409, 413, 503.
     */
    public static function post_statements(request $r, session_context $ctx): response {
        $r->assert_body_max(limits::max_body_bytes());
        $decoded = self::decode($r->body, true);
        $objects = self::decode($r->body, false);
        if ($objects instanceof \stdClass) {
            // One statement. An object decodes as a list when it is {} or has only the keys "0", "1"…: neither is a
            // statement, so it goes to the validator as one statement (400), never as an empty batch or a list.
            $statements = [$decoded !== [] && array_is_list($decoded) ? ['statement' => $decoded] : $decoded];
            $objects = [$objects];
        } else {
            $statements = statement_validator::normalize_batch($decoded);
        }
        return response::json(200, self::store_batch($statements, $ctx, 5, is_array($objects) ? $objects : null));
    }

    /**
     * PUT statements?statementId= (xAPI 1.0.3 part 3 §2.1): one statement; 204, also when the same statement is
     * already stored; 409 when the id is stored with other content.
     *
     * @param request $r Request.
     * @param session_context $ctx Session of the token.
     * @return response
     * @throws lrs_exception 400 without statementId, with another id in the body or a body that is not an object.
     */
    public static function put_statement(request $r, session_context $ctx): response {
        $r->assert_body_max(limits::max_body_bytes());
        $id = documents::string_param($r, 'statementId', true);
        $decoded = self::decode($r->body, true);
        $object = self::decode($r->body, false);
        if (!is_array($decoded) || !$object instanceof \stdClass) {
            throw new lrs_exception(400, 'err_invalidstatement', 'statement', 'xAPI 2.2');
        }
        if (!array_key_exists('id', $decoded)) {
            $decoded = ['id' => $id] + $decoded;
            $object = (object)(['id' => $id] + get_object_vars($object));
        } else if (!is_string($decoded['id']) || strtolower($decoded['id']) !== strtolower($id)) {
            throw new lrs_exception(400, 'err_lrsparam', 'statementId');
        }
        self::store_batch([$decoded], $ctx, 5, [$object]);
        return new response(204);
    }

    /**
     * Stores a batch of the AU all or nothing (design §7.2 steps 3–7).
     *
     * The statements are judged in their array form; the payload is built from the object form ($objects: the same
     * statements decoded with json_decode(…, false)), so {} inside an extension and objects with keys "0", "1"…
     * come back as they were sent. Without $objects (callers that already hold arrays) the object form is derived
     * from the arrays.
     *
     * @param array $statements Decoded statements (not yet validated).
     * @param session_context $ctx Session of the token.
     * @param int $locktimeout Seconds to wait for the registration lock.
     * @param array|null $objects The same statements decoded as objects, in the same order.
     * @return string[] The ids of the batch, in order (those already stored included).
     * @throws lrs_exception 400, 401, 403, 409, 413, 503 (Retry-After: 2).
     */
    public static function store_batch(
        array $statements,
        session_context $ctx,
        int $locktimeout = 5,
        ?array $objects = null
    ): array {
        global $DB;
        $statements = array_values($statements);
        $prepared = self::prepare($statements);
        if (!$prepared) {
            return [];
        }
        $objects = array_values($objects ?? array_map(
            static fn(array $s): mixed => json_decode(json_encode($s, self::JSON_FLAGS), false, self::JSON_DEPTH),
            $statements
        ));
        if (count($objects) !== count($prepared)) {
            throw new \coding_exception('store_batch: $objects must be the same statements as $statements');
        }
        self::check_analysis_bounds($prepared, $objects);
        // Pre-check over the state read by the authentication: a refused batch never waits for the lock.
        self::judge($prepared, $ctx);
        self::hook('beforelock');

        $lock = launcher::registration_lock((int)$ctx->reg->id, $locktimeout);
        if (!$lock) {
            throw new lrs_exception(503, 'err_lrsbusy', '', null, ['Retry-After' => '2']);
        }
        $itemdefs = [];
        $pkgid = (int)$ctx->reg->pkgid;
        $scored = false;
        $satisfied = false;
        try {
            $fresh = self::reload($ctx);
            $pkgid = (int)$fresh->reg->pkgid;
            [$newidx, $kinds, $state] = self::judge($prepared, $fresh);
            [$newidx, $kinds] = self::cap_analysis($prepared, $newidx, $kinds, $fresh->reg);
            if ($newidx) {
                try {
                    $transaction = $DB->start_delegated_transaction();
                    try {
                        $wassatisfied = (int)$fresh->reg->satisfied === 1;
                        $itemdefs = self::write($prepared, $objects, $newidx, $kinds, $state, $fresh);
                        self::after_store($fresh->reg, $fresh);
                        self::hook('beforecommit');
                        $transaction->allow_commit();
                        $scored = self::has_score($prepared, $newidx, $kinds);
                        $satisfied = !$wassatisfied && (int)$fresh->reg->satisfied === 1;
                    } catch (\Throwable $e) {
                        $transaction->rollback($e);
                    }
                } catch (\dml_write_exception $e) {
                    // Another registration took one of the ids between the check and the insert (the index is global).
                    $new = array_map(fn(int $i): array => $prepared[$i], $newidx);
                    $taken = $DB->get_fieldset_select('scormmakercmi5_stmt', 'statementid', ...self::in_ids($new));
                    if ($taken) {
                        throw new lrs_exception(409, 'err_lrsconflict', (string)reset($taken));
                    }
                    throw $e;
                }
            }
        } finally {
            $lock->release();
        }
        try {
            // The batch is committed: a failure of the derived rows is logged, never answered as a 5xx.
            self::hook('aftercommit');
            self::store_itemdefs($itemdefs, $pkgid);
        } catch (\Throwable $e) {
            endpoint::log_failure('lrs itemdef', $e);
        }
        // Gradebook and completion (design §6.6), outside the lock: they may be slow and trigger events. Each step has
        // its own try, so a failing grade never holds back the completion. A failure is logged and repaired on view.php
        // and launch: the grade by grade_calculator::repair_user(), the completion by custom_completion::repair_user().
        $userid = (int)$ctx->reg->userid;
        if ($scored) {
            try {
                self::hook('grade');
                grade_calculator::update_user($ctx->instance, $userid);
            } catch (\Throwable $e) {
                endpoint::log_failure('lrs grade', $e);
            }
        }
        if ($satisfied) {
            try {
                self::hook('completion');
                custom_completion::update_user($ctx->instance, $userid);
            } catch (\Throwable $e) {
                endpoint::log_failure('lrs completion', $e);
            }
        }
        return array_column($prepared, 'id');
    }

    /**
     * The SCORM Maker profile columns of a statement (design §3.9, §3.13; R8.2, R8.3).
     *
     * smprofile when the category https://scormmaker.com.br/xapi/perfil/v1 is there; smversion from the extension
     * versao-do-pacote (a string or an integer); smkind/smitem from an object IRI …/curso/<c>/(licao|questao)/<item>.
     * smblock/smblocktype/smprofilever (subproject D) from an object …/curso/<c>/bloco/<b>[/item/<i>] (smkind bloco
     * with smitem = <b>, or item with smitem = <i>) and the extensions tipo-de-bloco/versao-do-perfil.
     * A value wider than its column, or a version that is not a string or an integer, drops the whole profile: the
     * statement is kept without it, never truncated (two items would collide). A block type wider than its column
     * or not a string, and a profile version that is not an integer from 1 to 99, leave only that column NULL.
     *
     * @param array $statement Normalized statement.
     * @return \stdClass smprofile, smversion, smkind, smitem, smblock, smblocktype, smprofilever.
     */
    public static function sm_columns(array $statement): \stdClass {
        $none = (object)['smprofile' => 0, 'smversion' => null, 'smkind' => null, 'smitem' => null,
            'smblock' => null, 'smblocktype' => null, 'smprofilever' => null];
        $categories = $statement['context']['contextActivities']['category'] ?? [];
        $inprofile = false;
        foreach (is_array($categories) ? $categories : [] as $category) {
            $inprofile = $inprofile || (is_array($category) && ($category['id'] ?? null) === vocab::SM_PROFILE);
        }
        if (!$inprofile) {
            return $none;
        }
        $columns = clone $none;
        $columns->smprofile = 1;
        $extensions = $statement['context']['extensions'] ?? [];
        $extensions = is_array($extensions) ? $extensions : [];
        if (array_key_exists(vocab::SM_EXT_VERSION, $extensions)) {
            $version = $extensions[vocab::SM_EXT_VERSION];
            if (!is_string($version) && !is_int($version)) {
                return $none;
            }
            $columns->smversion = (string)$version;
            if (\core_text::strlen($columns->smversion) > self::SM_WIDTH) {
                return $none;
            }
        }
        $profilever = $extensions[vocab::SM_EXT_PROFILEVER] ?? null;
        if (is_int($profilever) && $profilever > 0 && $profilever < 100) {
            $columns->smprofilever = $profilever;
        }
        $object = is_array($statement['object'] ?? null) ? $statement['object'] : [];
        $isactivity = ($object['objectType'] ?? 'Activity') === 'Activity';
        if ($isactivity && is_string($object['id'] ?? null) && preg_match(self::SM_OBJECT, $object['id'], $m)) {
            if (($m[1] ?? '') !== '') {
                if (\core_text::strlen($m[2]) > self::SM_WIDTH) {
                    return $none;
                }
                $columns->smkind = $m[1];
                $columns->smitem = $m[2];
            } else {
                $block = $m[3];
                $item = $m[4] ?? '';
                if (\core_text::strlen($block) > self::SM_WIDTH || \core_text::strlen($item) > self::SM_WIDTH) {
                    return $none;
                }
                $columns->smkind = $item !== '' ? 'item' : 'bloco';
                $columns->smitem = $item !== '' ? $item : $block;
                $columns->smblock = $block;
                $type = $extensions[vocab::SM_EXT_BLOCKTYPE] ?? null;
                if (is_string($type) && $type !== '' && \core_text::strlen($type) <= self::SM_TYPE_WIDTH) {
                    $columns->smblocktype = $type;
                }
            }
        }
        return $columns;
    }

    /**
     * Stores a cmi5 defined statement of the LMS and counts it in the registration.
     *
     * Runs inside the transaction of the caller, under the registration lock (the counter is read and written).
     *
     * @param array $statement Statement from lms_statements.
     * @param \stdClass $reg Registration (statementcount is updated in it too).
     * @param int $auid AU id.
     * @param int|null $sessionid Session id.
     * @return string The statement id.
     */
    public static function insert_lms(array $statement, \stdClass $reg, int $auid, ?int $sessionid): string {
        global $DB;
        $storedms = xapi_time::now_ms();
        $statement['stored'] = xapi_time::from_ms($storedms);
        $statement['authority'] = actor::lms_authority();
        $statement['version'] = $statement['version'] ?? self::DEFAULT_VERSION;
        $row = self::extract_columns($statement);
        $row->scormmakercmi5id = $reg->scormmakercmi5id;
        $row->pkgid = $reg->pkgid;
        $row->regid = $reg->id;
        $row->auid = $auid;
        $row->userid = $reg->userid;
        $row->sessionid = $sessionid;
        $row->statementid = strtolower($statement['id']);
        $row->source = self::SOURCE_LMS;
        $row->cmi5kind = self::KIND_DEFINED;
        $row->storedms = $storedms;
        $row->smprofile = 0;
        $row->payload = json_encode($statement, self::JSON_FLAGS);
        $DB->insert_record('scormmakercmi5_stmt', $row);

        $count = (int)$DB->get_field('scormmakercmi5_reg', 'statementcount', ['id' => $reg->id], MUST_EXIST) + 1;
        $DB->set_field('scormmakercmi5_reg', 'statementcount', $count, ['id' => $reg->id]);
        $reg->statementcount = $count;
        return $statement['id'];
    }

    /**
     * The indexed columns of a statement that already passed validation.
     *
     * @param array $statement Decoded statement.
     * @return \stdClass verb, objecthash, objectid, timestampms, success, completion, scorescaled, durationms, progress.
     * @throws \InvalidArgumentException When the timestamp or the duration is not valid.
     */
    public static function extract_columns(array $statement): \stdClass {
        $objectid = (string)($statement['object']['id'] ?? '');
        $result = is_array($statement['result'] ?? null) ? $statement['result'] : [];
        $scaled = $result['score']['scaled'] ?? null;
        $progress = $result['extensions'][vocab::EXT_PROGRESS] ?? null;
        // JSON has one number type: 50.0 is the whole number 50 (cmi5 rule 18 accepts it).
        $wholeprogress = is_int($progress) || (is_float($progress) && floor($progress) === $progress && abs($progress) <= 999);
        return (object)[
            'verb' => (string)($statement['verb']['id'] ?? ''),
            'objecthash' => sha1($objectid),
            'objectid' => $objectid,
            'timestampms' => xapi_time::to_ms((string)($statement['timestamp'] ?? '')),
            'success' => self::bool_column($result['success'] ?? null),
            'completion' => self::bool_column($result['completion'] ?? null),
            'scorescaled' => is_int($scaled) || is_float($scaled) ? (float)$scaled : null,
            'durationms' => isset($result['duration']) ? iso_duration::to_ms((string)$result['duration']) : null,
            'progress' => $wholeprogress ? (int)$progress : null,
        ];
    }

    /**
     * The work that must happen in the same transaction as the statements: the satisfaction of AU, block and course
     * (design §6.5), whose "satisfied" statements carry the session id of this session (cmi5 §9.3.9).
     *
     * @param \stdClass $reg Registration, statementcount already updated.
     * @param session_context $ctx Session, read again under the lock.
     */
    protected static function after_store(\stdClass $reg, session_context $ctx): void {
        satisfaction::evaluate($reg, $ctx->session);
    }

    /**
     * Whether the new statements of a batch include a cmi5 passed/failed, or an allowed "scored", with a numeric
     * score.scaled (the grade changes only then; grade_calculator decides which of them counts).
     *
     * @param array $prepared Normalized statements of the batch.
     * @param int[] $newidx Indexes of the statements stored.
     * @param int[] $kinds Their cmi5 kinds, in the order of $newidx.
     * @return bool
     */
    private static function has_score(array $prepared, array $newidx, array $kinds): bool {
        foreach ($newidx as $n => $i) {
            $verb = $prepared[$i]['verb']['id'];
            $scaled = $prepared[$i]['result']['score']['scaled'] ?? null;
            $graded = ($kinds[$n] === self::KIND_DEFINED && ($verb === vocab::VERB_PASSED || $verb === vocab::VERB_FAILED))
                || ($kinds[$n] === self::KIND_ALLOWED && $verb === vocab::VERB_SCORED);
            if ($graded && (is_int($scaled) || is_float($scaled))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Decodes a body (design §7.2 step 3).
     *
     * @param string $body Body.
     * @param bool $assoc Objects as arrays (validation, rules) or as stdClass (the stored payload).
     * @return mixed
     * @throws lrs_exception 400 err_lrsbadjson.
     */
    private static function decode(string $body, bool $assoc): mixed {
        try {
            return json_decode($body, $assoc, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new lrs_exception(400, 'err_lrsbadjson', '', 'xAPI 2.2');
        }
    }

    /**
     * Batch size, then per statement validation (400), size (413) and normalization; ids repeated in the batch (400).
     *
     * @param array $statements List of decoded statements.
     * @return array Normalized statements.
     * @throws lrs_exception 400, 413.
     */
    private static function prepare(array $statements): array {
        $max = limits::max_batch();
        if (count($statements) > $max) {
            throw new lrs_exception(413, 'err_lrsbatchsize', (string)$max);
        }
        $prepared = [];
        $seen = [];
        foreach ($statements as $i => $statement) {
            if (!is_array($statement)) {
                throw new lrs_exception(400, 'err_invalidbatch', '', 'xAPI 2.2');
            }
            statement_validator::validate($statement);
            if (strlen(json_encode($statement, self::JSON_FLAGS)) > limits::max_statement_bytes()) {
                throw new lrs_exception(413, 'err_lrsstatementsize', (string)($i + 1));
            }
            $statement = statement_validator::normalize($statement);
            if (is_string($statement['id'] ?? null)) {
                $key = strtolower($statement['id']);
                if (isset($seen[$key])) {
                    throw new lrs_exception(400, 'err_lrsduplicateid', $statement['id'], 'xAPI 2.4.1');
                }
                $seen[$key] = true;
            }
            $prepared[] = $statement;
        }
        return $prepared;
    }

    /**
     * Sets aside the statements already stored, judges the others and applies the ceiling of the registration (to the
     * statements that are not analysis).
     *
     * @param array $prepared Normalized statements.
     * @param session_context $ctx Session (with the registration whose counter is used).
     * @return array [int[] indexes of the new statements in $prepared, int[] their kinds, rules_state after them].
     * @throws lrs_exception 403 err_cmi5rule / err_lrsreglimit, 409 err_lrsconflict.
     */
    private static function judge(array $prepared, session_context $ctx): array {
        global $DB;
        $existing = [];
        $ids = [];
        foreach ($prepared as $statement) {
            if (is_string($statement['id'] ?? null)) {
                $ids[] = strtolower($statement['id']);
            }
        }
        if ($ids) {
            $existing = $DB->get_records_list('scormmakercmi5_stmt', 'statementid', $ids, '', 'statementid, regid, payload');
        }
        $newidx = [];
        foreach ($prepared as $i => $statement) {
            $row = is_string($statement['id'] ?? null) ? ($existing[strtolower($statement['id'])] ?? null) : null;
            if ($row === null) {
                $newidx[] = $i;
                continue;
            }
            $stored = json_decode($row->payload, true, self::JSON_DEPTH);
            if ((int)$row->regid !== (int)$ctx->reg->id || !is_array($stored) || !self::same($statement, $stored)) {
                throw new lrs_exception(409, 'err_lrsconflict', $statement['id']);
            }
        }
        $new = array_map(fn(int $i): array => $prepared[$i], $newidx);
        $result = cmi5_rules::check_batch($new, rules_state::from_context($ctx));
        $max = limits::max_statements_per_reg();
        $essential = 0;
        foreach ($new as $n => $statement) {
            $essential += self::is_analysis($statement, $result['kinds'][$n]) ? 0 : 1;
        }
        // The analysis is not counted: a course visited many times must never keep the completion out.
        if ((int)$ctx->reg->statementcount + $essential > $max) {
            // 403, not 5xx: the client must not retry the batch (lesson of lrs.py).
            throw new lrs_exception(403, 'err_lrsreglimit', (string)$max, 'limit');
        }
        return [$newidx, $result['kinds'], $result['state']];
    }

    /**
     * The SQL condition of the stored analysis rows, the same predicate as is_analysis() over the stored columns:
     * cmi5 allowed, verb interacted or selected, SCORM Maker profile 2 (smprofile 1, smprofilever 2) and kind bloco or
     * item. Used by cap_analysis() and by the backfill task (statementcount of the registrations of 0.1.0).
     *
     * @return array [string $sql, array $params] (named parameters).
     */
    public static function analysis_where(): array {
        global $DB;
        [$verbsql, $params] = $DB->get_in_or_equal(self::ANALYSIS_VERBS, SQL_PARAMS_NAMED, 'anverb');
        [$kindsql, $kindparams] = $DB->get_in_or_equal(self::ANALYSIS_KINDS, SQL_PARAMS_NAMED, 'ankind');
        $sql = "cmi5kind = :anallowed AND verb {$verbsql} AND smprofile = 1 AND smprofilever = :anprofilever
            AND smkind {$kindsql}";
        return [$sql, $params + $kindparams + ['anallowed' => self::KIND_ALLOWED, 'anprofilever' => 2]];
    }

    /**
     * Whether a statement is analysis of blocks of a SCORM Maker package (subproject D): a block summary
     * (interacted) or a decision (selected), cmi5 allowed, in the profile 2 (smprofile 1, smprofilever 2) about a
     * block or an item of a block. Neither changes the session, the regau nor the completion. The same verbs from
     * any other AU are ordinary statements: they count in maxstatementsperreg and are never skipped.
     *
     * @param array $statement Normalized statement.
     * @param int $kind Its cmi5 kind.
     * @return bool
     */
    private static function is_analysis(array $statement, int $kind): bool {
        $verb = $statement['verb']['id'] ?? null;
        if ($kind !== self::KIND_ALLOWED || !in_array($verb, self::ANALYSIS_VERBS, true)) {
            return false;
        }
        $columns = self::sm_columns($statement);
        return $columns->smprofile === 1 && $columns->smprofilever === 2
            && in_array($columns->smkind, self::ANALYSIS_KINDS, true);
    }

    /**
     * Refuses the batch when a block summary of the analysis (is_analysis(), kind bloco) has a value outside the
     * limits of analysis_bounds, or a decision (kind item) an answer longer than 500 characters (audit M-D1): a
     * learner's numbers and texts must never size what the report allocates. Only the
     * analysis is checked; the same extensions in any other statement are not. The player drops a summary refused
     * with a 400 (FilaDeEnvio sends the batch again one by one) and the rest of its queue goes on.
     *
     * Also (final wave W2a, the same as the hosted course): the whole statement, as sent, is at most
     * analysis_bounds::MAX_STATEMENT_BYTES (413, as any statement too large: the player sends at most 4 KiB), and it
     * has a timestamp (400 here, before the cmi5 rule 2 would refuse it with a 403: the "last" decision depends on it);
     * the result of a summary follows analysis_bounds::summary_violation() (its duration and its extensions).
     *
     * @param array $prepared Normalized statements of the batch.
     * @param array $objects The same statements in object form.
     * @throws lrs_exception 400 err_lrsanalysislimit, 413 err_lrsstatementsize.
     */
    private static function check_analysis_bounds(array $prepared, array $objects): void {
        foreach ($prepared as $i => $statement) {
            $kind = cmi5_rules::classify($statement);
            if ($kind !== self::KIND_ALLOWED || !self::is_analysis($statement, $kind)) {
                continue;
            }
            $json = json_encode($objects[$i], self::JSON_FLAGS);
            if ($json === false || strlen($json) > analysis_bounds::MAX_STATEMENT_BYTES) {
                throw new lrs_exception(413, 'err_lrsstatementsize', (string)($i + 1));
            }
            if (!isset($statement['timestamp'])) {
                throw new lrs_exception(400, 'err_lrsanalysislimit', ($i + 1) . ' (timestamp)');
            }
            $field = self::sm_columns($statement)->smkind === 'bloco'
                ? analysis_bounds::summary_violation($objects[$i]->result ?? null)
                : analysis_bounds::decision_violation($objects[$i]->result ?? null);
            if ($field !== null) {
                throw new lrs_exception(400, 'err_lrsanalysislimit', ($i + 1) . " ({$field})");
            }
        }
    }

    /**
     * Leaves out of the new statements the analysis above limits::max_analysis_per_reg(), in the order of the batch
     * (under the lock: the count is that of the registration). The batch that reaches the ceiling leaves one line in
     * the log (registration id and number skipped, nothing of the learner); the later ones are silent.
     *
     * @param array $prepared Normalized statements of the batch.
     * @param int[] $newidx Indexes of the new statements in $prepared.
     * @param int[] $kinds Their cmi5 kinds, in the order of $newidx.
     * @param \stdClass $reg Registration.
     * @return array [int[] $newidx, int[] $kinds] without the analysis skipped.
     */
    private static function cap_analysis(array $prepared, array $newidx, array $kinds, \stdClass $reg): array {
        global $DB;
        $analysis = false;
        foreach ($newidx as $n => $i) {
            $analysis = $analysis || self::is_analysis($prepared[$i], $kinds[$n]);
        }
        if (!$analysis) {
            return [$newidx, $kinds];
        }
        [$where, $params] = self::analysis_where();
        // Index regstored (regid, …): the rows of one registration only.
        $stored = $DB->count_records_select('scormmakercmi5_stmt', "regid = :regid AND {$where}", $params + ['regid' => $reg->id]);
        $max = limits::max_analysis_per_reg();
        $room = $max - $stored;
        $keptidx = [];
        $keptkinds = [];
        $skipped = 0;
        foreach ($newidx as $n => $i) {
            if (self::is_analysis($prepared[$i], $kinds[$n])) {
                if ($room <= 0) {
                    $skipped++;
                    continue;
                }
                $room--;
            }
            $keptidx[] = $i;
            $keptkinds[] = $kinds[$n];
        }
        if ($skipped && $stored < $max) {
            debugging("mod_scormmakercmi5 lrs: registration {$reg->id} reached maxanalysisperreg ({$max}); " .
                "{$skipped} analysis statement(s) skipped, the next ones are skipped without a log", DEBUG_NORMAL);
        }
        return [$keptidx, $keptkinds];
    }

    /**
     * The session and the registration read again under the lock: a session no longer open refuses the batch.
     *
     * @param session_context $ctx Context of the authentication.
     * @return session_context
     * @throws lrs_exception 401 err_unauthorized.
     */
    private static function reload(session_context $ctx): session_context {
        global $DB;
        $session = $DB->get_record('scormmakercmi5_session', ['id' => $ctx->session->id]);
        $open = [launcher::STATUS_LAUNCHED, launcher::STATUS_INITIALIZED];
        $now = \core\di::get(\core\clock::class)->time();
        if (
            !$session || !in_array((int)$session->status, $open, true) || (int)$session->tokenexpires <= $now
                || $session->tokenhash !== $ctx->session->tokenhash
        ) {
            throw new lrs_exception(401, 'err_unauthorized');
        }
        $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $ctx->reg->id], '*', MUST_EXIST);
        return new session_context($session, $reg, $ctx->au, $ctx->instance, $ctx->cm, $ctx->context, $ctx->actor);
    }

    /**
     * The writes of a batch, in the transaction and under the lock.
     *
     * @param array $prepared Normalized statements of the batch (array form).
     * @param array $objects The same statements as sent, in object form (json_decode(…, false)).
     * @param int[] $newidx Indexes of the statements not stored yet.
     * @param int[] $kinds Their cmi5 kinds, in the order of $newidx.
     * @param rules_state $state State after them.
     * @param session_context $ctx Session read under the lock.
     * @return array The itemdef candidates: [smversion, smkind, smitem, objectid, name JSON, definition JSON, ms].
     */
    private static function write(
        array $prepared,
        array $objects,
        array $newidx,
        array $kinds,
        rules_state $state,
        session_context $ctx
    ): array {
        global $DB;
        $storedms = xapi_time::now_ms();
        $stored = xapi_time::from_ms($storedms);
        $rows = [];
        $itemdefs = [];
        $new = [];
        $essential = 0;
        foreach ($newidx as $n => $i) {
            $statement = $prepared[$i];
            $new[] = $statement;
            $essential += self::is_analysis($statement, $kinds[$n]) ? 0 : 1;
            $payload = self::normalize_object($objects[$i]);
            $payload->stored = $stored;
            $payload->authority = actor::lms_authority();
            if (!isset($payload->version)) {
                $payload->version = self::DEFAULT_VERSION;
            }
            $row = self::extract_columns($statement);
            $row->scormmakercmi5id = $ctx->reg->scormmakercmi5id;
            $row->pkgid = $ctx->reg->pkgid;
            $row->regid = $ctx->reg->id;
            $row->auid = $ctx->au->id;
            $row->userid = $ctx->reg->userid;
            $row->sessionid = $ctx->session->id;
            // The xAPI compares UUIDs ignoring case: the column is lower case, the payload keeps the id as sent.
            $row->statementid = strtolower($statement['id']);
            $row->source = self::SOURCE_AU;
            $row->cmi5kind = $kinds[$n];
            $row->storedms = $storedms;
            $sm = self::sm_columns($statement);
            foreach ((array)$sm as $name => $value) {
                $row->{$name} = $value;
            }
            if ($sm->smkind === 'bloco' && self::is_analysis($statement, $kinds[$n])) {
                // The time of a block summary follows the rule of the contract (decimal text, half up, up to 86400 s):
                // the same column as the hosted course, never the float of iso_duration::to_ms.
                $row->durationms = analysis_bounds::duration_ms($statement['result']['duration'] ?? null);
            }
            $row->payload = json_encode($payload, self::JSON_FLAGS);
            $rows[] = $row;

            $definition = $payload->object->definition ?? null;
            if (
                $sm->smversion !== null && $sm->smitem !== null && $definition instanceof \stdClass
                    && get_object_vars($definition) !== []
            ) {
                $name = isset($definition->name) ? json_encode($definition->name, self::JSON_FLAGS) : null;
                $itemdefs[] = [$sm->smversion, $sm->smkind, $sm->smitem, $statement['object']['id'], $name,
                    json_encode($definition, self::JSON_FLAGS), $storedms];
            }
        }
        $DB->insert_records('scormmakercmi5_stmt', $rows);
        // The counter of the registration counts what maxstatementsperreg limits: the analysis is left out.
        $ctx->reg->statementcount = (int)$ctx->reg->statementcount + $essential;
        $DB->set_field('scormmakercmi5_reg', 'statementcount', $ctx->reg->statementcount, ['id' => $ctx->reg->id]);
        self::update_session($new, $kinds, $state, $ctx, $storedms);
        self::update_regau($new, $kinds, $ctx, $storedms);
        return $itemdefs;
    }

    /**
     * statement_validator::normalize() on the object form: every contextActivities value that is a single object
     * becomes a list (xAPI 2.4.6.2), in the context and in the one of a SubStatement object.
     *
     * @param \stdClass $statement Statement decoded as objects (validated in its array form).
     * @return \stdClass
     */
    private static function normalize_object(\stdClass $statement): \stdClass {
        $activities = $statement->context->contextActivities ?? null;
        if ($activities instanceof \stdClass) {
            foreach (get_object_vars($activities) as $key => $value) {
                if ($value instanceof \stdClass) {
                    $activities->{$key} = [$value];
                }
            }
        }
        $object = $statement->object ?? null;
        if ($object instanceof \stdClass && ($object->objectType ?? null) === 'SubStatement') {
            $statement->object = self::normalize_object($object);
        }
        return $statement;
    }

    /**
     * initialized → status initialized, timeinitializedms; terminated → status terminated, token revoked,
     * timeendedms and the duration of the statement (null when it has years or months, design §6.3).
     *
     * @param array $new Statements.
     * @param int[] $kinds Kinds.
     * @param rules_state $state State after the batch.
     * @param session_context $ctx Session.
     * @param int $storedms Time of the storage, milliseconds.
     */
    private static function update_session(
        array $new,
        array $kinds,
        rules_state $state,
        session_context $ctx,
        int $storedms
    ): void {
        global $DB;
        $update = [];
        foreach ($new as $i => $statement) {
            if ($kinds[$i] !== self::KIND_DEFINED) {
                continue;
            }
            $verb = $statement['verb']['id'];
            if ($verb === vocab::VERB_INITIALIZED) {
                $update['timeinitializedms'] = $storedms;
            } else if ($verb === vocab::VERB_TERMINATED) {
                $update['tokenexpires'] = 0;
                $update['timeendedms'] = $storedms;
                $update['durationms'] = iso_duration::to_ms((string)$statement['result']['duration']);
            }
        }
        if ($state->sessionstatus !== (int)$ctx->session->status) {
            $update['status'] = $state->sessionstatus;
        }
        if ($update) {
            foreach ($update as $name => $value) {
                $ctx->session->{$name} = $value;
            }
            $DB->update_record('scormmakercmi5_session', (object)(['id' => $ctx->session->id] + $update));
        }
    }

    /**
     * The regau of the AU from its defined statements (design §3.6, §6.6): completed/passed/failed with their times,
     * and scorehighest/scorelast/timelastscore from the score.scaled of passed and failed.
     *
     * @param array $new Statements.
     * @param int[] $kinds Kinds.
     * @param session_context $ctx Session.
     * @param int $storedms Time of the storage, milliseconds.
     */
    private static function update_regau(array $new, array $kinds, session_context $ctx, int $storedms): void {
        global $DB;
        if (!in_array(self::KIND_DEFINED, $kinds, true)) {
            // Only defined statements change the regau: a batch of allowed statements does not touch it.
            return;
        }
        $key = ['regid' => $ctx->reg->id, 'auid' => $ctx->au->id];
        // The registration creates one regau per AU of its package version (launcher::get_or_create_registration).
        $regau = $DB->get_record('scormmakercmi5_regau', $key, '*', MUST_EXIST);
        $now = \core\di::get(\core\clock::class)->time();
        $update = [];
        foreach ($new as $i => $statement) {
            if ($kinds[$i] !== self::KIND_DEFINED) {
                continue;
            }
            $verb = $statement['verb']['id'];
            if ($verb === vocab::VERB_COMPLETED) {
                $update['completed'] = 1;
                $update['timecompleted'] = $now;
            } else if ($verb === vocab::VERB_PASSED) {
                $update['passed'] = 1;
                $update['timepassed'] = $now;
            } else if ($verb === vocab::VERB_FAILED) {
                $update['failed'] = 1;
            }
            $scaled = $statement['result']['score']['scaled'] ?? null;
            if (($verb === vocab::VERB_PASSED || $verb === vocab::VERB_FAILED) && (is_int($scaled) || is_float($scaled))) {
                $highest = $update['scorehighest'] ?? $regau->scorehighest;
                $update['scorehighest'] = $highest === null ? (float)$scaled : max((float)$highest, (float)$scaled);
                $update['scorelast'] = (float)$scaled;
                $update['timelastscore'] = $storedms;
            }
        }
        if ($update) {
            $DB->update_record('scormmakercmi5_regau', (object)(['id' => $regau->id] + $update));
        }
    }

    /**
     * Inserts the itemdef rows still missing (design §3.12): the first statement that brings the definition of an
     * item wins. After the commit and outside the lock: the rows are shared by every registration of the package,
     * so two registrations may insert the same item at once; the unique index refuses the second, which is ignored
     * (inside the transaction it would abort the batch on PostgreSQL). Derived data: the next statement with the
     * definition fills a row lost here.
     *
     * @param array $itemdefs Candidates from write().
     * @param int $pkgid Package version of the registration.
     */
    private static function store_itemdefs(array $itemdefs, int $pkgid): void {
        global $DB;
        if (!$itemdefs) {
            return;
        }
        $keyof = static fn(string $version, string $kind, string $item): string => json_encode([$version, $kind, $item]);
        // One query for the items of the batch that already exist.
        [$insql, $params] = $DB->get_in_or_equal(array_unique(array_column($itemdefs, 0)), SQL_PARAMS_NAMED);
        $done = [];
        $rs = $DB->get_recordset_select(
            'scormmakercmi5_itemdef',
            "pkgid = :pkgid AND smversion {$insql}",
            $params + ['pkgid' => $pkgid],
            '',
            'id, smversion, smkind, smitem'
        );
        foreach ($rs as $row) {
            $done[$keyof($row->smversion, $row->smkind, $row->smitem)] = true;
        }
        $rs->close();
        foreach ($itemdefs as [$smversion, $smkind, $smitem, $objectid, $name, $definition, $storedms]) {
            $id = $keyof($smversion, $smkind, $smitem);
            if (isset($done[$id])) {
                continue;
            }
            $done[$id] = true;
            try {
                $DB->insert_record('scormmakercmi5_itemdef', (object)[
                    'pkgid' => $pkgid,
                    'smversion' => $smversion,
                    'smkind' => $smkind,
                    'smitem' => $smitem,
                    'objectid' => $objectid,
                    'name' => $name,
                    'definition' => $definition,
                    'firstseenms' => $storedms,
                ]);
            } catch (\dml_write_exception $e) {
                // Another registration inserted the same item first.
                continue;
            }
        }
    }

    /**
     * Whether a statement sent again is the one stored: canonical comparison without the properties the LRS sets.
     *
     * @param array $sent Normalized statement.
     * @param array $stored Decoded payload.
     * @return bool
     */
    private static function same(array $sent, array $stored): bool {
        return self::canonical(self::comparable($sent)) === self::canonical(self::comparable($stored));
    }

    /**
     * A statement reduced to what makes it the same statement (xAPI 1.0.3 part two §2.3.1, "Statement comparison
     * requirements"): without the properties the LRS sets, the id in lower case (UUIDs ignore case), timestamps as
     * milliseconds (Z, +00:00 and the precision are the same instant) and the members of a Group in any order.
     *
     * @param array $statement Statement or SubStatement in array form.
     * @return array
     */
    private static function comparable(array $statement): array {
        foreach (self::LRS_KEYS as $key) {
            unset($statement[$key]);
        }
        if (is_string($statement['id'] ?? null)) {
            $statement['id'] = strtolower($statement['id']);
        }
        if (is_string($statement['timestamp'] ?? null)) {
            try {
                $statement['timestamp'] = xapi_time::to_ms($statement['timestamp']);
            } catch (\InvalidArgumentException $e) {
                // Left as text: it then only matches the same text.
                unset($e);
            }
        }
        foreach (['actor', 'object'] as $key) {
            $members = $statement[$key]['member'] ?? null;
            if (is_array($members) && array_is_list($members)) {
                usort($members, static fn($a, $b): int => strcmp(self::canonical($a), self::canonical($b)));
                $statement[$key]['member'] = $members;
            }
        }
        foreach (['instructor', 'team'] as $key) {
            $members = $statement['context'][$key]['member'] ?? null;
            if (is_array($members) && array_is_list($members)) {
                usort($members, static fn($a, $b): int => strcmp(self::canonical($a), self::canonical($b)));
                $statement['context'][$key]['member'] = $members;
            }
        }
        if (is_array($statement['object'] ?? null) && ($statement['object']['objectType'] ?? null) === 'SubStatement') {
            $statement['object'] = self::comparable($statement['object']);
        }
        return $statement;
    }

    /**
     * A canonical text of a decoded value: object keys sorted at every depth; 1.0 and 1 are the same number.
     *
     * @param mixed $value Decoded value.
     * @return string
     */
    private static function canonical(mixed $value): string {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return is_float($v) && floor($v) === $v && abs($v) < 1e15 ? (int)$v : $v;
            }
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            return array_map($sort, $v);
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * The select of the statements of a list by id.
     *
     * @param array $statements Statements with id.
     * @return array [string $select, array $params].
     */
    private static function in_ids(array $statements): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(array_map('strtolower', array_column($statements, 'id')), SQL_PARAMS_NAMED);
        return ["statementid {$insql}", $params];
    }

    /**
     * Calls the test seam.
     *
     * @param string $stage Stage.
     */
    private static function hook(string $stage): void {
        if (self::$testhook) {
            (self::$testhook)($stage);
        }
    }

    /**
     * A JSON boolean as 1/0, anything else as null.
     *
     * @param mixed $value Value.
     * @return int|null
     */
    private static function bool_column(mixed $value): ?int {
        return is_bool($value) ? (int)$value : null;
    }
}
