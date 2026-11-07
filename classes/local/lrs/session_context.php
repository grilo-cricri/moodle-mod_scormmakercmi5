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

namespace mod_scormmakercmi5\local\lrs;

use mod_scormmakercmi5\local\runtime\actor;

/**
 * The launch session an auth token belongs to (design §2.4; R5.2): the token is good for one actor, one
 * registration, one activityId and one session, and nothing else.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class session_context {
    /** @var \stdClass Session row (lastactivityms already updated). */
    public readonly \stdClass $session;

    /** @var \stdClass Registration row. */
    public readonly \stdClass $reg;

    /** @var \stdClass AU row. */
    public readonly \stdClass $au;

    /** @var \stdClass Activity instance row. */
    public readonly \stdClass $instance;

    /** @var \cm_info Course module, as seen by the learner. */
    public readonly \cm_info $cm;

    /** @var \context_module Module context. */
    public readonly \context_module $context;

    /** @var array xAPI agent of the learner (actor::for_user()). */
    public readonly array $actor;

    /**
     * Constructor.
     *
     * @param \stdClass $session Session.
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU.
     * @param \stdClass $instance Instance.
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param array $actor Agent of the learner.
     */
    public function __construct(
        \stdClass $session,
        \stdClass $reg,
        \stdClass $au,
        \stdClass $instance,
        \cm_info $cm,
        \context_module $context,
        array $actor
    ) {
        $this->session = $session;
        $this->reg = $reg;
        $this->au = $au;
        $this->instance = $instance;
        $this->cm = $cm;
        $this->context = $context;
        $this->actor = $actor;
    }

    /**
     * Refuses a request about another agent, activity or registration than those of the session (403 err_scope).
     * A null argument is not checked; an empty string is a value and does not match.
     *
     * @param array|null $agent Decoded agent of the request.
     * @param string|null $activityid activityId of the request.
     * @param string|null $registration registration of the request (UUID, compared ignoring case).
     * @throws lrs_exception 403 err_scope.
     */
    public function assert_scope(?array $agent, ?string $activityid, ?string $registration): void {
        if ($agent !== null && !actor::matches($agent, (int)$this->session->userid)) {
            throw new lrs_exception(403, 'err_scope');
        }
        if ($activityid !== null && $activityid !== $this->au->lmsactivityid) {
            throw new lrs_exception(403, 'err_scope');
        }
        if ($registration !== null && strtolower($registration) !== strtolower($this->reg->registration)) {
            throw new lrs_exception(403, 'err_scope');
        }
    }
}
