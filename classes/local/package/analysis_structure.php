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

namespace mod_scormmakercmi5\local\package;

/**
 * The analysis structure of a SCORM Maker package (analise/estrutura.json, subproject D, design §3.2): lessons, blocks
 * in order, items with labels and the images of the spatial blocks.
 *
 * The file comes from the package: every field is checked against the limits of the contract shared with SCORM Maker
 * (limitesDaEstrutura of tests/fixtures/contrato-dados-ricos.json, the same rules as the exporter's foraDosLimites and
 * the hosted estrutura.py), unknown keys are dropped and the texts are kept raw (they are escaped on output), except
 * U+0000 in a label or title, which becomes U+FFFD.
 *
 * Version 1 has top-level blocks only. Version 2 adds nested blocks (a video inside a marker of a map...): a top-level
 * block may list up to 50 children in filhos, each child names it in pai and has the lesson and the order of its
 * parent; one level only. pai and filhos are stored only when present, so a v1 structure is stored as before. An image
 * is kept only when it is a media/ file of the package, as a path relative to the package (never a URL); otherwise
 * only the image becomes null. Any other violation drops the whole structure: a bad or missing file never fails the
 * import, the rich report says the structure is unavailable.
 *
 * The structure is stored whatever the signature status, like smcourseid: the file is covered by the signed manifest,
 * so it is authentic only when the package is verified, and only the reports of verified packages read it (a recheck
 * may turn an unknown key into a verified package later).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class analysis_structure {
    /** @var string Path of the file in the package. */
    public const PATH = 'analise/estrutura.json';

    /** @var int Largest file read. */
    public const MAX_BYTES = 524288;

    /** @var int[] Versions of the structure (contract limitesDaEstrutura.versoes). */
    public const VERSIONS = [1, 2];

    /**
     * @var int Most levels of object or array, the root counted as 1 (contract limitesDaEstrutura.profundidade; the
     *     structure needs 5). An unknown key counts too, before it is dropped.
     */
    public const MAX_DEPTH = 16;

    /** @var int Depth given to json_decode: it counts one level more than the contract, so 17 accepts 16. */
    private const DEPTH = self::MAX_DEPTH + 1;

    /** @var int Lowest ordem of a block (contract limitesDaEstrutura.ordem.min). */
    public const MIN_ORDER = 0;

    /** @var int Highest ordem of a block (contract limitesDaEstrutura.ordem.max). */
    public const MAX_ORDER = 2147483647;

    /** @var int Most lessons (contract limitesDaEstrutura.licoes). */
    public const MAX_LESSONS = 500;

    /** @var int Most blocks, top-level and children together (contract limitesDaEstrutura.blocos). */
    public const MAX_BLOCKS = 2000;

    /** @var int Most items per block (contract limitesDaEstrutura.itens). */
    public const MAX_ITEMS = 200;

    /** @var int Most scenes per block (contract limitesDaEstrutura.cenas). */
    public const MAX_SCENES = 200;

    /** @var int Widest label or title, in code points (contract limitesDaEstrutura.rotulo). */
    public const MAX_LABEL = 80;

    /** @var int Children of one block (contract limitesDaEstrutura.filhosPorBloco). */
    public const MAX_CHILDREN = 50;

    /**
     * @var string Id of course, lesson, block, item and scene: 1 to 64 code points outside the forbidden ranges of the
     *     contract (limitesDaEstrutura.id.proibidos): "#", "/", "?", U+0000, the Unicode White_Space set, U+001C to
     *     U+001F and U+FEFF (the union of the \s of JS, Python and PCRE). Never \s: it matches different sets in PCRE,
     *     JS and Python. 64 code points is the width of stmt.smblock and stmt.smitem.
     */
    private const ID = '~^[^#/?\x{0}\x{9}-\x{D}\x{1C}-\x{1F}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}'
        . '\x{202F}\x{205F}\x{3000}\x{FEFF}]{1,64}$~Du';

    /** @var string Pattern of a block type (fits stmt.smblocktype). */
    private const TYPE = '~^[A-Za-z0-9]{1,32}$~D';

    /** @var string Pattern of a media path of the package. */
    private const MEDIA = '~^media/[A-Za-z0-9._-]{1,200}$~D';

    /** @var int Flags of the stored JSON. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Whether a value is a canonical id.
     *
     * @param mixed $value Value.
     * @return bool
     */
    public static function valid_id(mixed $value): bool {
        return self::id($value);
    }

    /**
     * The blocks of a lesson in report order (blocosEmOrdem of SCORM Maker): each top-level block, then its children
     * in the order of its filhos.
     *
     * @param array $structure Validated structure.
     * @param array $lesson One of its lessons.
     * @return array List of [string block id, string|null id of the parent].
     */
    public static function lesson_blocks(array $structure, array $lesson): array {
        $out = [];
        foreach ($lesson['blocos'] as $id) {
            $id = (string)$id;
            $out[] = [$id, null];
            foreach ($structure['blocos'][$id]['filhos'] ?? [] as $child) {
                $out[] = [(string)$child, $id];
            }
        }
        return $out;
    }

    /**
     * The validated structure of an extracted package, as normalised JSON.
     *
     * @param string $dir Extraction directory.
     * @param array $filehashes Relative path => hash of every file of the package.
     * @return string|null
     */
    public static function from_dir(string $dir, array $filehashes): ?string {
        $path = $dir . '/' . self::PATH;
        if (!array_key_exists(self::PATH, $filehashes) || !is_file($path) || filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $json = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return null;
        }
        return self::encode(self::from_json($json, array_map('strval', array_keys($filehashes))));
    }

    /**
     * The JSON stored in pkg.richstructure; blocks stay an object even when empty or with numeric ids.
     *
     * @param array|null $structure Result of from_json().
     * @return string|null
     */
    public static function encode(?array $structure): ?string {
        if ($structure === null) {
            return null;
        }
        $structure['blocos'] = (object)$structure['blocos'];
        $json = json_encode($structure, self::JSON_FLAGS);
        return $json === false ? null : $json;
    }

    /**
     * The stored structure of a package (pkg.richstructure).
     *
     * @param string|null $stored Stored JSON.
     * @return array|null
     */
    public static function decode(?string $stored): ?array {
        if ($stored === null || $stored === '') {
            return null;
        }
        $data = json_decode($stored, true, self::DEPTH);
        return is_array($data) ? $data : null;
    }

    /**
     * Validation of the file contents.
     *
     * @param string $json Contents.
     * @param string[] $paths Paths of the package.
     * @return array|null The structure, or null when invalid.
     */
    public static function from_json(string $json, array $paths): ?array {
        // The bytes rule of the contract: the size of the file as received.
        if (strlen($json) > self::MAX_BYTES) {
            return null;
        }
        // Without assoc: a JSON object is a stdClass and a JSON array a PHP array, so {} is never an empty list, [] never
        // an empty map and {"0": ...} never a list (contract regra). Every map is read through map().
        $data = self::map(json_decode(self::without_lone_surrogates($json), false, self::DEPTH));
        if (
            $data === null || !in_array($data['v'] ?? null, self::VERSIONS, true)
            || !self::id($data['courseId'] ?? null)
        ) {
            return null;
        }
        $version = $data['v'];
        $lessons = $data['licoes'] ?? null;
        $blockmap = self::map($data['blocos'] ?? null);
        if (
            !self::is_list($lessons) || count($lessons) > self::MAX_LESSONS
            || $blockmap === null || count($blockmap) > self::MAX_BLOCKS
        ) {
            return null;
        }
        $blocks = [];
        foreach ($blockmap as $key => $b) {
            $blocks[$key] = self::map($b);
            if ($blocks[$key] === null) {
                return null;
            }
        }
        $media = [];
        foreach ($paths as $p) {
            if (preg_match(self::MEDIA, $p) === 1) {
                $media[$p] = true;
            }
        }

        // Every top-level block is listed once, by the lesson it names. The keys of $blocks and $listed may be ints
        // (json_decode turns the key "123" into 123): cast back to string wherever a key is read.
        $lessonids = [];
        $listed = [];
        $outlessons = [];
        foreach ($lessons as $lesson) {
            $lesson = self::map($lesson);
            if (
                $lesson === null || !self::id($lesson['id'] ?? null) || array_key_exists($lesson['id'], $lessonids)
                || !self::text($lesson['titulo'] ?? null) || !self::is_list($lesson['blocos'] ?? null)
            ) {
                return null;
            }
            $lessonids[$lesson['id']] = true;
            foreach ($lesson['blocos'] as $blockid) {
                if (
                    !is_string($blockid) || !array_key_exists($blockid, $blocks) || array_key_exists($blockid, $listed)
                ) {
                    return null;
                }
                $listed[$blockid] = $lesson['id'];
            }
            $outlessons[] = ['id' => $lesson['id'], 'titulo' => self::without_nul($lesson['titulo']),
                'blocos' => $lesson['blocos']];
        }

        // The children (v2): listed by the top-level block that carries them, with the link both ways (child.pai = the
        // block that lists it in filhos) and one level only. Key presence counts, whatever the value (pai: null on a
        // top-level block, filhos: [] in v1 or on a child are refused). A block with pai that nobody lists is caught
        // by the count below.
        $parents = [];
        foreach ($listed as $topid => $lessonid) {
            $topid = (string)$topid;
            $top = $blocks[$topid];
            if (array_key_exists('pai', $top)) {
                return null;
            }
            if (!array_key_exists('filhos', $top)) {
                continue;
            }
            $children = $top['filhos'];
            if (
                $version === 1 || !self::is_list($children) || count($children) > self::MAX_CHILDREN
            ) {
                return null;
            }
            foreach ($children as $childid) {
                if (
                    !is_string($childid) || !array_key_exists($childid, $blocks) || array_key_exists($childid, $listed)
                    || !array_key_exists('pai', $blocks[$childid])
                    || $blocks[$childid]['pai'] !== $topid || array_key_exists('filhos', $blocks[$childid])
                ) {
                    return null;
                }
                $listed[$childid] = $lessonid;
                $parents[$childid] = $topid;
            }
        }
        if (count($listed) !== count($blocks)) {
            return null;
        }

        $outblocks = [];
        foreach ($blocks as $key => $b) {
            $key = (string)$key;
            $parent = array_key_exists($key, $parents) ? $parents[$key] : null;
            $block = self::block($key, $b, $listed[$key] ?? null, $media, $parent);
            if ($block === null) {
                return null;
            }
            $outblocks[$key] = $block;
        }
        // A child has the order of its parent (its lesson is checked by block()).
        foreach ($parents as $childid => $parentid) {
            if ($outblocks[$childid]['ordem'] !== $outblocks[$parentid]['ordem']) {
                return null;
            }
        }
        return ['v' => $version, 'courseId' => $data['courseId'], 'licoes' => $outlessons, 'blocos' => $outblocks];
    }

    /**
     * One validated block.
     *
     * @param string $key Key of the block in the structure.
     * @param array $b Block (already read through map()).
     * @param string|null $lessonid Id of the lesson that lists it.
     * @param array $media Media paths of the package (as keys).
     * @param string|null $parent Id of the top-level block that lists it in filhos, null for a top-level block.
     * @return array|null
     */
    private static function block(string $key, array $b, ?string $lessonid, array $media, ?string $parent): ?array {
        if (
            ($b['id'] ?? null) !== $key || !self::id($key)
            || !is_string($b['tipo'] ?? null) || preg_match(self::TYPE, $b['tipo']) !== 1
            || !self::text($b['rotulo'] ?? null) || $lessonid === null || ($b['licaoId'] ?? null) !== $lessonid
            || !is_int($b['ordem'] ?? null) || $b['ordem'] < self::MIN_ORDER || $b['ordem'] > self::MAX_ORDER
            || !is_bool($b['midia'] ?? null) || !is_bool($b['espacial'] ?? null)
            || !self::short_list($b['itens'] ?? null, self::MAX_ITEMS)
            || !self::short_list($b['cenas'] ?? null, self::MAX_SCENES)
        ) {
            return null;
        }
        $items = [];
        foreach ($b['itens'] as $item) {
            $item = self::map($item);
            if ($item === null || !self::id($item['id'] ?? null) || !self::text($item['rotulo'] ?? null)) {
                return null;
            }
            $items[] = ['id' => $item['id'], 'rotulo' => self::without_nul($item['rotulo'])];
        }
        $scenes = [];
        foreach ($b['cenas'] as $scene) {
            $scene = self::map($scene);
            if ($scene === null || !self::id($scene['id'] ?? null) || !self::text($scene['titulo'] ?? null)) {
                return null;
            }
            $scenes[] = [
                'id' => $scene['id'],
                'titulo' => self::without_nul($scene['titulo']),
                'imagem' => self::image($scene['imagem'] ?? null, $media),
            ];
        }
        $out = [
            'id' => $key, 'tipo' => $b['tipo'], 'rotulo' => self::without_nul($b['rotulo']), 'licaoId' => $lessonid,
            'ordem' => $b['ordem'], 'itens' => $items, 'imagem' => self::image($b['imagem'] ?? null, $media),
            'cenas' => $scenes, 'midia' => $b['midia'], 'espacial' => $b['espacial'],
        ];
        // Only when present, so a v1 structure is stored as before (from_json already checked the link).
        if ($parent !== null) {
            $out['pai'] = $parent;
        } else if (array_key_exists('filhos', $b)) {
            $out['filhos'] = $b['filhos'];
        }
        return $out;
    }

    /**
     * A label or title with every U+0000 replaced by U+FFFD (contract limitesDaEstrutura.regra; in an id U+0000 is
     * refused).
     *
     * @param string $text Text.
     * @return string
     */
    private static function without_nul(string $text): string {
        return str_replace("\0", "\u{FFFD}", $text);
    }

    /**
     * The JSON with every lone UTF-16 surrogate escape replaced by �.
     *
     * The exporter cuts labels with String.slice, which can split a surrogate pair, and JSON.stringify writes the
     * half left as an escape that json_decode refuses (JSON_ERROR_UTF16). An escaped backslash is consumed as a unit,
     * so "\\ud83d" (a backslash followed by the text ud83d) is left alone.
     *
     * @param string $json JSON.
     * @return string
     */
    private static function without_lone_surrogates(string $json): string {
        if (!str_contains($json, '\\u')) {
            return $json;
        }
        $result = preg_replace_callback(
            '~\\\\\\\\|\\\\u[dD][89abAB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}|\\\\u[dD][89a-fA-F][0-9a-fA-F]{2}~',
            static fn(array $m): string => strlen($m[0]) === 6 ? '\\ufffd' : $m[0],
            $json
        );
        return $result ?? $json;
    }

    /**
     * A JSON object as an array of its members, null for anything else (a JSON array included, even empty). Keys made
     * only of digits come back as ints: callers cast them back to string.
     *
     * @param mixed $value Value decoded without assoc.
     * @return array|null
     */
    private static function map(mixed $value): ?array {
        return $value instanceof \stdClass ? get_object_vars($value) : null;
    }

    /**
     * Whether the value is a JSON array (decoded without assoc, only a JSON array is a PHP array).
     *
     * @param mixed $value Value decoded without assoc.
     * @return bool
     */
    private static function is_list(mixed $value): bool {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Whether the value is a JSON array of at most $max entries.
     *
     * @param mixed $value Value decoded without assoc.
     * @param int $max Most entries.
     * @return bool
     */
    private static function short_list(mixed $value, int $max): bool {
        return self::is_list($value) && count($value) <= $max;
    }

    /**
     * Whether the value is a label of at most MAX_LABEL code points.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function text(mixed $value): bool {
        return is_string($value) && \core_text::strlen($value) <= self::MAX_LABEL;
    }

    /**
     * Whether the value is a canonical id (also the segment of the block and item IRIs of the statements).
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function id(mixed $value): bool {
        return is_string($value) && preg_match(self::ID, $value) === 1;
    }

    /**
     * The image when it is a media/ file of the package, null otherwise.
     *
     * @param mixed $value Value.
     * @param array $media Media paths of the package (as keys).
     * @return string|null
     */
    private static function image(mixed $value, array $media): ?string {
        return is_string($value) && isset($media[$value]) ? $value : null;
    }
}
