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

namespace mod_scormmakercmi5\local\signature;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\signature\key_lines::class)]
/**
 * Tests for the line parsing of the key settings (design §5.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\signature\key_lines
 */
final class key_lines_test extends \basic_testcase {
    /**
     * Trusted keys: entries and the first error, with its line number.
     */
    public function test_trusted(): void {
        $k1 = base64_encode(random_bytes(32));
        $k2 = base64_encode(random_bytes(32));
        $this->assertSame(['keys' => ['k1' => $k1, 'k2' => $k2], 'error' => null], key_lines::trusted("\n k1 = $k1 \r\nk2=$k2"));
        $result = key_lines::trusted("k1=$k1\n\nbad\nsm-hml-2026-09=$k2\nk2=###");
        $this->assertSame(['k1' => $k1], $result['keys']);
        $this->assertSame(['key' => 'err_trustedkeys_line', 'line' => 3], $result['error']);
        $this->assertSame(['key' => 'err_trustedkeys_embedded', 'line' => 1], key_lines::trusted("sm-hml-2026-09=$k1")['error']);
        $this->assertSame([], key_lines::trusted("sm-hml-2026-09=$k1")['keys']);
        $dup = ['keys' => [], 'error' => ['key' => 'err_trustedkeys_dup', 'line' => 2]];
        $this->assertSame($dup, key_lines::trusted("k1=$k1\nk1=$k2"));
    }

    /**
     * Revoked kids: list and the first error.
     */
    public function test_revoked(): void {
        $this->assertSame(['kids' => ['a', 'b.c-1'], 'error' => null], key_lines::revoked(" a \n\nb.c-1\na"));
        $bad = ['kids' => ['a'], 'error' => ['key' => 'err_revokedkids_kid', 'line' => 2]];
        $this->assertSame($bad, key_lines::revoked("a\nb c"));
    }

    /**
     * Strict public key check.
     */
    public function test_is_public_key(): void {
        $k = base64_encode(random_bytes(32));
        $this->assertTrue(key_lines::is_public_key($k));
        $this->assertFalse(key_lines::is_public_key(rtrim($k, '=')));
        $this->assertFalse(key_lines::is_public_key(base64_encode(random_bytes(31))));
        $this->assertFalse(key_lines::is_public_key(''));
    }

    /**
     * The runtime classes do not depend on the admin settings classes or adminlib (they run on import and report).
     */
    public function test_runtime_does_not_depend_on_admin(): void {
        $dir = \core_component::get_component_directory('mod_scormmakercmi5') . '/classes/local/signature/';
        foreach (['keyring.php', 'key_lines.php', 'canonical_json.php'] as $file) {
            $source = file_get_contents($dir . $file);
            $this->assertStringNotContainsString('adminlib', $source, $file);
            $this->assertStringNotContainsString('admin\\setting_', $source, $file);
        }
    }
}
