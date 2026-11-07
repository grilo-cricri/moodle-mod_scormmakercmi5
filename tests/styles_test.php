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

namespace mod_scormmakercmi5;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Moodle concatenates the styles.css of every plugin into the theme CSS of every page, so each rule of this plugin
 * must be scoped to its own pages (body class .path-mod-scormmakercmi5) or it leaks into the rest of the site.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class styles_test extends \basic_testcase {
    /** @var string Body class of every page of the module. */
    private const SCOPE = '.path-mod-scormmakercmi5';

    /**
     * The selectors of a style sheet, one per comma-separated part, with the at-rules (@media...) left out.
     *
     * @param string $css Style sheet.
     * @return string[]
     */
    private static function selectors(string $css): array {
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        // Drop the declaration blocks: what is left before each "{" is a selector list or an at-rule prelude.
        preg_match_all('~([^{}]+)\{~', $css, $matches);
        $selectors = [];
        foreach ($matches[1] as $prelude) {
            $prelude = trim($prelude);
            if ($prelude === '' || $prelude[0] === '@') {
                continue;
            }
            foreach (explode(',', $prelude) as $selector) {
                $selectors[] = trim(preg_replace('~\s+~', ' ', $selector));
            }
        }
        return $selectors;
    }

    /**
     * Every selector of styles.css starts with the body class of the module.
     */
    public function test_every_rule_is_scoped_to_the_module(): void {
        $selectors = self::selectors(file_get_contents(__DIR__ . '/../styles.css'));
        $this->assertNotEmpty($selectors);
        foreach ($selectors as $selector) {
            $this->assertStringStartsWith(self::SCOPE . ' ', $selector, 'Unscoped selector: ' . $selector);
        }
    }

    /**
     * The parser sees selectors inside @media and in a comma-separated list.
     */
    public function test_parser_finds_unscoped_selectors(): void {
        $css = "/* .x { } */\n.path-mod-scormmakercmi5 .a, .b { color: red; }\n" .
            "@media (max-width: 10px) {\n    .c { color: red; }\n}\n";
        $this->assertSame([self::SCOPE . ' .a', '.b', '.c'], self::selectors($css));
    }
}
