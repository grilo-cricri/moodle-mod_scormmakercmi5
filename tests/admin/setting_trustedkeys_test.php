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

namespace mod_scormmakercmi5\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\admin\setting_trustedkeys::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\admin\setting_revokedkids::class)]
/**
 * Tests for the trusted keys and revoked kids settings (design §5.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\admin\setting_trustedkeys
 * @covers     \mod_scormmakercmi5\admin\setting_revokedkids
 */
final class setting_trustedkeys_test extends \advanced_testcase {
    /**
     * The trusted keys setting.
     *
     * @return setting_trustedkeys
     */
    private function trusted(): setting_trustedkeys {
        return new setting_trustedkeys('mod_scormmakercmi5/trustedkeys', 'n', 'd', '');
    }

    /**
     * The revoked kids setting.
     *
     * @return setting_revokedkids
     */
    private function revoked(): setting_revokedkids {
        return new setting_revokedkids('mod_scormmakercmi5/revokedkids', 'n', 'd', '');
    }

    /**
     * Error text for a string with a line number.
     *
     * @param string $key String key.
     * @param int $line Line number.
     * @return string
     */
    private function err(string $key, int $line): string {
        return get_string($key, 'mod_scormmakercmi5', $line);
    }

    /**
     * Accepted trusted keys and their parsed form.
     */
    public function test_valid(): void {
        $k1 = base64_encode(random_bytes(32));
        $k2 = base64_encode(random_bytes(32));
        $this->assertTrue($this->trusted()->validate("k1=$k1"));
        $this->assertTrue($this->trusted()->validate("\r\n  k1=$k1  \n\n\tk.2_-X = $k2\n"));
        $this->assertTrue($this->trusted()->validate(''));
        $this->assertTrue($this->trusted()->validate(str_repeat('a', 64) . "=$k1"));
        $this->assertSame(['k1' => $k1, 'k.2_-X' => $k2], setting_trustedkeys::parse("\r\n  k1=$k1  \n\n\tk.2_-X = $k2\n"));
        $this->assertSame([], setting_trustedkeys::parse(''));
    }

    /**
     * Refused trusted keys name the line and the reason.
     */
    public function test_invalid(): void {
        $k1 = base64_encode(random_bytes(32));
        $short = base64_encode(random_bytes(31));
        $long = base64_encode(random_bytes(33));
        $this->assertSame($this->err('err_trustedkeys_key', 1), $this->trusted()->validate("k1=$short"));
        $this->assertSame($this->err('err_trustedkeys_key', 1), $this->trusted()->validate("k1=$long"));
        $this->assertSame($this->err('err_trustedkeys_key', 2), $this->trusted()->validate("k0=$k1\nk1=###"));
        $this->assertSame($this->err('err_trustedkeys_key', 1), $this->trusted()->validate('k1=' . rtrim($k1, '=')));
        $this->assertSame($this->err('err_trustedkeys_key', 1), $this->trusted()->validate('k1=' . substr($k1, 0, 20) . ' '
            . substr($k1, 20)));
        $this->assertSame($this->err('err_trustedkeys_key', 1), $this->trusted()->validate('k1='));
        $this->assertSame($this->err('err_trustedkeys_kid', 1), $this->trusted()->validate("k 1=$k1"));
        $this->assertSame($this->err('err_trustedkeys_kid', 1), $this->trusted()->validate(str_repeat('a', 65) . "=$k1"));
        $this->assertSame($this->err('err_trustedkeys_kid', 1), $this->trusted()->validate("=$k1"));
        $this->assertSame($this->err('err_trustedkeys_kid', 1), $this->trusted()->validate("kíd=$k1"));
        $this->assertSame($this->err('err_trustedkeys_dup', 3), $this->trusted()->validate("k1=$k1\n\nk1=$k1"));
        $this->assertSame($this->err('err_trustedkeys_line', 2), $this->trusted()->validate("k1=$k1\nsem igual"));
        $this->assertSame($this->err('err_trustedkeys_embedded', 2), $this->trusted()->validate("k1=$k1\nsm-hml-2026-09=$k1"));
        $this->assertSame(['k1' => $k1], setting_trustedkeys::parse("k1=$k1\nsm-hml-2026-09=$k1"));
        $keys = ['err_trustedkeys_key', 'err_trustedkeys_kid', 'err_trustedkeys_dup', 'err_trustedkeys_line',
            'err_trustedkeys_embedded'];
        foreach ($keys as $key) {
            $this->assertStringContainsString('2', $this->err($key, 2));
        }
    }

    /**
     * Revoked kids: one per line.
     */
    public function test_revoked(): void {
        $this->assertTrue($this->revoked()->validate("a\nb.c-1"));
        $this->assertTrue($this->revoked()->validate(" a \r\n\r\nb\n"));
        $this->assertTrue($this->revoked()->validate(''));
        $this->assertSame($this->err('err_revokedkids_kid', 1), $this->revoked()->validate('a b'));
        $this->assertSame($this->err('err_revokedkids_kid', 2), $this->revoked()->validate("a\n" . str_repeat('x', 65)));
        $this->assertSame(['a', 'b.c-1'], setting_revokedkids::parse(" a \n\nb.c-1\na\nbad kid"));
        $this->assertSame([], setting_revokedkids::parse(''));
    }

    /**
     * Saving through the admin setting stores valid input and refuses invalid input.
     */
    public function test_write_setting(): void {
        $this->resetAfterTest();
        $k1 = base64_encode(random_bytes(32));
        $setting = $this->trusted();
        $this->assertSame('', $setting->write_setting("k1=$k1"));
        $this->assertSame("k1=$k1", get_config('mod_scormmakercmi5', 'trustedkeys'));
        $this->assertSame($this->err('err_trustedkeys_key', 1), $setting->write_setting('k1=###'));
        $this->assertSame("k1=$k1", get_config('mod_scormmakercmi5', 'trustedkeys'));
    }
}
