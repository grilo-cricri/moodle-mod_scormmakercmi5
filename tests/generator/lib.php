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
 * Data generator of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/package_builder.php');

use mod_scormmakercmi5_test_package_builder as builder;

/**
 * Creates instances through the real add_instance() path (package in a draft area) and registrations.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_scormmakercmi5_generator extends testing_module_generator {
    /** @var string File name of the generated package in the draft area. */
    public const PACKAGE_FILENAME = 'pacote.zip';

    /**
     * Creates an instance with a package.
     *
     * Options in $record:
     * - package_entries: path => content for the package builder (default builder::minimal_entries());
     * - signed: true signs the package with builder::test_keypair() and trusts that key (trustedkeys);
     * - package: the name of a zip of tests/fixtures/packages without ".zip" (Behat: column "package"), such as
     *   "minimal"; the two options above are then ignored;
     * - packagefile: a draft item id to use as is (the options above are then ignored).
     *
     * @param array|stdClass|null $record Instance fields and options.
     * @param array|null $options Course module options.
     * @return stdClass The instance row with cmid.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (array)($record ?? []);
        $entries = $record['package_entries'] ?? builder::minimal_entries();
        $signed = !empty($record['signed']);
        $package = (string)($record['package'] ?? '');
        unset($record['package_entries'], $record['signed'], $record['package']);
        if ($package !== '' && !preg_match('/^[a-z0-9_]+$/', $package)) {
            throw new coding_exception('Invalid fixture package name: ' . $package);
        }
        // The base class replaces an empty name or intro with "cmi5 package … N"/"Test …": a single space passes
        // through it and still counts as empty for add_instance(), which then takes them from cmi5.xml (R1.1).
        foreach (['name', 'intro'] as $field) {
            if (array_key_exists($field, $record) && trim((string)$record[$field]) === '') {
                $record[$field] = ' ';
                $record['introformat'] = $record['introformat'] ?? FORMAT_HTML;
            }
        }

        return $this->as_a_user(function () use ($record, $entries, $signed, $package, $options) {
            if (!isset($record['packagefile']) && $package !== '') {
                $record['packagefile'] = $this->create_draft_from_path(__DIR__ . '/../fixtures/packages/' . $package . '.zip');
            }
            if (!isset($record['packagefile'])) {
                if ($signed) {
                    $keypair = builder::test_keypair();
                    set_config('trustedkeys', $keypair['kid'] . '=' . $keypair['public'], 'mod_scormmakercmi5');
                    $entries = builder::sign($entries, $keypair);
                }
                $record['packagefile'] = $this->create_draft_package($entries);
            }
            $config = get_config('mod_scormmakercmi5');
            $record += [
                'grademethod' => (int)($config->grademethod ?? 0),
                'browsemode' => (int)($config->browsemode ?? 0),
                'reviewmode' => (int)($config->reviewmode ?? 0),
            ];
            return parent::create_instance($record, $options);
        });
    }

    /**
     * Puts a package built from entries in a new draft area of the current user.
     *
     * @param array $entries path => content.
     * @return int Draft item id.
     */
    public function create_draft_package(array $entries): int {
        return $this->create_draft_from_path(builder::zip($entries));
    }

    /**
     * Puts a zip file in a new draft area of the current user.
     *
     * @param string $path Zip path.
     * @param string $filename File name in the draft area.
     * @return int Draft item id.
     */
    public function create_draft_from_path(string $path, string $filename = self::PACKAGE_FILENAME): int {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $path);
        return $draftitemid;
    }

    /**
     * The registration of a user, through launcher::get_or_create_registration() (current version, one regau per AU).
     *
     * @param array $record scormmakercmi5id and userid (required); other reg fields are written over the row.
     * @return stdClass The reg row.
     */
    public function create_registration(array $record): stdClass {
        global $DB;
        foreach (['scormmakercmi5id', 'userid'] as $field) {
            if (empty($record[$field])) {
                throw new coding_exception("create_registration() needs {$field}");
            }
        }
        $instance = $DB->get_record('scormmakercmi5', ['id' => $record['scormmakercmi5id']], '*', MUST_EXIST);
        $reg = \mod_scormmakercmi5\local\runtime\launcher::get_or_create_registration($instance, (int)$record['userid']);
        $extra = array_diff_key($record, ['scormmakercmi5id' => 1, 'userid' => 1]);
        if ($extra) {
            $DB->update_record('scormmakercmi5_reg', (object)(['id' => $reg->id] + $extra));
            $reg = $DB->get_record('scormmakercmi5_reg', ['id' => $reg->id], '*', MUST_EXIST);
        }
        return $reg;
    }

    /**
     * Runs the callback with a logged-in user: the admin when nobody is logged in (Behat data steps, CLI).
     *
     * The draft area and pkg.usermodified belong to $USER, so a user is needed.
     *
     * @param callable $callback Callback.
     * @return mixed Its result.
     */
    private function as_a_user(callable $callback) {
        global $USER;
        if (!empty($USER->id) && !isguestuser()) {
            return $callback();
        }
        $previous = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            return $callback();
        } finally {
            \core\session\manager::set_user($previous);
        }
    }
}
