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
 * Site settings of mod_scormmakercmi5 (page modsettingscormmakercmi5): limits, signing keys and instance defaults.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $component = 'mod_scormmakercmi5';

    // Limits (design §7.4). The form refuses anything but a positive whole number of up to 9 digits,
    // the same rule limits::positive() applies when reading (it falls back to the default otherwise).
    $limits = [
        'maxbodykb' => 1024,
        'maxbatch' => 100,
        'maxstatementkb' => 64,
        'maxstatementsperreg' => 10000,
        'maxanalysisperreg' => 20000,
        'maxstatekb' => 1024,
        'maxstatedocs' => 100,
        'sessiontimeout' => 28800,
        'maxentries' => 20000,
        'maxunzippedmb' => 1024,
        'recheckmaxmb' => 100,
        'recheckminhours' => 24,
    ];
    $settings->add(new admin_setting_heading(
        "{$component}/limitsheading",
        get_string('setting_limitsheading', $component),
        get_string('setting_limitsheading_desc', $component)
    ));
    foreach ($limits as $name => $default) {
        $settings->add(new admin_setting_configtext(
            "{$component}/{$name}",
            get_string("setting_{$name}", $component),
            get_string("setting_{$name}_desc", $component),
            $default,
            '/^[1-9][0-9]{0,8}$/'
        ));
    }
    $settings->add(new admin_setting_configcheckbox(
        "{$component}/requireprefsread",
        get_string('setting_requireprefsread', $component),
        get_string('setting_requireprefsread_desc', $component),
        1
    ));
    // AU outside the package (absolute https url): the learner data it receives is decided per package by the teacher,
    // so the site refuses such packages at import (importer::external_au_allowed()) until the administrator opts in:
    // off by default. A default only reaches a site with no stored value; one that saved 1 keeps it.
    $settings->add(new admin_setting_configcheckbox(
        "{$component}/allowexternalau",
        get_string('setting_allowexternalau', $component),
        get_string('setting_allowexternalau_desc', $component),
        0
    ));

    // Signing keys (design §5.3). The keys of SCORM Maker are embedded in local\signature\keyring.
    $settings->add(new admin_setting_heading(
        "{$component}/keysheading",
        get_string('setting_keysheading', $component),
        get_string('setting_keysheading_desc', $component)
    ));
    $settings->add(new \mod_scormmakercmi5\admin\setting_trustedkeys(
        "{$component}/trustedkeys",
        get_string('setting_trustedkeys', $component),
        get_string('setting_trustedkeys_desc', $component),
        ''
    ));
    $settings->add(new \mod_scormmakercmi5\admin\setting_revokedkids(
        "{$component}/revokedkids",
        get_string('setting_revokedkids', $component),
        get_string('setting_revokedkids_desc', $component),
        ''
    ));
    // Off by default since 0.3.1, when the PROD key was embedded; db/upgrade.php step 2026100500 turns it off on the
    // sites that never changed it.
    $settings->add(new admin_setting_configcheckbox(
        "{$component}/accepthmlkey",
        get_string('setting_accepthmlkey', $component),
        get_string('setting_accepthmlkey_desc', $component),
        0
    ));

    // Defaults of new instances.
    $settings->add(new admin_setting_heading(
        "{$component}/defaultsheading",
        get_string('setting_defaultsheading', $component),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        "{$component}/browsemode",
        get_string('setting_browsemode', $component),
        get_string('setting_browsemode_desc', $component),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        "{$component}/reviewmode",
        get_string('setting_reviewmode', $component),
        get_string('setting_reviewmode_desc', $component),
        0
    ));
    $settings->add(new admin_setting_configselect(
        "{$component}/grademethod",
        get_string('setting_grademethod', $component),
        get_string('setting_grademethod_desc', $component),
        0,
        [
            0 => get_string('grademethod_highest', $component),
            1 => get_string('grademethod_last', $component),
        ]
    ));
}
