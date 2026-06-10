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

namespace qtype_codeatelier\judge;

defined('MOODLE_INTERNAL') || die();

/**
 * Authoritative grader for web (frontend) questions on the secure tier.
 *
 * Sends the student's file set + every DOM assertion (visible and hidden) to the
 * web-judge service, then aggregates the per-assertion outcomes into the same
 * weighted-fraction / verdict result blob the program grader produces — so the
 * judge machinery (memo, async grading, review) treats a web question like any
 * other. The aggregation mirrors the in-browser web grader, so a given answer
 * scores the same on both tiers; the per-assertion check is run authoritatively
 * by the service, where the student cannot tamper with it.
 *
 * The blob never carries a hidden assertion's selector/expected/actual — only its
 * pass/fail — so the integrity boundary holds in the stored result too.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class web_judge_grader {
    /** @var web_judge_runner the service client. */
    private $runner;

    /** @var int per-assertion render timeout in milliseconds. */
    private $timeoutms;

    /**
     * @param web_judge_runner $runner the service client (real or test-injected)
     * @param int $timeoutms per-assertion render timeout
     */
    public function __construct(web_judge_runner $runner, int $timeoutms = 5000) {
        $this->runner = $runner;
        $this->timeoutms = $timeoutms;
    }

    /**
     * Grade a web answer against its DOM assertions.
     *
     * @param string $answerjson the canonical file-set JSON ([{name, content}, ...])
     * @param array $tests the assertion test rows
     * @param bool $allornothing whether a partial pass scores zero
     * @return array the result blob {infrastructure, fraction, verdict, compileoutput, testresults}
     */
    public function grade(string $answerjson, array $tests, bool $allornothing = false): array {
        $files = self::decode_files($answerjson);
        $assertions = [];
        foreach (array_values($tests) as $test) {
            $assertions[] = [
                'selector' => (string) $test->selector,
                'property' => (string) ($test->assertproperty !== null ? $test->assertproperty : 'text'),
                'matcher' => (string) ($test->assertmatcher !== null && $test->assertmatcher !== '' ? $test->assertmatcher : 'equals'),
                'expected' => (string) $test->expected,
                'actions' => isset($test->actions) ? (string) $test->actions : '',
            ];
        }

        $response = $this->runner->grade($files, $assertions, $this->timeoutms);
        if (isset($response['_error'])) {
            // The service is unreachable or errored: leave for grading, never fake a zero.
            return [
                'infrastructure' => true,
                'fraction' => 0.0,
                'verdict' => 'INTERNAL',
                'compileoutput' => '',
                'testresults' => [],
            ];
        }
        $results = (isset($response['results']) && is_array($response['results'])) ? $response['results'] : [];

        $totalweight = 0.0;
        $earned = 0.0;
        $testresults = [];
        foreach (array_values($tests) as $i => $test) {
            $r = isset($results[$i]) && is_array($results[$i]) ? $results[$i] : ['pass' => false, 'error' => 'no result'];
            $weight = (float) $test->weight;
            $totalweight += $weight;
            $passed = empty($r['error']) && !empty($r['pass']);
            if ($passed) {
                $earned += $weight;
            }
            $visible = (int) $test->visibility === 1;
            $row = [
                'index' => $i,
                'passed' => $passed,
                'visibility' => (int) $test->visibility,
                'status' => $passed ? 'pass' : 'fail',
            ];
            // Visible assertions carry the human check + the produced value for
            // review feedback; hidden assertions reveal nothing beyond pass/fail.
            if ($visible) {
                $row['check'] = self::describe($test);
                if (!empty($r['error'])) {
                    $row['actual'] = (string) $r['error'];
                } else if (!isset($r['actual']) || $r['actual'] === null) {
                    $row['actual'] = '';
                } else {
                    $row['actual'] = is_scalar($r['actual']) ? (string) $r['actual'] : json_encode($r['actual']);
                }
            }
            $testresults[] = $row;
        }

        $fraction = $totalweight > 0 ? $earned / $totalweight : 0.0;
        if ($allornothing && $fraction < 1) {
            $fraction = 0.0;
        }
        return [
            'infrastructure' => false,
            'fraction' => $fraction,
            'verdict' => $fraction >= 1 ? 'AC' : 'WA',
            'compileoutput' => '',
            'testresults' => $testresults,
        ];
    }

    /**
     * Decode the canonical file-set JSON into the [{name, content}, ...] the
     * service expects.
     *
     * @param string $json
     * @return array
     */
    protected static function decode_files(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $files = [];
        foreach ($decoded as $f) {
            if (is_array($f) && isset($f['name'])) {
                $files[] = ['name' => (string) $f['name'],
                        'content' => isset($f['content']) ? (string) $f['content'] : ''];
            }
        }
        return $files;
    }

    /**
     * A short human description of an assertion, e.g. `#title · text equals "Hi"`.
     * Mirrors the client's describeAssertion so review reads the same as the
     * practice-tier feedback.
     *
     * @param object $test the assertion test row
     * @return string
     */
    protected static function describe($test): string {
        $selector = (string) $test->selector;
        $property = (string) ($test->assertproperty !== null && $test->assertproperty !== '' ? $test->assertproperty : 'text');
        if ($property === 'exists') {
            $absent = preg_match('/^(false|0|no)$/i', trim((string) $test->expected));
            return $selector . ($absent ? ' is absent' : ' exists');
        }
        $matcher = (string) ($test->assertmatcher !== null && $test->assertmatcher !== '' ? $test->assertmatcher : 'equals');
        return $selector . ' · ' . $property . ' ' . $matcher . ' ' . json_encode((string) $test->expected);
    }
}
