<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace qtype_codeatelier;

use qtype_codeatelier\local\integrity\proctor_log;

/**
 * Tests for the server-side append-only proctor event log.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\local\integrity\proctor_log
 */
final class proctor_log_test extends \advanced_testcase {
    public function test_record_and_summarise(): void {
        $this->resetAfterTest();
        $this->assertTrue(proctor_log::record(100, 7, 'focusaway'));
        $this->assertTrue(proctor_log::record(100, 7, 'focusaway'));
        $this->assertTrue(proctor_log::record(100, 7, 'extpaste'));
        // A different attempt is kept separate.
        $this->assertTrue(proctor_log::record(200, 8, 'extdrop'));

        $this->assertSame(['extpaste' => 1, 'focusaway' => 2], $this->sorted(proctor_log::summarise(100)));
        $this->assertSame(['extdrop' => 1], proctor_log::summarise(200));
        $this->assertSame([], proctor_log::summarise(999));
    }

    public function test_record_rejects_unknown_event_type(): void {
        $this->resetAfterTest();
        $this->assertFalse(proctor_log::record(100, 7, 'definitelynotanevent'));
        $this->assertSame([], proctor_log::summarise(100));
    }

    public function test_record_is_server_stamped(): void {
        global $DB;
        $this->resetAfterTest();
        proctor_log::record(100, 42, 'focusaway', 1234567890);
        $row = $DB->get_record('qtype_codeatelier_proctorlog', ['questionattemptid' => 100]);
        // The user and time are what the server stored, not anything the client framed.
        $this->assertEquals(42, $row->userid);
        $this->assertEquals(1234567890, $row->timecreated);
        $this->assertSame('focusaway', $row->eventtype);
    }

    public function test_record_caps_events_per_attempt(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < proctor_log::MAX_PER_ATTEMPT; $i++) {
            $this->assertTrue(proctor_log::record(100, 7, 'focusaway'));
        }
        // The cap is reached: further events for this attempt are dropped.
        $this->assertFalse(proctor_log::record(100, 7, 'focusaway'));
        // A different attempt is unaffected.
        $this->assertTrue(proctor_log::record(101, 7, 'focusaway'));
    }

    public function test_counts_for_batches_attempts(): void {
        $this->resetAfterTest();
        proctor_log::record(100, 7, 'focusaway');
        proctor_log::record(100, 7, 'extpaste');
        proctor_log::record(200, 8, 'focusaway');
        // Attempt 300 has no events and must be absent from the result.
        $counts = proctor_log::counts_for([100, 200, 300]);
        $this->assertSame(2, $counts[100]);
        $this->assertSame(1, $counts[200]);
        $this->assertArrayNotHasKey(300, $counts);
        $this->assertSame([], proctor_log::counts_for([]));
    }

    /**
     * Sort a summary map by key for a stable assertion.
     *
     * @param array $map
     * @return array
     */
    private function sorted(array $map): array {
        ksort($map);
        return $map;
    }
}
