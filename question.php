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

/**
 * CodeAtelier question definition class.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/questionbase.php');

/**
 * Represents a CodeAtelier question at runtime.
 *
 * The student writes code (the 'answer' response variable). In the practice tier
 * the browser runs that code against the question's test cases, grades it with
 * the declarative matching spec, and submits the resulting fraction as the
 * 'score' response variable. grade_response() returns that score (clamped).
 * Because the score is stored with the response, a regrade re-reads the same
 * value and returns the same fraction (idempotent). This client-side grade is
 * for practice only — see the integrity boundary; real grading is the judge tier.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_question extends question_graded_automatically {

    /** @var string the primary/default language; always a member of the allowed set. */
    public $language;

    /** @var string the scenario: stdinstdout, function or sql. */
    public $scenario;

    /** @var string|null the function name to call for the function scenario. */
    public $entrypoint;

    /** @var string|null starter code shown in the editor. */
    public $startercode;

    /** @var string|null SQL scenario schema + seed run before the student query. */
    public $schemasql;

    /** @var string the execution tier: practice (in-browser) or judge (server sandbox). */
    public $tier = 'practice';

    /** @var float|null per-question judge CPU-time limit in seconds; null uses the site default. */
    public $cputimelimit = null;

    /** @var int|null per-question judge memory limit in kilobytes; null uses the site default. */
    public $memorylimit = null;

    /** @var string|null comma-separated set of languages the student may choose; empty means single-language. */
    public $languages = null;

    /** @var string|null JSON map of language to starter code, for per-language starters. */
    public $starters = null;

    /** @var array the test cases (rows from qtype_codeatelier_tests). */
    public $tests = array();

    /** @var \qtype_codeatelier\judge\judge0_runner|null injected runner; null means build from config. */
    public $judgerunner = null;

    public function get_expected_data() {
        // The student's chosen language is a per-attempt response variable (a
        // question may allow a set of languages). It is re-validated server-side
        // on every grading path, so a tampered value can only fall back to the
        // primary language — see resolve_language().
        $expected = array('answer' => PARAM_RAW, 'score' => PARAM_RAW_TRIMMED, 'language' => PARAM_ALPHANUMEXT);
        if ($this->is_judge()) {
            // The soft anti-cheat log travels as a response variable; it is stored
            // with the attempt and never affects the grade.
            $expected['proctorlog'] = PARAM_RAW;
        }
        return $expected;
    }

    /**
     * The set of languages a student may choose from. Always includes the primary
     * language; an empty/unset languages column means single-language.
     *
     * @return string[] the allowed language codes, primary first
     */
    public function get_allowed_languages() {
        $allowed = array();
        if ($this->languages !== null && trim((string) $this->languages) !== '') {
            foreach (explode(',', (string) $this->languages) as $lang) {
                $lang = trim($lang);
                if ($lang !== '' && !in_array($lang, $allowed, true)) {
                    $allowed[] = $lang;
                }
            }
        }
        if (!in_array($this->language, $allowed, true)) {
            array_unshift($allowed, $this->language);
        }
        return $allowed;
    }

    /**
     * Validate a candidate language against the allowed set, falling back to the
     * primary language. The single server-side guard for the client-supplied
     * choice — every run/grade path funnels through it so a disallowed language
     * can never execute or grade.
     *
     * @param string|null $candidate the requested language
     * @return string an allowed language code
     */
    public function resolve_language($candidate) {
        $candidate = trim((string) $candidate);
        return in_array($candidate, $this->get_allowed_languages(), true) ? $candidate : $this->language;
    }

    /**
     * Whether this question grades authoritatively on the server (judge tier)
     * rather than in the browser (practice tier).
     *
     * @return bool
     */
    public function is_judge() {
        return $this->tier === 'judge';
    }

    public function get_correct_response() {
        // There is no single canonical solution to display.
        return null;
    }

    public function is_complete_response(array $response) {
        if ($this->is_judge()) {
            // The grade is computed on the server, so any non-empty code is gradable.
            return array_key_exists('answer', $response) && trim((string) $response['answer']) !== '';
        }
        // Practice tier: the student must run their code (which sets the score) to submit.
        return array_key_exists('score', $response) && $response['score'] !== ''
                && is_numeric($response['score']);
    }

    public function is_gradable_response(array $response) {
        return $this->is_complete_response($response);
    }

    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return $this->is_judge()
                ? get_string('pleasewritecode', 'qtype_codeatelier')
                : get_string('pleaserun', 'qtype_codeatelier');
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        // The chosen language is part of the response identity: a different
        // language grades differently, so re-picking it is a new gradable response
        // (and must regrade rather than be deduplicated).
        $samelanguage = question_utils::arrays_same_at_key_missing_is_blank(
                $prevresponse, $newresponse, 'language');
        if ($this->is_judge()) {
            // The grade derives from the code (and language); a re-check that only
            // changes the (ignored) client score is the same gradable response.
            return $samelanguage && question_utils::arrays_same_at_key_missing_is_blank(
                    $prevresponse, $newresponse, 'answer');
        }
        return $samelanguage
                && question_utils::arrays_same_at_key_missing_is_blank(
                        $prevresponse, $newresponse, 'answer')
                && question_utils::arrays_same_at_key_missing_is_blank(
                        $prevresponse, $newresponse, 'score');
    }

    public function summarise_response(array $response) {
        if (!array_key_exists('answer', $response) || trim($response['answer']) === '') {
            return null;
        }
        return shorten_text(trim($response['answer']), 200);
    }

    public function grade_response(array $response) {
        if ($this->is_judge()) {
            return $this->grade_judge_response($response);
        }
        $fraction = $this->fraction_from_response($response);
        return array($fraction, question_state::graded_state_for_fraction($fraction));
    }

    /**
     * Read the client-supplied score from the response and clamp it to [0, 1].
     *
     * Practice tier only. The judge tier ignores any client score entirely.
     *
     * @param array $response the response data
     * @return float the grade fraction
     */
    protected function fraction_from_response(array $response) {
        if (!array_key_exists('score', $response) || !is_numeric($response['score'])) {
            return 0.0;
        }
        return max(0.0, min(1.0, (float) $response['score']));
    }

    /**
     * Grade a judge-tier response server-side: run the code in the sandbox over
     * every test (visible and hidden) and decide the fraction with the matching
     * spec. The client score is ignored. If the sandbox cannot be reached the
     * response is left for grading rather than scored zero — a grade is never
     * faked on an infrastructure failure.
     *
     * @param array $response the response data
     * @return array [fraction, question_state]
     */
    protected function grade_judge_response(array $response) {
        $code = isset($response['answer']) ? (string) $response['answer'] : '';
        $language = $this->resolve_language($response['language'] ?? null);
        // Grading runs OFF the web request so an exam-end burst of submissions does
        // not block the server. We only read the memo here: if the scheduled judge
        // task has already computed this answer (or it is an identical resubmission),
        // record the grade; otherwise leave it awaiting automatic grading. The task
        // runs the sandbox, populates the memo, and regrades the attempt.
        $result = $this->cached_judge_result($code, $language);
        if ($result === null) {
            return array(0.0, question_state::$needsgrading);
        }
        $fraction = max(0.0, min(1.0, (float) $result['fraction']));
        return array($fraction, question_state::graded_state_for_fraction($fraction));
    }

    /**
     * The configured judge-tier runner, or the test-injected one.
     *
     * @return \qtype_codeatelier\judge\judge0_runner|null null if no endpoint is configured
     */
    protected function judge_runner() {
        if ($this->judgerunner !== null) {
            return $this->judgerunner;
        }
        return \qtype_codeatelier\judge\judge0_runner::from_config(
                $this->cputimelimit !== null ? (float) $this->cputimelimit : null,
                $this->memorylimit !== null ? (int) $this->memorylimit : null);
    }

    /**
     * The effective judge limits that take part in the result-cache key: the
     * per-question values when set, otherwise the site defaults.
     *
     * @param \qtype_codeatelier\judge\judge0_runner|null $runner
     * @return array ['cputime' => float, 'memory' => int]
     */
    protected function judge_limits($runner) {
        if ($runner !== null) {
            return array('cputime' => $runner->get_cpu_time_limit(), 'memory' => $runner->get_memory_limit());
        }
        return array(
            'cputime' => $this->cputimelimit !== null ? (float) $this->cputimelimit
                    : \qtype_codeatelier\judge\judge0_runner::default_cpu_time_limit(),
            'memory' => $this->memorylimit !== null ? (int) $this->memorylimit
                    : \qtype_codeatelier\judge\judge0_runner::default_memory_limit(),
        );
    }

    /**
     * Grade the given code on the judge tier, using the memoised result when one
     * exists and running the sandbox (then caching) on a miss. May make a network
     * call on a cache miss — do not call from a render path; use
     * {@see cached_judge_result()} there.
     *
     * @param string $code the student submission
     * @param string|null $language the chosen language; null/invalid falls back to the primary
     * @return array|null the grading result (see judge_grader::grade), or null if no endpoint is configured
     */
    public function get_judge_result($code, $language = null) {
        $runner = $this->judge_runner();
        if ($runner === null) {
            return null;
        }
        $language = $this->resolve_language($language);
        $limits = $this->judge_limits($runner);
        $hash = \qtype_codeatelier\judge\result_cache::key(
                $language, (string) $code, $this->tests, $limits);
        $cached = \qtype_codeatelier\judge\result_cache::get($hash);
        if ($cached !== null) {
            return $cached;
        }
        $grader = new \qtype_codeatelier\judge\judge_grader($runner);
        $result = $grader->grade((string) $code, $language, $this->tests);
        if (empty($result['infrastructure'])) {
            \qtype_codeatelier\judge\result_cache::put($hash, $result);
        }
        return $result;
    }

    /**
     * Run the code against the VISIBLE example tests only and return the per-test
     * results — a formative, non-authoritative run for the judge tier (so a
     * compiled-language student can try their code before the real submit). Hidden
     * tests are never executed or returned, and no grade is recorded.
     *
     * @param string $code the student submission
     * @param string|null $language the chosen language; null/invalid falls back to the primary
     * @return array|null the judge_grader result over the visible tests, or null if no endpoint is configured
     */
    public function run_examples($code, $language = null) {
        $runner = $this->judge_runner();
        if ($runner === null) {
            return null;
        }
        $visible = array();
        foreach ($this->tests as $test) {
            if ((int) $test->visibility === 1) {
                $visible[] = $test;
            }
        }
        if (!$visible) {
            return array('verdict' => 'AC', 'compileoutput' => '', 'testresults' => array());
        }
        $grader = new \qtype_codeatelier\judge\judge_grader($runner);
        return $grader->grade((string) $code, $this->resolve_language($language), $visible);
    }

    /**
     * The memoised judge result for the given code, or null on a cache miss.
     * Never runs the sandbox — safe to call from a render path (review).
     *
     * @param string $code the student submission
     * @param string|null $language the chosen language; null/invalid falls back to the primary
     * @return array|null
     */
    public function cached_judge_result($code, $language = null) {
        $limits = $this->judge_limits($this->judge_runner());
        $hash = \qtype_codeatelier\judge\result_cache::key(
                $this->resolve_language($language), (string) $code, $this->tests, $limits);
        return \qtype_codeatelier\judge\result_cache::get($hash);
    }
}
