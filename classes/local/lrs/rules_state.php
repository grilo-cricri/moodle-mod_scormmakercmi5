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

use mod_scormmakercmi5\local\limits;
use mod_scormmakercmi5\local\runtime\launcher;

/**
 * What the cmi5 rules need to know about a session, its AU and its registration (design §6.3, §6.4).
 *
 * The properties are public and mutable: cmi5_rules works on a clone, so the state of the caller is never changed
 * by a batch that is refused. Only from_context() reads the database; cmi5_rules never does.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_state {
    /** @var int Session status (launcher::STATUS_*). */
    public int $sessionstatus = launcher::STATUS_LAUNCHED;

    /** @var array Verb IRI => true for each cmi5 defined statement the AU already sent in this session. */
    public array $sessionverbs = [];

    /** @var string launchMode of the session (Normal, Browse or Review). */
    public string $launchmode = 'Normal';

    /** @var string moveOn of the AU. */
    public string $moveon = '';

    /** @var float|null masteryScore of the AU, null when the course structure has none. */
    public ?float $masteryscore = null;

    /** @var string Registration UUID. */
    public string $registration = '';

    /** @var string Session id (extension sessionid of the contextTemplate). */
    public string $sessionuuid = '';

    /** @var string activityId of the AU given at launch (lmsactivityid). */
    public string $activityid = '';

    /** @var string Publisher id of the AU (grouping of the contextTemplate). */
    public string $publisherid = '';

    /** @var array The account of the learner: ['homePage' => ..., 'name' => ...]. */
    public array $actoraccount = [];

    /** @var bool The AU read the learner preferences in this session. */
    public bool $prefsread = false;

    /** @var bool "initialized" requires the learner preferences to be read first (setting requireprefsread). */
    public bool $requireprefsread = true;

    /** @var bool The registration already has a "completed" of this AU. */
    public bool $regcompleted = false;

    /** @var bool The registration already has a "passed" of this AU. */
    public bool $regpassed = false;

    /** @var bool The registration already has a "failed" of this AU. */
    public bool $regfailed = false;

    /**
     * The state of the session of a token, read from the database (session, AU, registration, regau and the
     * cmi5 defined statements the AU already sent in the session).
     *
     * @param session_context $ctx Session of the request.
     * @return rules_state
     */
    public static function from_context(session_context $ctx): rules_state {
        global $DB;
        $state = new self();
        $state->sessionstatus = (int)$ctx->session->status;
        $state->launchmode = (string)$ctx->session->launchmode;
        $state->moveon = (string)$ctx->au->moveon;
        $state->masteryscore = $ctx->au->masteryscore === null ? null : (float)$ctx->au->masteryscore;
        $state->registration = (string)$ctx->reg->registration;
        $state->sessionuuid = (string)$ctx->session->sessionuuid;
        $state->activityid = (string)$ctx->au->lmsactivityid;
        $state->publisherid = (string)$ctx->au->publisherid;
        $state->actoraccount = $ctx->actor['account'];
        $state->prefsread = !empty($ctx->session->prefsread);
        $state->requireprefsread = limits::require_prefs_read();

        $verbs = $DB->get_fieldset_select(
            'scormmakercmi5_stmt',
            'verb',
            'sessionid = :sessionid AND source = :source AND cmi5kind = :kind',
            ['sessionid' => $ctx->session->id, 'source' => statement_store::SOURCE_AU, 'kind' => cmi5_rules::KIND_DEFINED]
        );
        foreach ($verbs as $verb) {
            $state->sessionverbs[$verb] = true;
        }

        $regau = $DB->get_record(
            'scormmakercmi5_regau',
            ['regid' => $ctx->reg->id, 'auid' => $ctx->au->id],
            'completed, passed, failed'
        );
        if ($regau) {
            $state->regcompleted = !empty($regau->completed);
            $state->regpassed = !empty($regau->passed);
            $state->regfailed = !empty($regau->failed);
        }
        return $state;
    }
}
