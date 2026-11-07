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

use mod_scormmakercmi5\local\report\group_filter;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\output\renderer::class)]
/**
 * Tests for the renderer of the player page.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\output\renderer
 */
final class renderer_test extends \advanced_testcase {
    /**
     * Final review N-2: the player always carries the "statement limit" message, visible when the registration
     * already reached the limit, hidden otherwise (the player module shows it when the session state says so).
     */
    public function test_player_statement_limit_message(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/scormmakercmi5/player.php');
        $PAGE->set_context(\context_system::instance());
        /** @var renderer $renderer */
        $renderer = $PAGE->get_renderer('mod_scormmakercmi5');
        $au = (object)['title' => json_encode(['und' => 'Unidade 1'])];
        $viewurl = new \moodle_url('/mod/scormmakercmi5/view.php', ['id' => 5]);
        $message = get_string('statementlimitlearner', 'mod_scormmakercmi5');

        $html = $renderer->player($au, 'https://example.com/index.html', $viewurl, true);
        $this->assertMatchesRegularExpression('/<div[^>]*data-region="mod_scormmakercmi5-limit"(?![^>]*hidden)[^>]*>/', $html);
        $this->assertStringContainsString(s($message), $html);

        $html = $renderer->player($au, 'https://example.com/index.html', $viewurl, false);
        $this->assertMatchesRegularExpression('/<div[^>]*data-region="mod_scormmakercmi5-limit"[^>]*hidden[^>]*>/', $html);
    }

    /**
     * Final review I-1: the back link carries the region the player module wires (it asks the AU to close before
     * leaving), and an empty live region waits for the "leaving" message, so a screen reader announces it.
     */
    public function test_player_back_link(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/scormmakercmi5/player.php');
        $PAGE->set_context(\context_system::instance());
        /** @var renderer $renderer */
        $renderer = $PAGE->get_renderer('mod_scormmakercmi5');
        $au = (object)['title' => json_encode(['und' => 'Unidade 1'])];
        $viewurl = new \moodle_url('/mod/scormmakercmi5/view.php', ['id' => 5]);

        $html = $renderer->player($au, 'https://example.com/index.html', $viewurl);
        $this->assertMatchesRegularExpression(
            '~<a[^>]*href="' . preg_quote(s($viewurl->out(false)), '~') . '"[^>]*data-region="mod_scormmakercmi5-back"[^>]*>~',
            $html
        );
        $this->assertMatchesRegularExpression(
            '~<span[^>]*role="status"[^>]*data-region="mod_scormmakercmi5-back-status"[^>]*></span>~',
            $html
        );
    }

    /**
     * An AU outside the package: the player tells the learner the content is hosted outside Moodle, with its origin;
     * an AU of the package has no such notice.
     */
    public function test_player_external_notice(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/scormmakercmi5/player.php');
        $PAGE->set_context(\context_system::instance());
        /** @var renderer $renderer */
        $renderer = $PAGE->get_renderer('mod_scormmakercmi5');
        $viewurl = new \moodle_url('/mod/scormmakercmi5/view.php', ['id' => 5]);
        $notice = get_string('externalaulearner', 'mod_scormmakercmi5', 'https://cursos.exemplo.com');

        $au = (object)['title' => json_encode(['und' => 'Unidade 1']), 'auorigin' => 'https://cursos.exemplo.com'];
        $html = $renderer->player($au, 'https://cursos.exemplo.com/au/index.html', $viewurl);
        $this->assertStringContainsString(s($notice), $html);
        $this->assertStringContainsString('data-region="mod_scormmakercmi5-external"', $html);

        $au = (object)['title' => json_encode(['und' => 'Unidade 1']), 'auorigin' => null];
        $html = $renderer->player($au, 'https://example.com/index.html', $viewurl);
        $this->assertStringNotContainsString('data-region="mod_scormmakercmi5-external"', $html);
        $au = (object)['title' => json_encode(['und' => 'Unidade 1'])];
        $html = $renderer->player($au, 'https://example.com/index.html', $viewurl);
        $this->assertStringNotContainsString('data-region="mod_scormmakercmi5-external"', $html);
    }

