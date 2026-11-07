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
 * Validates cmi5.xml (embedded XSD + the cmi5 rules the XSD cannot express) and reads it (design §4.3).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cmi5_parser {
    /** @var string Namespace of the cmi5 course structure. */
    public const NS = 'https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd';

    /** @var int Largest accepted document (bytes). 1000 AUs take about 0.5 MB. */
    public const MAX_BYTES = 16777216;

    /** @var string[] Query parameter names the LMS adds to the launch URL (cmi5 §8.1, 8.1.0.0-6). */
    private const RESERVED_PARAMS = ['endpoint', 'fetch', 'actor', 'registration', 'activityId'];

    /** @var string Scheme at the start of an IRI or URL (RFC 3987 / 3986). */
    private const SCHEME = '/^[A-Za-z][A-Za-z0-9+.-]*:/';

    /** @var int Longest absolute AU url accepted. */
    public const MAX_EXTERNAL_URL = 2048;

    /** @var int Longest origin (https://host[:port]): the width of scormmakercmi5_au.auorigin. */
    public const MAX_ORIGIN = 255;

    /**
     * @var string An absolute AU url (decision of the list after D): https, a host a browser serialises the same way
     * (ASCII letters, digits, "-" and "." — an international name in its xn-- form — or an IP literal), an optional
     * port, then only the characters of RFC 3986 (no white space, no "\", no quote, no "<>", no raw non-ASCII). No
     * "@" before the path, so no user name or password, and no way for a browser to read another host than this one.
     */
    private const EXTERNAL_URL = '~^(?i:https)://(?<host>[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9.])?|\[[0-9A-Fa-f:.]+\])'
        . '(?::(?<port>[0-9]{1,5}))?(?<rest>[/?#][A-Za-z0-9\-._\~:/?#\[\]@!$&\'()*+,;=%]*)?$~D';

    /** @var string XML white space, trimmed from every value (cmi5 §13.1). */
    private const SPACE = " \t\n\r";

    /** @var array Ids already seen (id => true). */
    private array $ids = [];

    /** @var array Course objective ids (id => true). */
    private array $objectives = [];

    /** @var array Zip file paths (path => true). */
    private array $files;

    /**
     * Constructor.
     *
     * @param array $zipfiles File paths of the package.
     */
    private function __construct(array $zipfiles) {
        $this->files = array_fill_keys(array_map('strval', $zipfiles), true);
    }

    /**
     * Validates and reads a cmi5.xml document.
     *
     * @param string $xml Document bytes.
     * @param array $zipfiles File paths of the package (zip_listing::$files).
     * @param string $lang Moodle language of the course/user. Reading does not depend on it: every
     *        langstring is kept and course_structure::pick() chooses one.
     * @return course_structure
     * @throws \moodle_exception err_xmlsize, err_doctype, err_xmlinvalid, err_iri, err_duplicateid,
     *         err_objectiveref, err_auabsoluteurl, err_aufilemissing, err_aureservedparam, err_masteryscore.
     */
    public static function parse(string $xml, array $zipfiles, string $lang = ''): course_structure {
        if (strlen($xml) > self::MAX_BYTES) {
            throw self::error('err_xmlsize', (int)(self::MAX_BYTES / 1048576));
        }
        // Refused before parsing, so no DTD is ever read; the check after loading covers other encodings.
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw self::error('err_doctype');
        }
        $doc = self::load($xml);
        return (new self($zipfiles))->read($doc);
    }

    /**
     * Loads the document and validates it against the embedded XSD, with no network and no external
     * resource other than the XSD itself.
     *
     * @param string $xml Document bytes.
     * @return \DOMDocument
     * @throws \moodle_exception err_doctype, err_xmlinvalid.
     */
    private static function load(string $xml): \DOMDocument {
        $xsd = realpath(__DIR__ . '/../../../xsd/CourseStructure.xsd');
        $previousloader = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
        $previouserrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        libxml_set_external_entity_loader(static function ($public, $system) use ($xsd) {
            $path = is_string($system) ? preg_replace('~^file://~', '', $system) : '';
            return ($path !== '' && realpath($path) === $xsd) ? $xsd : null;
        });
        try {
            $doc = new \DOMDocument();
            if ($xml === '' || !$doc->loadXML($xml, LIBXML_NONET)) {
                throw self::xml_error();
            }
            if ($doc->doctype !== null) {
                throw self::error('err_doctype');
            }
            self::trim_attributes($doc->documentElement);
            if (!$doc->schemaValidate($xsd)) {
                throw self::xml_error();
            }
            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previouserrors);
            libxml_set_external_entity_loader($previousloader);
        }
    }

    /**
     * Trims the attributes of the cmi5 elements, so " Passed" meets the XSD vocabulary (cmi5 §13.1).
     *
     * @param \DOMElement|null $el Element.
     */
    private static function trim_attributes(?\DOMElement $el): void {
        if ($el === null || $el->namespaceURI !== self::NS) {
            return;
        }
        foreach ($el->attributes as $attr) {
            if ($attr->namespaceURI === null) {
                $attr->value = trim($attr->value, self::SPACE);
            }
        }
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                self::trim_attributes($child);
            }
        }
    }

    /**
     * The first libxml error as err_xmlinvalid.
     *
     * @return \moodle_exception
     */
    private static function xml_error(): \moodle_exception {
        $error = libxml_get_errors()[0] ?? null;
        $message = $error ? trim(str_replace('{' . self::NS . '}', '', $error->message)) : '';
        return self::error('err_xmlinvalid', (object)[
            'line' => $error ? (int)$error->line : 0,
            'message' => display_text::of($message),
        ]);
    }

    /**
     * Reads the validated document.
     *
     * @param \DOMDocument $doc Document valid against the XSD.
     * @return course_structure
     */
    private function read(\DOMDocument $doc): course_structure {
        $root = $doc->documentElement;
        $course = self::child($root, 'course');
        $courseid = $this->id($course, 'course');
        $objectives = self::child($root, 'objectives');
        if ($objectives !== null) {
            foreach (self::children($objectives, 'objective') as $objective) {
                $this->objectives[$this->id($objective, 'objective')] = true;
            }
        }
        return new course_structure(
            $courseid,
            self::langstrings(self::child($course, 'title')),
            self::langstrings(self::child($course, 'description')),
            $this->items($root, 1)
        );
    }

    /**
     * The AUs and blocks under an element, in document order.
     *
     * @param \DOMElement $parent courseStructure or block.
     * @param int $depth Depth of the items.
     * @return array au_definition|block_definition
     */
    private function items(\DOMElement $parent, int $depth): array {
        $items = [];
        foreach (self::children($parent, null) as $el) {
            if ($el->localName === 'au') {
                $items[] = $this->au($el, $depth);
            } else if ($el->localName === 'block') {
                $id = $this->id($el, 'block');
                $this->check_references($el);
                $items[] = new block_definition(
                    $id,
                    self::langstrings(self::child($el, 'title')),
                    self::langstrings(self::child($el, 'description')),
                    $this->items($el, $depth + 1),
                    $depth
                );
            }
        }
        return $items;
    }

    /**
     * Reads one AU.
     *
     * @param \DOMElement $el au element.
     * @param int $depth Depth.
     * @return au_definition
     */
    private function au(\DOMElement $el, int $depth): au_definition {
        $id = $this->id($el, 'au');
        $this->check_references($el);
        $url = self::text(self::child($el, 'url'));
        $origin = self::external_origin($url);
        $urlpath = $origin === null ? $this->url_path($url) : '';
        $masteryscore = null;
        if ($el->hasAttribute('masteryScore')) {
            $masteryscore = self::mastery_score($el->getAttribute('masteryScore'));
        }
        $params = self::child($el, 'launchParameters');
        $key = self::child($el, 'entitlementKey');
        return new au_definition(
            $id,
            self::langstrings(self::child($el, 'title')),
            self::langstrings(self::child($el, 'description')),
            $url,
            $urlpath,
            $el->getAttribute('launchMethod') ?: 'AnyWindow',
            $el->getAttribute('moveOn') ?: 'NotApplicable',
            $masteryscore,
            $params === null ? null : self::text($params),
            $key === null ? null : self::text($key),
            $el->hasAttribute('activityType') ? $el->getAttribute('activityType') : null,
            $depth,
            $origin
        );
    }

    /**
     * The id of a course, block, AU or objective: an IRI with a scheme and no white space, unique in
     * the document (13.1.2.0-1, 13.1.3.0-1, 13.1.4.0-1).
     *
     * @param \DOMElement $el Element.
     * @param string $name Element name for the message.
     * @return string Trimmed id.
     * @throws \moodle_exception err_iri, err_duplicateid.
     */
    private function id(\DOMElement $el, string $name): string {
        $id = $el->getAttribute('id');
        if (!preg_match(self::SCHEME, $id) || preg_match('/[\x00-\x20\x7F]/', $id)) {
            throw self::error('err_iri', $name);
        }
        if (isset($this->ids[$id])) {
            throw self::error('err_duplicateid', display_text::of($id));
        }
        $this->ids[$id] = true;
        return $id;
    }

    /**
     * Every objective@idref of a block or AU must name a course objective.
     *
     * @param \DOMElement $el Block or AU.
     * @throws \moodle_exception err_objectiveref.
     */
    private function check_references(\DOMElement $el): void {
        $objectives = self::child($el, 'objectives');
        if ($objectives === null) {
            return;
        }
        foreach (self::children($objectives, 'objective') as $ref) {
            $idref = $ref->getAttribute('idref');
            if (!isset($this->objectives[$idref])) {
                throw self::error('err_objectiveref', display_text::of($idref));
            }
        }
    }

    /**
     * The https origin of an AU outside the package, or null for a relative url (decision of the list after D: accepted
     * as unsigned, CORS restricted to this origin, no rich report).
     *
     * The origin is written the way a browser sends it in the Origin header (RFC 6454): scheme and host in lower case,
     * the default port 443 left out. The query follows the rules of a relative url, so what is checked here is exactly
     * what the launcher sends.
     *
     * The host must also be written the way a browser writes it, or the Origin header would never match: a host whose
     * last label is a number (decimal, or 0x hexadecimal) is an IPv4 address to a browser (WHATWG URL), kept only as four
     * decimal numbers 0–255 without leading zeros; an IPv6 address only in its short form (RFC 5952: lower case, no
     * leading zeros, the longest run of zero groups as "::", no embedded IPv4); no empty label. The origin fits the
     * column auorigin (MAX_ORIGIN).
     *
     * @param string $url Trimmed au/url.
     * @return string|null
     * @throws \moodle_exception err_auabsoluteurl, err_auhost, err_auhosttoolong, err_aureservedparam, err_auquery.
     */
    public static function external_origin(string $url): ?string {
        if (!preg_match(self::SCHEME, $url) && strpos($url, '//') !== 0) {
            return null;
        }
        if (strlen($url) > self::MAX_EXTERNAL_URL || !preg_match(self::EXTERNAL_URL, $url, $m)) {
            throw self::error('err_auabsoluteurl');
        }
        $port = isset($m['port']) && $m['port'] !== '' ? (int)$m['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw self::error('err_auabsoluteurl');
        }
        $host = strtolower($m['host']);
        $origin = 'https://' . $host . ($port === null || $port === 443 ? '' : ':' . $port);
        if (strlen($origin) > self::MAX_ORIGIN) {
            throw self::error('err_auhosttoolong', self::MAX_ORIGIN);
        }
        if (!self::browser_host($host)) {
            throw self::error('err_auhost');
        }
        $query = explode('?', explode('#', $m['rest'] ?? '', 2)[0], 2)[1] ?? null;
        if ($query !== null) {
            self::check_query($query);
        }
        return $origin;
    }

    /**
     * Whether a browser writes this host, in lower case, the same way in the Origin header (see external_origin()).
     *
     * @param string $host Host of EXTERNAL_URL, in lower case.
     * @return bool
     */
    private static function browser_host(string $host): bool {
        if ($host[0] === '[') {
            $address = substr($host, 1, -1);
            $binary = @inet_pton($address);
            return $binary !== false && strlen($binary) === 16 && self::ipv6_text($binary) === $address;
        }
        // One trailing dot is kept by browsers (a fully qualified name); any other empty label is not a host.
        $labels = explode('.', str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
        if (in_array('', $labels, true)) {
            return false;
        }
        if (!preg_match('/^(?:[0-9]+|0x[0-9a-f]*)$/D', end($labels))) {
            return true;
        }
        // Ends in a number: an IPv4 address to a browser, which writes it as four decimal numbers.
        if (str_ends_with($host, '.') || count($labels) !== 4) {
            return false;
        }
        foreach ($labels as $label) {
            if (!preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $label) || (int)$label > 255) {
                return false;
            }
        }
        return true;
    }

    /**
     * The short text form of an IPv6 address (RFC 5952, as browsers serialise it): hexadecimal groups in lower case
     * without leading zeros, the first longest run of two or more zero groups as "::".
     *
     * @param string $binary The 16 bytes of the address.
     * @return string
     */
    private static function ipv6_text(string $binary): string {
        $groups = array_map(static fn(int $g): string => dechex($g), array_values(unpack('n8', $binary)));
        $start = -1;
        $length = 0;
        for ($i = 0; $i < 8; $i++) {
            $run = 0;
            while ($i + $run < 8 && $groups[$i + $run] === '0') {
                $run++;
            }
            if ($run > $length && $run >= 2) {
                [$start, $length] = [$i, $run];
            }
        }
        if ($start < 0) {
            return implode(':', $groups);
        }
        return implode(':', array_slice($groups, 0, $start)) . '::' . implode(':', array_slice($groups, $start + $length));
    }

    /**
     * The path of the AU file inside the package.
     *
     * @param string $url Trimmed au/url, relative.
     * @return string Decoded path, dot segments resolved.
     * @throws \moodle_exception err_aureservedparam, err_auquery, err_aufilemissing.
     */
    private function url_path(string $url): string {
        $nofragment = explode('#', $url, 2)[0];
        [$path, $query] = array_pad(explode('?', $nofragment, 2), 2, null);
        if ($query !== null) {
            self::check_query($query);
        }
        $resolved = self::package_path($url);
        if ($resolved === null || !isset($this->files[$resolved])) {
            throw self::error('err_aufilemissing', display_text::of(rawurldecode($path)));
        }
        return $resolved;
    }

    /**
     * Refuses the cmi5 parameters the LMS adds (cmi5 §8.1) and a query the launcher could not pass on unchanged.
     *
     * @param string $query Query of the url, without "?".
     * @throws \moodle_exception err_aureservedparam, err_auquery.
     */
    private static function check_query(string $query): void {
        foreach (preg_split('/[&;]/', $query) as $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (in_array($name, self::RESERVED_PARAMS, true)) {
                throw self::error('err_aureservedparam', $name);
            }
        }
        self::check_query_names($query);
    }

    /**
     * Refuses a query the launcher could not pass on unchanged (moodle_url keeps parameters in a PHP array):
     * ";" as separator, an empty name, a name made only of digits, or the same name twice. What is validated
     * here is then exactly what launches.
     *
     * @param string $query Query of the url, without "?".
     * @throws \moodle_exception err_auquery.
     */
    private static function check_query_names(string $query): void {
        if (str_contains($query, ';')) {
            throw self::error('err_auquery', ';');
        }
        $seen = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            if ($name === '' || ctype_digit($name) || isset($seen[$name])) {
                throw self::error('err_auquery', display_text::of($name === '' ? $pair : $name));
            }
            $seen[$name] = true;
        }
    }

    /**
     * The file of the package a relative AU url points to: query and fragment removed, path decoded, "." and
     * ".." resolved. The launcher uses it too, so the file served is the file checked at import.
     *
     * @param string $url Relative url as written in cmi5.xml.
     * @return string|null null when the path climbs above the package root.
     */
    public static function package_path(string $url): ?string {
        $path = explode('?', explode('#', $url, 2)[0], 2)[0];
        return self::remove_dot_segments(rawurldecode($path));
    }

    /**
     * Resolves "." and ".." segments of a relative path.
     *
     * @param string $path Decoded path.
     * @return string|null null when the path climbs above the package root.
     */
    private static function remove_dot_segments(string $path): ?string {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!$out) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        return implode('/', $out);
    }

    /**
     * masteryScore: the XSD checks 0–1; the column keeps 4 decimals, so more significant digits are refused.
     *
     * @param string $value Trimmed attribute, already a valid xs:decimal.
     * @return float
     * @throws \moodle_exception err_masteryscore.
     */
    private static function mastery_score(string $value): float {
        $dot = strpos($value, '.');
        $decimals = $dot === false ? '' : rtrim(substr($value, $dot + 1), '0');
        if (strlen($decimals) > 4) {
            throw self::error('err_masteryscore', display_text::of($value));
        }
        return (float)$value;
    }

    /**
     * The langstrings of a title or description, lang => text; the first text of each lang wins.
     *
     * @param \DOMElement|null $el title or description.
     * @return array
     */
    private static function langstrings(?\DOMElement $el): array {
        $out = [];
        if ($el === null) {
            return $out;
        }
        foreach (self::children($el, 'langstring') as $langstring) {
            $lang = $langstring->getAttribute('lang');
            if (!array_key_exists($lang, $out)) {
                $out[$lang] = self::text($langstring);
            }
        }
        return $out;
    }

    /**
     * Trimmed text of an element.
     *
     * @param \DOMElement|null $el Element.
     * @return string
     */
    private static function text(?\DOMElement $el): string {
        return $el === null ? '' : trim($el->textContent, self::SPACE);
    }

    /**
     * The first child element in the cmi5 namespace with a local name.
     *
     * @param \DOMElement $parent Parent.
     * @param string $name Local name.
     * @return \DOMElement|null
     */
    private static function child(\DOMElement $parent, string $name): ?\DOMElement {
        foreach (self::children($parent, $name) as $el) {
            return $el;
        }
        return null;
    }

    /**
     * Child elements in the cmi5 namespace, optionally with a given local name.
     *
     * @param \DOMElement $parent Parent.
     * @param string|null $name Local name, or null for all.
     * @return \Generator
     */
    private static function children(\DOMElement $parent, ?string $name): \Generator {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->namespaceURI !== self::NS) {
                continue;
            }
            if ($name === null || $node->localName === $name) {
                yield $node;
            }
        }
    }

    /**
     * Builds the exception.
     *
     * @param string $code String identifier.
     * @param mixed $a Placeholder value.
     * @return \moodle_exception
     */
    private static function error(string $code, $a = null): \moodle_exception {
        return new \moodle_exception($code, 'mod_scormmakercmi5', '', $a);
    }
}
