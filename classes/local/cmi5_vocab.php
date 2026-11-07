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

namespace mod_scormmakercmi5\local;

/**
 * cmi5 and xAPI vocabulary used by the LMS and the LRS (constants only).
 *
 * Each IRI was checked against the cmi5 specification (AICC/ADL cmi5_spec.md, branch quartz); the section is
 * given next to each constant. The verbs voided, experienced, answered and progressed are not cmi5 verbs: they
 * come from the ADL xAPI vocabulary (http://adlnet.gov/expapi/verbs/) and are here because the rules and the
 * reports look for them.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cmi5_vocab {
    /** @var string cmi5 §9.3.1. */
    public const VERB_LAUNCHED = 'http://adlnet.gov/expapi/verbs/launched';

    /** @var string cmi5 §9.3.2. */
    public const VERB_INITIALIZED = 'http://adlnet.gov/expapi/verbs/initialized';

    /** @var string cmi5 §9.3.3. */
    public const VERB_COMPLETED = 'http://adlnet.gov/expapi/verbs/completed';

    /** @var string cmi5 §9.3.4. */
    public const VERB_PASSED = 'http://adlnet.gov/expapi/verbs/passed';

    /** @var string cmi5 §9.3.5. */
    public const VERB_FAILED = 'http://adlnet.gov/expapi/verbs/failed';

    /** @var string cmi5 §9.3.8. */
    public const VERB_TERMINATED = 'http://adlnet.gov/expapi/verbs/terminated';

    /** @var string cmi5 §9.3.6. */
    public const VERB_ABANDONED = 'https://w3id.org/xapi/adl/verbs/abandoned';

    /** @var string cmi5 §9.3.7. */
    public const VERB_WAIVED = 'https://w3id.org/xapi/adl/verbs/waived';

    /** @var string cmi5 §9.3.9. */
    public const VERB_SATISFIED = 'https://w3id.org/xapi/adl/verbs/satisfied';

    /** @var string xAPI 1.0.3 Part 2 §2.3.2 (voiding); ADL vocabulary. */
    public const VERB_VOIDED = 'http://adlnet.gov/expapi/verbs/voided';

    /** @var string ADL vocabulary (cmi5 allowed statements). */
    public const VERB_EXPERIENCED = 'http://adlnet.gov/expapi/verbs/experienced';

    /** @var string ADL vocabulary (cmi5 allowed statements). */
    public const VERB_ANSWERED = 'http://adlnet.gov/expapi/verbs/answered';

    /** @var string ADL vocabulary (cmi5 allowed statements). */
    public const VERB_PROGRESSED = 'http://adlnet.gov/expapi/verbs/progressed';

    /**
     * @var string ADL vocabulary: the grade of a SCORM Maker course with no pass score (a cmi5 allowed statement with
     * result.score, sent in Normal mode only).
     */
    public const VERB_SCORED = 'http://adlnet.gov/expapi/verbs/scored';

    /** @var string cmi5 §9.6.2.1. */
    public const CAT_CMI5 = 'https://w3id.org/xapi/cmi5/context/categories/cmi5';

    /** @var string cmi5 §9.6.2.2. */
    public const CAT_MOVEON = 'https://w3id.org/xapi/cmi5/context/categories/moveon';

    /** @var string cmi5 §9.6.3.1. */
    public const EXT_SESSIONID = 'https://w3id.org/xapi/cmi5/context/extensions/sessionid';

    /** @var string cmi5 §9.6.3.2. */
    public const EXT_MASTERYSCORE = 'https://w3id.org/xapi/cmi5/context/extensions/masteryscore';

    /** @var string cmi5 §9.6.3.3. */
    public const EXT_LAUNCHMODE = 'https://w3id.org/xapi/cmi5/context/extensions/launchmode';

    /** @var string cmi5 §9.6.3.4. */
    public const EXT_LAUNCHURL = 'https://w3id.org/xapi/cmi5/context/extensions/launchurl';

    /** @var string cmi5 §9.6.3.6. */
    public const EXT_MOVEON = 'https://w3id.org/xapi/cmi5/context/extensions/moveon';

    /** @var string cmi5 §9.6.3.7. */
    public const EXT_LAUNCHPARAMETERS = 'https://w3id.org/xapi/cmi5/context/extensions/launchparameters';

    /** @var string cmi5 §9.5.5.2. */
    public const EXT_REASON = 'https://w3id.org/xapi/cmi5/result/extensions/reason';

    /** @var string cmi5 §9.5.5.1. */
    public const EXT_PROGRESS = 'https://w3id.org/xapi/cmi5/result/extensions/progress';

    /** @var string cmi5 §9.4 (satisfied statement of the course). */
    public const TYPE_COURSE = 'https://w3id.org/xapi/cmi5/activitytype/course';

    /** @var string cmi5 §9.4 (satisfied statement of a block). */
    public const TYPE_BLOCK = 'https://w3id.org/xapi/cmi5/activitytype/block';

    /** @var string Category of the SCORM Maker profile (design §3.9, R8). */
    public const SM_PROFILE = 'https://scormmaker.com.br/xapi/perfil/v1';

    /** @var string Context extension with the package version of SCORM Maker (R8.2). */
    public const SM_EXT_VERSION = 'https://scormmaker.com.br/xapi/extensao/versao-do-pacote';

    /** @var string Extension versao-do-perfil of the SCORM Maker profile (2 = rich data, subproject D). */
    public const SM_EXT_PROFILEVER = 'https://scormmaker.com.br/xapi/extensao/versao-do-perfil';

    /** @var string Extension tipo-de-bloco. */
    public const SM_EXT_BLOCKTYPE = 'https://scormmaker.com.br/xapi/extensao/tipo-de-bloco';

    /** @var string Extension tipo-de-decisao (escolha, final, sei, descoberto). */
    public const SM_EXT_DECISIONTYPE = 'https://scormmaker.com.br/xapi/extensao/tipo-de-decisao';

    /**
     * @var string Result extension cena-de-destino of a decision escolha of a scenario: the scene the choice leads to
     *     (its own scene on a retry). Optional; kept in the payload, no column.
     */
    public const SM_EXT_DESTSCENE = 'https://scormmaker.com.br/xapi/extensao/cena-de-destino';

    /** @var string Result extension resumo-do-bloco. */
    public const SM_EXT_SUMMARY = 'https://scormmaker.com.br/xapi/extensao/resumo-do-bloco';

    /** @var string Result extension video. */
    public const SM_EXT_VIDEO = 'https://scormmaker.com.br/xapi/extensao/video';

    /** @var string Result extension mapa-de-calor. */
    public const SM_EXT_HEATMAP = 'https://scormmaker.com.br/xapi/extensao/mapa-de-calor';

    /** @var string ADL verb of a block summary. */
    public const VERB_INTERACTED = 'http://adlnet.gov/expapi/verbs/interacted';

    /** @var string Verb of a decision (Tin Can registry). */
    public const VERB_SELECTED = 'http://id.tincanapi.com/verb/selected';

    /** @var string[] Reasons of a waived statement, cmi5 §9.5.5.2. */
    public const WAIVE_REASONS = ['Tested Out', 'Equivalent AU', 'Equivalent Outside Activity', 'Administrative'];

    /** @var string State id of the launch data document, cmi5 §10.1. */
    public const LAUNCHDATA_ID = 'LMS.LaunchData';

    /** @var string Agent Profile id of the learner preferences, cmi5 §11.0. */
    public const PREFS_ID = 'cmi5LearnerPreferences';
}
