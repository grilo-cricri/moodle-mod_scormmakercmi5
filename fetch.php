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
 * The cmi5 fetch URL (design §2.4, §6.2; cmi5 §8.2): POST ?k=<one-time key> → {"auth-token": …}.
 *
 * Cookieless like mod/lti/services.php: no Moodle session, no debug output, JSON only (never HTML) and no-store.
 * The authorisation is the one-time key itself; fetch_service checks that the learner still has access (R4.7).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing -- The one-time key is the authorisation (cmi5 §8.2).
define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

use mod_scormmakercmi5\local\lrs\endpoint;
use mod_scormmakercmi5\local\lrs\response;
use mod_scormmakercmi5\local\runtime\fetch_service;

endpoint::serve(function (): response {
    // A missing or malformed key is cleaned to '' or to a key that matches nothing: error 2, not an HTML page.
    $key = optional_param('k', '', PARAM_ALPHANUMEXT);
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : null;
    return fetch_service::handle((string)($_SERVER['REQUEST_METHOD'] ?? ''), $key, 5, $origin);
}, new response(200, fetch_service::FALLBACK_BODY, ['Content-Type' => 'application/json']), 'fetch');