    /**
     * The block report of the class: package images through the content area of the package version, the retention
     * chart of the core, and "unavailable" without a structure; the learner part has no map.
     */
    public function test_rich_blocks(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $instance = $this->getDataGenerator()->create_module('scormmakercmi5', ['course' => $course->id, 'signed' => 1]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $reg = \mod_scormmakercmi5\local\runtime\launcher::get_or_create_registration($instance, (int)$user->id);
        $dir = \core_component::get_component_directory('mod_scormmakercmi5');
        $case = json_decode(
            file_get_contents($dir . '/tests/fixtures/contrato-dados-ricos.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        )['casos'][0];
        $store = \mod_scormmakercmi5\local\lrs\statement_store::class;
        foreach ($case['statements']['A'] as $n => $st) {
            $st['context']['extensions'][\mod_scormmakercmi5\local\cmi5_vocab::SM_EXT_VERSION] = (string)$pkg->smversion;
            $row = $store::extract_columns($st);
            foreach ((array)$store::sm_columns($st) as $name => $value) {
                $row->{$name} = $value;
            }
            $DB->insert_record('scormmakercmi5_stmt', (object)((array)$row + [
                'scormmakercmi5id' => $instance->id, 'pkgid' => $pkg->id, 'regid' => $reg->id, 'auid' => 0,
                'userid' => $user->id, 'statementid' => $st['id'], 'source' => 0, 'cmi5kind' => 1,
                'storedms' => (int)$row->timestampms + $n, 'payload' => json_encode($st),
            ]));
        }
        $structure = \mod_scormmakercmi5\local\package\analysis_structure::from_json(
            json_encode($case['estrutura']),
            ['media/m1.jpg']
        );
        $context = \context_module::instance($cm->id);
        $PAGE->set_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id]);
        $PAGE->set_context($context);
        /** @var renderer $renderer */
        $renderer = $PAGE->get_renderer('mod_scormmakercmi5');
        $url = new \moodle_url('/mod/scormmakercmi5/report.php', ['id' => $cm->id, 'mode' => 'rich']);
        $version = (string)$pkg->smversion;
        $everyone = group_filter::everyone();

        $html = $renderer->rich_blocks($cm, $pkg, $structure, $version, $everyone, $url);
        $image = "/pluginfile.php/{$context->id}/mod_scormmakercmi5/content/{$pkg->id}/media/m1.jpg";
        $this->assertStringContainsString('src="https://www.example.com/moodle' . $image . '"', $html);
        $this->assertSame(1, substr_count($html, '<img '), 'the scene image of the tour is not in the package');
        $this->assertStringContainsString('chart-area', $html, 'the retention chart of the core');
        $this->assertStringContainsString(get_string('richmaplegend', 'mod_scormmakercmi5'), $html);
        // The download of the aggregates: csv, excel and ods only, to this page with blocks=1.
        $this->assertStringContainsString(get_string('richdownloadblocks', 'mod_scormmakercmi5'), $html);
        $this->assertStringContainsString('<input type="hidden" name="blocks" value="1">', $html);
        $this->assertSame(3, preg_match_all('~<option value="(csv|excel|ods)">~', $html));
        $this->assertSame(3, substr_count($html, '<option value='));

        $this->assertStringContainsString(
            get_string('richblocksnone', 'mod_scormmakercmi5'),
            $renderer->rich_blocks($cm, $pkg, $structure, $version, group_filter::from_groups([-1]), $url),
            'a group with nobody'
        );
        $unavailable = get_string('richblocksunavailable', 'mod_scormmakercmi5');
        $this->assertStringContainsString($unavailable, $renderer->rich_blocks($cm, $pkg, null, $version, $everyone, $url));

        $learner = $renderer->rich_blocks_learner($structure, $reg, $version);
        $this->assertStringContainsString('Final feliz', $learner);
        $this->assertStringNotContainsString('<svg', $learner);
        $this->assertStringContainsString($unavailable, $renderer->rich_blocks_learner(null, $reg, $version));
    }
}
