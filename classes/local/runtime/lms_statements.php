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

use mod_scormmakercmi5\local\cmi5_vocab as vocab;
use mod_scormmakercmi5\local\lrs\iso_duration;
use mod_scormmakercmi5\local\lrs\xapi_time;

/**
 * Statements the LMS writes on behalf of the AU (design §6.4, cmi5 §9.3).
 *
 * Each one is a cmi5 defined statement: the context is the contextTemplate of the session (session id and
 * publisher id in grouping, cmi5 §9.6.2.3, §9.6.3.1) plus the registration and the cmi5 category.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lms_statements {
    /**
     * "launched" (cmi5 §9.3.1), with the context extensions of §9.6.3.2–§9.6.3.7.
     *
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU.
     * @param \stdClass $session Session.
     * @param array $contexttemplate contextTemplate of the LaunchData.
     * @param string $launchmode Normal|Browse|Review.
     * @param string $launchurl URL of the AU without the cmi5 launch parameters (§9.6.3.4).
     * @param string $moveon moveOn of the LaunchData.
     * @param float|null $masteryscore masteryScore of the LaunchData, if any.
     * @param string|null $launchparameters launchParameters of the LaunchData, if any.
     * @return array
     */
    public static function launched(
        \stdClass $reg,
        \stdClass $au,
        \stdClass $session,
        array $contexttemplate,
        string $launchmode,
        string $launchurl,
        string $moveon,
        ?float $masteryscore,
        ?string $launchparameters
    ): array {
        $extensions = [
            vocab::EXT_LAUNCHMODE => $launchmode,
            vocab::EXT_LAUNCHURL => $launchurl,
            vocab::EXT_MOVEON => $moveon,
        ];
        if ($masteryscore !== null) {
            $extensions[vocab::EXT_MASTERYSCORE] = $masteryscore;
        }
        if ($launchparameters !== null) {
            $extensions[vocab::EXT_LAUNCHPARAMETERS] = $launchparameters;
        }
        return self::statement($reg, self::au_object($au), vocab::VERB_LAUNCHED, 'launched', null, $contexttemplate, $extensions);
    }

    /**
     * "abandoned" (cmi5 §9.3.6) with the session time as result.duration (§9.5.4.2).
     *
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU.
     * @param \stdClass $session Abandoned session.
     * @param array $contexttemplate contextTemplate of the session.
     * @param int $durationms Duration in milliseconds.
     * @return array
     */
    public static function abandoned(
        \stdClass $reg,
        \stdClass $au,
        \stdClass $session,
        array $contexttemplate,
        int $durationms
    ): array {
        $result = ['duration' => iso_duration::from_ms($durationms)];
        return self::statement($reg, self::au_object($au), vocab::VERB_ABANDONED, 'abandoned', $result, $contexttemplate, []);
    }

    /**
     * "waived" (cmi5 §9.3.7): the LMS records that the learner does not need to take the AU.
     *
     * result.success and result.completion are true and result.extensions carries the reason, one of the cmi5
     * vocabulary (§9.5.5.2); having success and completion, it is in the moveon category (§9.6.2.2). The session is a
     * synthetic one: the waiver happens outside any launch of the AU.
     *
     * @param \stdClass $reg Registration.
     * @param \stdClass $au AU waived.
     * @param \stdClass $session Synthetic session of the waiver.
     * @param array $contexttemplate contextTemplate of that session (launcher::context_template()).
     * @param string $reason One of cmi5_vocab::WAIVE_REASONS.
     * @return array
     */
    public static function waived(
        \stdClass $reg,
        \stdClass $au,
        \stdClass $session,
        array $contexttemplate,
        string $reason
    ): array {
        $result = ['success' => true, 'completion' => true, 'extensions' => [vocab::EXT_REASON => $reason]];
        $statement = self::statement($reg, self::au_object($au), vocab::VERB_WAIVED, 'waived', $result, $contexttemplate, []);
        $statement['context']['contextActivities']['category'][] = ['objectType' => 'Activity', 'id' => vocab::CAT_MOVEON];
        return $statement;
    }

    /**
     * "satisfied" of a block or of the course (cmi5 §9.3.9).
     *
     * The object is the activity the LMS generated for the block or the course, with its cmi5 activity type (§9.4);
     * the grouping carries the publisher id of that block or course (§9.6.2.3) and the session id is the one of the
     * session that triggered it — an AU launch, or a synthetic session outside a launch (§9.3.9, §9.6.3.1).
     *
     * @param \stdClass $reg Registration.
     * @param string $objectiri block.lmsiri or the lmscourseiri of the instance.
     * @param string $activitytype cmi5_vocab::TYPE_BLOCK or cmi5_vocab::TYPE_COURSE.
     * @param string $publisherid Publisher id of the block or of the course (cmi5.xml).
     * @param \stdClass $session Session that triggered it.
     * @return array
     */
    public static function satisfied(
        \stdClass $reg,
        string $objectiri,
        string $activitytype,
        string $publisherid,
        \stdClass $session
    ): array {
        $object = ['objectType' => 'Activity', 'id' => $objectiri, 'definition' => ['type' => $activitytype]];
        $template = [
            'contextActivities' => ['grouping' => [['objectType' => 'Activity', 'id' => $publisherid]]],
            'extensions' => [vocab::EXT_SESSIONID => $session->sessionuuid],
        ];
        return self::statement($reg, $object, vocab::VERB_SATISFIED, 'satisfied', null, $template, []);
    }

    /**
     * The object of a statement about an AU: its LMS activity id (§8.1, §9.4).
     *
     * @param \stdClass $au AU.
     * @return array
     */
    private static function au_object(\stdClass $au): array {
        return ['objectType' => 'Activity', 'id' => $au->lmsactivityid];
    }

    /**
     * A statement of the LMS about the learner of the registration.
     *
     * @param \stdClass $reg Registration.
     * @param array $object Object (an Activity).
     * @param string $verb Verb IRI.
     * @param string $display English display of the verb.
     * @param array|null $result Result, if any.
     * @param array $contexttemplate contextTemplate.
     * @param array $extensions Context extensions added to those of the template.
     * @return array
     */
    private static function statement(
        \stdClass $reg,
        array $object,
        string $verb,
        string $display,
        ?array $result,
        array $contexttemplate,
        array $extensions
    ): array {
        $statement = [
            'id' => \core\uuid::generate(),
            'timestamp' => xapi_time::from_ms(xapi_time::now_ms()),
            'actor' => actor::for_user((int)$reg->userid),
            'verb' => ['id' => $verb, 'display' => ['en-US' => $display]],
            'object' => $object,
        ];
        if ($result !== null) {
            $statement['result'] = $result;
        }
        $statement['context'] = self::context($reg, $contexttemplate, $extensions);
        return $statement;
    }

    /**
     * The contextTemplate with the registration, the cmi5 category and more extensions.
     *
     * @param \stdClass $reg Registration.
     * @param array $contexttemplate contextTemplate.
     * @param array $extensions Extensions to add.
     * @return array
     */
    private static function context(\stdClass $reg, array $contexttemplate, array $extensions): array {
        $context = $contexttemplate;
        $context['registration'] = $reg->registration;
        $context['contextActivities']['category'][] = ['objectType' => 'Activity', 'id' => vocab::CAT_CMI5];
        $context['extensions'] = ($context['extensions'] ?? []) + $extensions;
        return $context;
    }
}
