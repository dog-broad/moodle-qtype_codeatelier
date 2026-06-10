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

/**
 * Tests for the server-tier matching-spec interpreter.
 *
 * Both assertions below are driven by tests/fixtures/matching_corpus.json — the
 * same fixture the in-browser grader is run against (tests/grader.parity.test.mjs).
 * If both runners satisfy every 'want' in that file, the two interpreters agree on
 * every case, which is the tier-parity guarantee the declarative spec exists for.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\grader
 */
final class grader_test extends \advanced_testcase {
    /**
     * Load and decode the shared corpus (objects as objects, mirroring the JS side).
     *
     * @return \stdClass
     */
    private function corpus(): \stdClass {
        $json = file_get_contents(__DIR__ . '/fixtures/matching_corpus.json');
        return json_decode($json);
    }

    public function test_matches_against_shared_corpus(): void {
        foreach ($this->corpus()->matches as $case) {
            $got = grader::matches($case->actual, $case->expected, $case->mode, $case->tolerance);
            $this->assertSame(
                $case->want,
                $got,
                "matches case '{$case->name}' ({$case->mode}) disagreed with the corpus"
            );
        }
    }

    public function test_grade_against_shared_corpus(): void {
        foreach ($this->corpus()->grade as $case) {
            // The runner result is an array on the server tier; the corpus carries
            // it as an object, so cast each result to an array before grading.
            $results = array_map(function ($r) {
                return (array) $r;
            }, $case->results);

            $options = isset($case->options) ? (array) $case->options : [];
            $graded = grader::grade($results, $case->tests, $options);

            $this->assertEqualsWithDelta(
                $case->wantfraction,
                $graded['fraction'],
                1e-9,
                "grade case '{$case->name}' fraction disagreed with the corpus"
            );
            $this->assertSame(
                $case->wantverdict,
                $graded['verdict'],
                "grade case '{$case->name}' verdict disagreed with the corpus"
            );

            foreach ($case->wanttestresults as $i => $want) {
                $this->assertSame(
                    $want->status,
                    $graded['testresults'][$i]['status'],
                    "grade case '{$case->name}' test {$i} status disagreed"
                );
                $this->assertSame(
                    $want->passed,
                    $graded['testresults'][$i]['passed'],
                    "grade case '{$case->name}' test {$i} passed disagreed"
                );
                $this->assertSame(
                    (int) $want->visibility,
                    $graded['testresults'][$i]['visibility'],
                    "grade case '{$case->name}' test {$i} visibility disagreed"
                );
            }
        }
    }
}
