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
 * Hook callbacks of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The verified package files are stored byte for byte: the two listeners bracket the EXIF remover of core (priority
// 100) and every other listener (local\package\verbatim_storage).
$callbacks = [
    [
        'hook' => \core_files\hook\before_file_created::class,
        'callback' => [\mod_scormmakercmi5\local\package\verbatim_storage::class, 'remember'],
        'priority' => 10000,
    ],
    [
        'hook' => \core_files\hook\before_file_created::class,
        'callback' => [\mod_scormmakercmi5\local\package\verbatim_storage::class, 'restore'],
        'priority' => -10000,
    ],
];
