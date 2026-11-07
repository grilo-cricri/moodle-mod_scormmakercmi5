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

/**
 * Detailed download of the block activity (subproject D): the nine tables of the hosted course (contract: planilha
 * of the cases, cabecalhosDaPlanilha, regraDaPlanilha), in csv, excel and ods only, the formats of the basic report
 * (basic_table::DOWNLOAD_FORMATS: the html and pdf writers print cells unescaped), with its spreadsheet formula guard
 * (basic_table::spreadsheet_safe) on every cell of every table.
 *
 * Excel and ODS: one file, one sheet per table. CSV: a zip with one <id>.csv per table. Aggregates of the class only:
 * no learner, no sequence. The rows are the ones of tabelasDaPlanilha of SCORM Maker, row by row.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class block_export {
    /** @var string[] Accepted data formats. */
    public const FORMATS = basic_table::DOWNLOAD_FORMATS;

    /** @var string[] Ids of the tables, in the order of the sheets (contract: cabecalhosDaPlanilha). */
    public const TABLES = ['blocos', 'itens', 'retencao', 'escolhas', 'finais', 'abandono', 'cartoes', 'pontos', 'mapas'];

    /** @var int[] Number of columns of each table (strings richcol<id>1..n). */
    private const COLUMNS = ['blocos' => 6, 'itens' => 5, 'retencao' => 7, 'escolhas' => 5, 'finais' => 4, 'abandono' => 4,
        'cartoes' => 6, 'pontos' => 4, 'mapas' => 6];

    /** @var int Longest sheet name of a spreadsheet. */
    private const SHEET_NAME_MAX = 31;

    /**
     * Whether a requested format is accepted: one of FORMATS that the site has enabled.
     *
     * @param string $format Requested format.
     * @return bool
     */
    public static function accepts(string $format): bool {
        return in_array($format, basic_table::download_formats(), true);
    }

    /**
     * A cell that a spreadsheet will not read as a formula (text through basic_table::spreadsheet_safe; numbers as is).
     *
     * @param mixed $value Value.
     * @return mixed
     */
    public static function safe_cell(mixed $value): mixed {
        return is_string($value) ? basic_table::spreadsheet_safe($value) : $value;
    }

    /**
     * A row with every cell guarded (the callback of \core\dataformat).
     *
     * @param array $row Row.
     * @return array
     */
    public static function guard(array $row): array {
        return array_map([self::class, 'safe_cell'], $row);
    }

    /**
     * Text of an xlsx cell that Excel reads back as written. Excel reads _xHHHH_ as the character U+HHHH, so the "_" of
     * a literal one must be written _x005F_. The xlsx writer of Moodle (OpenSpout) already does that, but only for
     * upper-case hex digits; a sequence with a lower-case one is escaped here, with a lower-case _x005f_ that the writer
     * leaves alone (an upper-case one it would escape a second time). Every "_" is looked at, so sequences side by side
     * or sharing their "_" are all seen. Known gap: two upper-case sequences sharing their "_" (_x0041_x0042_) lose the
     * second one in the writer, whatever is written here.
     *
     * @param string $text Text.
     * @return string
     */
    public static function excel_text(string $text): string {
        if (!str_contains($text, '_x')) {
            return $text;
        }
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if (
                $text[$i] === '_' && preg_match('~^_x([0-9A-Fa-f]{4})_~', substr($text, $i, 7), $m)
                && preg_match('~[a-f]~', $m[1])
            ) {
                $out .= '_x005f';
            }
            $out .= $text[$i];
        }
        return $out;
    }

    /**
     * The cells of a row as the writer of a format gets them: guarded, and escaped for xlsx.
     *
     * @param string $format One of FORMATS.
     * @param array $row Row.
     * @return array
     */
    public static function cells_for(string $format, array $row): array {
        $row = self::guard($row);
        if ($format !== 'excel') {
            return $row;
        }
        return array_map(static fn($v) => is_string($v) ? self::excel_text($v) : $v, $row);
    }

    /**
     * The blocks of the structure in the order of the report: lessons in order, blocks of each lesson in order and each
     * child right after its parent, labelled "Parent › Child" (blocosEmOrdem of SCORM Maker).
     *
     * @param array $structure Analysis structure.
     * @return array[] [lesson title, block id, label] each.
     */
    private static function ordered_blocks(array $structure): array {
        $out = [];
        foreach ($structure['licoes'] as $lesson) {
            $title = block_presenter::label((string)$lesson['titulo'], $lesson['id']);
            foreach (analysis_structure::lesson_blocks($structure, $lesson) as [$id, $parentid]) {
                $out[] = [$title, $id, block_presenter::block_label($structure, $id, $parentid)];
            }
        }
        return $out;
    }

    /**
     * Counts by learners (desc), ties in code point order of the text (strcmp; porContagem of SCORM Maker). A key of
     * digits only comes back from json_decode as an int: it is text again here.
     *
     * @param array $counts Text => learners.
     * @return array[] [text, learners] each.
     */
    private static function by_count(array $counts): array {
        $out = [];
        foreach ($counts as $text => $n) {
            $out[] = [(string)$text, $n];
        }
        usort($out, static fn(array $x, array $y): int => ($y[1] <=> $x[1]) ?: strcmp($x[0], $y[0]));
        return $out;
    }

    /**
     * Counts by item: the items of the structure first, in its order, then any other id in code point order
     * (naOrdemDosItens of SCORM Maker).
     *
     * @param array $block Block of the structure.
     * @param array $counts Item id => learners.
     * @return array[] [item id, learners] each.
     */
    private static function in_item_order(array $block, array $counts): array {
        $out = [];
        $known = [];
        foreach ($block['itens'] as $item) {
            $id = (string)$item['id'];
            $known[$id] = true;
            if (array_key_exists($id, $counts)) {
                $out[] = [$id, $counts[$id]];
            }
        }
        $rest = [];
        foreach ($counts as $id => $n) {
            if (!isset($known[(string)$id])) {
                $rest[] = [(string)$id, $n];
            }
        }
        usort($rest, static fn(array $x, array $y): int => strcmp($x[0], $y[0]));
        return array_merge($out, $rest);
    }

    /**
     * The nine tables of the detailed download, row by row the ones of tabelasDaPlanilha of SCORM Maker (contract:
     * regraDaPlanilha). Rows come only from the aggregates of the class.
     *
     * @param array $structure Analysis structure.
     * @param array $aggregates block_report::activity().
     * @return array[] [id, name, columns, rows] each, in the order of TABLES.
     */
    public static function tables(array $structure, array $aggregates): array {
        $rows = array_fill_keys(self::TABLES, []);
        foreach (self::ordered_blocks($structure) as [$lesson, $id, $name]) {
            $b = $structure['blocos'][$id] ?? null;
            $a = $aggregates['blocos'][$id] ?? null;
            $rows['blocos'][] = [
                $lesson,
                $name,
                (int)($a['percentualAlcance'] ?? 0),
                round((float)($a['tempoMedioMs'] ?? 0) / 1000, 1),
                (float)($a['interacoesPorAluno'] ?? 0),
                isset($a['midia']) ? (int)$a['midia']['percentualMedioAssistido'] : '',
            ];
            if ($b === null || $a === null) {
                continue;
            }
            // Cards and scenes have tables of their own; a block nobody reached gets no rows of zeros.
            if ($b['itens'] && !isset($a['sei']) && !isset($a['escolhas'])) {
                foreach ($b['itens'] as $item) {
                    $c = $a['itens'][(string)$item['id']] ?? [];
                    $rows['itens'][] = [$lesson, $name, block_presenter::label((string)$item['rotulo'], $item['id']),
                        $c['abriram'] ?? 0, $c['percentual'] ?? 0];
                }
            }
            if (isset($a['midia'])) {
                $m = $a['midia'];
                foreach (array_values($m['retencao']) as $i => $percent) {
                    $from = $i * $m['faixaSegundos'];
                    $rows['retencao'][] = [$lesson, $name, $from, min($from + $m['faixaSegundos'], $m['duracaoMidia']),
                        $percent, $m['pulados'][$i] ?? 0, $m['abandonos'][$i] ?? 0];
                }
            }
            if (isset($a['escolhas'])) {
                foreach ($b['itens'] as $item) {
                    $scene = block_presenter::label((string)$item['rotulo'], $item['id']);
                    foreach (self::by_count($a['escolhas'][(string)$item['id']] ?? []) as [$option, $n]) {
                        $rows['escolhas'][] = [$lesson, $name, $scene, $option, $n];
                    }
                }
            }
            foreach (self::by_count($a['finais'] ?? []) as [$ending, $n]) {
                $rows['finais'][] = [$lesson, $name, $ending, $n];
            }
            foreach (self::in_item_order($b, $a['abandonoPorCena'] ?? []) as [$itemid, $n]) {
                $rows['abandono'][] = [$lesson, $name, block_presenter::item_label($b, $itemid), $n];
            }
            if (isset($a['sei'])) {
                $cards = [];
                foreach ($a['sei'] as $itemid => $k) {
                    $cards[] = [block_presenter::item_label($b, (string)$itemid), $k['sei'], $k['naoSei'], $k['percentualSei']];
                }
                // Hardest first; ties in code point order of the label (strcmp, never numeric).
                usort($cards, static fn(array $x, array $y): int => ($x[3] <=> $y[3]) ?: strcmp($x[0], $y[0]));
                foreach ($cards as $card) {
                    $rows['cartoes'][] = array_merge([$lesson, $name], $card);
                }
            }
            if (isset($a['descobertos'])) {
                foreach ($b['itens'] as $item) {
                    $rows['pontos'][] = [$lesson, $name, block_presenter::label((string)$item['rotulo'], $item['id']),
                        $a['descobertos'][(string)$item['id']] ?? 0];
                }
            }
            // The 1-based cells of the report: without scene first, then the scenes in the order of the structure.
            if (isset($a['mapa'])) {
                foreach (block_presenter::grid_cells($a['mapa']['celulas'] ?? []) as $c) {
                    $rows['mapas'][] = [$lesson, $name, '', $c['row'], $c['col'], $c['count']];
                }
                foreach ($b['cenas'] as $scene) {
                    $title = block_presenter::label((string)$scene['titulo'], $scene['id']);
                    foreach (block_presenter::grid_cells($a['mapa']['cenas'][(string)$scene['id']] ?? []) as $c) {
                        $rows['mapas'][] = [$lesson, $name, $title, $c['row'], $c['col'], $c['count']];
                    }
                }
            }
        }
        $tables = [];
        foreach (self::TABLES as $id) {
            $columns = [];
            for ($n = 1; $n <= self::COLUMNS[$id]; $n++) {
                $columns[] = get_string('richcol' . $id . $n, 'mod_scormmakercmi5');
            }
            $tables[] = ['id' => $id, 'name' => get_string('richsheet' . $id, 'mod_scormmakercmi5'), 'columns' => $columns,
                'rows' => $rows[$id]];
        }
        return $tables;
    }

    /**
     * Sheet names a spreadsheet accepts: without \ / ? * : [ ], not starting or ending with an apostrophe, at most 31
     * characters, never empty (the id instead), and unique without regard to case (a translation may repeat a name).
     *
     * @param array $tables tables().
     * @return string[] One name per table, in order.
     */
    public static function sheet_names(array $tables): array {
        $names = [];
        $used = [];
        foreach ($tables as $t) {
            $base = trim(strtr((string)$t['name'], '[]*/\?:', '       '), " '");
            $base = trim(\core_text::substr($base, 0, self::SHEET_NAME_MAX), " '");
            $base = $base !== '' ? $base : self::table_id($t);
            $name = $base;
            for ($n = 2; isset($used[\core_text::strtolower($name)]); $n++) {
                $suffix = ' (' . $n . ')';
                $name = rtrim(\core_text::substr($base, 0, self::SHEET_NAME_MAX - strlen($suffix)), " '") . $suffix;
            }
            $used[\core_text::strtolower($name)] = true;
            $names[] = $name;
        }
        return $names;
    }

    /**
     * The id of a table, the name of its file inside the zip: one of TABLES, nothing else.
     *
     * @param array $table A table of tables().
     * @return string
     */
    private static function table_id(array $table): string {
        if (!in_array($table['id'] ?? null, self::TABLES, true)) {
            throw new \coding_exception('Unknown table');
        }
        return $table['id'];
    }

    /**
     * The tables of the download, with the checks of the page: viewreports, the group restriction (a group outside the
     * allowed ones is refused) and the structure of a package verified at this opening only.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $pkg scormmakercmi5_pkg row of the version (the recheck's).
     * @param verification_result $verification verifier::recheck() of that package.
     * @param string $smversion Package version.
     * @param int $groupid Group asked for (0 = all the viewer may see).
     * @return array|null tables(); null: no structure to export (not verified, or none).
     */
    public static function sheet(
        \cm_info $cm,
        \stdClass $pkg,
        verification_result $verification,
        string $smversion,
        int $groupid
    ): ?array {
        $context = \context_module::instance($cm->id);
        require_capability('mod/scormmakercmi5:viewreports', $context);
        if ((int)$pkg->scormmakercmi5id !== (int)$cm->instance) {
            throw new \coding_exception('The package is not of this activity');
        }
        $groupids = basic_table::restrict_groups($cm, $context, $groupid);
        $structure = block_report::structure($pkg, $verification);
        if ($structure === null) {
            return null;
        }
        $aggregates = block_report::activity((int)$cm->instance, $smversion, group_filter::from_restriction($groupids), $structure);
        return self::tables($structure, $aggregates);
    }

    /** @var string Name of the file on disk, without extension: never the activity name (a Linux file name has at most 255 bytes). */
    private const TEMP_NAME = 'tables';

    /**
     * The CSV download: a zip with one <id>.csv per table, each guarded.
     *
     * @param array $tables tables().
     * @return string Path of the zip (tables.zip in a request directory).
     */
    public static function csv_zip(array $tables): string {
        $files = [];
        foreach ($tables as $t) {
            $id = self::table_id($t);
            $files[$id . '.csv'] = \core\dataformat::write_data($id, 'csv', $t['columns'], $t['rows'], [self::class, 'guard']);
        }
        $zip = make_request_directory() . '/' . self::TEMP_NAME . '.zip';
        if (!get_file_packer('application/zip')->archive_to_pathname($files, $zip)) {
            throw new \moodle_exception('cannotcreatezip', 'error');
        }
        return $zip;
    }

    /**
     * The Excel or ODS download: one file, one sheet per table (sheet_names()), each row guarded (cells_for()), numbers as numbers.
     *
     * @param string $format excel or ods.
     * @param array $tables tables().
     * @return string Path of the file (tables.xlsx or tables.ods in a request directory).
     */
    public static function workbook(string $format, array $tables): string {
        if ($format !== 'excel' && $format !== 'ods') {
            throw new \coding_exception('Not a workbook format: ' . $format);
        }
        \core_php_time_limit::raise();
        // The writers of Moodle with numbers kept as numbers (typed_cells).
        $writer = $format === 'excel'
            ? new class extends \dataformat_excel\writer {
                use typed_cells;
            }
            : new class extends \dataformat_ods\writer {
                use typed_cells;
            };
        $path = make_request_directory() . '/' . self::TEMP_NAME . $writer->get_extension();
        $writer->set_filepath($path);
        $writer->start_output_to_file();
        foreach (self::sheet_names($tables) as $n => $name) {
            $t = $tables[$n];
            self::table_id($t);
            $writer->set_sheettitle($name);
            $writer->start_sheet($t['columns']);
            foreach ($t['rows'] as $r => $row) {
                $writer->write_record(self::cells_for($format, $row), $r + 1);
            }
            $writer->close_sheet($t['columns']);
        }
        $writer->close_output_to_file();
        return $path;
    }

    /**
     * Sends the file and ends the request: excel/ods one workbook, csv a zip of CSV files.
     *
     * @param string $filename File name without extension (plain text).
     * @param string $format One of accepts().
     * @param array $tables tables().
     */
    public static function download_tables(string $filename, string $format, array $tables): void {
        if (!self::accepts($format)) {
            throw new \coding_exception('Format not accepted: ' . $format);
        }
        $path = $format === 'csv' ? self::csv_zip($tables) : self::workbook($format, $tables);
        // The activity name goes only to the header of the download, never to the file on disk.
        send_temp_file($path, self::download_name($filename, $format));
    }

    /**
     * The name the browser saves: the file name, clean of path characters, with the extension of the format.
     *
     * @param string $filename File name without extension (plain text).
     * @param string $format One of accepts().
     * @return string
     */
    public static function download_name(string $filename, string $format): string {
        $extension = ['csv' => '.zip', 'excel' => '.xlsx', 'ods' => '.ods'][$format] ?? null;
        if ($extension === null) {
            throw new \coding_exception('Format not accepted: ' . $format);
        }
        return clean_filename($filename) . $extension;
    }

    /**
     * The format selector of the download, to the URL of the rich report with blocks=1.
     *
     * @param \moodle_url $url Current URL of the rich report.
     * @return string HTML.
     */
    public static function selector(\moodle_url $url): string {
        $target = new \moodle_url($url, ['blocks' => 1]);
        $target->remove_params('page');
        return basic_table::format_selector($target, get_string('richdownloadblocks', 'mod_scormmakercmi5'));
    }
}
