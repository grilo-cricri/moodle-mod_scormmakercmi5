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

namespace mod_scormmakercmi5\local\report;

/**
 * Status of an AU and of the course within a registration, as shown to the learner and in the reports (R4.6).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status {
    /** @var string Never launched. */
    public const NOT_STARTED = 'notstarted';

    /** @var string Launched at least once, no result yet. */
    public const IN_PROGRESS = 'inprogress';

    /** @var string The AU sent completed. */
    public const COMPLETED = 'completed';

    /** @var string The AU sent passed. */
    public const PASSED = 'passed';

    /** @var string The AU sent failed (and never passed). */
    public const FAILED = 'failed';

    /** @var string The AU was waived by a teacher (R4.8). */
    public const WAIVED = 'waived';

    /** @var string The AU or the course is satisfied (R5.4), without a more telling result for an AU. */
    public const SATISFIED = 'satisfied';

    /** @var string[] Every state, in the order of the lang strings. */
    private const STATES = [
        self::NOT_STARTED, self::IN_PROGRESS, self::COMPLETED, self::PASSED, self::FAILED, self::WAIVED, self::SATISFIED,
    ];

    /**
     * The state of an AU: waived > passed > failed > completed > satisfied > inprogress > notstarted.
     *
     * A pass after a fail counts as passed: cmi5 lets a learner try again, and passed is what satisfies moveOn.
     * An AU satisfied without any result (moveOn NotApplicable, satisfied when the registration is created) shows as
     * satisfied, not as not started. Any launch counts, Browse and Review included: a unit only browsed is in progress.
     *
     * @param \stdClass|null $regau scormmakercmi5_regau row (null when there is no registration yet).
     * @param bool $haslaunch Whether the learner ever launched the AU.
     * @return string One of the state constants.
     */
    public static function au_state(?\stdClass $regau, bool $haslaunch): string {
        foreach ([self::WAIVED, self::PASSED, self::FAILED, self::COMPLETED, self::SATISFIED] as $flag) {
            if ($regau && !empty($regau->{$flag})) {
                return $flag;
            }
        }
        return $haslaunch ? self::IN_PROGRESS : self::NOT_STARTED;
    }

    /**
     * The state of the course: satisfied, in progress or not started.
     *
     * @param \stdClass|null $reg scormmakercmi5_reg row (null when there is no registration yet).
     * @param bool $haslaunch Whether the learner ever launched an AU of the registration.
     * @return string satisfied, inprogress or notstarted.
     */
    public static function course_state(?\stdClass $reg, bool $haslaunch): string {
        if ($reg && !empty($reg->satisfied)) {
            return self::SATISFIED;
        }
        return $haslaunch ? self::IN_PROGRESS : self::NOT_STARTED;
    }

    /**
     * The translated label of a state (string status_<state>).
     *
     * @param string $state One of the state constants.
     * @return string
     */
    public static function label(string $state): string {
        if (!in_array($state, self::STATES, true)) {
            throw new \coding_exception('Unknown cmi5 status: ' . $state);
        }
        return get_string('status_' . $state, 'mod_scormmakercmi5');
    }
}
