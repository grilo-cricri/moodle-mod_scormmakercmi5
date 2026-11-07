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

namespace mod_scormmakercmi5\local;

use mod_scormmakercmi5\event\package_replaced;
use mod_scormmakercmi5\local\grading\grade_calculator;
use mod_scormmakercmi5\local\package\course_structure;
use mod_scormmakercmi5\local\package\importer;

/**
 * Instance lifecycle behind the lib.php callbacks: create with the package, replace the package (R1.5), delete.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class instance_manager {
    /** @var string[] Instance fields the form may change. */
    private const FORM_FIELDS = ['name', 'intro', 'introformat', 'grade', 'grademethod', 'browsemode', 'reviewmode',
        'completionsatisfied'];

    /** @var int Ids per delete_records_list() call, well below the placeholder limit of every database. */
    private const CHUNK = 1000;

    /** @var int Width of the name column. */
    private const NAME_CHARS = 255;

    /**
     * Creates the instance and imports its package (design §4.1). Name and intro left empty come from cmi5.xml (R1.1).
     *
     * add_moduleinfo() has created the course module already, so its context exists; it also runs this inside its
     * transaction, so a failed import leaves no instance behind.
     *
     * @param \stdClass $data Form data: packagefile is the draft item id; coursemodule the new course module id.
     * @return int Instance id.
     */
    public static function add(\stdClass $data): int {
        global $DB;
        $course = $DB->get_record('course', ['id' => $data->course], '*', MUST_EXIST);
        $context = \context_module::instance($data->coursemodule);

        $record = new \stdClass();
        $record->course = $course->id;
        foreach (self::FORM_FIELDS as $field) {
            if (property_exists($data, $field)) {
                $record->$field = $data->$field;
            }
        }
        $record->name = self::clean_name($record->name ?? '');
        $record->intro = $record->intro ?? '';
        $record->introformat = $record->introformat ?? FORMAT_HTML;
        $record->timecreated = time();
        $record->timemodified = $record->timecreated;
        $record->currentpkgid = 0;
        $record->lmscourseiri = importer::generated_iri('course');
        $record->id = (int)$DB->insert_record('scormmakercmi5', $record);

        $structure = null;
        $pkg = (new importer())->import_draft((int)($data->packagefile ?? 0), $record, $context, $course, $structure);

        $update = (object)['id' => $record->id, 'currentpkgid' => $pkg->id];
        if ($record->name === '') {
            $update->name = self::name_from($structure);
        }
        if (html_is_blank($record->intro)) {
            $description = course_structure::pick($structure->description, current_language());
            $update->intro = $description === '' ? '' : '<p>' . nl2br(s($description)) . '</p>';
            $update->introformat = FORMAT_HTML;
        }
        $DB->update_record('scormmakercmi5', $update);

        // The caller add_moduleinfo() keeps using $data: the name for the course module, the intro it saves after this call.
        foreach (['name', 'intro', 'introformat'] as $field) {
            if (isset($update->$field)) {
                $data->$field = $update->$field;
            }
        }
        $data->currentpkgid = $pkg->id;
        $data->lmscourseiri = $record->lmscourseiri;
        self::grade_item_update($record->id, $data);
        return $record->id;
    }

    /**
     * Updates the instance. A package in the draft area whose contenthash differs from the current zip becomes a new
     * version (R1.5): currentpkgid points to it, registrations keep their pkgid, package_replaced is triggered.
     *
     * The confirmation required when registrations exist is checked by the form (mod_form::validation()), not here:
     * this is a contract of the callers. The form is the only one that replaces a package today (the web service of
     * local_scorm_maker_import only adds activities); any new caller that replaces one must enforce R1.5 itself.
     *
     * @param \stdClass $data Form data: instance is the id; packagefile the draft item id.
     * @return bool
     */
    public static function update(\stdClass $data): bool {
        global $DB;
        $instance = $DB->get_record('scormmakercmi5', ['id' => $data->instance], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('scormmakercmi5', $instance->id, $instance->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $record = (object)['id' => $instance->id, 'timemodified' => time()];
        foreach (self::FORM_FIELDS as $field) {
            if (property_exists($data, $field)) {
                $record->$field = $data->$field;
            }
        }

        $pkg = null;
        $structure = null;
        if (self::is_new_package((int)($data->packagefile ?? 0), $instance)) {
            $course = $DB->get_record('course', ['id' => $instance->course], '*', MUST_EXIST);
            $pkg = (new importer())->import_draft((int)$data->packagefile, $instance, $context, $course, $structure, true);
            $record->currentpkgid = $pkg->id;
        }

        $record->name = self::clean_name($record->name ?? $instance->name);
        if ($record->name === '') {
            $record->name = $structure ? self::name_from($structure) : $instance->name;
        }
        $data->name = $record->name;
        $DB->update_record('scormmakercmi5', $record);
        $fresh = self::grade_item_update((int)$instance->id, $data);
        if ((int)$fresh->grade !== (int)$instance->grade || (int)$fresh->grademethod !== (int)$instance->grademethod) {
            // The grades come from the stored AU scores (design §6.6): recompute them with the new settings.
            grade_calculator::update($fresh);
        }

        if ($pkg) {
            $event = package_replaced::create([
                'objectid' => $pkg->id,
                'context' => $context,
                'other' => ['oldpkgid' => (int)$instance->currentpkgid, 'versionnum' => (int)$pkg->versionnum],
            ]);
            $event->add_record_snapshot('scormmakercmi5_pkg', $pkg);
            $event->trigger();
        }
        return true;
    }

    /**
     * Creates or updates the grade item from the saved instance row.
     *
     * @param int $id Instance id.
     * @param \stdClass $data Form data (cmidnumber, when present, is the item idnumber).
     * @return \stdClass The instance row, with cmidnumber when the form sent it.
     */
    private static function grade_item_update(int $id, \stdClass $data): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scormmakercmi5/lib.php');
        $instance = $DB->get_record('scormmakercmi5', ['id' => $id], '*', MUST_EXIST);
        if (isset($data->cmidnumber)) {
            $instance->cmidnumber = $data->cmidnumber;
        }
        scormmakercmi5_grade_item_update($instance);
        return $instance;
    }

    /**
     * Whether the draft area holds a zip other than the current package of the instance.
     *
     * @param int $draftitemid Draft item id of the file manager (of the current user).
     * @param \stdClass $instance Instance row.
     * @return bool False when the draft area is empty or has the same zip.
     */
    public static function is_new_package(int $draftitemid, \stdClass $instance): bool {
        global $DB;
        $file = importer::draft_file($draftitemid);
        if (!$file) {
            return false;
        }
        $current = $DB->get_field('scormmakercmi5_pkg', 'zipcontenthash', ['id' => $instance->currentpkgid]);
        return $file->get_contenthash() !== $current;
    }

    /**
     * Whether the instance has registrations, i.e. learners who started it.
     *
     * @param int $instanceid Instance id.
     * @return bool
     */
    public static function has_registrations(int $instanceid): bool {
        global $DB;
        return $DB->record_exists('scormmakercmi5_reg', ['scormmakercmi5id' => $instanceid]);
    }

    /**
     * Deletes the instance and everything it owns, children first: stmt, state, session, regblock, regau, reg,
     * itemdef, pkgfile, au, block, pkg, the content and package file areas, the grade item and the instance row.
     *
     * @param int $id Instance id.
     * @return bool False when the instance does not exist.
     */
    public static function delete(int $id): bool {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $instance = $DB->get_record('scormmakercmi5', ['id' => $id]);
        if (!$instance) {
            return false;
        }
        $regids = $DB->get_fieldset_select('scormmakercmi5_reg', 'id', 'scormmakercmi5id = ?', [$id]);
        $pkgids = $DB->get_fieldset_select('scormmakercmi5_pkg', 'id', 'scormmakercmi5id = ?', [$id]);

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('scormmakercmi5_stmt', ['scormmakercmi5id' => $id]);
        $DB->delete_records('scormmakercmi5_state', ['scormmakercmi5id' => $id]);
        foreach (['scormmakercmi5_session', 'scormmakercmi5_regblock', 'scormmakercmi5_regau'] as $table) {
            self::delete_list($table, 'regid', $regids);
        }
        $DB->delete_records('scormmakercmi5_reg', ['scormmakercmi5id' => $id]);
        foreach (['scormmakercmi5_itemdef', 'scormmakercmi5_pkgfile', 'scormmakercmi5_au', 'scormmakercmi5_block'] as $table) {
            self::delete_list($table, 'pkgid', $pkgids);
        }
        $DB->delete_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $id]);

        // The course module still exists when course_delete_module() calls this; without it the context (and its
        // files) is already gone.
        $cm = get_coursemodule_from_instance('scormmakercmi5', $id, $instance->course);
        if ($cm) {
            $context = \context_module::instance($cm->id, IGNORE_MISSING);
            if ($context) {
                $fs = get_file_storage();
                foreach ([importer::AREA_CONTENT, importer::AREA_PACKAGE] as $area) {
                    $fs->delete_area_files($context->id, importer::COMPONENT, $area);
                }
            }
        }
        grade_update('mod/scormmakercmi5', $instance->course, 'mod', 'scormmakercmi5', $id, 0, null, ['deleted' => 1]);
        $DB->delete_records('scormmakercmi5', ['id' => $id]);
        $transaction->allow_commit();
        return true;
    }

    /**
     * Deletes the rows whose field is in the ids, in chunks.
     *
     * @param string $table Table.
     * @param string $field Field.
     * @param array $ids Ids.
     */
    private static function delete_list(string $table, string $field, array $ids): void {
        global $DB;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $DB->delete_records_list($table, $field, $chunk);
        }
    }

    /**
     * The activity name from the course title of cmi5.xml, in the current language.
     *
     * @param course_structure $structure Parsed cmi5.xml.
     * @return string
     */
    private static function name_from(course_structure $structure): string {
        $name = self::clean_name(course_structure::pick($structure->title, current_language()));
        return $name === '' ? get_string('modulename', 'mod_scormmakercmi5') : $name;
    }

    /**
     * A name trimmed and cut to the column width.
     *
     * @param string $name Name.
     * @return string
     */
    private static function clean_name(string $name): string {
        return \core_text::substr(trim($name), 0, self::NAME_CHARS);
    }
}
