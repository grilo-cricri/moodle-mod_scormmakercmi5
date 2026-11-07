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

/**
 * The backup fields that must come back byte for byte (design §12), shared by the backup and restore steps.
 *
 * The backup XML writer passes every value through the link encoders of every installed module (and file.php
 * rewriting): a "…/mod/<any>/view.php?id=N" inside a statement payload, a State document (the LaunchData returnURL
 * is one), a signed manifest or cmi5.xml would come back changed — a different payload, a broken signature, an
 * objecthash that no longer matches objectid. These fields travel base64-encoded, which no encoder matches.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_verbatim {
    /** @var array Backup element => fields sent base64-encoded: text that comes from the package or the learner. */
    public const FIELDS = [
        'pkg' => ['filename', 'publishercourseid', 'cmi5xml', 'manifest', 'smcourseid', 'smversion', 'richstructure'],
        'block' => ['publisherid', 'title', 'description'],
        'au' => ['publisherid', 'title', 'description', 'url', 'launchparameters', 'entitlementkey', 'activitytype'],
        'pkgfile' => ['path'],
        'itemdef' => ['smversion', 'smitem', 'objectid', 'name', 'definition'],
        'stmt' => ['verb', 'objectid', 'smversion', 'smitem', 'smblock', 'smblocktype', 'payload'],
        'state' => ['stateid', 'contenttype', 'content'],
        'instancestate' => ['stateid', 'contenttype', 'content'],
    ];

    /**
     * Encodes the verbatim fields of a source row (null stays null).
     *
     * @param string $element Backup element name.
     * @param array $values Row.
     * @return array
     */
    public static function encode(string $element, array $values): array {
        foreach (self::FIELDS[$element] ?? [] as $field) {
            if (isset($values[$field])) {
                $values[$field] = base64_encode((string)$values[$field]);
            }
        }
        return $values;
    }

    /**
     * Replaces UUIDs in a text, ignoring case: an AU may send them in upper case, the columns hold them in lower case.
     *
     * The keys are random v4 UUIDs and the new values are fresh ones, so one replacement never creates a match for
     * another.
     *
     * @param string $text Statement payload or State document.
     * @param array $uuids Old UUID => new UUID.
     * @return string
     */
    public static function swap_uuids(string $text, array $uuids): string {
        return $uuids ? str_ireplace(array_keys($uuids), array_values($uuids), $text) : $text;
    }

    /**
     * Decodes the verbatim fields of restored data.
     *
     * @param string $element Backup element name.
     * @param \stdClass $data Data of the element.
     * @return \stdClass
     * @throws \moodle_exception When a field is not valid base64 (a damaged or hand-edited backup).
     */
    public static function decode(string $element, \stdClass $data): \stdClass {
        foreach (self::FIELDS[$element] ?? [] as $field) {
            if (isset($data->$field)) {
                $decoded = base64_decode((string)$data->$field, true);
                if ($decoded === false) {
                    throw new \moodle_exception('err_backupfield', 'mod_scormmakercmi5', '', $element . '/' . $field);
                }
                $data->$field = $decoded;
            }
        }
        return $data;
    }
}
