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
 * An assignable unit read from cmi5.xml (values already trimmed and validated).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class au_definition {
    /**
     * Constructor.
     *
     * @param string $publisherid au@id.
     * @param array $title lang => text, document order, first one per lang.
     * @param array $description lang => text.
     * @param string $url URL as written (query and fragment kept): relative, or https for an AU outside the package.
     * @param string $urlpath Decoded path inside the package, without query and fragment, dot segments resolved; ''
     *     for an AU outside the package.
     * @param string $launchmethod AnyWindow|OwnWindow.
     * @param string $moveon Passed|Completed|CompletedAndPassed|CompletedOrPassed|NotApplicable.
     * @param float|null $masteryscore 0–1 with at most 4 decimals, or null.
     * @param string|null $launchparameters Text of launchParameters, or null when absent.
     * @param string|null $entitlementkey Text of entitlementKey, or null when absent.
     * @param string|null $activitytype au@activityType, or null when absent.
     * @param int $depth 1 for a child of the course, +1 per enclosing block.
     * @param string|null $origin https origin of an AU outside the package (absolute url), null for one inside.
     */
    public function __construct(
        /** @var string au@id. */
        public readonly string $publisherid,
        /** @var array lang => text. */
        public readonly array $title,
        /** @var array lang => text. */
        public readonly array $description,
        /** @var string URL as written. */
        public readonly string $url,
        /** @var string Decoded path inside the package. */
        public readonly string $urlpath,
        /** @var string AnyWindow|OwnWindow. */
        public readonly string $launchmethod,
        /** @var string moveOn criterion. */
        public readonly string $moveon,
        /** @var float|null Mastery score. */
        public readonly ?float $masteryscore,
        /** @var string|null Launch parameters. */
        public readonly ?string $launchparameters,
        /** @var string|null Entitlement key. */
        public readonly ?string $entitlementkey,
        /** @var string|null Activity type. */
        public readonly ?string $activitytype,
        /** @var int Depth. */
        public readonly int $depth,
        /** @var string|null Origin of an AU outside the package. */
        public readonly ?string $origin = null
    ) {
    }
}
