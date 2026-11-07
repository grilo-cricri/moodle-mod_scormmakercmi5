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

/**
 * Upgrade steps of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin from $oldversion.
 *
 * No plugin class is called here: the code may not match the schema in the middle of an upgrade.
 *
 * @param int $oldversion Installed version.
 * @return bool
 */
function xmldb_scormmakercmi5_upgrade(int $oldversion): bool {
    global $DB, $OUTPUT;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100100) {
        // Subproject D: columns of the SCORM Maker profile 2 (block summaries and decisions).
        $table = new xmldb_table('scormmakercmi5_stmt');
        $fields = [
            new xmldb_field('smblock', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'smitem'),
            new xmldb_field('smblocktype', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'smblock'),
            new xmldb_field('smprofilever', XMLDB_TYPE_INTEGER, '2', null, null, null, null, 'smblocktype'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('instanceversionblock', XMLDB_INDEX_NOTUNIQUE, ['scormmakercmi5id', 'smversion', 'smblock']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        // The rows already stored stay NULL here: a package exported with the activity analysis may have been used
        // while the site ran 0.1.0, so the step 2026100102 queues their backfill.
        upgrade_mod_savepoint(true, 2026100100, 'scormmakercmi5');
    }

    if ($oldversion < 2026100101) {
        $table = new xmldb_table('scormmakercmi5_pkg');
        $field = new xmldb_field('richstructure', XMLDB_TYPE_TEXT, null, null, null, null, null, 'smversion');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Package versions imported by 0.1.0 stay NULL here: their structure is still only in the stored package
        // files, so the step 2026100103 queues their backfill.
        upgrade_mod_savepoint(true, 2026100101, 'scormmakercmi5');
    }

    if ($oldversion < 2026100102) {
        // Subproject D: the profile 2 columns of the statements stored before 0.2.0 are re-extracted from the payload
        // by an ad hoc task, in batches, after the upgrade (a large statements table would hold the upgrade for long).
        // Only the task is queued here: its class runs later, against the final schema. The guard repeats
        // backfill_profile_columns::candidates_where() (no plugin code is called during the upgrade).
        $pending = $DB->record_exists_select(
            'scormmakercmi5_stmt',
            'source = :source AND smprofile = 1 AND smprofilever IS NULL AND smkind IS NULL',
            ['source' => 0]
        );
        if ($pending) {
            \core\task\manager::queue_adhoc_task(new \mod_scormmakercmi5\task\backfill_profile_columns(), true);
        }
        upgrade_mod_savepoint(true, 2026100102, 'scormmakercmi5');
    }

    if ($oldversion < 2026100103) {
        // Subproject D: the analysis structure (analise/estrutura.json) of the package versions imported by 0.1.0 is
        // read from their stored files by an ad hoc task, after the upgrade. Queued only when a version has the file
        // and no structure; the guard repeats the FROM and WHERE of backfill_rich_structure::candidates_sql() (no
        // plugin code is called during the upgrade).
        $pending = $DB->record_exists_sql(
            'SELECT 1
               FROM {scormmakercmi5_pkg} p
               JOIN {scormmakercmi5_pkgfile} f ON f.pkgid = p.id AND f.pathhash = :pathhash
              WHERE p.richstructure IS NULL',
            ['pathhash' => sha1('analise/estrutura.json')]
        );
        if ($pending) {
            \core\task\manager::queue_adhoc_task(new \mod_scormmakercmi5\task\backfill_rich_structure(), true);
        }
        upgrade_mod_savepoint(true, 2026100103, 'scormmakercmi5');
    }

    if ($oldversion < 2026100200) {
        // Before 0.2.0 the EXIF remover of core (Moodle 4.4+) rewrote the JPEG files the import stored, so their
        // signed package versions fail every recheck with "file changed". An ad hoc task gives back the signed bytes
        // from the stored zip, under the checks of task\repair_redacted_images, and rechecks. Queued only when a verified
        // version has a JPEG in its content area; the guard repeats the FROM and WHERE of candidates_sql() (no plugin
        // code is called during the upgrade).
        $pending = $DB->record_exists_sql(
            'SELECT 1
               FROM {scormmakercmi5_pkg} p
              WHERE p.sigstatus = :verified
                    AND EXISTS (SELECT 1 FROM {files} f
                                 WHERE f.component = :component AND f.filearea = :filearea
                                       AND f.itemid = p.id AND f.mimetype = :mimetype)',
            ['verified' => 'verified', 'component' => 'mod_scormmakercmi5', 'filearea' => 'content',
                'mimetype' => 'image/jpeg']
        );
        if ($pending) {
            \core\task\manager::queue_adhoc_task(new \mod_scormmakercmi5\task\repair_redacted_images(), true);
        }
        upgrade_mod_savepoint(true, 2026100200, 'scormmakercmi5');
    }

    if ($oldversion < 2026100201) {
        // Limits of the block summaries of the activity analysis (audit M-D1): the LRS refuses a summary outside them
        // from now on. Nothing is rewritten: the report ignores the summaries outside the limits stored before.
        upgrade_mod_savepoint(true, 2026100201, 'scormmakercmi5');
    }

    if ($oldversion < 2026100202) {
        // Final wave of the activity analysis: the time of a block summary follows the rule of the contract (only
        // PT<n>S up to 86400 s, milliseconds from the decimal text). The summaries already stored get their column
        // recomputed by an ad hoc task, in batches, after the upgrade (null outside the rule, so a forged P1D no longer
        // counts in the average). Queued only when a block summary of the analysis is stored; the guard repeats
        // statement_store::analysis_where() with kind bloco (no plugin code is called during the upgrade). The rows of
        // 0.1.0 are not classified yet: backfill_profile_columns writes their time when it classifies them.
        $pending = $DB->record_exists_select(
            'scormmakercmi5_stmt',
            'cmi5kind = :allowed AND verb IN (:interacted, :selected) AND smprofile = 1 AND smprofilever = :profilever
                AND smkind = :bloco',
            ['allowed' => 1, 'interacted' => 'http://adlnet.gov/expapi/verbs/interacted',
                'selected' => 'http://id.tincanapi.com/verb/selected', 'profilever' => 2, 'bloco' => 'bloco']
        );
        if ($pending) {
            \core\task\manager::queue_adhoc_task(new \mod_scormmakercmi5\task\recompute_summary_durations(), true);
        }
        upgrade_mod_savepoint(true, 2026100202, 'scormmakercmi5');
    }

    if ($oldversion < 2026100203) {
        // Texts of the block report changed (final wave W2b): a new version makes the sites purge their caches.
        upgrade_mod_savepoint(true, 2026100203, 'scormmakercmi5');
    }

    if ($oldversion < 2026100300) {
        // AU outside the package (absolute https url, 0.3.0): its origin, for the CORS of lrs.php and fetch.php. No data
        // to migrate: such AUs were refused at import until now.
        $table = new xmldb_table('scormmakercmi5_au');
        $field = new xmldb_field('auorigin', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'activitytype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('auorigin', XMLDB_INDEX_NOTUNIQUE, ['auorigin']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_mod_savepoint(true, 2026100300, 'scormmakercmi5');
    }

    if ($oldversion < 2026100500) {
        // The SCORM Maker PROD key is embedded (0.3.1) and accepthmlkey becomes off by default (audit B-3). The sites
        // where no administrator ever changed it get 0: the value was never saved, or every config_log row of it is a
        // first write (old value NULL: the default applied at install, or the upgrade settings page saved untouched).
        // A change by an administrator leaves a row with an old value, and such a site keeps its value. The change is
        // logged like one made in the settings, and the administrator gets a notice on the upgrade page.
        $component = 'mod_scormmakercmi5';
        $current = get_config($component, 'accepthmlkey');
        if ($current === false || (string)$current !== '0') {
            $changed = $DB->record_exists_select(
                'config_log',
                'plugin = :plugin AND name = :name AND oldvalue IS NOT NULL',
                ['plugin' => $component, 'name' => 'accepthmlkey']
            );
            if (!$changed) {
                set_config('accepthmlkey', '0', $component);
                add_to_config_log('accepthmlkey', $current === false ? null : (string)$current, '0', $component);
                echo $OUTPUT->notification(
                    get_string('upgrade_hmlkeyoff', $component),
                    \core\output\notification::NOTIFY_WARNING
                );
            }
        }
        upgrade_mod_savepoint(true, 2026100500, 'scormmakercmi5');
    }

    if ($oldversion < 2026100501) {
        // The plugin icon changed (0.3.2): a new version makes the sites purge their caches.
        upgrade_mod_savepoint(true, 2026100501, 'scormmakercmi5');
    }

    if ($oldversion < 2026110600) {
        // Release 0.4.0 (Plugins Directory preparation): no schema change. allowexternalau is off by default from now
        // on, but a site with no stored value has been running with it on (a missing value counted as on): it keeps
        // that behaviour, so an upgrade never starts refusing the AUs outside the package of the site. The default 0
        // reaches new installs only; a stored 0 or 1 is left as it is.
        if (get_config('mod_scormmakercmi5', 'allowexternalau') === false) {
            set_config('allowexternalau', '1', 'mod_scormmakercmi5');
        }
        upgrade_mod_savepoint(true, 2026110600, 'scormmakercmi5');
    }

    return true;
}
