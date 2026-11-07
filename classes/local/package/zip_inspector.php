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
 * Inspects a zip without extracting anything (design §4.1 step 2).
 *
 * Refuses unsafe names (".." segment, leading "/" or "\", drive letter, backslash, NUL byte), names
 * the File API extractor would write differently (clean_param PARAM_PATH drops control characters and
 * & < > " ` | ' :, collapses "//"), a "." segment, symbolic links, names that differ only in case, a file
 * whose name is also a folder, too many entries and a total uncompressed size above the limit (zip bomb).
 * Zip64 is accepted. The listing therefore names exactly the files the extractor creates.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class zip_inspector {
    /** @var int Unix file type mask (S_IFMT). */
    private const S_IFMT = 0170000;

    /** @var int Unix symbolic link type (S_IFLNK). */
    private const S_IFLNK = 0120000;

    /**
     * Inspects the zip.
     *
     * @param string $zippath Path of the zip file.
     * @param int $maxentries Maximum number of entries (directories included).
     * @param int $maxunzippedbytes Maximum sum of the uncompressed sizes.
     * @return zip_listing
     * @throws \moodle_exception err_notzip, err_zippath, err_zipsymlink, err_zipduplicate,
     *     err_zipfiledir, err_zipentries, err_zipsize.
     */
    public static function inspect(string $zippath, int $maxentries, int $maxunzippedbytes): zip_listing {
        global $CFG;
        require_once($CFG->libdir . '/filestorage/zip_archive.php');
        if (!is_file($zippath) || !is_readable($zippath) || filesize($zippath) === 0) {
            throw self::error('err_notzip');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zippath, \ZipArchive::RDONLY) !== true) {
            throw self::error('err_notzip');
        }
        // The File API extractor (zip_packer::extract_to_pathname) names files through zip_archive::get_info().
        $moodlezip = new \zip_archive();
        if (!$moodlezip->open($zippath, \file_archive::OPEN)) {
            $zip->close();
            throw self::error('err_notzip');
        }
        try {
            $count = $zip->count();
            if ($count > $maxentries) {
                throw self::error('err_zipentries', $maxentries);
            }
            // Libzip turns a NUL byte of a name into a space, so NUL is looked for in the raw central directory.
            $nul = self::raw_name_with_nul($zippath, $count);
            if ($nul !== null) {
                throw self::error('err_zippath', display_text::of($nul));
            }
            $files = [];
            $filekeys = [];
            $dirkeys = [];
            $explicitdirs = [];
            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                // Raw bytes, as zip_archive reads them.
                $stat = $zip->statIndex($i, \ZipArchive::FL_ENC_RAW);
                if ($stat === false) {
                    throw self::error('err_notzip');
                }
                $name = (string)$stat['name'];
                $info = $moodlezip->get_info($i);
                if (!self::is_safe_name($name) || self::extracted_name($info, $name) !== $name) {
                    // Unsafe, or the extractor would write it under another name (and could drop it as a collision).
                    throw self::error('err_zippath', display_text::of($name));
                }
                $opsys = 0;
                $attr = 0;
                if (
                    $zip->getExternalAttributesIndex($i, $opsys, $attr)
                    && $opsys !== \ZipArchive::OPSYS_DOS
                    && ((($attr >> 16) & self::S_IFMT) === self::S_IFLNK)
                ) {
                    throw self::error('err_zipsymlink', display_text::of($name));
                }
                $isdir = substr($name, -1) === '/';
                $key = \core_text::strtolower(rtrim($name, '/'));
                if (isset($filekeys[$key]) || ($isdir && isset($explicitdirs[$key]))) {
                    throw self::error('err_zipduplicate', display_text::of($name));
                }
                if (!$isdir && isset($dirkeys[$key])) {
                    throw self::error('err_zipfiledir', display_text::of($name));
                }
                // Every parent folder of the entry: a file with the same name could not be extracted.
                $parts = explode('/', $key);
                if (!$isdir) {
                    array_pop($parts);
                }
                $parent = '';
                foreach ($parts as $part) {
                    $parent = $parent === '' ? $part : $parent . '/' . $part;
                    if (isset($filekeys[$parent])) {
                        throw self::error('err_zipfiledir', display_text::of($filekeys[$parent]));
                    }
                    $dirkeys[$parent] = true;
                }
                if ($isdir) {
                    $explicitdirs[$key] = true;
                } else {
                    $filekeys[$key] = $name;
                }
                $total += (int)$stat['size'];
                if ($total > $maxunzippedbytes) {
                    throw self::error('err_zipsize', (int)floor($maxunzippedbytes / 1048576));
                }
                // The extractor skips operating system files (get_info() returns false for them).
                if (!$isdir && $info !== false) {
                    $files[] = $name;
                }
            }
        } finally {
            $zip->close();
            $moodlezip->close();
        }
        return new zip_listing($files, $total);
    }

    /**
     * The name under which zip_packer::extract_to_pathname() writes an entry.
     *
     * Mirrors the extractor: zip_archive::get_info() cleans the name (clean_param PARAM_PATH, no leading
     * slash) and, on Windows servers, a trailing dot of a segment becomes "_". Operating system files the
     * extractor skips (__MACOSX, .DS_Store, Thumbs.db: get_info() is false) keep their raw name.
     *
     * @param \stdClass|false $info zip_archive::get_info() of the entry.
     * @param string $name Raw name.
     * @return string
     */
    private static function extracted_name($info, string $name): string {
        global $CFG;
        if ($info === false) {
            return $name;
        }
        $extracted = (string)$info->pathname;
        if ($CFG->ostype === 'WINDOWS') {
            $extracted = preg_replace('~([^/]+)\.(/|$)~', '\1_\2', $extracted);
        }
        return $extracted;
    }

    /**
     * Whether an entry name is a safe relative path.
     *
     * @param string $name Entry name as libzip reports it.
     * @return bool
     */
    private static function is_safe_name(string $name): bool {
        if ($name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false) {
            return false;
        }
        if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            // A "." segment is kept by clean_param(PARAM_PATH) but lands on the same file as the name without it.
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }
        return true;
    }

    /**
     * Reads the central directory directly and returns the first name that contains a NUL byte.
     *
     * @param string $zippath Zip path.
     * @param int $count Number of entries libzip found.
     * @return string|null Raw name, or null when no name has a NUL byte.
     * @throws \moodle_exception err_notzip when the central directory cannot be read.
     */
    private static function raw_name_with_nul(string $zippath, int $count): ?string {
        $fh = fopen($zippath, 'rb');
        if ($fh === false) {
            throw self::error('err_notzip');
        }
        try {
            [$entries, $offset] = self::central_directory($fh, filesize($zippath));
            if ($entries !== $count || fseek($fh, $offset) !== 0) {
                throw self::error('err_notzip');
            }
            for ($i = 0; $i < $entries; $i++) {
                $header = fread($fh, 46);
                if ($header === false || strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") {
                    throw self::error('err_notzip');
                }
                $lengths = unpack('vname/vextra/vcomment', substr($header, 28, 6));
                $name = $lengths['name'] > 0 ? fread($fh, $lengths['name']) : '';
                if ($name === false || strlen($name) !== $lengths['name']) {
                    throw self::error('err_notzip');
                }
                if (strpos($name, "\0") !== false) {
                    return $name;
                }
                if (fseek($fh, $lengths['extra'] + $lengths['comment'], SEEK_CUR) !== 0) {
                    throw self::error('err_notzip');
                }
            }
            return null;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Locates the central directory from the end of central directory record (zip64 aware).
     *
     * @param resource $fh Open zip.
     * @param int $size File size.
     * @return int[] [number of entries, offset of the central directory].
     * @throws \moodle_exception err_notzip.
     */
    private static function central_directory($fh, int $size): array {
        $tail = min($size, 22 + 65535);
        if ($tail < 22 || fseek($fh, $size - $tail) !== 0) {
            throw self::error('err_notzip');
        }
        $bytes = fread($fh, $tail);
        $pos = $bytes === false ? false : strrpos($bytes, "PK\x05\x06");
        if ($pos === false || strlen($bytes) - $pos < 22) {
            throw self::error('err_notzip');
        }
        // Zip64: a locator right before the classic record points to the zip64 record, whose values prevail.
        $locator = $pos >= 20 ? substr($bytes, $pos - 20, 20) : '';
        if (substr($locator, 0, 4) !== "PK\x06\x07") {
            // A wrong offset shows up as a missing central directory signature in the caller.
            $eocd = unpack('ventries/Vcdoffset', substr($bytes, $pos + 10, 2) . substr($bytes, $pos + 16, 4));
            return [$eocd['entries'], $eocd['cdoffset']];
        }
        $recordoffset = unpack('P', substr($locator, 8, 8))[1];
        if ($recordoffset < 0 || $recordoffset > $size - 56 || fseek($fh, $recordoffset) !== 0) {
            throw self::error('err_notzip');
        }
        $record = fread($fh, 56);
        if ($record === false || strlen($record) !== 56 || substr($record, 0, 4) !== "PK\x06\x06") {
            throw self::error('err_notzip');
        }
        $zip64 = unpack('Pentries/Pcdsize/Pcdoffset', substr($record, 32, 24));
        return [$zip64['entries'], $zip64['cdoffset']];
    }

    /**
     * Builds a component exception.
     *
     * @param string $code Error string identifier.
     * @param mixed $a String parameter.
     * @return \moodle_exception
     */
    private static function error(string $code, $a = null): \moodle_exception {
        return new \moodle_exception($code, 'mod_scormmakercmi5', '', $a);
    }
}
