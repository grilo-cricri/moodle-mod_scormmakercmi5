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

namespace mod_scormmakercmi5\local\runtime;

/**
 * The xAPI agent of a learner and of the LMS (cmi5 §9.2, §8.1.3).
 *
 * The learner is an account on the site: homePage = wwwroot, name = user id, with no name or e-mail (§8.1.3
 * "SHOULD NOT include sensitive PII"; the same convention as core_xapi).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class actor {
    /** @var string Account name of the LMS authority. */
    private const LMS_NAME = 'mod_scormmakercmi5';

    /** @var string[] Inverse functional identifiers other than account. */
    private const OTHER_IFIS = ['mbox', 'mbox_sha1sum', 'openid'];

    /**
     * The agent of a user.
     *
     * @param int $userid User id.
     * @return array
     */
    public static function for_user(int $userid): array {
        return self::account((string)$userid);
    }

    /**
     * Whether an agent from a request is the agent of the user: objectType Agent (or absent), the account of the
     * user as its only identifier. A display name is allowed.
     *
     * @param array $agent Decoded agent.
     * @param int $userid User id.
     * @return bool
     */
    public static function matches(array $agent, int $userid): bool {
        global $CFG;
        if (($agent['objectType'] ?? 'Agent') !== 'Agent') {
            return false;
        }
        foreach (self::OTHER_IFIS as $ifi) {
            if (array_key_exists($ifi, $agent)) {
                return false;
            }
        }
        $account = $agent['account'] ?? null;
        return is_array($account)
            && ($account['homePage'] ?? null) === $CFG->wwwroot
            && ($account['name'] ?? null) === (string)$userid;
    }

    /**
     * The authority the LRS puts on every statement it stores (design §7.1).
     *
     * @return array
     */
    public static function lms_authority(): array {
        return self::account(self::LMS_NAME);
    }

    /**
     * An agent identified by an account on the site.
     *
     * @param string $name Account name.
     * @return array
     */
    private static function account(string $name): array {
        global $CFG;
        return ['objectType' => 'Agent', 'account' => ['homePage' => $CFG->wwwroot, 'name' => $name]];
    }
}
