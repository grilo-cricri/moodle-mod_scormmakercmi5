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

namespace mod_scormmakercmi5\local\lrs;

/**
 * An LRS error with its HTTP status (design §2.4): the router answers it as JSON {"error", "message", "rule"}.
 *
 * The message is the string $errorcode of mod_scormmakercmi5, with $message as its {$a} (a field name, a method...).
 * It must never carry a token, a key or an Authorization value: it goes to the client.
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lrs_exception extends \moodle_exception {
    /** @var int HTTP status of the response. */
    public readonly int $httpstatus;

    /** @var string|null The cmi5 requirement that was violated (e.g. '10.2.1.0-5'), if any. */
    public readonly ?string $rule;

    /** @var array Extra response headers (e.g. Allow, Retry-After). */
    public readonly array $headers;

    /**
     * Constructor.
     *
     * @param int $status HTTP status.
     * @param string $errorcode String id in mod_scormmakercmi5, also the "error" of the body.
     * @param string $message Detail passed as {$a} of the string ('' for none).
     * @param string|null $rule cmi5 requirement id.
     * @param array $headers Extra response headers.
     */
    public function __construct(int $status, string $errorcode, string $message = '', ?string $rule = null, array $headers = []) {
        $this->httpstatus = $status;
        $this->rule = $rule;
        $this->headers = $headers;
        parent::__construct($errorcode, 'mod_scormmakercmi5', '', $message === '' ? null : $message);
    }
}
