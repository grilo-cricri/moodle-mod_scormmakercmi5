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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\iso_duration::class)]
/**
 * Tests for ISO 8601 durations (cmi5 §9.5.4).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\iso_duration
 */
final class iso_duration_test extends \advanced_testcase {
    /**
     * Durations with a fixed length.
     */
    public function test_to_ms(): void {
        $this->assertSame(3723500, iso_duration::to_ms('PT1H2M3.5S'));
        $this->assertSame(86400000, iso_duration::to_ms('P1D'));
        $this->assertSame(0, iso_duration::to_ms('PT0S'));
        $this->assertSame(604800000, iso_duration::to_ms('P1W'));
        $this->assertSame(90061001, iso_duration::to_ms('P1DT1H1M1.001S'));
        $this->assertSame(1500, iso_duration::to_ms('PT1,5S'));
        $this->assertSame(10, iso_duration::to_ms('PT0.0104S'));
        $this->assertSame(90000, iso_duration::to_ms('PT1.5M'));
        $this->assertSame(5400000, iso_duration::to_ms('PT1.5H'));
        $this->assertSame(63000, iso_duration::to_ms('P0Y0M0DT0H1M3S'));
        $this->assertSame(129600000, iso_duration::to_ms('P1,5D'));
    }

    /**
     * Valid durations with years or months, or beyond INT(15), have no milliseconds: null, never an exception.
     */
    public function test_unknown_length(): void {
        foreach (['P3Y1M29DT4H35M59.14S', 'P1Y', 'P1M', 'P0,5Y', 'PT99999999999999999S', 'P0.5M'] as $d) {
            $this->assertTrue(iso_duration::is_valid($d), $d);
            $this->assertNull(iso_duration::to_ms($d), $d);
        }
    }

    /**
     * Text that does not follow ISO 8601 4.4.3.2 is refused.
     *
     * @dataProvider invalid_provider
     * @param string $value Value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid(string $value): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->assertFalse(iso_duration::is_valid($value));
        iso_duration::to_ms($value);
    }

    /**
     * Invalid durations.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'T without component' => ['PT'],
            'P alone' => ['P'],
            'no P' => ['1H'],
            'negative' => ['-PT1S'],
            'empty' => [''],
            'fraction not last' => ['PT1.5H30M'],
            'two fractions' => ['P1.5DT1.5H'],
            'weeks with days' => ['P1W1D'],
            'trailing newline' => ["PT1S\n"],
            'lower case' => ['pt1s'],
        ];
    }

    /**
     * Milliseconds to the shortest duration, hours as the largest unit.
     */
    public function test_from_ms(): void {
        $this->assertSame('PT1H2M3.5S', iso_duration::from_ms(3723500));
        $this->assertSame('PT0S', iso_duration::from_ms(0));
        $this->assertSame('PT1H', iso_duration::from_ms(3600000));
        $this->assertSame('PT0.001S', iso_duration::from_ms(1));
        $this->assertSame('PT24H', iso_duration::from_ms(86400000));
        $this->assertSame('PT0S', iso_duration::from_ms(-5));
        $this->assertSame(3723500, iso_duration::to_ms(iso_duration::from_ms(3723500)));
    }
}
