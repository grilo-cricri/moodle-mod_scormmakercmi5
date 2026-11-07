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

/**
 * Public keys trusted to sign SCORM Maker packages (design §5.3, R3).
 *
 * Effective keys = (embedded ∪ admin) − (embedded revoked ∪ admin revoked) − sm-hml-* when the HML key is off.
 * An admin entry never replaces an embedded key with the same kid. Rotation and revocation of the embedded keys
 * ship as new plugin versions (procedure in docs/xapi/assinatura.md of SCORM Maker).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class keyring {
    /**
     * @var array Embedded public keys, kid => base64 Ed25519 public key (design §17.1). PROD is always trusted; HML only
     * while accepthmlkey is on. Public keys only: the private keys never leave SCORM Maker.
     */
    public const EMBEDDED = [
        'sm-hml-2026-09' => 'G7Aw/GpCag3lzz/kqPgHf+bzfkVSj0riPLavTZWlfyM=',
        'sm-prod-2026-10' => '92zIJWqpNtJFD4dM069A4uLCcpNXZtyK1d2aESIe9xU=',
    ];

    /** @var string[] Kids revoked by a plugin version. */
    public const EMBEDDED_REVOKED = [];

    /** @var string Prefix of the kids of the SCORM Maker HML environment. */
    public const HML_PREFIX = 'sm-hml-';

    /** @var string The kid is trusted. */
    public const STATUS_TRUSTED = 'trusted';

    /** @var string No key with that kid. */
    public const STATUS_UNKNOWN = 'unknownkid';

    /** @var string The kid was revoked. */
    public const STATUS_REVOKED = 'revokedkid';

    /** @var string HML kid while the HML key is turned off. */
    public const STATUS_HMLDISABLED = 'hmldisabled';

    /** @var array kid => raw 32-byte key. */
    private array $keys = [];

    /** @var array kid => true. */
    private array $revoked = [];

    /** @var bool Whether sm-hml-* kids are accepted. */
    private bool $accepthml;

    /**
     * Builds a keyring. Keys that are not the strict base64 of 32 bytes are ignored.
     *
     * @param array $embedded Embedded keys, kid => base64.
     * @param string[] $embeddedrevoked Kids revoked in code.
     * @param array $adminkeys Admin keys, kid => base64.
     * @param string[] $adminrevoked Kids revoked by the admin.
     * @param bool $accepthml Whether sm-hml-* kids are accepted.
     */
    public function __construct(
        array $embedded,
        array $embeddedrevoked,
        array $adminkeys,
        array $adminrevoked,
        bool $accepthml
    ) {
        foreach ([$embedded, $adminkeys] as $source) {
            foreach ($source as $kid => $b64) {
                $kid = (string)$kid;
                if (!isset($this->keys[$kid]) && is_string($b64) && key_lines::is_public_key($b64)) {
                    $this->keys[$kid] = base64_decode($b64, true);
                }
            }
        }
        foreach (array_merge($embeddedrevoked, $adminrevoked) as $kid) {
            $this->revoked[(string)$kid] = true;
        }
        $this->accepthml = $accepthml;
    }

    /**
     * Keyring of this site: embedded keys plus the plugin settings.
     *
     * @return keyring
     */
    public static function from_config(): keyring {
        $config = get_config('mod_scormmakercmi5');
        return new self(
            self::EMBEDDED,
            self::EMBEDDED_REVOKED,
            key_lines::trusted((string)($config->trustedkeys ?? ''))['keys'],
            key_lines::revoked((string)($config->revokedkids ?? ''))['kids'],
            // A value never saved counts as off, like the default of the setting (0.3.1).
            (bool)(int)($config->accepthmlkey ?? 0)
        );
    }

    /**
     * Status of a kid, checked in this order: unknown, revoked, HML disabled, trusted.
     *
     * @param string $kid Key identifier.
     * @return string One of the STATUS_* constants.
     */
    public function status_of(string $kid): string {
        if (!isset($this->keys[$kid])) {
            return self::STATUS_UNKNOWN;
        }
        if (isset($this->revoked[$kid])) {
            return self::STATUS_REVOKED;
        }
        if (!$this->accepthml && str_starts_with($kid, self::HML_PREFIX)) {
            return self::STATUS_HMLDISABLED;
        }
        return self::STATUS_TRUSTED;
    }

    /**
     * Raw 32-byte public key of a trusted kid.
     *
     * @param string $kid Key identifier.
     * @return string
     * @throws \coding_exception When the kid is not trusted.
     */
    public function public_key(string $kid): string {
        if ($this->status_of($kid) !== self::STATUS_TRUSTED) {
            throw new \coding_exception('Public key requested for a kid that is not trusted');
        }
        return $this->keys[$kid];
    }
}
