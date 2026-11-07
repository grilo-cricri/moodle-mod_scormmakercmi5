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

namespace mod_scormmakercmi5\completion;

use core_completion\activity_custom_completion;

/**
 * The completion rule "satisfy the cmi5 course" (design §6.6; R6.2): complete when the registration of the learner
 * has its course satisfied (reg.satisfied, set by local\runtime\satisfaction, which also counts waived AUs).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /** @var string The rule. */
    public const RULE = 'completionsatisfied';

    /**
     * The state of a rule for the user.
     *
     * @param string $rule Rule.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $satisfied = $DB->record_exists('scormmakercmi5_reg', [
            'scormmakercmi5id' => $this->cm->instance,
            'userid' => $this->userid,
            'satisfied' => 1,
        ]);
        return $satisfied ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The custom rules of the module.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return [self::RULE];
    }

    /**
     * Descriptions of the custom rules, shown to the learner.
     *
     * @return array rule => text.
     */
    public function get_custom_rule_descriptions(): array {
        return [self::RULE => get_string('completiondetail:satisfied', 'mod_scormmakercmi5')];
    }

    /**
     * Every rule, in display order.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return ['completionview', self::RULE, 'completionusegrade', 'completionpassgrade'];
    }

    /**
     * Recomputes the completion of a learner, after the commit that satisfied the course (design §6.6) or that
     * deleted the attempt (COMPLETION_INCOMPLETE). Does nothing when completion is off in the course or the activity.
     *
     * @param \stdClass $instance Instance row.
     * @param int $userid Learner.
     * @param int|null $possibleresult COMPLETION_INCOMPLETE after the data was deleted; null is COMPLETION_UNKNOWN.
     */
    public static function update_user(\stdClass $instance, int $userid, ?int $possibleresult = null): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $modinfo = get_fast_modinfo((int)$instance->course);
        $cm = $modinfo->instances['scormmakercmi5'][$instance->id] ?? null;
        if (!$cm) {
            return;
        }
        $completion = new \completion_info($modinfo->get_course());
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($cm, $possibleresult ?? COMPLETION_UNKNOWN, $userid);
        }
    }

    /**
     * Repairs a completion left behind (design §6.6): the course is satisfied, the rule is on and the stored state
     * is not complete — the update after the commit that satisfied it failed. Cheap when nothing is wrong (one
     * indexed read and the cached completion data), so view.php and launch call it on every visit.
     *
     * @param \cm_info $cm Course module.
     * @param int $userid Learner.
     */
    public static function repair_user(\cm_info $cm, int $userid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        if (empty($cm->customdata['customcompletionrules'][self::RULE])) {
            return;
        }
        $completion = new \completion_info($cm->get_course());
        if ($completion->is_enabled($cm) != COMPLETION_TRACKING_AUTOMATIC) {
            return;
        }
        $satisfied = $DB->record_exists(
            'scormmakercmi5_reg',
            ['scormmakercmi5id' => $cm->instance, 'userid' => $userid, 'satisfied' => 1]
        );
        if (!$satisfied) {
            return;
        }
        $state = (int)$completion->get_data($cm, false, $userid)->completionstate;
        if ($state !== COMPLETION_COMPLETE && $state !== COMPLETION_COMPLETE_PASS) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }
}
