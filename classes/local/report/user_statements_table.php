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

use mod_scormmakercmi5\local\package\course_structure;

/**
 * The statements of one registration, for diagnosis (R7.2, design §8.1): date, verb (last segment of its IRI), object
 * (definition.name in the user's language, or its IRI) and result (success, completion, score, duration).
 *
 * Base class: \core_table\sql_table (Moodle 4.5, see basic_table). Paged in SQL by (regid, timestampms), the index
 * regtimestamp, newest first, with the id as tie-break so a page never repeats or skips a statement. The result comes
 * from the indexed columns; only the name of the object is read from the payload, and only for the rows of the page.
 * Everything sent by the AU is escaped with s().
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_statements_table extends \core_table\sql_table {
    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /** @var \context_module Context of the module of the registration. */
    protected \context_module $modcontext;

    /**
     * Builds the table of the statements of a registration.
     *
     * @param string $uniqueid Unique id of the table.
     * @param int $regid Registration.
     */
    public function __construct(string $uniqueid, int $regid) {
        global $DB;
        parent::__construct($uniqueid);
        $instanceid = $DB->get_field('scormmakercmi5_reg', 'scormmakercmi5id', ['id' => $regid], MUST_EXIST);
        $cm = get_coursemodule_from_instance('scormmakercmi5', $instanceid, 0, false, MUST_EXIST);
        $this->modcontext = \context_module::instance($cm->id);

        $this->define_columns(['timestampms', 'verb', 'object', 'result']);
        $this->define_headers([
            get_string('reportstatementdate', self::COMPONENT),
            get_string('reportverb', self::COMPONENT),
            get_string('reportobject', self::COMPONENT),
            get_string('reportresult', self::COMPONENT),
        ]);
        $this->sortable(true, 'timestampms', SORT_DESC);
        foreach (['verb', 'object', 'result'] as $column) {
            $this->no_sorting($column);
        }
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable mod-scormmakercmi5-statements');
        $this->set_sql(
            'id, verb, objectid, success, completion, scorescaled, durationms, timestampms, payload',
            '{scormmakercmi5_stmt}',
            'regid = :regid',
            ['regid' => $regid]
        );
    }

    /**
     * The context of the module.
     *
     * @return \context
     */
    public function get_context(): \context {
        return $this->modcontext;
    }

    /**
     * The sort with the id as tie-break, in the same direction.
     *
     * @return string
     */
    public function get_sql_sort() {
        $columns = $this->get_sort_columns();
        $direction = ($columns['timestampms'] ?? SORT_DESC) == SORT_ASC ? 'ASC' : 'DESC';
        return "timestampms {$direction}, id {$direction}";
    }

    /**
     * The last segment of a verb IRI (after the last "/" or "#").
     *
     * @param string $iri Verb IRI.
     * @return string
     */
    public static function short_verb(string $iri): string {
        $segment = preg_replace('~^.*[/#]~', '', rtrim($iri, '/#'));
        return $segment === '' ? $iri : $segment;
    }

    /**
     * Date of the statement.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_timestampms($row) {
        return userdate((int)floor((int)$row->timestampms / 1000), get_string('strftimedatetimeaccurate', 'core_langconfig'));
    }

    /**
     * Short verb, with the IRI as its title.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_verb($row) {
        return \html_writer::span(s(self::short_verb((string)$row->verb)), '', ['title' => (string)$row->verb]);
    }

    /**
     * The name of the object in the user's language, or its IRI.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_object($row) {
        $statement = json_decode((string)$row->payload, true);
        $names = $statement['object']['definition']['name'] ?? null;
        $name = '';
        if (is_array($names)) {
            $names = array_filter($names, fn($text, $lang) => is_string($text) && is_string($lang), ARRAY_FILTER_USE_BOTH);
            $name = course_structure::pick($names, current_language());
        }
        if ($name === '') {
            return s((string)$row->objectid);
        }
        return \html_writer::span(s($name), '', ['title' => (string)$row->objectid]);
    }

    /**
     * Success, completion, score and duration, when present.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_result($row) {
        $parts = [];
        foreach (['success', 'completion'] as $field) {
            if ($row->{$field} !== null) {
                $answer = get_string((int)$row->{$field} ? 'yes' : 'no');
                $parts[] = get_string('report' . $field, self::COMPONENT, $answer);
            }
        }
        if ($row->scorescaled !== null) {
            $parts[] = get_string('reportscore', self::COMPONENT, format_float((float)$row->scorescaled * 100, 2, true, true));
        }
        if ($row->durationms !== null) {
            $parts[] = get_string('reportduration', self::COMPONENT, format_time((int)round((int)$row->durationms / 1000)));
        }
        return $parts ? s(implode('; ', $parts)) : '-';
    }
}
