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

/**
 * Behat steps of mod_scormmakercmi5.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps of mod_scormmakercmi5: a second window of the activity and the player coming back into view.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_scormmakercmi5 extends behat_base {
    /**
     * The URL of a page of an activity, for "I am on the "<idnumber or name>" "mod_scormmakercmi5 > <type>" page":
     * "Report" is the basic report (report.php), "Rich report" the rich one (mode=rich), "Index" the list of the
     * activities of a course (index.php, identifier = course shortname or full name).
     *
     * @param string $type Page type.
     * @param string $identifier Course module idnumber or activity name.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'report':
                return new moodle_url('/mod/scormmakercmi5/report.php', ['id' => $this->cm($identifier)->id]);
            case 'rich report':
                return new moodle_url('/mod/scormmakercmi5/report.php', ['id' => $this->cm($identifier)->id, 'mode' => 'rich']);
            case 'index':
                return new moodle_url('/mod/scormmakercmi5/index.php', ['id' => $this->get_course_id($identifier)]);
            default:
                throw new Exception("Unrecognised mod_scormmakercmi5 page type '{$type}'.");
        }
    }

    /**
     * Opens the activity page in a new browser window with the given name (as a learner opening a second tab).
     *
     * @Given /^I open the "(?P<idnumber_string>(?:[^"]|\\")*)" cmi5 activity in a window named "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $idnumber Course module idnumber.
     * @param string $name Window name, for "I switch to ... window".
     */
    public function i_open_the_cmi5_activity_in_a_window_named(string $idnumber, string $name): void {
        $cm = $this->cm($idnumber);
        $url = (new moodle_url('/mod/scormmakercmi5/view.php', ['id' => $cm->id]))->out(false);
        $this->execute_script('window.open(' . json_encode($url) . ', ' . json_encode($name) . ');');
    }

    /**
     * Opens the page that waives a unit of a learner by its URL (the report links to it; this reaches it directly).
     *
     * @When /^I open the cmi5 waive page of "(?P<username>[^"]*)" for "(?P<unit>[^"]*)" in "(?P<idnumber>[^"]*)"$/
     * @param string $username Learner.
     * @param string $unit Title of the unit (pt-BR langstring of the fixture).
     * @param string $idnumber Course module idnumber.
     */
    public function i_open_the_cmi5_waive_page(string $username, string $unit, string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $cm->instance], MUST_EXIST);
        $auid = null;
        foreach ($DB->get_records('scormmakercmi5_au', ['pkgid' => $pkgid], 'sortorder', 'id, title') as $au) {
            if (in_array($unit, (array)json_decode($au->title, true), true)) {
                $auid = $au->id;
            }
        }
        if (!$auid) {
            throw new ExpectationException("No unit {$unit} in {$idnumber}", $this->getSession());
        }
        $url = new moodle_url('/mod/scormmakercmi5/waive.php', ['id' => $cm->id, 'userid' => $userid, 'au' => $auid]);
        $this->execute('behat_general::i_visit', [$url]);
    }

    /**
     * Opens the page that deletes the attempt of a learner by its URL (the report links to it; this reaches it directly).
     *
     * @When /^I open the cmi5 attempt deletion page of "(?P<username>(?:[^"]|\\")*)" in "(?P<idnumber>(?:[^"]|\\")*)"$/
     * @param string $username Learner.
     * @param string $idnumber Course module idnumber.
     */
    public function i_open_the_cmi5_attempt_deletion_page(string $username, string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $url = new moodle_url('/mod/scormmakercmi5/attempt_delete.php', ['id' => $cm->id, 'userid' => $userid]);
        $this->execute('behat_general::i_visit', [$url]);
    }

    /**
     * Whether a user has a registration (an attempt) in the activity.
     *
     * @Then /^"(?P<username>(?:[^"]|\\")*)" should (?P<not>not )?have a cmi5 attempt in "(?P<idnumber>(?:[^"]|\\")*)"$/
     * @param string $username User.
     * @param string $not "not " or empty.
     * @param string $idnumber Course module idnumber.
     */
    public function user_should_have_a_cmi5_attempt(string $username, string $not, string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $exists = $DB->record_exists('scormmakercmi5_reg', ['scormmakercmi5id' => $cm->instance, 'userid' => $userid]);
        if ($exists === ($not !== '')) {
            throw new ExpectationException("{$username} attempt: " . ($exists ? 'exists' : 'missing'), $this->getSession());
        }
    }

    /**
     * That no recheck of the current package of the activity was recorded: opening a report writes nothing.
     *
     * @Then /^the signature of the "(?P<idnumber>(?:[^"]|\\")*)" cmi5 package should not have been rechecked$/
     * @param string $idnumber Course module idnumber.
     */
    public function the_package_should_not_have_been_rechecked(string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $cm->instance], MUST_EXIST);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $pkgid], 'id, timerecheck, timefullrecheck', MUST_EXIST);
        if ((int)$pkg->timerecheck !== 0 || (int)$pkg->timefullrecheck !== 0) {
            throw new ExpectationException('The package of ' . $idnumber . ' was rechecked on a page view', $this->getSession());
        }
    }

    /**
     * Stores SCORM Maker profile statements of a learner (version of the current package): lesson "Lição 1" for 30 s,
     * progress 40 then 80, and question "Quanto é 1 + 1?" answered right. Rows written directly, as the LRS stores them
     * (columns + itemdef); the ingestion itself is covered by PHPUnit.
     *
     * @Given /^"(?P<username>(?:[^"]|\\")*)" has SCORM Maker statements in "(?P<idnumber>(?:[^"]|\\")*)"$/
     * @param string $username Learner.
     * @param string $idnumber Course module idnumber.
     */
    public function user_has_scorm_maker_statements(string $username, string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $userid = (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $reg = \mod_scormmakercmi5\local\runtime\launcher::get_or_create_registration($instance, $userid);
        $auid = (int)$DB->get_field_sql('SELECT MIN(id) FROM {scormmakercmi5_au} WHERE pkgid = ?', [$pkg->id]);
        $version = (string)$pkg->smversion;
        $course = 'https://scormmaker.com.br/xapi/curso/c1';
        $adl = 'http://adlnet.gov/expapi/verbs/';
        $base = time() * 1000;
        $rows = [
            ['experienced', $course . '/licao/l1', 'licao', 'l1', ['durationms' => 30000]],
            ['progressed', $course, null, null, ['progress' => 40]],
            ['answered', $course . '/questao/q1', 'questao', 'q1', ['success' => 1]],
            ['progressed', $course, null, null, ['progress' => 80]],
        ];
        foreach ($rows as $n => [$verb, $objectid, $kind, $item, $columns]) {
            $statementid = \core\uuid::generate();
            $result = isset($columns['success']) ? ['success' => true, 'response' => 'b'] : [];
            $DB->insert_record('scormmakercmi5_stmt', (object)($columns + [
                'scormmakercmi5id' => $instance->id, 'pkgid' => $pkg->id, 'regid' => $reg->id, 'auid' => $auid,
                'userid' => $userid, 'statementid' => $statementid, 'source' => 0, 'verb' => $adl . $verb,
                'objecthash' => sha1($objectid), 'objectid' => $objectid, 'cmi5kind' => 1,
                'timestampms' => $base + $n * 60000, 'storedms' => $base + $n * 60000,
                'smprofile' => 1, 'smversion' => $version, 'smkind' => $kind, 'smitem' => $item,
                'payload' => json_encode(['id' => $statementid, 'verb' => ['id' => $adl . $verb],
                    'object' => ['id' => $objectid], 'result' => $result]),
            ]));
        }
        $definitions = [
            ['licao', 'l1', ['pt-BR' => 'Lição 1'], ['name' => ['pt-BR' => 'Lição 1']]],
            ['questao', 'q1', ['pt-BR' => 'Questão 1'], ['name' => ['pt-BR' => 'Questão 1'],
                'description' => ['pt-BR' => 'Quanto é 1 + 1?'], 'interactionType' => 'choice',
                'correctResponsesPattern' => ['b'],
                'choices' => [
                    ['id' => 'a', 'description' => ['pt-BR' => 'Um']],
                    ['id' => 'b', 'description' => ['pt-BR' => 'Dois']],
                ]]],
        ];
        foreach ($definitions as [$kind, $item, $name, $definition]) {
            $key = ['pkgid' => $pkg->id, 'smversion' => $version, 'smkind' => $kind, 'smitem' => $item];
            if (!$DB->record_exists('scormmakercmi5_itemdef', $key)) {
                $DB->insert_record('scormmakercmi5_itemdef', (object)[
                    'pkgid' => $pkg->id, 'smversion' => $version, 'smkind' => $kind, 'smitem' => $item,
                    'objectid' => $course . '/' . $kind . '/' . $item, 'name' => json_encode($name),
                    'definition' => json_encode($definition), 'firstseenms' => $base,
                ]);
            }
        }
    }

    /**
     * Statements of the learner A of the shared contract (subproject D) and the structure of the contract on the
     * current package of the activity. Rows written directly, with the columns the LRS extracts; the ingestion
     * itself is covered by PHPUnit.
     *
     * @Given /^"(?P<username>(?:[^"]|\\")*)" has SCORM Maker block statements in "(?P<idnumber>(?:[^"]|\\")*)"$/
     * @param string $username Learner.
     * @param string $idnumber Course module idnumber.
     */
    public function user_has_scorm_maker_block_statements(string $username, string $idnumber): void {
        $this->user_has_scorm_maker_block_statements_of_case($username, 'turma-de-tres', $idnumber);
    }

    /**
     * Statements of the learner A of one case of the shared contract and the structure of that case on the current
     * package of the activity (as the step above).
     *
     * @Given /^"(?P<username>[^"]*)" has SCORM Maker block statements of the contract case "(?P<case>[^"]*)" in "(?P<cm>[^"]*)"$/
     * @param string $username Learner.
     * @param string $casename Nome of the contract case.
     * @param string $idnumber Course module idnumber.
     */
    public function user_has_scorm_maker_block_statements_of_case(string $username, string $casename, string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $userid = (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $instance = $DB->get_record('scormmakercmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $pkg = $DB->get_record('scormmakercmi5_pkg', ['id' => $instance->currentpkgid], '*', MUST_EXIST);
        $reg = \mod_scormmakercmi5\local\runtime\launcher::get_or_create_registration($instance, $userid);
        $auid = (int)$DB->get_field_sql('SELECT MIN(id) FROM {scormmakercmi5_au} WHERE pkgid = ?', [$pkg->id]);
        require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contract.php');
        $case = \mod_scormmakercmi5_test_contract::case_named($casename);
        // As the import stores it: no image of the contract is in this package, so they all become null.
        $structure = \mod_scormmakercmi5\local\package\analysis_structure::from_json(
            \mod_scormmakercmi5_test_contract::encode($case['estrutura']),
            []
        );
        $DB->set_field(
            'scormmakercmi5_pkg',
            'richstructure',
            \mod_scormmakercmi5\local\package\analysis_structure::encode($structure),
            ['id' => $pkg->id]
        );
        $store = \mod_scormmakercmi5\local\lrs\statement_store::class;
        foreach ($case['statements']['A'] as $n => $st) {
            $st['context']['extensions'][\mod_scormmakercmi5\local\cmi5_vocab::SM_EXT_VERSION] = (string)$pkg->smversion;
            $row = $store::extract_columns($st);
            foreach ((array)$store::sm_columns($st) as $name => $value) {
                $row->{$name} = $value;
            }
            $DB->insert_record('scormmakercmi5_stmt', (object)((array)$row + [
                'scormmakercmi5id' => $instance->id, 'pkgid' => $pkg->id, 'regid' => $reg->id, 'auid' => $auid,
                'userid' => $userid, 'statementid' => $st['id'], 'source' => 0, 'cmi5kind' => 1,
                'storedms' => (int)$row->timestampms + $n, 'payload' => json_encode($st),
            ]));
        }
    }

    /**
     * The computed start padding of the row header of a block in the block report, as the browser resolves the cascade
     * (a class in the HTML says nothing about the indent the teacher sees).
     *
     * @Then /^the block row "(?P<label>(?:[^"]|\\")*)" should have a start padding of "(?P<value>[^"]*)"$/
     * @param string $label Exact text of the row header.
     * @param string $value Expected computed padding-inline-start, as "24px".
     */
    public function the_block_row_should_have_a_start_padding_of(string $label, string $value): void {
        $label = str_replace('\\"', '"', $label);
        $actual = $this->evaluate_script('(function () {
            var ths = document.querySelectorAll(".mod-scormmakercmi5-blocktable tbody th[scope=row]");
            for (var i = 0; i < ths.length; i++) {
                if (ths[i].textContent.trim() === ' . json_encode($label) . ') {
                    return getComputedStyle(ths[i]).paddingInlineStart;
                }
            }
            return "no row";
        })()');
        if ($actual !== $value) {
            throw new ExpectationException("Row {$label}: padding-inline-start {$actual}, not {$value}", $this->getSession());
        }
    }

    /**
     * The file of the block activity download, as the selector of the page asks for it: the form of the selector with
     * blocks=1 (its action and hidden fields) and the format, fetched with the session of the browser.
     *
     * @param string $format csv, excel or ods (an option of the selector).
     * @return string Contents.
     */
    private function download_block_activity(string $format): string {
        $form = $this->find('xpath', "//form[contains(@class, 'dataformatselector')][.//input[@name='blocks']]");
        if (!$form->find('xpath', ".//select[@name='download']/option[@value=" . behat_context_helper::escape($format) . ']')) {
            throw new ExpectationException("The selector has no {$format}", $this->getSession());
        }
        $params = [];
        foreach ($form->findAll('xpath', ".//input[@type='hidden']") as $input) {
            $params[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        $params['download'] = $format;
        $url = $form->getAttribute('action') . '?' . http_build_query($params, '', '&');
        $session = $this->getSession()->getCookie('MoodleSession');
        $content = download_file_content($url, ['Cookie' => 'MoodleSession=' . $session]);
        if (!is_string($content) || $content === '') {
            throw new ExpectationException("Nothing downloaded as {$format}", $this->getSession());
        }
        return $content;
    }

    /**
     * Part => text of a downloaded block activity: for csv each file of the zip, for excel and ods each sheet (its XML,
     * entities decoded), in order.
     *
     * @param string $content Contents of the download.
     * @param string $format csv, excel or ods.
     * @return array
     */
    private function parts_of_download(string $content, string $format): array {
        $path = make_request_directory() . '/download.zip';
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ExpectationException("The {$format} download is not a zip file", $this->getSession());
        }
        $decode = static fn(string $xml): string => html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $out = [];
        if ($format === 'csv') {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            }
        } else if ($format === 'excel') {
            $n = 0;
            foreach ((new \SimpleXMLElement($zip->getFromName('xl/workbook.xml')))->sheets->sheet as $sheet) {
                $out[(string)$sheet['name']] = $decode((string)$zip->getFromName('xl/worksheets/sheet' . (++$n) . '.xml'));
            }
        } else {
            $content = (string)$zip->getFromName('content.xml');
            preg_match_all('~<table:table [^>]*table:name="([^"]*)".*?</table:table>~s', $content, $m, PREG_SET_ORDER);
            foreach ($m as $table) {
                $out[$decode($table[1])] = $decode($table[0]);
            }
        }
        $zip->close();
        return $out;
    }

    /**
     * The block activity downloaded through the selector of the rich report has these parts, in this order: the files
     * of the zip (csv) or the sheets of the workbook (excel, ods).
     *
     * @Then /^downloading the block activity as "(?P<format>csv|excel|ods)" should give "(?P<parts>[^"]*)"$/
     * @param string $format Format.
     * @param string $parts Comma-separated names.
     */
    public function downloading_the_block_activity_should_give(string $format, string $parts): void {
        $actual = array_keys($this->parts_of_download($this->download_block_activity($format), $format));
        $expected = array_map('trim', explode(',', $parts));
        if ($actual !== $expected) {
            throw new ExpectationException("The {$format} download has " . implode(', ', $actual), $this->getSession());
        }
    }

    /**
     * A text in one part (a file of the csv zip or a sheet) of the block activity downloaded through the selector.
     *
     * @Then /^downloading the block activity as "(?P<format>csv|excel|ods)" should have "(?P<text>[^"]*)" in "(?P<part>[^"]*)"$/
     * @param string $format Format.
     * @param string $text Text.
     * @param string $part File of the zip or sheet name.
     */
    public function downloading_the_block_activity_should_have(string $format, string $text, string $part): void {
        $parts = $this->parts_of_download($this->download_block_activity($format), $format);
        if (!str_contains($parts[$part] ?? '', $text)) {
            throw new ExpectationException("No {$text} in {$part} of the {$format} download", $this->getSession());
        }
    }

    /**
     * The course module of an activity by idnumber, or an exception.
     *
     * @param string $idnumber Course module idnumber.
     * @return cm_info
     */
    private function cm(string $idnumber): cm_info {
        $cm = $this->get_course_module_for_identifier($idnumber);
        if (!$cm) {
            throw new ExpectationException("No activity with idnumber {$idnumber}", $this->getSession());
        }
        return $cm;
    }

    /**
     * The fields of a launch of the first unit of the activity in Normal mode, as the form of view.php sends them.
     *
     * @param string $idnumber Course module idnumber.
     * @return array [fields as JSON, launch.php URL as JSON]
     */
    private function launch_fields(string $idnumber): array {
        global $DB;
        $cm = $this->cm($idnumber);
        $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $cm->instance], MUST_EXIST);
        $auid = (int)$DB->get_field_sql('SELECT MIN(id) FROM {scormmakercmi5_au} WHERE pkgid = ?', [$pkgid]);
        return [
            json_encode(['id' => (int)$cm->id, 'au' => $auid, 'mode' => 'Normal']),
            json_encode((new moodle_url('/mod/scormmakercmi5/launch.php'))->out(false)),
        ];
    }

    /**
     * Posts a launch of the first unit of the activity from the current page with the sesskey of the page, even where
     * view.php shows no button: this is how the guards of launch.php are reached.
     *
     * @When /^I post a cmi5 launch of "(?P<idnumber_string>(?:[^"]|\\")*)"$/
     * @param string $idnumber Course module idnumber.
     */
    public function i_post_a_cmi5_launch_of(string $idnumber): void {
        [$fields, $action] = $this->launch_fields($idnumber);
        $this->execute_script(<<<EOF
(() => {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = {$action};
    Object.entries(Object.assign({$fields}, {sesskey: M.cfg.sesskey})).forEach(([name, value]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
    });
    document.body.append(form);
    form.submit();
})();
EOF);
        $this->wait_for_pending_js();
    }

    /**
     * Posts a launch with a wrong sesskey in the background (the error page would fail the step that shows it) and
     * checks that the answer is the core invalid sesskey error.
     *
     * @When /^a cmi5 launch of "(?P<idnumber_string>(?:[^"]|\\")*)" posted with a wrong sesskey is refused$/
     * @param string $idnumber Course module idnumber.
     */
    public function a_cmi5_launch_posted_with_a_wrong_sesskey_is_refused(string $idnumber): void {
        [$fields, $action] = $this->launch_fields($idnumber);
        $answer = $this->background_answer($action, "new URLSearchParams(Object.assign({$fields}, {sesskey: 'wrong'}))");
        if (!is_string($answer) || !str_contains($answer, get_string('invalidsesskey', 'error'))) {
            throw new ExpectationException('launch.php did not refuse the wrong sesskey', $this->getSession());
        }
    }

    /**
     * Opens a page of the activity in the background (an error page would fail the step that shows it) and checks
     * that its answer contains a text, such as the core error of a missing capability.
     *
     * @Then /^opening the cmi5 "(?P<type>[^"]*)" page of "(?P<idnumber>(?:[^"]|\\")*)" should show "(?P<text>(?:[^"]|\\")*)"$/
     * @param string $type Page type, as in resolve_page_instance_url().
     * @param string $idnumber Course module idnumber.
     * @param string $text Expected text.
     */
    public function opening_the_cmi5_page_should_show(string $type, string $idnumber, string $text): void {
        $url = json_encode($this->resolve_page_instance_url($type, $idnumber)->out(false));
        $answer = $this->background_answer($url, 'null');
        if (!is_string($answer) || !str_contains(html_entity_decode($answer, ENT_QUOTES), $text)) {
            throw new ExpectationException("The {$type} page did not show \"{$text}\"", $this->getSession());
        }
    }

    // The step regex of the next docblock cannot be wrapped: Behat reads it as one line.
    // phpcs:disable moodle.Files.LineLength.TooLong
    /**
     * Opens a page about one learner in the background and checks that its answer has (or has not) a text: the waive
     * page of the first unit, the attempt deletion page or the learner's detail in the report.
     *
     * @Then /^opening the cmi5 (?P<page>waive|attempt deletion|(?:rich )?learner) page of "(?P<user>[^"]+)" in "(?P<cm>[^"]+)" should (?P<not>not )?show "(?P<text>[^"]*)"$/
     * @param string $page waive, attempt deletion, learner or rich learner.
     * @param string $username Learner.
     * @param string $idnumber Course module idnumber.
     * @param string $not "not " or empty.
     * @param string $text Text.
     */
    public function opening_the_cmi5_learner_page_should_show(
        string $page,
        string $username,
        string $idnumber,
        string $not,
        string $text
    ): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $params = ['id' => $cm->id, 'userid' => $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST)];
        if ($page === 'waive') {
            $pkgid = $DB->get_field('scormmakercmi5', 'currentpkgid', ['id' => $cm->instance], MUST_EXIST);
            $params['au'] = (int)$DB->get_field_sql('SELECT MIN(id) FROM {scormmakercmi5_au} WHERE pkgid = ?', [$pkgid]);
            $url = new moodle_url('/mod/scormmakercmi5/waive.php', $params);
        } else if ($page === 'attempt deletion') {
            $url = new moodle_url('/mod/scormmakercmi5/attempt_delete.php', $params);
        } else {
            $url = new moodle_url('/mod/scormmakercmi5/report.php', $params + ['mode' => $page === 'learner' ? 'user' : 'rich']);
        }
        $answer = $this->background_answer(json_encode($url->out(false)), 'null');
        if (!is_string($answer)) {
            throw new ExpectationException("The {$page} page did not answer", $this->getSession());
        }
        if (str_contains(html_entity_decode($answer, ENT_QUOTES), $text) === ($not !== '')) {
            $what = $not ? 'shows' : 'does not show';
            throw new ExpectationException("The {$page} page {$what} \"{$text}\"", $this->getSession());
        }
    }
    // phpcs:enable moodle.Files.LineLength.TooLong

    /**
     * Fetches a URL from the current page with its cookies (POST when there is a body, GET otherwise) and waits for the
     * text of the answer.
     *
     * @param string $url URL as a JavaScript string literal.
     * @param string $body JavaScript expression of the body, or 'null' for a GET.
     * @return string|null The answer, null when it did not come in time.
     */
    private function background_answer(string $url, string $body): ?string {
        $this->execute_script(<<<EOF
(() => {
    window.cmi5backgroundanswer = null;
    const body = {$body};
    const options = body === null ? {credentials: 'same-origin'} : {method: 'POST', body: body, credentials: 'same-origin'};
    fetch({$url}, options)
        .then(response => response.text())
        .then(text => { window.cmi5backgroundanswer = text; })
        .catch(e => { window.cmi5backgroundanswer = 'failed ' + e; });
})();
EOF);
        $answer = null;
        for ($i = 0; $i < self::get_extended_timeout() * 10 && $answer === null; $i++) {
            usleep(100000);
            $answer = $this->evaluate_script('return window.cmi5backgroundanswer;');
        }
        return $answer;
    }

    /**
     * Opens launch.php of the activity with a plain GET (a bookmark or the back button).
     *
     * @When /^I open the cmi5 launch page of "(?P<idnumber_string>(?:[^"]|\\")*)" without posting$/
     * @param string $idnumber Course module idnumber.
     */
    public function i_open_the_cmi5_launch_page_without_posting(string $idnumber): void {
        $cm = $this->cm($idnumber);
        $this->execute('behat_general::i_visit', [new moodle_url('/mod/scormmakercmi5/launch.php', ['id' => $cm->id])]);
    }

    /**
     * Opens player.php with the last launch session of a user in the activity (as if its URL had been shared).
     *
     * @When /^I open the cmi5 player of the last session of "(?P<username>(?:[^"]|\\")*)" in "(?P<idnumber>(?:[^"]|\\")*)"$/
     * @param string $username User of the session.
     * @param string $idnumber Course module idnumber.
     */
    public function i_open_the_cmi5_player_of_the_last_session_of(string $username, string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $uuid = $DB->get_field_sql(
            'SELECT s.sessionuuid FROM {scormmakercmi5_session} s JOIN {scormmakercmi5_reg} r ON r.id = s.regid
              WHERE r.scormmakercmi5id = ? AND s.userid = ? ORDER BY s.id DESC',
            [$cm->instance, $userid],
            IGNORE_MULTIPLE
        );
        if (!$uuid) {
            throw new ExpectationException("{$username} has no session", $this->getSession());
        }
        $url = new moodle_url('/mod/scormmakercmi5/player.php', ['id' => $cm->id, 's' => $uuid]);
        $this->execute('behat_general::i_visit', [$url]);
    }

    /**
     * Hides the activity, or restricts it to a date in the future, after the page was opened.
     *
     * @Given /^the "(?P<idnumber_string>(?:[^"]|\\")*)" cmi5 activity is (?P<how_string>hidden|available only from tomorrow)$/
     * @param string $idnumber Course module idnumber.
     * @param string $how hidden or available only from tomorrow.
     */
    public function the_cmi5_activity_is(string $idnumber, string $how): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $cm = $this->cm($idnumber);
        if ($how === 'hidden') {
            set_coursemodule_visible($cm->id, 0);
        } else {
            $availability = ['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]], 'showc' => [true]];
            $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $cm->id]);
        }
        rebuild_course_cache($cm->course, true);
    }

    /**
     * Enables the guest access enrolment method of a course.
     *
     * @Given /^guest access is enabled in course "(?P<shortname_string>(?:[^"]|\\")*)"$/
     * @param string $shortname Course short name.
     */
    public function guest_access_is_enabled_in_course(string $shortname): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $plugin = enrol_get_plugin('guest');
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'guest']);
        if (!$instance) {
            $plugin->add_instance(get_course($courseid), ['status' => ENROL_INSTANCE_ENABLED]);
        } else {
            $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
        }
    }

    /**
     * Counts the launch sessions of a user in the activity (not the synthetic session of a satisfied statement).
     *
     * @Then /^"(?P<username>(?:[^"]|\\")*)" should have (?P<count>\d+) cmi5 sessions? in "(?P<idnumber>(?:[^"]|\\")*)"$/
     * @param string $username User.
     * @param int $count Expected number.
     * @param string $idnumber Course module idnumber.
     */
    public function user_should_have_cmi5_sessions(string $username, int $count, string $idnumber): void {
        global $DB;
        $cm = $this->cm($idnumber);
        $userid = (int)$DB->get_field('user', 'id', ['username' => $username]);
        $actual = (int)$DB->count_records_sql(
            'SELECT COUNT(1) FROM {scormmakercmi5_session} s JOIN {scormmakercmi5_reg} r ON r.id = s.regid
              WHERE r.scormmakercmi5id = ? AND s.userid = ? AND s.kind = ?',
            [$cm->instance, $userid, \mod_scormmakercmi5\local\runtime\launcher::KIND_LAUNCH]
        );
        if ($actual !== (int)$count) {
            throw new ExpectationException("{$username} has {$actual} sessions, not {$count}", $this->getSession());
        }
    }

    /**
     * The AU puts its own page in full screen from a button of its own, clicked for real (full screen needs a user
     * gesture), as the video and 360° blocks of SCORM Maker do.
     *
     * @When /^the AU in the cmi5 player goes full screen by itself$/
     */
    public function the_au_goes_full_screen_by_itself(): void {
        $this->execute_script(<<<'EOF'
(() => {
    const frame = document.querySelector('[data-region="mod_scormmakercmi5-frame"]');
    frame.name = 'cmi5au';
    const doc = frame.contentDocument;
    const button = doc.createElement('button');
    button.type = 'button';
    button.id = 'aufullscreen';
    button.textContent = 'AU full screen';
    button.addEventListener('click', () => doc.documentElement.requestFullscreen());
    doc.body.append(button);
})();
EOF);
        $this->getSession()->switchToIFrame('cmi5au');
        $this->find('css', '#aufullscreen')->click();
        $this->getSession()->switchToIFrame(null);
        $this->wait_until_au_fullscreen(true);
    }

    /**
     * The AU leaves its own full screen (the player region stays in full screen if it was).
     *
     * @When /^the AU in the cmi5 player leaves full screen$/
     */
    public function the_au_leaves_full_screen(): void {
        $this->execute_script(
            "document.querySelector('[data-region=\"mod_scormmakercmi5-frame\"]').contentDocument.exitFullscreen();"
        );
        $this->wait_until_au_fullscreen(false);
    }

    /**
     * Waits until the iframe of the AU is (or is no longer) the full screen element of the player page, then lets
     * its handlers run. The player region may stay in full screen under it.
     *
     * @param bool $on Expected state.
     */
    private function wait_until_au_fullscreen(bool $on): void {
        $op = $on ? '===' : '!==';
        $script = "return document.fullscreenElement {$op} "
            . "document.querySelector('[data-region=\"mod_scormmakercmi5-frame\"]');";
        for ($i = 0; $i < self::get_extended_timeout() * 10; $i++) {
            if ($this->evaluate_script($script)) {
                usleep(300000);
                return;
            }
            usleep(100000);
        }
        throw new ExpectationException('The full screen state of the AU did not change', $this->getSession());
    }

    /**
     * The focus is inside the AU (the iframe is the active element of the player page).
     *
     * @Then /^the focus should be in the cmi5 player frame$/
     */
    public function the_focus_should_be_in_the_cmi5_player_frame(): void {
        $where = $this->evaluate_script(
            "return document.activeElement ? (document.activeElement.dataset.region || document.activeElement.tagName) : '';"
        );
        if ($where !== 'mod_scormmakercmi5-frame') {
            throw new ExpectationException("The focus is on {$where}, not in the AU", $this->getSession());
        }
    }

    /**
     * Loads axe into the (same-origin) iframe of the player, so that "the page should meet accessibility standards"
     * also checks the AU: axe checks frames only where it runs, and otherwise waits for the frame until it times out.
     *
     * @Given /^axe is loaded into the cmi5 player frame$/
     */
    public function axe_is_loaded_into_the_cmi5_player_frame(): void {
        $axeurl = json_encode((new moodle_url('/lib/behat/axe/axe.min.js'))->out(false));
        $this->execute_script(<<<EOF
(() => {
    const frame = document.querySelector('[data-region="mod_scormmakercmi5-frame"]');
    const script = frame.contentDocument.createElement('script');
    script.src = {$axeurl};
    frame.contentDocument.head.append(script);
})();
EOF);
        $loaded = "return !!document.querySelector('[data-region=\"mod_scormmakercmi5-frame\"]').contentWindow.axe;";
        for ($i = 0; $i < self::get_extended_timeout() * 10; $i++) {
            if ($this->evaluate_script($loaded)) {
                return;
            }
            usleep(100000);
        }
        throw new ExpectationException('axe did not load in the player frame', $this->getSession());
    }

    /**
     * The (same-origin) AU of the player answers the request to close the way the SCORM Maker player does (final
     * review I-1): only from its parent and only from the parent's exact origin, it notes the request in the session
     * storage of the tab, then answers with the same id 300 ms later.
     *
     * @Given /^the AU in the cmi5 player answers the request to close$/
     */
    public function the_au_answers_the_request_to_close(): void {
        $this->execute_script(<<<'EOF'
(() => {
    const frame = document.querySelector('[data-region="mod_scormmakercmi5-frame"]');
    const doc = frame.contentDocument;
    const script = doc.createElement('script');
    script.textContent = `
        window.addEventListener('message', (e) => {
            if (e.source !== window.parent || e.origin !== window.location.origin) {
                return;
            }
            const d = e.data;
            if (!d || d.type !== 'scormmaker:exit' || typeof d.id !== 'string') {
                return;
            }
            sessionStorage.setItem('smcmi5-exit', JSON.stringify(d));
            setTimeout(() => window.parent.postMessage({type: 'scormmaker:exit-done', id: d.id}, e.origin), 300);
        });
        document.documentElement.dataset.exitready = '1';`;
    doc.head.append(script);
})();
EOF);
        $ready = "return document.querySelector('[data-region=\"mod_scormmakercmi5-frame\"]')"
            . ".contentDocument.documentElement.dataset.exitready === '1';";
        for ($i = 0; $i < self::get_extended_timeout() * 10; $i++) {
            if ($this->evaluate_script($ready)) {
                return;
            }
            usleep(100000);
        }
        throw new ExpectationException('The AU did not start listening', $this->getSession());
    }

    /**
     * Clicks "Back to the activity" of the player and measures how long the activity page took to open: about the
     * answer of the AU when it answers the request to close, the timeout of the player module (4 s) when it does not.
     *
     * @When /^I leave the cmi5 player by its back link, which takes between (\d+(?:\.\d+)?) and (\d+(?:\.\d+)?) seconds$/
     * @param string $min Least seconds.
     * @param string $max Most seconds.
     */
    public function i_leave_the_cmi5_player_by_its_back_link(string $min, string $max): void {
        $link = $this->find('css', '[data-region="mod_scormmakercmi5-back"]');
        $start = microtime(true);
        $link->click();
        $elapsed = null;
        for ($i = 0; $i < 300; $i++) {
            if (strpos($this->getSession()->getCurrentUrl(), '/mod/scormmakercmi5/view.php') !== false) {
                $elapsed = microtime(true) - $start;
                break;
            }
            usleep(50000);
        }
        if ($elapsed === null) {
            throw new ExpectationException('The activity page did not open', $this->getSession());
        }
        if ($elapsed < (float)$min || $elapsed > (float)$max) {
            $message = sprintf('The activity page opened after %.2f s, not between %s and %s s', $elapsed, $min, $max);
            throw new ExpectationException($message, $this->getSession());
        }
        $this->wait_for_pending_js();
    }

    /**
     * The AU got the request to close from the player page (noted in the session storage of the tab, same origin).
     *
     * @Then /^the cmi5 AU should have received the request to close$/
     */
    public function the_cmi5_au_should_have_received_the_request_to_close(): void {
        $note = $this->evaluate_script("return sessionStorage.getItem('smcmi5-exit');");
        $data = json_decode((string)$note, true);
        $valid = is_array($data) && ($data['type'] ?? '') === 'scormmaker:exit' && is_string($data['id'] ?? null)
            && $data['id'] !== '';
        if (!$valid) {
            throw new ExpectationException('The AU got no request to close: ' . var_export($note, true), $this->getSession());
        }
    }

    /**
     * The player page becomes visible again (the learner comes back to its tab): the browser sends visibilitychange,
     * which a WebDriver window switch does not do by itself.
     *
     * @When /^I return to the cmi5 player$/
     */
    public function i_return_to_the_cmi5_player(): void {
        $this->execute_script("document.dispatchEvent(new Event('visibilitychange'));");
        $this->wait_for_pending_js();
    }
}
