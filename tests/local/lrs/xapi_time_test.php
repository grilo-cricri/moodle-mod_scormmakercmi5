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

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_scormmakercmi5\local\lrs\xapi_time::class)]
/**
 * Tests for xAPI timestamps (cmi5 §9.7).
 *
 * @package    mod_scormmakercmi5
 * @copyright  2026 ScormMaker.com.br
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_scormmakercmi5\local\lrs\xapi_time
 */
final class xapi_time_test extends \advanced_testcase {
    /**
     * Milliseconds survive a round trip and the text is UTC with milliseconds and Z.
     */
    public function test_round_trip(): void {
        $x = 1790000000123;
        $this->assertSame($x, xapi_time::to_ms(xapi_time::from_ms($x)));
        $this->assertSame('2026-09-21T14:13:20.123Z', xapi_time::from_ms($x));
        $this->assertSame('1970-01-01T00:00:00.000Z', xapi_time::from_ms(0));
    }

    /**
     * Offsets, fractions and a missing time zone.
     */
    public function test_to_ms(): void {
        $this->assertSame(1790000000000, xapi_time::to_ms('2026-09-21T14:13:20Z'));
        $this->assertSame(1790000000000, xapi_time::to_ms('2026-09-21T11:13:20-03:00'));
        $this->assertSame(1790000000000, xapi_time::to_ms('2026-09-21T14:13:20+00:00'));
        $this->assertSame(1790000000120, xapi_time::to_ms('2026-09-21T14:13:20.12Z'));
        $this->assertSame(1790000000123, xapi_time::to_ms('2026-09-21T14:13:20.123999Z'));
        $this->assertSame(1790000000000, xapi_time::to_ms('2026-09-21T14:13:20'));
    }

    /**
     * UTC means Z or a zero offset; another offset or none is not UTC.
     */
    public function test_is_utc(): void {
        $this->assertTrue(xapi_time::is_utc('2026-09-30T12:00:00Z'));
        $this->assertTrue(xapi_time::is_utc('2026-09-30T12:00:00.5z'));
        $this->assertTrue(xapi_time::is_utc('2026-09-30T12:00:00+00:00'));
        $this->assertFalse(xapi_time::is_utc('2026-09-30T12:00:00-03:00'));
        $this->assertFalse(xapi_time::is_utc('2026-09-30T12:00:00'));
        $this->assertFalse(xapi_time::is_utc('ontem'));
    }

    /**
     * Text that is not an ISO 8601 date-time is refused.
     *
     * @dataProvider invalid_provider
     * @param string $value Value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_invalid(string $value): void {
        $this->expectException(\InvalidArgumentException::class);
        xapi_time::to_ms($value);
    }

    /**
     * Invalid timestamps.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'word' => ['ontem'],
            'empty' => [''],
            'date only' => ['2026-09-30'],
            'month 13' => ['2026-13-01T00:00:00Z'],
            'february 30' => ['2026-02-30T00:00:00Z'],
            'hour 25' => ['2026-09-30T25:00:00Z'],
            'offset 25' => ['2026-09-30T12:00:00+25:00'],
            'trailing text' => ['2026-09-30T12:00:00Zx'],
            'trailing newline' => ["2026-09-30T12:00:00Z\n"],
        ];
    }

    /**
     * now_ms() follows the clock of the dependency container.
     */
    public function test_now_ms_uses_clock(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(1790000000);
        $this->assertSame(1790000000000, xapi_time::now_ms());
    }
}
