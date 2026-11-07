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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\signature\keyring::class)]
/**
 * Tests for the trusted keyring (design §5.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\signature\keyring
 */
final class keyring_test extends \advanced_testcase {
    /** @var string Embedded HML kid. */
    private const HML = 'sm-hml-2026-09';

    /** @var string Embedded PROD kid. */
    private const PROD = 'sm-prod-2026-10';

    /** @var string PROD public key, verbatim from docs/xapi/assinatura.md of SCORM Maker. */
    private const PROD_KEY = '92zIJWqpNtJFD4dM069A4uLCcpNXZtyK1d2aESIe9xU=';

    /**
     * Clears the key settings.
     */
    private function clear_config(): void {
        foreach (['trustedkeys', 'revokedkids', 'accepthmlkey'] as $name) {
            unset_config($name, 'mod_scormmakercmi5');
        }
    }

    /**
     * Without any config the embedded PROD key is trusted, the HML key is off (0.3.1) and anything else is unknown.
     */
    public function test_defaults(): void {
        $this->resetAfterTest();
        $this->clear_config();
        $keyring = keyring::from_config();
        $this->assertSame(keyring::STATUS_TRUSTED, $keyring->status_of(self::PROD));
        $this->assertSame('trusted', $keyring->status_of(self::PROD));
        $this->assertSame(32, strlen($keyring->public_key(self::PROD)));
        $this->assertSame(base64_decode(self::PROD_KEY, true), $keyring->public_key(self::PROD));
        $this->assertSame('hmldisabled', $keyring->status_of(self::HML));
        $this->assertSame('unknownkid', $keyring->status_of('x'));
        $this->assertSame('unknownkid', $keyring->status_of(''));
    }

    /**
     * A new site gets the HML key off: the default of the setting is 0.
     */
    public function test_new_site_has_the_hml_key_off(): void {
        $this->resetAfterTest();
        $this->assertSame('0', get_config('mod_scormmakercmi5', 'accepthmlkey'));
        $this->assertSame('hmldisabled', keyring::from_config()->status_of(self::HML));
        $this->assertSame('trusted', keyring::from_config()->status_of(self::PROD));
    }

    /**
     * The PROD key does not depend on accepthmlkey, and the HML key follows it.
     */
    public function test_prod_key_ignores_accepthmlkey(): void {
        $this->resetAfterTest();
        $this->clear_config();
        foreach (['0' => 'hmldisabled', '1' => 'trusted'] as $value => $hml) {
            set_config('accepthmlkey', $value, 'mod_scormmakercmi5');
            $keyring = keyring::from_config();
            $this->assertSame('trusted', $keyring->status_of(self::PROD), "accepthmlkey {$value}");
            $this->assertSame(base64_decode(self::PROD_KEY, true), $keyring->public_key(self::PROD));
            $this->assertSame($hml, $keyring->status_of(self::HML), "accepthmlkey {$value}");
        }
        // Revoking the PROD key works like any other kid.
        set_config('revokedkids', self::PROD, 'mod_scormmakercmi5');
        $this->assertSame('revokedkid', keyring::from_config()->status_of(self::PROD));
    }

    /**
     * The embedded constants are exactly the approved ones (design §17.1).
     */
    public function test_embedded_constants(): void {
        $this->assertSame([
            'sm-hml-2026-09' => 'G7Aw/GpCag3lzz/kqPgHf+bzfkVSj0riPLavTZWlfyM=',
            'sm-prod-2026-10' => '92zIJWqpNtJFD4dM069A4uLCcpNXZtyK1d2aESIe9xU=',
        ], keyring::EMBEDDED);
        foreach (keyring::EMBEDDED as $kid => $b64) {
            $this->assertSame(32, strlen(base64_decode($b64, true)), $kid);
        }
        $this->assertStringStartsNotWith(keyring::HML_PREFIX, self::PROD);
        $this->assertSame([], keyring::EMBEDDED_REVOKED);
        $this->assertSame('sm-hml-', keyring::HML_PREFIX);
    }

