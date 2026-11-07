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

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the Mustache templates of the plugin: the docblock never reaches the page.
 *
 * A "}}" inside a "{{! … }}" comment closes it early and the rest of the docblock (and its example JSON) is printed
 * as text at the top of the page (E2E Step 2, defect 1).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class templates_test extends \advanced_testcase {
    /**
     * The templates of the plugin.
     *
     * @return array
     */
    public static function template_provider(): array {
        $dir = dirname(__DIR__, 2) . '/templates';
        $out = [];
        foreach (glob($dir . '/*.mustache') as $file) {
            $name = basename($file, '.mustache');
            $out[$name] = [$name];
        }
        return $out;
    }

    /**
     * Rendering a template with its own example context prints none of its docblock.
     *
     * @dataProvider template_provider
     * @param string $name Template name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('template_provider')]
    public function test_docblock_not_rendered(string $name): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/scormmakercmi5/view.php');
        $PAGE->set_context(\context_system::instance());
        $source = file_get_contents(dirname(__DIR__, 2) . "/templates/{$name}.mustache");
        $context = [];
        if (preg_match('/Example context \(json\):\s*(\{.*?\})\s*\n\}\}/s', $source, $m)) {
            $context = json_decode($m[1], true);
            $this->assertIsArray($context, "the example context of {$name} is valid JSON");
        }
        $html = $OUTPUT->render_from_template("mod_scormmakercmi5/{$name}", $context);
        foreach (['@template', 'Context variables', 'Example context', 'This file is part of Moodle', 'GNU General'] as $text) {
            $this->assertStringNotContainsString($text, $html, "{$name} prints its docblock");
        }
    }
}
