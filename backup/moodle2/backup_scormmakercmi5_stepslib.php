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
 * Backup structure of mod_scormmakercmi5 (design §12, NF7).
 *
 * @package    mod_scormmakercmi5
 * @category   backup
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_scormmakercmi5\backup\verbatim_element;

/**
 * The structure: scormmakercmi5 → pkgs/pkg → (blocks/block, aus/au, pkgfiles/pkgfile, itemdefs/itemdef); with user
 * data → regs/reg → (regaus/regau, regblocks/regblock, sessions/session, stmts/stmt, states/state) and
 * instancestates/instancestate (State documents written without a registration).
 *
 * Without user data only the current version goes; with user data, the current one and those registrations are
 * pinned to. Never in the backup: session fetch keys and tokens (hashes and expiry), the recheck state of the
 * versions (the target site rechecks with its own keyring), the Agent Profile (it belongs to the user, not to the
 * activity).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_scormmakercmi5_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $root = new backup_nested_element('scormmakercmi5', ['id'], ['name', 'intro', 'introformat', 'timecreated',
            'timemodified', 'grade', 'grademethod', 'completionsatisfied', 'browsemode', 'reviewmode', 'currentpkgid',
            'lmscourseiri']);

        $pkgs = new backup_nested_element('pkgs');
        $pkg = new verbatim_element('pkg', ['id'], ['versionnum', 'filename', 'zipcontenthash',
            'totalbytes', 'filecount', 'publishercourseid', 'cmi5xml', 'sigstatus', 'sigreason', 'sigkid', 'manifest',
            'signature', 'smcourseid', 'smversion', 'richstructure', 'usermodified', 'timecreated']);
        $blocks = new backup_nested_element('blocks');
        $block = new verbatim_element('block', ['id'], ['parentid', 'sortorder', 'depth',
            'publisherid', 'lmsiri', 'title', 'description']);
        $aus = new backup_nested_element('aus');
        $au = new verbatim_element('au', ['id'], ['blockid', 'sortorder', 'publisherid',
            'lmsactivityid', 'title', 'description', 'url', 'launchmethod', 'moveon', 'masteryscore', 'launchparameters',
            'entitlementkey', 'activitytype', 'auorigin']);
        $pkgfiles = new backup_nested_element('pkgfiles');
        $pkgfile = new verbatim_element('pkgfile', ['id'], ['pathhash', 'path', 'sha256',
            'contenthash', 'filesize']);
        $itemdefs = new backup_nested_element('itemdefs');
        $itemdef = new verbatim_element('itemdef', ['id'], ['smversion', 'smkind', 'smitem',
            'objectid', 'name', 'definition', 'firstseenms']);

        $regs = new backup_nested_element('regs');
        $reg = new backup_nested_element('reg', ['id'], ['userid', 'pkgid', 'registration', 'satisfied',
            'timesatisfied', 'grade', 'statementcount', 'timecreated', 'timemodified']);
        $regaus = new backup_nested_element('regaus');
        $regau = new backup_nested_element('regau', ['id'], ['auid', 'completed', 'passed', 'failed', 'waived',
            'waivedreason', 'satisfied', 'scorehighest', 'scorelast', 'timelastscore', 'timecompleted', 'timepassed',
            'timesatisfied']);
        $regblocks = new backup_nested_element('regblocks');
        $regblock = new backup_nested_element('regblock', ['id'], ['blockid', 'timesatisfied']);
        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], ['auid', 'userid', 'sessionuuid', 'kind', 'launchmode',
            'status', 'fetchused', 'prefsread', 'timelaunchedms', 'timeinitializedms', 'lastactivityms', 'timeendedms',
            'durationms']);
        $stmts = new backup_nested_element('stmts');
        $stmt = new verbatim_element('stmt', ['id'], ['pkgid', 'auid', 'userid', 'sessionid',
            'statementid', 'source', 'verb', 'objecthash', 'objectid', 'cmi5kind', 'timestampms', 'storedms', 'success',
            'completion', 'scorescaled', 'durationms', 'progress', 'smprofile', 'smversion', 'smkind', 'smitem',
            'smblock', 'smblocktype', 'smprofilever', 'payload']);
        $statefields = ['userid', 'activityhash', 'registration', 'stateid', 'contenttype', 'content', 'isbase64', 'etag',
            'updatedms', 'lmsowned'];
        $states = new backup_nested_element('states');
        $state = new verbatim_element('state', ['id'], $statefields);
        $instancestates = new backup_nested_element('instancestates');
        $instancestate = new verbatim_element('instancestate', ['id'], $statefields);

        // The versions first: the learner data points to them. Within a registration, the sessions before the
        // statements that point to them.
        $root->add_child($pkgs);
        $pkgs->add_child($pkg);
        $pkg->add_child($blocks);
        $blocks->add_child($block);
        $pkg->add_child($aus);
        $aus->add_child($au);
        $pkg->add_child($pkgfiles);
        $pkgfiles->add_child($pkgfile);
        $pkg->add_child($itemdefs);
        $itemdefs->add_child($itemdef);
        $root->add_child($regs);
        $regs->add_child($reg);
        $reg->add_child($regaus);
        $regaus->add_child($regau);
        $reg->add_child($regblocks);
        $regblocks->add_child($regblock);
        $reg->add_child($sessions);
        $sessions->add_child($session);
        $reg->add_child($stmts);
        $stmts->add_child($stmt);
        $reg->add_child($states);
        $states->add_child($state);
        $root->add_child($instancestates);
        $instancestates->add_child($instancestate);

        $root->set_source_table('scormmakercmi5', ['id' => backup::VAR_ACTIVITYID]);
        if ($userinfo) {
            $pkg->set_source_sql(
                "SELECT *
                   FROM {scormmakercmi5_pkg}
                  WHERE scormmakercmi5id = ?
                    AND (id = (SELECT currentpkgid FROM {scormmakercmi5} WHERE id = ?)
                         OR id IN (SELECT pkgid FROM {scormmakercmi5_reg} WHERE scormmakercmi5id = ?))
               ORDER BY versionnum",
                [backup::VAR_PARENTID, backup::VAR_PARENTID, backup::VAR_PARENTID]
            );
        } else {
            $pkg->set_source_sql(
                "SELECT p.*
                   FROM {scormmakercmi5_pkg} p
                   JOIN {scormmakercmi5} s ON s.currentpkgid = p.id
                  WHERE s.id = ?",
                [backup::VAR_PARENTID]
            );
        }
        // By id: a parent block is always created before its children (importer), so restore maps parentid in order.
        $block->set_source_table('scormmakercmi5_block', ['pkgid' => backup::VAR_PARENTID], 'id ASC');
        $au->set_source_table('scormmakercmi5_au', ['pkgid' => backup::VAR_PARENTID], 'id ASC');
        $pkgfile->set_source_table('scormmakercmi5_pkgfile', ['pkgid' => backup::VAR_PARENTID], 'id ASC');
        $itemdef->set_source_table('scormmakercmi5_itemdef', ['pkgid' => backup::VAR_PARENTID], 'id ASC');

        if ($userinfo) {
            $reg->set_source_table('scormmakercmi5_reg', ['scormmakercmi5id' => backup::VAR_PARENTID], 'id ASC');
            $regau->set_source_table('scormmakercmi5_regau', ['regid' => backup::VAR_PARENTID], 'id ASC');
            $regblock->set_source_table('scormmakercmi5_regblock', ['regid' => backup::VAR_PARENTID], 'id ASC');
            $session->set_source_table('scormmakercmi5_session', ['regid' => backup::VAR_PARENTID], 'id ASC');
            $stmt->set_source_table('scormmakercmi5_stmt', ['regid' => backup::VAR_PARENTID], 'id ASC');
            $state->set_source_table('scormmakercmi5_state', ['regid' => backup::VAR_PARENTID], 'id ASC');
            $instancestate->set_source_sql(
                "SELECT *
                   FROM {scormmakercmi5_state}
                  WHERE scormmakercmi5id = ? AND regid = 0
               ORDER BY id",
                [backup::VAR_PARENTID]
            );
        }

        $pkg->annotate_ids('user', 'usermodified');
        $reg->annotate_ids('user', 'userid');
        $session->annotate_ids('user', 'userid');
        $stmt->annotate_ids('user', 'userid');
        $state->annotate_ids('user', 'userid');
        $instancestate->annotate_ids('user', 'userid');

        $root->annotate_files('mod_scormmakercmi5', 'intro', null);
        $pkg->annotate_files('mod_scormmakercmi5', 'package', 'id');
        $pkg->annotate_files('mod_scormmakercmi5', 'content', 'id');

        return $this->prepare_activity_structure($root);
    }
}
