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

namespace mod_scormmakercmi5\local\package;

/**
 * A block read from cmi5.xml.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_definition {
    /**
     * Constructor.
     *
     * @param string $publisherid block@id.
     * @param array $title lang => text.
     * @param array $description lang => text.
     * @param array $children au_definition|block_definition in document order.
     * @param int $depth 1 for a child of the course, +1 per enclosing block.
     */
    public function __construct(
        /** @var string block@id. */
        public readonly string $publisherid,
        /** @var array lang => text. */
        public readonly array $title,
        /** @var array lang => text. */
        public readonly array $description,
        /** @var array au_definition|block_definition in document order. */
        public readonly array $children,
        /** @var int Depth. */
        public readonly int $depth
    ) {
    }
}
