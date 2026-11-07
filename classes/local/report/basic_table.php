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

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\package\course_structure;

/**
 * The basic report of any cmi5 package (R7.1, R7.3, design §8.1): one row per learner with the status of the course
 * and of each AU, the grade, the total time, the number of sessions and the first and last access.
 *
 * Base class: \core_table\sql_table, which exists in Moodle 4.5 (lib/table/classes/sql_table.php; \table_sql is its
 * legacy alias), so no tablelib.php include is needed.
 *
 * Learners: users enrolled (active) with mod/scormmakercmi5:view, LEFT JOIN their registration, so those who never
 * started appear as "Not started". Staff who see the reports (viewreports) are left out unless they have an attempt
 * of their own: the teachers of the course also have view, and a list of learners full of teachers is noise.
 *
 * Every figure comes from indexed columns in one aggregated, parameterised query (no JSON is read): reg (unique
 * scormmakercmi5id, userid), the sessions of the instance's registrations grouped by regid (index regid, auid,
 * status). The status of each AU comes from regau (unique regid, auid) for the registrations of the page only, in one
 * query, matched to the columns by lmsactivityid so a learner on an older package version still lines up (R1.5).
 *
 * Groups: in separate groups without moodle/site:accessallgroups, only the allowed groups of the user
 * (groups_get_activity_allowed_groups); a group outside them is refused with required_capability_exception.
 *
 * Download through the table's dataformat export (\core\dataformat writers): plain text, and every text cell that a
 * spreadsheet would read as a formula gets an apostrophe in front (spreadsheet_safe()).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class basic_table extends \core_table\sql_table {
    /** @var string[] Accepted values of the status filter ('' = all). */
    public const STATUS_FILTERS = ['', status::NOT_STARTED, status::IN_PROGRESS, status::SATISFIED];

    /**
     * @var string[] Download formats offered and accepted (design §8.1 names CSV and xlsx). The HTML and PDF writers
     *     declare supports_html() and write cells unescaped, so AU titles from the package would reach them as markup.
     */
    public const DOWNLOAD_FORMATS = ['csv', 'excel', 'ods'];

    /** @var int Up to this many AUs, one column each; above, one "x/y" column (design §8.1). */
    public const MAX_AU_COLUMNS = 20;

    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /** @var \cm_info Course module. */
    protected \cm_info $cm;

    /** @var \context_module Context of the module. */
    protected \context_module $modcontext;

    /** @var \stdClass Instance row. */
    protected \stdClass $instance;

    /** @var \stdClass[] AUs of the columns: column name => AU row. */
    protected array $aucolumns = [];

    /** @var bool Whether the AUs are shown as one "x/y" column. */
    protected bool $aussummary;

    /** @var int Number of AUs of the current package (y of "x/y" for a learner without registration). */
    protected int $aucount;

    /** @var string Status filter after the allowlist. */
    protected string $statusfilter;

    /** @var array regid => lmsactivityid => regau row (status flags). */
    protected array $regaus = [];

    /** @var array regid => lmsactivityid => true for the AUs launched at least once. */
    protected array $launched = [];

    /** @var array regid => number of satisfied AUs. */
    protected array $satisfiedcount = [];

    /** @var array pkgid => number of AUs. */
    protected array $pkgaucount = [];

    /** @var string[] Status => HTML of its icon and label, built once. */
    protected array $statushtml = [];

    /** @var bool Whether the viewer sees full names. */
    protected bool $viewfullnames;

    /**
     * Builds the table and its query.
     *
     * @param string $uniqueid Unique id of the table.
     * @param \cm_info $cm Course module.
     * @param \stdClass $instance Instance row.
     * @param \stdClass[] $aus AUs of the current package (id, title, lmsactivityid), in order.
     * @param int $groupid Group to show (0 = every group the viewer may see).
     * @param string $statusfilter '', notstarted, inprogress or satisfied; anything else counts as ''.
     * @throws \required_capability_exception A group the viewer may not see.
     */
    public function __construct(
        string $uniqueid,
        \cm_info $cm,
        \stdClass $instance,
        array $aus,
        int $groupid,
        string $statusfilter
    ) {
        parent::__construct($uniqueid);
        $this->cm = $cm;
        $this->instance = $instance;
        $this->modcontext = \context_module::instance($cm->id);
        $this->statusfilter = in_array($statusfilter, self::STATUS_FILTERS, true) ? $statusfilter : '';
        $this->aucount = count($aus);
        $this->aussummary = $this->aucount > self::MAX_AU_COLUMNS;
        $this->viewfullnames = has_capability('moodle/site:viewfullnames', $this->modcontext);

        $columns = ['fullname' => get_string('reportlearner', self::COMPONENT)];
        $columns['coursestatus'] = get_string('reportcoursestatus', self::COMPONENT);
        $lang = current_language();
        if ($this->aussummary) {
            $columns['ausatisfied'] = get_string('reportausatisfied', self::COMPONENT);
        } else {
            foreach (array_values($aus) as $au) {
                $titles = json_decode((string)$au->title, true);
                $columns['au' . (int)$au->id] = course_structure::pick(is_array($titles) ? $titles : [], $lang);
                $this->aucolumns['au' . (int)$au->id] = $au;
            }
        }
        $columns['grade'] = get_string('reportgrade', self::COMPONENT);
        $columns['totaltime'] = get_string('reporttotaltime', self::COMPONENT);
        $columns['sessions'] = get_string('reportsessions', self::COMPONENT);
        $columns['timefirst'] = get_string('reportfirstaccess', self::COMPONENT);
        $columns['timelast'] = get_string('reportlastaccess', self::COMPONENT);
        $this->define_columns(array_keys($columns));
        // The headers go out as HTML in the page: the AU titles come from the package.
        $this->define_headers(array_map('s', array_values($columns)));
        $this->define_header_column('fullname');
        $this->sortable(true, 'lastname');
        foreach (array_merge(['coursestatus', 'ausatisfied'], array_keys($this->aucolumns)) as $column) {
            $this->no_sorting($column);
        }
        $this->collapsible(false);
        $this->is_downloadable(true);
        $this->show_download_buttons_at([TABLE_P_BOTTOM]);
        $this->set_attribute('class', 'generaltable mod-scormmakercmi5-report');
        $this->set_caption(get_string('reportlearners', self::COMPONENT), ['class' => 'accesshide']);
        $this->useridfield = 'id';

        $this->build_sql($this->group_filter($groupid));
    }

    /**
     * The status filter in use (after the allowlist).
     *
     * @return string
     */
    public function get_status_filter(): string {
        return $this->statusfilter;
    }

    /**
     * The context of the module (names, capabilities).
     *
     * @return \context
     */
    public function get_context(): \context {
        return $this->modcontext;
    }

    /**
     * The download formats enabled on the site among DOWNLOAD_FORMATS.
     *
     * @return string[]
     */
    public static function download_formats(): array {
        $enabled = array_keys(\core_plugin_manager::instance()->get_enabled_plugins('dataformat') ?? []);
        return array_values(array_intersect(self::DOWNLOAD_FORMATS, $enabled));
    }

    /**
     * The download selector, with the formats of download_formats() only (core lists every enabled format).
     *
     * @return string
     */
    public function download_buttons() {
        if (!$this->is_downloadable() || $this->is_downloading()) {
            return '';
        }
        return self::format_selector($this->baseurl, get_string('downloadas', 'table'));
    }

    /**
     * The core download form (core/dataformat_selector) with the formats of download_formats() only: the basic
     * report and the download of the block activity (block_export) share it.
     *
     * @param \moodle_url $url Target; its parameters go as hidden fields, the format as "download".
     * @param string $label Label of the format list.
     * @return string HTML.
     */
    public static function format_selector(\moodle_url $url, string $label): string {
        global $OUTPUT;
        $options = [];
        foreach (self::download_formats() as $format) {
            $options[] = ['value' => $format, 'label' => get_string('dataformat', 'dataformat_' . $format)];
        }
        $params = [];
        foreach ($url->params() as $name => $value) {
            $params[] = ['name' => $name, 'value' => $value];
        }
        return $OUTPUT->render_from_template('core/dataformat_selector', [
            'label' => $label,
            'base' => $url->out_omit_querystring(),
            'name' => 'download',
            'params' => $params,
            'options' => $options,
            'sesskey' => sesskey(),
            'submit' => get_string('download'),
        ]);
    }

    /**
     * The groups the rows of a report are restricted to (R7.3): the basic and the rich report share it.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Its context.
     * @param int $groupid Group asked for (0 = all the viewer may see).
     * @return int[] [] = every learner; [-1] = nobody (no allowed group).
     * @throws \required_capability_exception A group outside the allowed ones.
     */
    public static function restrict_groups(\cm_info $cm, \context_module $context, int $groupid): array {
        $restricted = groups_get_activity_groupmode($cm) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $context);
        $allowed = $restricted ? array_map('intval', array_keys(groups_get_activity_allowed_groups($cm))) : [];
        if ($groupid > 0) {
            if ($restricted && !in_array($groupid, $allowed, true)) {
                throw new \required_capability_exception($context, 'moodle/site:accessallgroups', 'nopermissions', '');
            }
            return [$groupid];
        }
        if ($restricted) {
            return $allowed ?: [-1];
        }
        return [];
    }

    /**
     * Neutralises spreadsheet formulas (CSV injection, as SCORM Maker does in its own reports): a text that starts with
     * = + - @ tab or CR gets an apostrophe in front.
     *
     * @param string $value Cell.
     * @return string
     */
    public static function spreadsheet_safe(string $value): string {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Runs the query, then loads the status of the AUs of the registrations fetched (one query each).
     *
     * @param int $pagesize Page size.
     * @param bool $useinitialsbar Whether to use the initials bar.
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        parent::query_db($pagesize, $useinitialsbar);
        $this->preload();
    }

    /**
     * The learner's name, linked to their detail in the report (mode=user).
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_fullname($row) {
        $name = fullname($row, $this->viewfullnames);
        if ($this->is_downloading()) {
            return self::spreadsheet_safe($name);
        }
        $url = new \moodle_url('/mod/scormmakercmi5/report.php', ['id' => $this->cm->id, 'mode' => 'user', 'userid' => $row->id]);
        return \html_writer::link($url, s($name));
    }

    /**
     * Status of the course, flagged when the registration reached maxstatementsperreg: the AU's statements are refused
     * from then on, until the attempt is deleted (final review N-2).
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_coursestatus($row) {
        $reg = $row->regid ? (object)['satisfied' => $row->satisfied] : null;
        $cell = $this->status_cell(status::course_state($reg, (int)$row->sessions > 0));
        if (!$row->regid || !limits::statement_limit_reached((int)$row->statementcount)) {
            return $cell;
        }
        $flag = get_string('reportstatementlimit', self::COMPONENT);
        if ($this->is_downloading()) {
            return $cell . ' (' . self::spreadsheet_safe($flag) . ')';
        }
        return $cell . ' ' . \html_writer::span(s($flag), 'badge bg-warning text-dark');
    }

    /**
     * Satisfied AUs "x/y" (more than MAX_AU_COLUMNS AUs).
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_ausatisfied($row) {
        $total = $row->regid ? ($this->pkgaucount[(int)$row->pkgid] ?? 0) : $this->aucount;
        $done = $row->regid ? ($this->satisfiedcount[(int)$row->regid] ?? 0) : 0;
        return $done . '/' . $total;
    }

    /**
     * Grade in the scale of the activity (reg.grade is 0–1).
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_grade($row) {
        if ($row->grade === null || !$row->regid) {
            return '-';
        }
        return format_float((float)$row->grade * (float)$this->instance->grade, 2);
    }

    /**
     * Total time: the sum of the durations of the sessions.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_totaltime($row) {
        if ($row->totaltime === null) {
            return '-';
        }
        return format_time((int)round((int)$row->totaltime / 1000));
    }

    /**
     * Number of launch sessions.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_sessions($row) {
        return (string)(int)$row->sessions;
    }

    /**
     * First access: the first launch.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_timefirst($row) {
        return $this->time_cell($row->timefirst);
    }

    /**
     * Last access: the last activity of a launch session.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_timelast($row) {
        return $this->time_cell($row->timelast);
    }

    /**
     * The status of one AU (columns au<id>).
     *
     * @param string $column Column.
     * @param \stdClass $row Row.
     * @return string|null
     */
    public function other_cols($column, $row) {
        if (!isset($this->aucolumns[$column])) {
            return null;
        }
        $key = (string)$this->aucolumns[$column]->lmsactivityid;
        $regid = (int)$row->regid;
        if ($regid && !isset($this->regaus[$regid][$key])) {
            // An AU that the package version of this registration does not have.
            return '-';
        }
        return $this->status_cell(status::au_state($this->regaus[$regid][$key] ?? null, !empty($this->launched[$regid][$key])));
    }

    /**
     * The headers of the download as plain text.
     */
    public function start_output() {
        if ($this->is_downloading()) {
            $this->headers = array_map(
                fn($header) => self::spreadsheet_safe(htmlspecialchars_decode((string)$header, ENT_QUOTES)),
                $this->headers
            );
        }
        parent::start_output();
    }

    /**
     * The query: learners (enrolled with view, in the groups) LEFT JOIN registration LEFT JOIN the aggregates of
     * their sessions, with the status filter.
     *
     * @param array $groupids Groups to restrict to ([] = no restriction; [-1] = none visible).
     */
    protected function build_sql(array $groupids): void {
        $members = $groupids === [-1] ? 0 : ($groupids ?: 0);
        [$enrolledsql, $enrolledparams] = get_enrolled_sql($this->modcontext, 'mod/scormmakercmi5:view', $members, true);
        [$staffsql, $staffparams] = get_with_capability_sql($this->modcontext, 'mod/scormmakercmi5:viewreports');
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        $fields = "u.id, {$namefields}, r.id AS regid, r.pkgid, r.satisfied, r.grade AS grade, r.statementcount,
                   COALESCE(ss.sessions, 0) AS sessions, ss.totalms AS totaltime, ss.firstms AS timefirst,
                   ss.lastms AS timelast";
        $from = "{user} u
                 JOIN ({$enrolledsql}) je ON je.id = u.id
            LEFT JOIN {scormmakercmi5_reg} r ON r.userid = u.id AND r.scormmakercmi5id = :instanceid
            LEFT JOIN (SELECT s.regid,
                              SUM(CASE WHEN s.kind = 0 THEN 1 ELSE 0 END) AS sessions,
                              SUM(s.durationms) AS totalms,
                              MIN(CASE WHEN s.kind = 0 THEN s.timelaunchedms END) AS firstms,
                              MAX(CASE WHEN s.kind = 0 THEN s.lastactivityms END) AS lastms
                         FROM {scormmakercmi5_session} s
                         JOIN {scormmakercmi5_reg} sr ON sr.id = s.regid
                        WHERE sr.scormmakercmi5id = :sinstanceid
                     GROUP BY s.regid) ss ON ss.regid = r.id";
        $where = "u.deleted = 0 AND (r.id IS NOT NULL OR u.id NOT IN ({$staffsql}))";
        $params = ['instanceid' => $this->instance->id, 'sinstanceid' => $this->instance->id]
            + $enrolledparams + $staffparams;
        if ($groupids === [-1]) {
            $where .= ' AND 1 = 0';
        }
        $where .= match ($this->statusfilter) {
            status::NOT_STARTED => ' AND (r.id IS NULL OR (r.satisfied = 0 AND COALESCE(ss.sessions, 0) = 0))',
            status::IN_PROGRESS => ' AND r.satisfied = 0 AND ss.sessions > 0',
            status::SATISFIED => ' AND r.satisfied = 1',
            default => '',
        };
        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(1) FROM {$from} WHERE {$where}", $params);
    }

    /**
     * The groups the rows are restricted to.
     *
     * @param int $groupid Group asked for (0 = all the viewer may see).
     * @return int[] [] = every learner; [-1] = nobody (no allowed group).
     * @throws \required_capability_exception A group outside the allowed ones.
     */
    protected function group_filter(int $groupid): array {
        return self::restrict_groups($this->cm, $this->modcontext, $groupid);
    }

    /**
     * Loads, for the registrations fetched, the status of each AU (or the number of satisfied AUs) and the AUs
     * launched; and the number of AUs of each package version.
     */
    protected function preload(): void {
        global $DB;
        $this->regaus = [];
        $this->launched = [];
        $this->satisfiedcount = [];
        $regids = [];
        foreach ($this->rawdata ?: [] as $row) {
            if ($row->regid) {
                $regids[] = (int)$row->regid;
            }
        }
        if (!$regids) {
            return;
        }
        if ($this->is_downloading()) {
            // Every row of the instance: an IN list could exceed the limit of parameters of the database.
            $regsql = 'SELECT id FROM {scormmakercmi5_reg} WHERE scormmakercmi5id = :preginstance';
            $regparams = ['preginstance' => $this->instance->id];
        } else {
            [$regsql, $regparams] = $DB->get_in_or_equal($regids, SQL_PARAMS_NAMED, 'preg');
            $regsql = 'SELECT id FROM {scormmakercmi5_reg} WHERE id ' . $regsql;
        }

        if ($this->aussummary) {
            $sql = "SELECT ra.regid, SUM(ra.satisfied) AS done
                      FROM {scormmakercmi5_regau} ra
                     WHERE ra.regid IN ({$regsql})
                  GROUP BY ra.regid";
            foreach ($DB->get_records_sql($sql, $regparams) as $count) {
                $this->satisfiedcount[(int)$count->regid] = (int)$count->done;
            }
            $sql = "SELECT a.pkgid, COUNT(1) AS total
                      FROM {scormmakercmi5_au} a
                      JOIN {scormmakercmi5_pkg} p ON p.id = a.pkgid
                     WHERE p.scormmakercmi5id = :instanceid
                  GROUP BY a.pkgid";
            foreach ($DB->get_records_sql($sql, ['instanceid' => $this->instance->id]) as $count) {
                $this->pkgaucount[(int)$count->pkgid] = (int)$count->total;
            }
            return;
        }

        $sql = "SELECT ra.id, ra.regid, a.lmsactivityid, ra.completed, ra.passed, ra.failed, ra.waived, ra.satisfied
                  FROM {scormmakercmi5_regau} ra
                  JOIN {scormmakercmi5_au} a ON a.id = ra.auid
                 WHERE ra.regid IN ({$regsql})";
        $rs = $DB->get_recordset_sql($sql, $regparams);
        foreach ($rs as $regau) {
            $this->regaus[(int)$regau->regid][(string)$regau->lmsactivityid] = $regau;
        }
        $rs->close();
        $sql = "SELECT DISTINCT s.regid, a.lmsactivityid
                  FROM {scormmakercmi5_session} s
                  JOIN {scormmakercmi5_au} a ON a.id = s.auid
                 WHERE s.kind = 0 AND s.regid IN ({$regsql})";
        $rs = $DB->get_recordset_sql($sql, $regparams);
        foreach ($rs as $launch) {
            $this->launched[(int)$launch->regid][(string)$launch->lmsactivityid] = true;
        }
        $rs->close();
    }

    /**
     * A status as text with an icon (HTML), or the plain label in a download.
     *
     * @param string $state Status.
     * @return string
     */
    protected function status_cell(string $state): string {
        if ($this->is_downloading()) {
            return self::spreadsheet_safe(status::label($state));
        }
        if (!isset($this->statushtml[$state])) {
            global $PAGE;
            /** @var \mod_scormmakercmi5\output\renderer $renderer */
            $renderer = $PAGE->get_renderer(self::COMPONENT);
            $this->statushtml[$state] = $renderer->status($state);
        }
        return $this->statushtml[$state];
    }

    /**
     * A time in milliseconds as a date, '-' when there is none.
     *
     * @param mixed $ms Milliseconds or null.
     * @return string
     */
    protected function time_cell($ms): string {
        if ($ms === null || (int)$ms <= 0) {
            return '-';
        }
        return userdate((int)floor((int)$ms / 1000));
    }
}
