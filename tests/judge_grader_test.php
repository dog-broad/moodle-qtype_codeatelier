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

use qtype_codeatelier\judge\judge_grader;
use qtype_codeatelier_stub_runner;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_runner.php');

/**
 * Tests for the authoritative judge-tier grading orchestration.
 *
 * Pure logic against a scripted runner: no network, no Judge0. Verifies that
 * execution outcomes are lifted into the AC/WA/TLE/RE/CE taxonomy and that the
 * matching spec decides correctness.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\judge\judge_grader
 */
final class judge_grader_test extends \advanced_testcase {

    /**
     * Two trimmed-match tests: visible "5", hidden "30".
     *
     * @return array
     */
    private function sum_tests(): array {
        return array(
            (object) array('input' => "2 3\n", 'expected' => '5', 'matchmode' => 'trimmed',
                'tolerance' => null, 'weight' => 1, 'visibility' => 1),
            (object) array('input' => "10 20\n", 'expected' => '30', 'matchmode' => 'trimmed',
                'tolerance' => null, 'weight' => 1, 'visibility' => 0),
        );
    }

    public function test_all_correct_is_ac(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertFalse($result['infrastructure']);
        $this->assertEquals(1.0, $result['fraction']);
        $this->assertSame('AC', $result['verdict']);
        $this->assertCount(2, $result['testresults']);
        $this->assertTrue($result['testresults'][0]['passed']);
        $this->assertTrue($result['testresults'][1]['passed']);
    }

    public function test_one_wrong_is_partial_wa(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "999\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertEquals(0.5, $result['fraction']);
        $this->assertSame('WA', $result['verdict']);
        $this->assertTrue($result['testresults'][0]['passed']);
        $this->assertFalse($result['testresults'][1]['passed']);
        // Visibility is preserved so the renderer can keep hidden tests secret.
        $this->assertSame(1, $result['testresults'][0]['visibility']);
        $this->assertSame(0, $result['testresults'][1]['visibility']);
    }

    public function test_timeout_is_tle(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('tle'),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertSame('TLE', $result['verdict']);
        $this->assertSame('tle', $result['testresults'][1]['status']);
        $this->assertEquals(0.5, $result['fraction']);
    }

    public function test_memory_limit_exceeded_is_mle(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('mle', '', array('memory' => 128000)),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertSame('MLE', $result['verdict']);
        $this->assertSame('mle', $result['testresults'][1]['status']);
        $this->assertEquals(0.5, $result['fraction']);
    }

    public function test_runtime_error_precedes_mle_in_verdict(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('re'),
            qtype_codeatelier_stub_runner::row('mle'),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());
        $this->assertSame('RE', $result['verdict']);
    }

    public function test_visible_tests_carry_diagnostics_hidden_do_not(): void {
        // Visible test fails (wrong output); hidden test passes.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "999\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $visible = $result['testresults'][0];
        $hidden = $result['testresults'][1];
        // The visible failure carries input/expected/actual for the diff.
        $this->assertArrayHasKey('expected', $visible);
        $this->assertArrayHasKey('actual', $visible);
        $this->assertSame('5', $visible['expected']);
        $this->assertSame("999\n", $visible['actual']);
        // The hidden test never carries its input, expected or produced output.
        $this->assertArrayNotHasKey('expected', $hidden);
        $this->assertArrayNotHasKey('actual', $hidden);
        $this->assertArrayNotHasKey('input', $hidden);
    }

    public function test_runtime_error_is_re(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('re'),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertSame('RE', $result['verdict']);
        $this->assertSame('re', $result['testresults'][0]['status']);
    }

    public function test_compile_error_fails_whole_submission(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'error: expected ;')),
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'error: expected ;')),
        ));
        $result = (new judge_grader($runner))->grade('code', 'cpp', $this->sum_tests());

        $this->assertSame('CE', $result['verdict']);
        $this->assertEquals(0.0, $result['fraction']);
        $this->assertStringContainsString('expected ;', $result['compileoutput']);
        $this->assertFalse($result['testresults'][0]['passed']);
        $this->assertFalse($result['testresults'][1]['passed']);
    }

    public function test_infrastructure_failure_is_flagged(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('internal', '', array('statusdesc' => 'judge0 unreachable')),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertTrue($result['infrastructure']);
        $this->assertSame('INTERNAL', $result['verdict']);
        $this->assertEquals(0.0, $result['fraction']);
    }
}
