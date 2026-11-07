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
 * The English and Brazilian Portuguese language packs have the same keys, in order.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lang_parity_test extends \advanced_testcase {
    /**
     * Loads the keys of one language file, in file order.
     *
     * @param string $lang Language code.
     * @return string[]
     */
    private function keys(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/scormmakercmi5.php');
        return array_keys($string);
    }

    /**
     * Both files define exactly the same keys.
     */
    public function test_same_keys(): void {
        $en = $this->keys('en');
        $ptbr = $this->keys('pt_br');
        sort($en, SORT_STRING);
        sort($ptbr, SORT_STRING);
        $this->assertNotEmpty($en);
        $this->assertSame($en, $ptbr);
    }

    /**
     * The English file lists its keys in alphabetical order.
     */
    public function test_english_is_sorted(): void {
        $en = $this->keys('en');
        $sorted = $en;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $en);
    }
}
