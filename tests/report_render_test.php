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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/type/codeatelier/renderer.php');

/**
 * The per-question report hides best-effort sections that have no data, rather than
 * rendering them as a column of zeros.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier_renderer::render_question_report
 */
final class report_render_test extends \advanced_testcase {

    /**
     * A minimal stats aggregate of the shape report rendering expects.
     *
     * @param array $overrides field overrides
     * @return \stdClass
     */
    private function stats(array $overrides = []): \stdClass {
        $base = [
            'questionid' => 1,
            'name' => 'Sample',
            'tier' => 'judge',
            'totalattempts' => 5,
            'graded' => 5,
            'needsgrading' => 0,
            'meanscore' => 0.8,
            'scoredist' => (object) ['zero' => 1, 'partial' => 1, 'full' => 3],
            'verdicts' => ['AC' => 3, 'WA' => 2],
            'languages' => ['python' => 5],
            'pertest' => [
                (object) ['index' => 0, 'visible' => true, 'passes' => 4, 'total' => 5, 'passrate' => 0.8],
            ],
            'timing' => (object) ['maxtimems' => 12.0, 'maxmemorykb' => 2048, 'meantimems' => 8.0],
            'proctorflagged' => 0,
            'detailcoverage' => (object) ['withresult' => 5, 'total' => 5],
        ];
        return (object) array_merge($base, $overrides);
    }

    /**
     * Render a report and return its HTML.
     *
     * @param \stdClass $stats
     * @return string
     */
    private function render(\stdClass $stats): string {
        global $PAGE;
        $renderer = $PAGE->get_renderer('qtype_codeatelier');
        $url = new \moodle_url('/question/type/codeatelier/report.php', ['id' => 1]);
        return $renderer->render_question_report($stats, $url);
    }

    public function test_pertest_table_shown_when_detail_present(): void {
        $this->resetAfterTest();
        $html = $this->render($this->stats(['detailcoverage' => (object) ['withresult' => 5, 'total' => 5]]));
        $this->assertStringContainsString(get_string('report_pertest', 'qtype_codeatelier'), $html);
        $this->assertStringContainsString(get_string('report_exportcsv', 'qtype_codeatelier'), $html);
    }

    public function test_pertest_table_hidden_when_no_detail(): void {
        $this->resetAfterTest();
        // Submissions exist but none has a live cache entry (withresult = 0): the
        // per-test table and its CSV export are hidden rather than shown as zeros.
        $html = $this->render($this->stats([
            'detailcoverage' => (object) ['withresult' => 0, 'total' => 5],
            'verdicts' => [],
        ]));
        $this->assertStringNotContainsString(get_string('report_pertest', 'qtype_codeatelier'), $html);
        $this->assertStringNotContainsString(get_string('report_exportcsv', 'qtype_codeatelier'), $html);
        // The coverage note still explains the absence.
        $this->assertStringContainsString('0', $html);
    }

    public function test_score_distribution_shown_when_graded(): void {
        $this->resetAfterTest();
        $html = $this->render($this->stats(['graded' => 5]));
        $this->assertStringContainsString(get_string('report_scoredist', 'qtype_codeatelier'), $html);
    }

    public function test_score_distribution_hidden_when_nothing_graded(): void {
        $this->resetAfterTest();
        // Every attempt is still awaiting grading: the distribution would be all zeros.
        $html = $this->render($this->stats([
            'graded' => 0,
            'needsgrading' => 5,
            'meanscore' => null,
            'scoredist' => (object) ['zero' => 0, 'partial' => 0, 'full' => 0],
            'verdicts' => [],
            'detailcoverage' => (object) ['withresult' => 0, 'total' => 5],
        ]));
        $this->assertStringNotContainsString(get_string('report_scoredist', 'qtype_codeatelier'), $html);
    }
}
