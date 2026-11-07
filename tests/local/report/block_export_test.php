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

use mod_scormmakercmi5\local\package\analysis_structure;
use mod_scormmakercmi5\local\signature\verification_result;

defined('MOODLE_INTERNAL') || die();

require_once(\core_component::get_component_directory('mod_scormmakercmi5') . '/tests/fixtures/contract.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\report\block_export::class)]
/**
 * Tests for the detailed download of the block activity: the nine tables of the contract (planilha).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\report\block_export
 */
final class block_export_test extends \advanced_testcase {
    /**
     * The structure of a case as the import stores it and block_report::structure() reads it back.
     *
     * @param array $case Case of the contract.
     * @return array
     */
    private static function stored_structure(array $case): array {
        $stored = analysis_structure::encode(analysis_structure::from_json(
            \mod_scormmakercmi5_test_contract::encode($case['estrutura']),
            ['media/m1.jpg']
        ));
        return analysis_structure::decode($stored);
    }

    /**
     * Cases with "planilha".
     *
     * @return array
     */
    public static function sheet_case_provider(): array {
        $out = [];
        foreach (\mod_scormmakercmi5_test_contract::data()['casos'] as $c) {
            if (array_key_exists('planilha', $c)) {
                $out[$c['nome']] = [$c['nome']];
            }
        }
        return $out;
    }

    /**
     * Every table of the contract cases with "planilha", row by row (numbers by value, text never a number), from the
     * structure of the contract and from the structure as the import stores it.
     *
     * @dataProvider sheet_case_provider
     * @param string $name Case name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sheet_case_provider')]
    public function test_tables_from_the_contract(string $name): void {
        $case = \mod_scormmakercmi5_test_contract::case_named($name);
        foreach ([$case['estrutura'], self::stored_structure($case)] as $structure) {
            $tables = block_export::tables($structure, $case['atividade']);
            $this->assertSame(block_export::TABLES, array_column($tables, 'id'));
            foreach ($tables as $t) {
                $this->assertSame(
                    \mod_scormmakercmi5_test_contract::by_value($case['planilha'][$t['id']]),
                    \mod_scormmakercmi5_test_contract::by_value($t['rows']),
                    "{$name} {$t['id']}"
                );
                foreach ($t['rows'] as $row) {
                    $this->assertCount(count($t['columns']), $row, "{$name} {$t['id']}");
                }
            }
        }
    }

    /**
     * Four cases have "planilha" (the provider must not shrink by accident).
     */
    public function test_sheet_cases(): void {
        $this->assertSame(
            ['turma-de-tres', 'blocos-aninhados', 'aninhados-fora-de-ordem', 'planilha-desempates'],
            array_keys(self::sheet_case_provider())
        );
    }

    /**
     * The pt_br strings are the headers of the contract (read from the file: the pt_br pack may not be installed).
     */
    public function test_pt_br_headers_are_the_contract(): void {
        $string = [];
        include(\core_component::get_component_directory('mod_scormmakercmi5') . '/lang/pt_br/scormmakercmi5.php');
        $headers = \mod_scormmakercmi5_test_contract::data()['cabecalhosDaPlanilha'];
        $this->assertSame(block_export::TABLES, array_keys($headers));
        foreach ($headers as $id => $h) {
            $this->assertSame($h['nome'], $string['richsheet' . $id], $id);
            foreach ($h['colunas'] as $n => $col) {
                $this->assertSame($col, $string['richcol' . $id . ($n + 1)], "{$id} {$n}");
            }
            $this->assertArrayNotHasKey('richcol' . $id . (count($h['colunas']) + 1), $string, $id);
        }
    }

    /**
     * The names and columns of the tables are the strings of the language of the user (English here), as many as the
     * contract has.
     */
    public function test_names_and_columns_are_strings(): void {
        $headers = \mod_scormmakercmi5_test_contract::data()['cabecalhosDaPlanilha'];
        $case = \mod_scormmakercmi5_test_contract::case_named('turma-de-tres');
        foreach (block_export::tables($case['estrutura'], $case['atividade']) as $t) {
            $this->assertSame(get_string('richsheet' . $t['id'], 'mod_scormmakercmi5'), $t['name']);
            $this->assertCount(count($headers[$t['id']]['colunas']), $t['columns'], $t['id']);
            $this->assertSame(get_string('richcol' . $t['id'] . '1', 'mod_scormmakercmi5'), $t['columns'][0]);
        }
        $this->assertSame('Blocks', block_export::tables($case['estrutura'], $case['atividade'])[0]['name']);
        $this->assertSame('Heat maps', block_export::tables($case['estrutura'], $case['atividade'])[8]['name']);
    }

    /**
     * Formula guard: text starting with = + - @ tab or CR gets an apostrophe; numbers stay numbers.
     */
    public function test_safe_cell(): void {
        $this->assertSame("'=HYPERLINK(\"x\")", block_export::safe_cell('=HYPERLINK("x")'));
        foreach (['+1', '-1', '@a', "\tx", "\rx"] as $text) {
            $this->assertSame("'" . $text, block_export::safe_cell($text));
        }
        $this->assertSame('Abas 1', block_export::safe_cell('Abas 1'));
        $this->assertSame('', block_export::safe_cell(''));
        $this->assertSame(-3, block_export::safe_cell(-3));
        $this->assertSame(1.5, block_export::safe_cell(1.5));
        $this->assertSame(["'=1", 67, 7.5, ''], block_export::guard(['=1', 67, 7.5, '']));
    }

    /**
     * Only csv, excel and ods are accepted, whatever else the site has enabled.
     */
    public function test_formats(): void {
        $this->assertSame(['csv', 'excel', 'ods'], block_export::FORMATS);
        foreach (['csv', 'excel', 'ods'] as $format) {
            $this->assertTrue(block_export::accepts($format), $format);
        }
        foreach (['html', 'pdf', 'json', '', 'CSV', '../csv'] as $format) {
            $this->assertFalse(block_export::accepts($format), $format);
        }
    }

    /**
     * Ids of all digits are text in the sheet: a block with no label is named by its id, as a string.
     */
    public function test_blocks_with_numeric_ids(): void {
        $case = \mod_scormmakercmi5_test_contract::data()['casos'][2];
        $structure = self::stored_structure($case);
        $rows = block_export::tables($structure, $case['atividade'])[0]['rows'];
        $this->assertSame([['Lição 1', 'Vídeo 123', 100, 5.0, 0.0, 15], ['Lição 1', 'Cartões 456', 50, 3.0, 1.0, '']], $rows);
        $structure['blocos']['456']['rotulo'] = '';
        $structure['licoes'][0]['titulo'] = '';
        $rows = block_export::tables($structure, $case['atividade'])[0]['rows'];
        $this->assertSame('456', $rows[1][1]);
        $this->assertSame('l1', $rows[1][0]);
    }

    /**
     * The case turma-de-tres with a formula at the start of every text that comes from the package or the learners:
     * lesson titles, block, item and scene labels, options and endings. The first item of each block starts with a tab
     * instead: Moodle's writers already escape = + - @ (also after blanks), so only a tab or CR at the start shows
     * that the guard of the export runs.
     *
     * @return array [structure, aggregates]
     */
    private static function formula_case(): array {
        $case = \mod_scormmakercmi5_test_contract::case_named('turma-de-tres');
        $s = $case['estrutura'];
        $a = $case['atividade'];
        foreach ($s['licoes'] as &$lesson) {
            $lesson['titulo'] = '=L(' . $lesson['titulo'] . ')';
        }
        foreach ($s['blocos'] as &$block) {
            $block['rotulo'] = '+' . $block['rotulo'];
            foreach ($block['itens'] as $n => &$item) {
                $item['rotulo'] = ($n === 0 ? "\t" : '-') . $item['rotulo'];
            }
            foreach ($block['cenas'] as &$scene) {
                $scene['titulo'] = '@' . $scene['titulo'];
            }
        }
        unset($lesson, $block, $item, $scene);
        foreach ($a['blocos'] as &$agg) {
            if (isset($agg['finais'])) {
                $agg['finais'] = array_combine(array_map(fn($k) => '=' . $k, array_keys($agg['finais'])), $agg['finais']);
            }
            foreach ($agg['escolhas'] ?? [] as $itemid => $options) {
                $agg['escolhas'][$itemid] = array_combine(array_map(fn($k) => '=' . $k, array_keys($options)), $options);
            }
        }
        unset($agg);
        return [$s, $a];
    }

    /**
     * Every table is guarded: in the zip of the CSV download, every text cell that starts with a formula character, a
     * tab or a CR is written with an apostrophe in front, in every table.
     */
    public function test_every_table_is_guarded(): void {
        $this->resetAfterTest();
        [$structure, $aggregates] = self::formula_case();
        $tables = block_export::tables($structure, $aggregates);
        $files = self::zip_entries(block_export::csv_zip($tables));
        $guarded = [];
        $tabs = 0;
        foreach ($tables as $t) {
            $this->assertNotEmpty($t['rows'], $t['id']);
            $written = self::csv_rows($files[$t['id'] . '.csv']);
            $this->assertSame($t['columns'], $written[0], $t['id']);
            $this->assertCount(count($t['rows']) + 1, $written, $t['id']);
            foreach ($t['rows'] as $r => $row) {
                foreach ($row as $c => $cell) {
                    if (is_string($cell) && $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false) {
                        $this->assertSame("'" . $cell, $written[$r + 1][$c], "{$t['id']} {$r} {$c}");
                        $guarded[$t['id']][$c] = true;
                        $tabs += (int)($cell[0] === "\t");
                    }
                }
            }
        }
        // The lesson and the block label in every table; items, scenes, options and endings in theirs.
        $this->assertSame(block_export::TABLES, array_keys($guarded));
        foreach ($guarded as $id => $columns) {
            $this->assertArrayHasKey(0, $columns, $id);
            $this->assertArrayHasKey(1, $columns, $id);
        }
        foreach (['itens', 'escolhas', 'finais', 'abandono', 'cartoes', 'pontos', 'mapas'] as $id) {
            $this->assertArrayHasKey(2, $guarded[$id], $id);
        }
        $this->assertArrayHasKey(3, $guarded['escolhas']);
        $this->assertGreaterThan(0, $tabs);
    }

    /**
     * Name => contents of each entry of a zip.
     *
     * @param string $path Zip.
     * @return array
     */
    private static function zip_entries(string $path): array {
        $zip = new \ZipArchive();
        $zip->open($path);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        return $out;
    }

    /**
     * The rows of a CSV text written by \core\dataformat (header first; the BOM removed).
     *
     * @param string $csv Contents.
     * @return array
     */
    private static function csv_rows(string $csv): array {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, preg_replace('~^\xEF\xBB\xBF~', '', $csv));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    /**
     * The CSV download is a zip with one file per table, each with its header and its rows.
     */
    public function test_csv_is_a_zip_of_tables(): void {
        $this->resetAfterTest();
        $case = \mod_scormmakercmi5_test_contract::case_named('blocos-aninhados');
        $tables = block_export::tables($case['estrutura'], $case['atividade']);
        $zip = block_export::csv_zip($tables);
        $this->assertSame('tables.zip', basename($zip));
        $files = get_file_packer('application/zip')->list_files($zip);
        $this->assertEqualsCanonicalizing(
            array_map(fn($id) => "{$id}.csv", block_export::TABLES),
            array_column(array_map(fn($f) => (array)$f, $files), 'pathname')
        );
        $entries = self::zip_entries($zip);
        foreach ($tables as $t) {
            $rows = self::csv_rows($entries[$t['id'] . '.csv']);
            $this->assertSame($t['columns'], array_shift($rows), $t['id']);
            $this->assertCount(count($t['rows']), $rows, $t['id']);
        }
        $blocks = self::csv_rows($entries['blocos.csv']);
        $this->assertSame(['Lição 1', 'Mapa 1 › Vídeo dentro', '100', '22.5', '0', '75'], $blocks[2]);
        $this->assertSame([['Lição 1', 'Mapa 1', '', '3', '4', '1']], array_slice(self::csv_rows($entries['mapas.csv']), 1));
        $this->assertSame([$tables[3]['columns']], self::csv_rows($entries['escolhas.csv']), 'an empty table keeps its header');
    }

    /**
     * The files are written under a fixed name, never the name of the activity: a long accented name (over 255 bytes,
     * the limit of a file name on Linux) still downloads. The name of the activity goes only to the download, clean
     * of path characters, with the extension of the format.
     */
    public function test_long_accented_name_downloads(): void {
        $this->resetAfterTest();
        $case = \mod_scormmakercmi5_test_contract::case_named('blocos-aninhados');
        $tables = block_export::tables($case['estrutura'], $case['atividade']);
        $name = str_repeat('Avaliação de ações ', 12) . '- Atividade dentro das lições';
        $this->assertGreaterThan(255, strlen($name));
        foreach (['csv' => '.zip', 'excel' => '.xlsx', 'ods' => '.ods'] as $format => $ext) {
            $path = $format === 'csv' ? block_export::csv_zip($tables) : block_export::workbook($format, $tables);
            $this->assertFileExists($path, $format);
            $this->assertSame('tables' . $ext, basename($path), $format);
            $this->assertSame(clean_filename($name) . $ext, block_export::download_name($name, $format), $format);
        }
        $this->assertSame(clean_filename('../a/b:c') . '.zip', block_export::download_name('../a/b:c', 'csv'));
        $this->assertStringNotContainsString('/', block_export::download_name('../a/b:c', 'csv'));
    }

    /**
     * A table id that is not one of TABLES never becomes a file name.
     */
    public function test_unknown_table_is_refused(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        block_export::csv_zip([['id' => '../evil', 'name' => 'x', 'columns' => ['a'], 'rows' => [['b']]]]);
    }

    /**
     * Excel and ODS: one file, one sheet per table, in order, with the names of the tables; every text guarded.
     */
    public function test_workbook_has_one_sheet_per_table(): void {
        $this->resetAfterTest();
        [$structure, $aggregates] = self::formula_case();
        $tables = block_export::tables($structure, $aggregates);
        $names = array_column($tables, 'name');
        foreach (['excel' => '.xlsx', 'ods' => '.ods'] as $format => $ext) {
            $file = block_export::workbook($format, $tables);
            $this->assertSame('tables' . $ext, basename($file));
            $sheets = self::sheets($file, $format);
            $this->assertSame($names, array_keys($sheets), $format);
            foreach ($sheets as $name => $text) {
                $this->assertStringContainsString("'=L(Lição 1)", $text, "{$format} {$name}");
                $this->assertStringNotContainsString('<f>', $text, "{$format} {$name}");
                $this->assertStringNotContainsString('table:formula', $text, "{$format} {$name}");
            }
            $this->assertStringContainsString("'+Vídeo 1", $sheets[$names[0]], $format);
            $this->assertStringContainsString("'=Final feliz", $sheets[$names[4]], $format);
        }
    }

    /**
     * Numbers are number cells in the workbook and text stays text, also a text of digits ("7", a key of the
     * aggregate): Moodle's writers turn every value into text (\core\dataformat::escape_spreadsheet_formula returns
     * ?string), and the export must not.
     */
    public function test_workbook_numbers_are_numbers(): void {
        $this->resetAfterTest();
        $tables = [['id' => 'blocos', 'name' => 'T', 'columns' => ['A', 'B', 'C', 'D'], 'rows' => [['x', 7, 2.5, '7']]]];
        $xlsx = self::sheets(block_export::workbook('excel', $tables), 'excel')['T'];
        $this->assertMatchesRegularExpression('~<c r="B2"[^>]*><v>7</v></c>~', $xlsx);
        $this->assertMatchesRegularExpression('~<c r="C2"[^>]*><v>2.5</v></c>~', $xlsx);
        $this->assertMatchesRegularExpression('~<c r="D2"[^>]*t="inlineStr"><is><t>7</t></is></c>~', $xlsx);
        $ods = self::sheets(block_export::workbook('ods', $tables), 'ods')['T'];
        $this->assertMatchesRegularExpression('~office:value-type="float"[^>]*office:value="7"~', $ods);
        $this->assertMatchesRegularExpression('~office:value-type="float"[^>]*office:value="2\.5"~', $ods);
        $this->assertMatchesRegularExpression('~office:value-type="string"[^>]*><text:p>7</text:p>~', $ods);
        // Text is still guarded by the writer of Moodle too (a blank before the formula).
        $tables[0]['rows'] = [[' =1+1', 1, 1, '']];
        $this->assertStringContainsString("' =1+1", self::sheets(block_export::workbook('excel', $tables), 'excel')['T']);
    }

    /**
     * Name => XML text of each sheet of an xlsx or ods file (entities decoded).
     *
     * @param string $file File.
     * @param string $format excel or ods.
     * @return array
     */
    private static function sheets(string $file, string $format): array {
        $zip = new \ZipArchive();
        $zip->open($file);
        $out = [];
        if ($format === 'excel') {
            $workbook = new \SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
            $n = 0;
            foreach ($workbook->sheets->sheet as $sheet) {
                $n++;
                $out[(string)$sheet['name']] = html_entity_decode(
                    $zip->getFromName("xl/worksheets/sheet{$n}.xml"),
                    ENT_QUOTES | ENT_XML1,
                    'UTF-8'
                );
            }
        } else {
            $content = $zip->getFromName('content.xml');
            preg_match_all('~<table:table [^>]*table:name="([^"]*)".*?</table:table>~s', $content, $m, PREG_SET_ORDER);
            foreach ($m as $table) {
                $out[html_entity_decode($table[1], ENT_QUOTES | ENT_XML1, 'UTF-8')] =
                    html_entity_decode($table[0], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $zip->close();
        return $out;
    }

    /**
     * Sheet names are at most 31 characters, without the characters a spreadsheet refuses, and unique (a translation
     * may give two tables the same name or a long one).
     */
    public function test_sheet_names_are_safe_and_unique(): void {
        $this->resetAfterTest();
        $long = str_repeat('Ç', 40);
        $tables = [];
        foreach (block_export::TABLES as $n => $id) {
            $name = match (true) {
                $n < 3 => 'Same',
                $n < 5 => $long,
                $n === 5 => "a[b]c*d/e\\f?g:h",
                default => 'same',
            };
            $tables[] = ['id' => $id, 'name' => $name, 'columns' => ['A'], 'rows' => [['x']]];
        }
        $names = block_export::sheet_names($tables);
        $this->assertCount(9, $names);
        $this->assertCount(9, array_unique(array_map('core_text::strtolower', $names)));
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(31, \core_text::strlen($name), $name);
            $this->assertDoesNotMatchRegularExpression('~[\[\]*/\\\\?:]~', $name);
            $this->assertNotSame('', $name);
        }
        $this->assertSame('Same', $names[0]);
        $this->assertSame(\core_text::substr($long, 0, 31), $names[3]);
        foreach (['excel', 'ods'] as $format) {
            $this->assertSame($names, array_keys(self::sheets(block_export::workbook($format, $tables), $format)));
        }
    }

    /**
     * The text of a cell as Excel reads it: each _xHHHH_ (any case) is the character U+HHHH, left to right.
     *
     * @param string $text Text inside <t>.
     * @return string
     */
    private static function excel_reads(string $text): string {
        return preg_replace_callback('~_x([0-9A-Fa-f]{4})_~', fn($m) => \core_text::code2utf8(hexdec($m[1])), $text);
    }

    /**
     * A literal _xHHHH_ in a label reaches Excel as the text itself, not as the character it encodes (the writer
     * escapes the sequences it knows; the export escapes the ones it misses: lowercase hex).
     */
    public function test_excel_keeps_literal_escape_sequences(): void {
        $this->resetAfterTest();
        $texts = ['_x0041_', 'a_x00e9_b', '_x0041__x0042_', '_x00e9_x0041_', '_x0041_x00e9_', '_x00e9__x00ea_',
            '_x00e9_x00ea_', '_x00E9_', '_x005F_', '_x005f_', 'x_x_1234', '_x12345_'];
        $tables = [['id' => 'blocos', 'name' => 'T', 'columns' => ['A'], 'rows' => array_map(fn($t) => [$t], $texts)]];
        $sheet = self::sheets(block_export::workbook('excel', $tables), 'excel')['T'];
        preg_match_all('~<t[^>]*>(.*?)</t>~s', $sheet, $m);
        $this->assertSame(array_merge(['A'], $texts), array_map([self::class, 'excel_reads'], $m[1]));
        // The CSV and the ODS keep the text as it is.
        $this->assertSame(['_x00e9_'], block_export::cells_for('csv', ['_x00e9_']));
        $this->assertSame(['_x00e9_'], block_export::cells_for('ods', ['_x00e9_']));
    }

    /**
     * A course with an activity, and a stored package row of it with the structure of the contract.
     *
     * @param int $groupmode Group mode of the activity.
     * @return array [course, cm_info, pkg].
     */
    private function activity(int $groupmode = NOGROUPS): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('scormmakercmi5', ['course' => $course->id,
            'groupmode' => $groupmode]);
        $cm = get_fast_modinfo($course->id)->get_cm($instance->cmid);
        $pkg = (object)[
            'scormmakercmi5id' => (int)$instance->id,
            'richstructure' => analysis_structure::encode(analysis_structure::from_json(
                json_encode(\mod_scormmakercmi5_test_contract::case_named('turma-de-tres')['estrutura']),
                ['media/m1.jpg']
            )),
        ];
        return [$course, $cm, $pkg];
    }

    /**
     * A verified package gives the nine tables (blocks with zeros, the others empty: nobody posted yet).
     */
    public function test_sheet_of_a_verified_package(): void {
        [, $cm, $pkg] = $this->activity();
        $tables = block_export::sheet($cm, $pkg, new verification_result(verification_result::STATUS_VERIFIED), '1.0.0', 0);
        $this->assertSame(block_export::TABLES, array_column($tables, 'id'));
        $this->assertCount(8, $tables[0]['rows']);
        $this->assertSame(['Lição 1', 'Vídeo 1', 0, 0.0, 0.0, ''], $tables[0]['rows'][0]);
        foreach (array_slice($tables, 1) as $t) {
            $this->assertSame([], $t['rows'], $t['id']);
        }
    }

    /**
     * An unverified package (unsigned, invalid) or one without structure gets no export.
     */
    public function test_sheet_of_an_unverified_package(): void {
        [, $cm, $pkg] = $this->activity();
        $unsigned = new verification_result(verification_result::STATUS_UNSIGNED);
        $this->assertNull(block_export::sheet($cm, $pkg, $unsigned, '1.0.0', 0));
        $invalid = new verification_result(verification_result::STATUS_INVALID, 'filechanged');
        $this->assertNull(block_export::sheet($cm, $pkg, $invalid, '1.0.0', 0));
        $verified = new verification_result(verification_result::STATUS_VERIFIED);
        $this->assertNull(block_export::sheet($cm, (object)['scormmakercmi5id' => $pkg->scormmakercmi5id,
            'richstructure' => null], $verified, '1.0.0', 0));
    }

    /**
     * A package of another activity is refused.
     */
    public function test_sheet_of_another_activity(): void {
        [, $cm, $pkg] = $this->activity();
        $pkg->scormmakercmi5id = (int)$pkg->scormmakercmi5id + 1;
        $this->expectException(\coding_exception::class);
        block_export::sheet($cm, $pkg, new verification_result(verification_result::STATUS_VERIFIED), '1.0.0', 0);
    }

    /**
     * A learner (no viewreports) is refused.
     */
    public function test_sheet_denied_to_a_learner(): void {
        [$course, $cm, $pkg] = $this->activity();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->expectException(\required_capability_exception::class);
        block_export::sheet($cm, $pkg, new verification_result(verification_result::STATUS_VERIFIED), '1.0.0', 0);
    }

    /**
     * Separate groups: a teacher without accessallgroups asking for a group of others is refused.
     */
    public function test_sheet_denied_for_another_group(): void {
        [$course, $cm, $pkg] = $this->activity(SEPARATEGROUPS);
        $gen = $this->getDataGenerator();
        $mine = $gen->create_group(['courseid' => $course->id]);
        $others = $gen->create_group(['courseid' => $course->id]);
        $teacher = $gen->create_and_enrol($course, 'teacher');
        $gen->create_group_member(['groupid' => $mine->id, 'userid' => $teacher->id]);
        $this->setUser($teacher);
        $verified = new verification_result(verification_result::STATUS_VERIFIED);
        $this->assertCount(9, block_export::sheet($cm, $pkg, $verified, '1.0.0', (int)$mine->id));
        $this->expectException(\required_capability_exception::class);
        block_export::sheet($cm, $pkg, $verified, '1.0.0', (int)$others->id);
    }
}
