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
 * Restore structure of mod_scormmakercmi5 (design §12, NF7).
 *
 * @package    mod_scormmakercmi5
 * @category   backup
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_scormmakercmi5\local\backup_verbatim;
use mod_scormmakercmi5\local\package\cmi5_parser;
use mod_scormmakercmi5\local\package\importer;
use mod_scormmakercmi5\local\signature\keyring;
use mod_scormmakercmi5\local\signature\verification_result;
use mod_scormmakercmi5\local\signature\verifier;

/**
 * Restores one activity: its package versions, structure and files, and with user data the learner data.
 *
 * Ids: pkgid, blockid, auid, regid, sessionid and userid are mapped; currentpkgid in after_execute(). The recheck
 * state of every version is reset here; the task's after_restore() then verifies signature and file map with this
 * site's keyring (no byte hashing) and records that as the verdict, and the rich report rechecks again when it opens.
 * A skipped version or registration is mapped to 0, so its children are skipped too. Without user data only the
 * current version is restored (also from a backup that has more) and the
 * LMS IRIs (lmscourseiri, block lmsiri, AU lmsactivityid) are generated anew, so two copies on one site never share
 * them; with user data they are kept, so LaunchData and statements stay coherent.
 *
 * Sessions come back closed: an open one (launched or initialized) as abandoned; fetch keys and tokens never travel.
 *
 * Registration, session and statement UUIDs are unique in the database (xAPI ids are global in the LRS). Restored on
 * a site that still has them (a copy on the same site), the copy gets new UUIDs, and its statement payloads and State
 * documents get the same textual replacement (old UUID → new); anywhere else they are kept and the payloads come back
 * byte for byte. The actor of the payloads keeps the homePage and account name of the source site: it is history;
 * new launches use the actor of the target site, and resuming works because State is keyed by the mapped userid.
 * A State document written without a registration that already exists for the same user, activity IRI and stateId
 * (a copy on the same site shares the activity IRIs) is left out: the AU already reads that document.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_scormmakercmi5_activity_structure_step extends restore_activity_structure_step {
    /** @var int Session status: launched. */
    private const LAUNCHED = 0;

    /** @var int Session status: initialized. */
    private const INITIALIZED = 1;

    /** @var int Session status: abandoned. */
    private const ABANDONED = 3;

    /** @var bool Whether the learner data is restored. */
    private bool $userinfo = false;

    /** @var int[] New ids of the versions whose backup said "verified" (provisionally invalid until after_execute()). */
    private array $claimedverified = [];

    /** @var int currentpkgid of the backup. */
    private int $oldcurrentpkgid = 0;

    /** @var array new regid => [old UUID => new UUID] of the registration and its sessions that got new UUIDs. */
    private array $uuids = [];

    /** @var array new regid => registration UUID of the restored registration. */
    private array $registrations = [];

    /**
     * Defines the paths.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $this->userinfo = (bool)$this->get_setting_value('userinfo');
        $base = '/activity/scormmakercmi5';
        $paths = [
            new restore_path_element('scormmakercmi5', $base),
            new restore_path_element('scormmakercmi5_pkg', $base . '/pkgs/pkg'),
            new restore_path_element('scormmakercmi5_block', $base . '/pkgs/pkg/blocks/block'),
            new restore_path_element('scormmakercmi5_au', $base . '/pkgs/pkg/aus/au'),
            new restore_path_element('scormmakercmi5_pkgfile', $base . '/pkgs/pkg/pkgfiles/pkgfile'),
            new restore_path_element('scormmakercmi5_itemdef', $base . '/pkgs/pkg/itemdefs/itemdef'),
        ];
        if ($this->userinfo) {
            $paths[] = new restore_path_element('scormmakercmi5_reg', $base . '/regs/reg');
            $paths[] = new restore_path_element('scormmakercmi5_regau', $base . '/regs/reg/regaus/regau');
            $paths[] = new restore_path_element('scormmakercmi5_regblock', $base . '/regs/reg/regblocks/regblock');
            $paths[] = new restore_path_element('scormmakercmi5_session', $base . '/regs/reg/sessions/session');
            $paths[] = new restore_path_element('scormmakercmi5_stmt', $base . '/regs/reg/stmts/stmt');
            $paths[] = new restore_path_element('scormmakercmi5_state', $base . '/regs/reg/states/state');
            $paths[] = new restore_path_element('scormmakercmi5_instancestate', $base . '/instancestates/instancestate');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * The instance; currentpkgid is set in after_execute().
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5($data) {
        global $DB;
        $data = (object)$data;
        $this->oldcurrentpkgid = (int)$data->currentpkgid;
        $data->course = $this->get_courseid();
        $data->currentpkgid = 0;
        if (!$this->userinfo || empty($data->lmscourseiri)) {
            $data->lmscourseiri = importer::generated_iri('course');
        }
        $newid = $DB->insert_record('scormmakercmi5', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * A package version (without user data, only the current one).
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_pkg($data) {
        global $DB;
        $data = backup_verbatim::decode('pkg', (object)$data);
        $oldid = (int)$data->id;
        if (!$this->userinfo && $oldid !== $this->oldcurrentpkgid) {
            // Mapped to 0 (no files): get_new_parentid() returns the newid of the LAST mapping, so without this the
            // blocks, AUs, hashes and definitions of a skipped version would attach to the version restored before.
            $this->set_mapping('scormmakercmi5_pkg', $oldid, 0);
            return;
        }
        $data->scormmakercmi5id = $this->get_new_parentid('scormmakercmi5');
        $data->usermodified = (int)$this->get_mappingid('user', $data->usermodified, 0);
        // The status in a backup is a claim, never a fact: "verified" decides that the files are stored byte for byte
        // (verbatim_storage), skipping the file processing of the site, and a hand-made backup can say anything. It
        // comes back provisionally invalid, and after_execute() restores "verified" only when the signature holds on
        // this site, before the files are created. (Not "unsigned": a recheck never reverifies an unsigned version.)
        $claimedverified = $data->sigstatus === verification_result::STATUS_VERIFIED;
        if ($claimedverified) {
            $data->sigstatus = verification_result::STATUS_INVALID;
            $data->sigreason = null;
        }
        $data->recheckstatus = null;
        $data->recheckreason = null;
        $data->timerecheck = 0;
        $data->timefullrecheck = 0;
        $data->fullrecheckstatus = null;
        $data->fullrecheckreason = null;
        $newid = $DB->insert_record('scormmakercmi5_pkg', $data);
        if ($claimedverified) {
            $this->claimedverified[] = $newid;
        }
        $this->set_mapping('scormmakercmi5_pkg', $oldid, $newid, true);
    }

    /**
     * A block (parents come before their children).
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_block($data) {
        global $DB;
        $data = backup_verbatim::decode('block', (object)$data);
        $data->pkgid = $this->get_new_parentid('scormmakercmi5_pkg');
        if (!$data->pkgid) {
            return;
        }
        $oldid = $data->id;
        $data->parentid = $data->parentid ? (int)$this->get_mappingid('scormmakercmi5_block', $data->parentid, 0) : 0;
        if (!$this->userinfo) {
            $data->lmsiri = importer::generated_iri('block');
        }
        $newid = $DB->insert_record('scormmakercmi5_block', $data);
        $this->set_mapping('scormmakercmi5_block', $oldid, $newid);
    }

    /**
     * An AU.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_au($data) {
        global $DB;
        $data = backup_verbatim::decode('au', (object)$data);
        $data->pkgid = $this->get_new_parentid('scormmakercmi5_pkg');
        if (!$data->pkgid) {
            return;
        }
        $oldid = $data->id;
        $data->blockid = $data->blockid ? (int)$this->get_mappingid('scormmakercmi5_block', $data->blockid, 0) : 0;
        if (!$this->userinfo) {
            $data->lmsactivityid = importer::generated_iri('au');
        }
        // The origin of an AU outside the package opens the CORS of the site to it: it comes from the url again, never
        // from the backup (a hand-made one could name any origin). A url refused today keeps no origin, and so does
        // every AU outside the package when the site turned allowexternalau off (restore, course import, copy and
        // duplicate bring the package in too): such an AU is never launched (launcher gives err_externalaudisabled).
        try {
            $data->auorigin = cmi5_parser::external_origin((string)$data->url);
        } catch (\moodle_exception $e) {
            $data->auorigin = null;
        }
        if ($data->auorigin !== null && !importer::external_au_allowed()) {
            $this->log('mod_scormmakercmi5: the AU outside the package (' . $data->auorigin . ') is restored disabled: '
                . 'this site does not allow AUs outside the package (allowexternalau)', backup::LOG_WARNING);
            $data->auorigin = null;
        }
        $newid = $DB->insert_record('scormmakercmi5_au', $data);
        $this->set_mapping('scormmakercmi5_au', $oldid, $newid);
    }

    /**
     * A file hash of a version.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_pkgfile($data) {
        global $DB;
        $data = backup_verbatim::decode('pkgfile', (object)$data);
        $data->pkgid = $this->get_new_parentid('scormmakercmi5_pkg');
        if (!$data->pkgid) {
            return;
        }
        // The key of the row comes from the path, never from the backup: the signature checks and verbatim_storage find
        // the row by path, so a forged pathhash could hide the row the manifest vouches for behind another one.
        $data->pathhash = sha1((string)$data->path);
        if ($DB->record_exists('scormmakercmi5_pkgfile', ['pkgid' => $data->pkgid, 'pathhash' => $data->pathhash])) {
            // A second row for the same path (unique index pkgid + pathhash) can only come from a hand-made backup: the
            // first one stays, the file map is then checked against it, and the version cannot come back verified
            // unless that row is the one the signed manifest lists.
            $this->log('mod_scormmakercmi5: a duplicate file hash for ' . s($data->path) . ' was left out', backup::LOG_WARNING);
            return;
        }
        $DB->insert_record('scormmakercmi5_pkgfile', $data);
    }

    /**
     * An item definition of a version.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_itemdef($data) {
        global $DB;
        $data = backup_verbatim::decode('itemdef', (object)$data);
        $data->pkgid = $this->get_new_parentid('scormmakercmi5_pkg');
        if ($data->pkgid) {
            $DB->insert_record('scormmakercmi5_itemdef', $data);
        }
    }

    /**
     * A registration; left out when its user or its version is not restored.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_reg($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->pkgid = $this->get_mappingid('scormmakercmi5_pkg', $data->pkgid);
        if (!$data->userid || !$data->pkgid) {
            // Mapped to 0: get_new_parentid() returns the newid of the LAST mapping, so without this the children of a
            // skipped registration (regau, regblock…) would attach to the registration of the previous learner.
            $this->set_mapping('scormmakercmi5_reg', $oldid, 0);
            return;
        }
        $data->scormmakercmi5id = $this->get_new_parentid('scormmakercmi5');
        $uuids = [];
        $data->registration = $this->unique_uuid('scormmakercmi5_reg', 'registration', $data->registration, $uuids);
        $newid = $DB->insert_record('scormmakercmi5_reg', $data);
        $this->set_mapping('scormmakercmi5_reg', $oldid, $newid);
        $this->uuids[$newid] = $uuids;
        $this->registrations[$newid] = $data->registration;
    }

    /**
     * The status of an AU in a registration.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_regau($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->regid = $this->get_new_parentid('scormmakercmi5_reg');
        $data->auid = $this->get_mappingid('scormmakercmi5_au', $data->auid);
        if ($data->regid && $data->auid) {
            // Mapped for the restore of the logs (event au_waived names scormmakercmi5_regau).
            $this->set_mapping('scormmakercmi5_regau', $oldid, $DB->insert_record('scormmakercmi5_regau', $data));
        }
    }

    /**
     * A satisfied block of a registration.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_regblock($data) {
        global $DB;
        $data = (object)$data;
        $data->regid = $this->get_new_parentid('scormmakercmi5_reg');
        $data->blockid = $this->get_mappingid('scormmakercmi5_block', $data->blockid);
        if ($data->regid && $data->blockid) {
            $DB->insert_record('scormmakercmi5_regblock', $data);
        }
    }

    /**
     * A session, always closed and without fetch key or token: an open one comes back abandoned.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_session($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->regid = $this->get_new_parentid('scormmakercmi5_reg');
        $data->auid = $this->get_mappingid('scormmakercmi5_au', $data->auid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->regid || !$data->auid || !$data->userid) {
            return;
        }
        $uuids = $this->uuids[$data->regid];
        $data->sessionuuid = $this->unique_uuid('scormmakercmi5_session', 'sessionuuid', $data->sessionuuid, $uuids);
        $this->uuids[$data->regid] = $uuids;
        if (in_array((int)$data->status, [self::LAUNCHED, self::INITIALIZED], true)) {
            $data->status = self::ABANDONED;
            $data->timeendedms = max((int)$data->timeendedms, (int)$data->lastactivityms, (int)$data->timelaunchedms);
        }
        $data->fetchkeyhash = null;
        $data->tokenhash = null;
        $data->tokenexpires = 0;
        $data->fetchused = 1;
        $newid = $DB->insert_record('scormmakercmi5_session', $data);
        $this->set_mapping('scormmakercmi5_session', $oldid, $newid);
    }

    /**
     * A statement: ids mapped, payload as backed up (with the UUID replacement of a same-site copy).
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_stmt($data) {
        global $DB;
        $data = backup_verbatim::decode('stmt', (object)$data);
        $data->regid = $this->get_new_parentid('scormmakercmi5_reg');
        $data->pkgid = $this->get_mappingid('scormmakercmi5_pkg', $data->pkgid);
        $data->auid = $this->get_mappingid('scormmakercmi5_au', $data->auid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->regid || !$data->pkgid || !$data->auid || !$data->userid) {
            return;
        }
        $data->scormmakercmi5id = $this->get_new_parentid('scormmakercmi5');
        $sessionid = empty($data->sessionid) ? false : $this->get_mappingid('scormmakercmi5_session', $data->sessionid);
        $data->sessionid = $sessionid ?: null;
        $uuids = $this->uuids[$data->regid];
        $data->statementid = $this->unique_uuid('scormmakercmi5_stmt', 'statementid', $data->statementid, $uuids);
        // Shared with the later statements of the registration: a StatementRef to this one follows the new id.
        $this->uuids[$data->regid] = $uuids;
        $data->payload = backup_verbatim::swap_uuids($data->payload, $uuids);
        $DB->insert_record('scormmakercmi5_stmt', $data);
    }

    /**
     * A State document of a registration.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_state($data) {
        $data = backup_verbatim::decode('state', (object)$data);
        $data->regid = $this->get_new_parentid('scormmakercmi5_reg');
        if (!$data->regid) {
            return;
        }
        $data->registration = $this->registrations[$data->regid];
        $this->insert_state($data, $this->uuids[$data->regid]);
    }

    /**
     * A State document written without a registration.
     *
     * @param array $data Data.
     */
    protected function process_scormmakercmi5_instancestate($data) {
        $data = backup_verbatim::decode('instancestate', (object)$data);
        $data->regid = 0;
        $data->registration = '';
        $this->insert_state($data, []);
    }

    /**
     * Inserts a State document unless its key already exists on this site.
     *
     * @param \stdClass $data Document with regid and registration set.
     * @param array $uuids UUID replacements of its registration.
     */
    private function insert_state(\stdClass $data, array $uuids): void {
        global $DB;
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $data->scormmakercmi5id = $this->get_new_parentid('scormmakercmi5');
        $key = ['userid' => $data->userid, 'activityhash' => $data->activityhash, 'registration' => $data->registration,
            'stateid' => $data->stateid];
        if ($DB->record_exists('scormmakercmi5_state', $key)) {
            return;
        }
        if ($uuids && !(int)$data->isbase64) {
            $content = backup_verbatim::swap_uuids($data->content, $uuids);
            if ($content !== $data->content) {
                $data->content = $content;
                $data->etag = sha1($content);
            }
        }
        $DB->insert_record('scormmakercmi5_state', $data);
    }

    /**
     * The UUID itself, or a new one when the site already has it in that column (a copy on the same site).
     *
     * @param string $table Table.
     * @param string $field Unique column.
     * @param string $uuid UUID from the backup.
     * @param array $uuids Replacements, old => new; a new UUID is added.
     * @return string
     */
    private function unique_uuid(string $table, string $field, string $uuid, array &$uuids): string {
        global $DB;
        if (!$DB->record_exists($table, [$field => $uuid])) {
            return $uuid;
        }
        $new = \core\uuid::generate();
        $uuids[$uuid] = $new;
        return $new;
    }

    /**
     * currentpkgid and the files.
     */
    protected function after_execute() {
        global $DB;
        $instanceid = $this->task->get_activityid();
        $current = $this->get_mappingid('scormmakercmi5_pkg', $this->oldcurrentpkgid);
        if (!$current) {
            // A backup whose current version was not written: the newest restored version takes its place.
            $versions = $DB->get_records('scormmakercmi5_pkg', ['scormmakercmi5id' => $instanceid], 'versionnum DESC', 'id', 0, 1);
            $current = $versions ? (int)array_key_first($versions) : 0;
        }
        $DB->set_field('scormmakercmi5', 'currentpkgid', $current, ['id' => $instanceid]);

        // The signature decides, on this site, whether the files are kept byte for byte: the claim of the backup
        // does not (see process_scormmakercmi5_pkg()). The pkgfile rows are in by now, the files are not.
        $keyring = keyring::from_config();
        foreach ($this->claimedverified as $pkgid) {
            if (verifier::signature_and_map_hold($pkgid, $keyring)) {
                $DB->update_record('scormmakercmi5_pkg', (object)['id' => $pkgid,
                    'sigstatus' => verification_result::STATUS_VERIFIED, 'sigreason' => null]);
            }
        }

        $this->add_related_files('mod_scormmakercmi5', 'intro', null);
        $this->add_related_files('mod_scormmakercmi5', 'package', 'scormmakercmi5_pkg');
        $this->add_related_files('mod_scormmakercmi5', 'content', 'scormmakercmi5_pkg');
    }
}
