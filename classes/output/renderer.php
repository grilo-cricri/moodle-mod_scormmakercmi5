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

namespace mod_scormmakercmi5\output;

use mod_scormmakercmi5\form\waive_form;
use mod_scormmakercmi5\local\package\course_structure;
use mod_scormmakercmi5\local\package\importer;
use mod_scormmakercmi5\local\report\block_export;
use mod_scormmakercmi5\local\report\block_presenter;
use mod_scormmakercmi5\local\report\block_report;
use mod_scormmakercmi5\local\report\group_filter;
use mod_scormmakercmi5\local\report\rich_report;
use mod_scormmakercmi5\local\report\status;
use mod_scormmakercmi5\local\signature\verification_result;

/**
 * Renderer of mod_scormmakercmi5: all HTML goes through templates or core renderables.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /** @var string Component. */
    private const COMPONENT = 'mod_scormmakercmi5';

    /**
     * The status of the course and the table of assignable units, in file order, with the launch buttons (R4.6).
     *
     * Each button is a POST form to launch.php with the sesskey; an OwnWindow AU opens in a new window.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $instance Instance row (browsemode, reviewmode).
     * @param \stdClass[] $aus scormmakercmi5_au rows (id, title as the JSON of langstrings, launchmethod), sorted.
     * @param \stdClass|null $reg Registration of the user, if any.
     * @param \stdClass[] $regaus scormmakercmi5_regau rows of the registration, by auid.
     * @param bool[] $launched auid => true for the AUs the user ever launched.
     * @param bool $canlaunch Whether the launch buttons are shown (not for a guest).
     * @return string
     */
    public function view_aus(
        \cm_info $cm,
        \stdClass $instance,
        array $aus,
        ?\stdClass $reg,
        array $regaus,
        array $launched,
        bool $canlaunch = true
    ): string {
        $lang = current_language();
        $rows = [];
        foreach ($aus as $au) {
            $titles = json_decode((string)$au->title, true);
            $regau = $regaus[$au->id] ?? null;
            $state = status::au_state($regau, !empty($launched[$au->id]));
            $actions = [[
                'mode' => 'Normal',
                'label' => get_string($state === status::NOT_STARTED ? 'launchstart' : 'launchcontinue', self::COMPONENT),
                'primary' => true,
            ]];
            if (!$canlaunch) {
                $actions = [];
            } else if (!empty($instance->reviewmode) || !empty($regau->satisfied)) {
                $actions[] = ['mode' => 'Review', 'label' => get_string('launchreview', self::COMPONENT), 'primary' => false];
            }
            if ($canlaunch && !empty($instance->browsemode)) {
                $actions[] = ['mode' => 'Browse', 'label' => get_string('launchbrowse', self::COMPONENT), 'primary' => false];
            }
            $rows[] = [
                'id' => (int)$au->id,
                'title' => course_structure::pick(is_array($titles) ? $titles : [], $lang),
                'state' => $state,
                'status' => status::label($state),
                'ownwindow' => $au->launchmethod === 'OwnWindow',
                'actions' => $actions,
            ];
        }
        $coursestate = status::course_state($reg, (bool)$launched);
        return $this->render_from_template('mod_scormmakercmi5/view_aus', [
            'coursestatus' => get_string('coursestatus', self::COMPONENT, status::label($coursestate)),
            'coursestate' => $coursestate,
            'hasaus' => (bool)$rows,
            'canlaunch' => $canlaunch,
            'aus' => $rows,
            'launchurl' => (new \moodle_url('/mod/scormmakercmi5/launch.php'))->out(false),
            'cmid' => (int)$cm->id,
            'sesskey' => sesskey(),
        ]);
    }

    /**
     * The player of an AnyWindow AU: the AU in an iframe titled with the AU title (R4.2, NF5), and the message of the
     * statement limit, hidden unless the registration already reached it (the player module shows it later).
     *
     * @param \stdClass $au scormmakercmi5_au row (title as the JSON of langstrings).
     * @param string $url Launch URL of the AU.
     * @param \moodle_url $viewurl The activity page.
     * @param bool $limitreached Whether the registration reached maxstatementsperreg.
     * @return string
     */
    public function player(\stdClass $au, string $url, \moodle_url $viewurl, bool $limitreached = false): string {
        $titles = json_decode((string)$au->title, true);
        $origin = (string)($au->auorigin ?? '');
        return $this->render_from_template('mod_scormmakercmi5/player', [
            'title' => course_structure::pick(is_array($titles) ? $titles : [], current_language()),
            'url' => $url,
            'viewurl' => $viewurl->out(false),
            'limitreached' => $limitreached,
            'externalorigin' => $origin === '' ? null : $origin,
        ]);
    }

    /**
     * "This session has ended", with a link back to the activity.
     *
     * @param \moodle_url $viewurl The activity page.
     * @return string
     */
    public function session_ended(\moodle_url $viewurl): string {
        return $this->render_from_template('mod_scormmakercmi5/session_ended', ['viewurl' => $viewurl->out(false)]);
    }

    /**
     * A status as text with a decorative icon: never colour or icon alone (NF5).
     *
     * @param string $state One of the status constants.
     * @return string
     */
    public function status(string $state): string {
        $icons = [
            status::NOT_STARTED => 'i/empty',
            status::IN_PROGRESS => 'i/duration',
            status::COMPLETED => 'i/checked',
            status::PASSED => 'i/valid',
            status::FAILED => 'i/invalid',
            status::WAIVED => 'i/excluded',
            status::SATISFIED => 'i/checkedcircle',
        ];
        $label = status::label($state);
        $icon = $this->output->pix_icon($icons[$state], '', 'moodle', ['aria-hidden' => 'true']);
        return \html_writer::span($icon . s($label), 'mod-scormmakercmi5-status', ['data-state' => $state]);
    }

    /**
     * The detail of a learner in the report (mode=user): status of the course and of each AU of the package version of
     * the registration, with the links to waive an AU and to delete the attempt for those who manage attempts.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $instance Instance row (grade).
     * @param \stdClass $user Learner.
     * @param \stdClass[] $aus scormmakercmi5_au rows (id, title), sorted.
     * @param \stdClass|null $reg Registration of the learner, if any.
     * @param \stdClass[] $regaus scormmakercmi5_regau rows by auid.
     * @param bool[] $launched auid => true for the AUs launched at least once.
     * @param bool $canmanage Whether the viewer has manageattempts.
     * @return string
     */
    public function report_user(
        \cm_info $cm,
        \stdClass $instance,
        \stdClass $user,
        array $aus,
        ?\stdClass $reg,
        array $regaus,
        array $launched,
        bool $canmanage
    ): string {
        $lang = current_language();
        $reasons = waive_form::reason_options();
        $rows = [];
        foreach ($aus as $au) {
            $titles = json_decode((string)$au->title, true);
            $regau = $regaus[$au->id] ?? null;
            $state = status::au_state($regau, !empty($launched[$au->id]));
            $waivable = $canmanage && empty($regau->waived) && empty($regau->satisfied);
            $rows[] = [
                'title' => course_structure::pick(is_array($titles) ? $titles : [], $lang),
                'status' => $this->status($state),
                'waivedreason' => !empty($regau->waived) ? ($reasons[$regau->waivedreason] ?? (string)$regau->waivedreason) : '',
                'waiveurl' => $waivable ? (new \moodle_url('/mod/scormmakercmi5/waive.php', [
                    'id' => $cm->id, 'userid' => $user->id, 'au' => $au->id,
                ]))->out(false) : '',
            ];
        }
        $coursestate = status::course_state($reg, (bool)$launched);
        $params = ['id' => $cm->id, 'userid' => $user->id];
        $grade = ($reg && $reg->grade !== null) ? format_float((float)$reg->grade * (float)$instance->grade, 2) : '-';
        return $this->render_from_template('mod_scormmakercmi5/report_user', [
            'learner' => fullname($user, has_capability('moodle/site:viewfullnames', \context_module::instance($cm->id))),
            'coursestatus' => $this->status($coursestate),
            'grade' => $grade,
            'hasaus' => (bool)$rows,
            'aus' => $rows,
            'canmanage' => $canmanage,
            'statementsurl' => $reg ? (new \moodle_url('/mod/scormmakercmi5/report.php', $params + ['mode' => 'statements']))
                ->out(false) : '',
            'deleteurl' => ($reg && $canmanage) ? (new \moodle_url('/mod/scormmakercmi5/attempt_delete.php', $params))
                ->out(false) : '',
            'reporturl' => (new \moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id]))->out(false),
        ]);
    }

    /**
     * The verification status of the package (R2.3), with the reason when the signature is invalid.
     *
     * @param \stdClass $pkg scormmakercmi5_pkg row (sigstatus, sigreason).
     * @return string
     */
    public function verification_notice(\stdClass $pkg): string {
        $sm = get_string_manager();
        $status = 'sigstatus_' . $pkg->sigstatus;
        if (!$sm->string_exists($status, self::COMPONENT)) {
            return '';
        }
        $message = s(get_string('verificationheading', self::COMPONENT, get_string($status, self::COMPONENT)));
        $reason = 'sigreason_' . (string)$pkg->sigreason;
        if (!empty($pkg->sigreason) && $sm->string_exists($reason, self::COMPONENT)) {
            $message .= ' ' . s(get_string($reason, self::COMPONENT));
        }
        $type = [
            verification_result::STATUS_VERIFIED => \core\output\notification::NOTIFY_SUCCESS,
            verification_result::STATUS_UNSIGNED => \core\output\notification::NOTIFY_INFO,
        ][$pkg->sigstatus] ?? \core\output\notification::NOTIFY_WARNING;
        $notification = new \core\output\notification($message, $type, false);
        return $this->output->render($notification);
    }

    /**
     * The notice of a package version with AUs outside the package (decision of the list after D): their origins, and
     * that the version is unsigned and has no block report. '' when every AU is inside the package.
     *
     * @param string[] $origins The distinct auorigin of the AUs of the version.
     * @return string
     */
    public function external_au_notice(array $origins): string {
        if (!$origins) {
            return '';
        }
        sort($origins, SORT_STRING);
        $message = s(get_string('externalaunotice', self::COMPONENT, implode(', ', $origins)));
        return $this->output->render(new \core\output\notification($message, \core\output\notification::NOTIFY_INFO, false));
    }

    /**
     * The notice for the learner of a package version with AUs outside the package: the activity is hosted outside
     * Moodle, at these origins. '' when every AU is inside the package.
     *
     * @param string[] $origins The distinct auorigin of the AUs of the version.
     * @return string
     */
    public function external_au_learner_notice(array $origins): string {
        if (!$origins) {
            return '';
        }
        sort($origins, SORT_STRING);
        $message = s(get_string('externalaulearner', self::COMPONENT, implode(', ', $origins)));
        return $this->output->render(new \core\output\notification($message, \core\output\notification::NOTIFY_INFO, false));
    }

    /**
     * The link to the rich report, for a package verified at its last recheck, or at import when it was never
     * rechecked ('' otherwise). The page checks the package again, read-only, when it opens (R2.4).
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $pkg scormmakercmi5_pkg row (sigstatus, recheckstatus).
     * @return string
     */
    public function rich_report_link(\cm_info $cm, \stdClass $pkg): string {
        if (($pkg->recheckstatus ?? $pkg->sigstatus) !== verification_result::STATUS_VERIFIED) {
            return '';
        }
        $url = new \moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id, 'mode' => 'rich']);
        $link = \html_writer::link($url, get_string('reportrich', self::COMPONENT), ['class' => 'btn btn-secondary']);
        return \html_writer::div($link, 'mb-3');
    }

    /**
     * Why the rich report is not shown (R2.4): the recheck result, above the basic report.
     *
     * @param verification_result $result Result of verifier::evaluate().
     * @param \moodle_url|null $currenturl Rich report of the current version, when another version was chosen.
     * @return string
     */
    public function rich_unavailable(verification_result $result, ?\moodle_url $currenturl = null): string {
        $sm = get_string_manager();
        $text = get_string('sigstatus_' . $result->status, self::COMPONENT);
        if ($result->reason !== null && $sm->string_exists('sigreason_' . $result->reason, self::COMPONENT)) {
            $text .= '. ' . get_string('sigreason_' . $result->reason, self::COMPONENT);
        }
        $message = s(get_string('richunavailable', self::COMPONENT, $text));
        if ($currenturl) {
            $message .= ' ' . \html_writer::link($currenturl, get_string('richcurrentversion', self::COMPONENT));
        }
        return $this->output->render(new \core\output\notification($message, \core\output\notification::NOTIFY_WARNING, false));
    }

    /**
     * The per-activity part of the rich report (R8.1): average time per lesson, where learners stop, success rate per
     * question and the learners with a link to their detail.
     *
     * @param \cm_info $cm Course module.
     * @param int $instanceid Instance id.
     * @param string $smversion Package version.
     * @param group_filter $filter Group filter.
     * @param int $page Page of the learner list.
     * @param \moodle_url $url URL of the page (paging, links).
     * @return string
     */
    public function rich_activity(
        \cm_info $cm,
        int $instanceid,
        string $smversion,
        group_filter $filter,
        int $page,
        \moodle_url $url
    ): string {
        $perpage = 50;
        $lessons = [];
        foreach (rich_report::avg_time_per_lesson($instanceid, $smversion, $filter) as $row) {
            $lessons[] = ['name' => $row->name !== '' ? $row->name : $row->smitem, 'learners' => $row->learners,
                'time' => $this->duration($row->average)];
        }
        $dropoff = [];
        foreach (rich_report::dropoff_by_lesson($instanceid, $smversion, $filter) as $row) {
            $dropoff[] = ['name' => $row->name !== '' ? $row->name : $row->smitem, 'learners' => $row->learners,
                'percent' => get_string('percents', 'moodle', format_float($row->percent, 1))];
        }
        $questions = [];
        foreach (rich_report::question_success_rates($instanceid, $smversion, $filter) as $row) {
            $questions[] = [
                'question' => $row->question !== '' ? $row->question : $row->smitem,
                'choices' => array_map(
                    static fn(array $choice): array => ['text' => $choice['text'] !== '' ? $choice['text'] : $choice['id']],
                    $row->choices
                ),
                'haschoices' => (bool)$row->choices,
                'correct' => $row->correct,
                'respondents' => $row->respondents,
                'attempts' => $row->attempts,
                'correctlast' => $row->correctlast,
                'rate' => get_string('percents', 'moodle', format_float($row->rate, 1)),
            ];
        }
        [$users, $total] = rich_report::learners($instanceid, $smversion, $filter, $page, $perpage);
        $viewfullnames = has_capability('moodle/site:viewfullnames', \context_module::instance($cm->id));
        $learners = [];
        foreach ($users as $user) {
            $learners[] = ['name' => fullname($user, $viewfullnames),
                'url' => (new \moodle_url($url, ['userid' => $user->id, 'page' => null]))->out(false)];
        }
        return $this->render_from_template('mod_scormmakercmi5/report_rich', [
            'activity' => [
                'lessons' => $lessons, 'haslessons' => (bool)$lessons,
                'dropoff' => $dropoff, 'hasdropoff' => (bool)$dropoff,
                'questions' => $questions, 'hasquestions' => (bool)$questions,
                'learners' => $learners, 'haslearners' => (bool)$learners,
                'pagingbar' => $total > $perpage ? $this->output->paging_bar($total, $page, $perpage, $url) : '',
            ],
        ]);
    }

    /**
     * The per-learner part of the rich report (R8.1): lessons visited and time in each, progress over time (chart
     * with its data table), and the last answer to each question.
     *
     * @param \stdClass $user Learner.
     * @param \stdClass|null $reg Registration of the learner on this instance.
     * @param string $smversion Package version.
     * @param \moodle_url $backurl URL of the per-activity rich report.
     * @param bool $viewfullnames Whether the viewer sees full names.
     * @return string
     */
    public function rich_learner(
        \stdClass $user,
        ?\stdClass $reg,
        string $smversion,
        \moodle_url $backurl,
        bool $viewfullnames
    ): string {
        $lessons = [];
        $answers = [];
        $chart = '';
        if ($reg) {
            foreach (rich_report::lessons_for_registration((int)$reg->id, $smversion) as $row) {
                $lessons[] = ['name' => $row->name !== '' ? $row->name : $row->smitem, 'visits' => $row->visitas,
                    'time' => $this->duration($row->tempo)];
            }
            foreach (rich_report::answers_for_registration((int)$reg->id, $smversion) as $row) {
                $answers[] = [
                    'question' => $row->question !== '' ? $row->question : $row->smitem,
                    'correct' => $row->success === true,
                    'incorrect' => $row->success === false,
                    'response' => $row->response ?? '-',
                ];
            }
            $timeline = rich_report::progress_timeline((int)$reg->id, $smversion);
            if ($timeline) {
                $series = new \core\chart_series(
                    get_string('richprogress', self::COMPONENT),
                    array_map(static fn($point): int => $point->progress, $timeline)
                );
                $chart = new \core\chart_line();
                $chart->set_title(get_string('richprogressevolution', self::COMPONENT));
                $chart->add_series($series);
                $chart->set_labels(array_map(
                    static fn($point): string => userdate(intdiv($point->timestampms, 1000), get_string('strftimedatetimeshort')),
                    $timeline
                ));
                $yaxis = $chart->get_yaxis(0, true);
                $yaxis->set_min(0);
                $yaxis->set_max(100);
                $chart = $this->output->render($chart);
            }
        }
        return $this->render_from_template('mod_scormmakercmi5/report_rich', [
            'learner' => [
                'name' => fullname($user, $viewfullnames),
                'hasattempt' => (bool)$reg,
                'lessons' => $lessons, 'haslessons' => (bool)$lessons,
                'answers' => $answers, 'hasanswers' => (bool)$answers,
                'progresschart' => $chart, 'hasprogress' => $chart !== '',
                'backurl' => $backurl->out(false),
            ],
        ]);
    }

    /**
     * Activity inside the lessons (subproject D) for one version, laid over the analysis structure of its package.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $pkg Package of the version (its images are served from its content area).
     * @param array|null $structure block_report::structure() of that package: null when it is not verified at this
     *     opening or has no structure, and the section says the report is unavailable.
     * @param string $smversion Package version.
     * @param group_filter $filter Groups the viewer may see.
     * @param \moodle_url $url Current URL (the download selector of Task 23 is built from it).
     * @return string
     */
    public function rich_blocks(
        \cm_info $cm,
        \stdClass $pkg,
        ?array $structure,
        string $smversion,
        group_filter $filter,
        \moodle_url $url
    ): string {
        if ($structure === null) {
            return $this->render_from_template('mod_scormmakercmi5/report_blocks', ['unavailable' => true]);
        }
        $aggregates = block_report::activity((int)$cm->instance, $smversion, $filter, $structure);
        if ($aggregates['alunos'] === 0) {
            return $this->render_from_template('mod_scormmakercmi5/report_blocks', ['haslessons' => false]);
        }
        // Images are files of this package version, by their relative media/ path (never a URL of the structure).
        $contextid = \context_module::instance($cm->id)->id;
        $pkgid = (int)$pkg->id;
        $imageurl = static function (string $path) use ($contextid, $pkgid): string {
            return \moodle_url::make_pluginfile_url(
                $contextid,
                self::COMPONENT,
                importer::AREA_CONTENT,
                $pkgid,
                '/' . dirname($path) . '/',
                basename($path)
            )->out(false);
        };
        $data = block_presenter::activity($structure, $aggregates, $imageurl);
        foreach ($data['lessons'] as $l => $lesson) {
            foreach ($lesson['blocks'] as $b => $block) {
                if (empty($block['hasmedia'])) {
                    continue;
                }
                $bands = $block['media']['bands'];
                $chart = new \core\chart_line();
                $chart->set_title(get_string('richmediaretention', self::COMPONENT));
                $chart->add_series(new \core\chart_series(
                    get_string('richmediawatching', self::COMPONENT),
                    array_column($bands, 'retention')
                ));
                $chart->set_labels(array_column($bands, 'label'));
                $yaxis = $chart->get_yaxis(0, true);
                $yaxis->set_min(0);
                $yaxis->set_max(100);
                $data['lessons'][$l]['blocks'][$b]['media']['chart'] = $this->output->render($chart);
            }
        }
        // Download of the aggregates (csv, excel and ods only), to this page with blocks=1.
        $data['download'] = block_export::selector($url);
        $data['unavailable'] = false;
        return $this->render_from_template('mod_scormmakercmi5/report_blocks', $data);
    }

    /**
     * Activity inside the lessons of one learner (subproject D): the summary only, no sequence and no coordinates.
     *
     * @param array|null $structure block_report::structure() of the package of the version (null = unavailable).
     * @param \stdClass $reg Registration of the learner on THIS instance, as report.php resolved it after the group
     *     checks (never a regid from the request: block_report::learner() is not scoped by instance).
     * @param string $smversion Package version.
     * @return string
     */
    public function rich_blocks_learner(?array $structure, \stdClass $reg, string $smversion): string {
        if ($structure === null) {
            return $this->render_from_template('mod_scormmakercmi5/report_blocks_user', ['unavailable' => true]);
        }
        $data = block_presenter::learner($structure, block_report::learner((int)$reg->id, $smversion, $structure));
        return $this->render_from_template('mod_scormmakercmi5/report_blocks_user', $data + ['unavailable' => false]);
    }

    /**
     * A duration in milliseconds as text, '-' when there is none.
     *
     * @param int $ms Milliseconds.
     * @return string
     */
    private function duration(int $ms): string {
        return $ms > 0 ? format_time(max(1, (int)round($ms / 1000))) : '-';
    }
}