    /**
     * A key added by the admin is trusted; revoking it by kid makes it revoked.
     */
    public function test_admin_key_and_revocation(): void {
        $this->resetAfterTest();
        $this->clear_config();
        set_config('accepthmlkey', 1, 'mod_scormmakercmi5');
        $raw = random_bytes(32);
        set_config('trustedkeys', "k1=" . base64_encode($raw), 'mod_scormmakercmi5');
        $keyring = keyring::from_config();
        $this->assertSame('trusted', $keyring->status_of('k1'));
        $this->assertSame($raw, $keyring->public_key('k1'));
        $this->assertSame('trusted', $keyring->status_of(self::HML));

        set_config('revokedkids', "k1", 'mod_scormmakercmi5');
        $keyring = keyring::from_config();
        $this->assertSame('revokedkid', $keyring->status_of('k1'));
        $this->assertSame('trusted', $keyring->status_of(self::HML));

        set_config('revokedkids', self::HML, 'mod_scormmakercmi5');
        $this->assertSame('revokedkid', keyring::from_config()->status_of(self::HML));
    }

    /**
     * A kid revoked in the plugin code is revoked.
     */
    public function test_embedded_revoked(): void {
        $keyring = new keyring(keyring::EMBEDDED, [self::HML], [], [], true);
        $this->assertSame('revokedkid', $keyring->status_of(self::HML));
    }

    /**
     * Turning the HML key off disables every sm-hml-* kid, embedded or admin.
     */
    public function test_hml_disabled(): void {
        $this->resetAfterTest();
        $this->clear_config();
        set_config('accepthmlkey', 0, 'mod_scormmakercmi5');
        $keys = "sm-hml-test=" . base64_encode(random_bytes(32)) . "\nk2=" . base64_encode(random_bytes(32));
        set_config('trustedkeys', $keys, 'mod_scormmakercmi5');
        $keyring = keyring::from_config();
        $this->assertSame('hmldisabled', $keyring->status_of(self::HML));
        $this->assertSame('hmldisabled', $keyring->status_of('sm-hml-test'));
        $this->assertSame('trusted', $keyring->status_of('k2'));
        $this->assertSame('unknownkid', $keyring->status_of('sm-hml-nothere'));

        set_config('accepthmlkey', 1, 'mod_scormmakercmi5');
        $this->assertSame('trusted', keyring::from_config()->status_of('sm-hml-test'));
    }

    /**
     * Order of the checks: unknown, then revoked, then HML disabled, then trusted.
     */
    public function test_status_order(): void {
        $keyring = new keyring(keyring::EMBEDDED, [self::HML], [], ['ghost'], false);
        $this->assertSame('revokedkid', $keyring->status_of(self::HML));
        $this->assertSame('unknownkid', $keyring->status_of('ghost'));
    }

    /**
     * An admin entry cannot replace an embedded key, and a malformed one is not trusted.
     */
    public function test_admin_cannot_override_embedded_and_bad_keys_are_ignored(): void {
        $other = base64_encode(random_bytes(32));
        $keyring = new keyring(keyring::EMBEDDED, [], [self::HML => $other, 'short' => base64_encode(random_bytes(31)),
            'bad' => '###'], [], true);
        $this->assertSame(base64_decode(keyring::EMBEDDED[self::HML], true), $keyring->public_key(self::HML));
        $prod = new keyring(keyring::EMBEDDED, [], [self::PROD => $other], [], false);
        $this->assertSame(base64_decode(self::PROD_KEY, true), $prod->public_key(self::PROD));
        $this->assertSame('unknownkid', $keyring->status_of('short'));
        $this->assertSame('unknownkid', $keyring->status_of('bad'));
    }

    /**
     * Config set around the admin form (CLI, direct DB) is still parsed defensively.
     */
    public function test_config_bypassing_validation(): void {
        $this->resetAfterTest();
        $this->clear_config();
        $good = base64_encode(random_bytes(32));
        $keys = "ok=$good\nbroken line\nshort=" . base64_encode(random_bytes(31)) . "\ndup=$good\ndup=$good";
        set_config('trustedkeys', $keys, 'mod_scormmakercmi5');
        $keyring = keyring::from_config();
        $this->assertSame('trusted', $keyring->status_of('ok'));
        $this->assertSame('unknownkid', $keyring->status_of('short'));
        $this->assertSame('unknownkid', $keyring->status_of('dup'));
    }

    /**
     * Asking the key of a kid that is not trusted is a programming error.
     */
    public function test_public_key_of_untrusted(): void {
        $keyring = new keyring(keyring::EMBEDDED, [], [], [], false);
        $this->expectException(\coding_exception::class);
        $keyring->public_key(self::HML);
    }
}
