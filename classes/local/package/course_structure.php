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
 * The course structure read from cmi5.xml.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_structure {
    /**
     * Constructor.
     *
     * @param string $publisherid course@id.
     * @param array $title lang => text.
     * @param array $description lang => text.
     * @param array $children au_definition|block_definition in document order.
     */
    public function __construct(
        /** @var string course@id. */
        public readonly string $publisherid,
        /** @var array lang => text. */
        public readonly array $title,
        /** @var array lang => text. */
        public readonly array $description,
        /** @var array au_definition|block_definition in document order. */
        public readonly array $children
    ) {
    }

    /**
     * All AUs, nested ones included, in document order.
     *
     * @return au_definition[]
     */
    public function all_aus(): array {
        $aus = [];
        self::walk($this->children, $aus, au_definition::class);
        return $aus;
    }

    /**
     * All blocks, nested ones included, in document order.
     *
     * @return block_definition[]
     */
    public function all_blocks(): array {
        $blocks = [];
        self::walk($this->children, $blocks, block_definition::class);
        return $blocks;
    }

    /**
     * The text for a Moodle language: exact tag, then same primary language, then the first one.
     *
     * The Moodle code becomes an RFC 5646 tag ("pt_br" → "pt-BR"); tags are compared without case.
     *
     * @param array $langstrings lang => text, as in title and description.
     * @param string $lang Moodle language code.
     * @return string '' when there is no langstring.
     */
    public static function pick(array $langstrings, string $lang): string {
        if (!$langstrings) {
            return '';
        }
        $wanted = strtolower(self::rfc5646($lang));
        $primary = strtok($wanted, '-');
        $sameprimary = null;
        foreach ($langstrings as $tag => $text) {
            $tag = strtolower((string)$tag);
            if ($wanted !== '' && $tag === $wanted) {
                return $text;
            }
            if ($sameprimary === null && $primary !== false && $primary !== '' && strtok($tag, '-') === $primary) {
                $sameprimary = $text;
            }
        }
        return $sameprimary ?? reset($langstrings);
    }

    /**
     * Moodle language code to RFC 5646: "_" becomes "-" and the suffix is upper case ("pt_br" → "pt-BR").
     *
     * @param string $lang Moodle language code.
     * @return string
     */
    public static function rfc5646(string $lang): string {
        $parts = explode('_', $lang, 2);
        return isset($parts[1]) ? $parts[0] . '-' . strtoupper($parts[1]) : $parts[0];
    }

    /**
     * Collects the items of one class, depth first.
     *
     * @param array $children Items.
     * @param array $out Collected items.
     * @param string $class Class to collect.
     */
    private static function walk(array $children, array &$out, string $class): void {
        foreach ($children as $child) {
            if ($child instanceof $class) {
                $out[] = $child;
            }
            if ($child instanceof block_definition) {
                self::walk($child->children, $out, $class);
            }
        }
    }
}
