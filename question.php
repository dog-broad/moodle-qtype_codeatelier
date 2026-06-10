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

    /** @var string|null typed signature for static-language function grading. */
    public $functionsignature;

    /** @var string|null project scenario: JSON array of starter files [{name, content}, ...]. */
    public $projectfiles = null;

    /** @var string|null project scenario: the entry/main file Judge0 compiles and runs. */
    public $entryfile = null;

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

    /** @var string|null reference solution (primary language) for validate-on-save and capture. */
    public $referencesolution = null;

    /** @var int 1 grades all-or-nothing (full marks only if every test passes), 0 partial credit. */
    public $allornothing = 0;

    /** @var string|null custom-checker language; null/empty means no checker (use the matching rule). */
    public $checkerlang = null;

    /** @var string|null custom-checker source: decides AC/WA per test instead of the matching rule. */
    public $checkersource = null;

    /** @var array the test cases (rows from qtype_codeatelier_tests). */
    public $tests = [];

    /** @var \qtype_codeatelier\judge\judge0_runner|null injected runner; null means build from config. */
    public $judgerunner = null;

    /** @var \qtype_codeatelier\judge\web_judge_runner|null injected web-judge runner; null means build from config. */
    public $webjudgerunner = null;

    public function get_expected_data() {
        // The student's chosen language is a per-attempt response variable (a
        // question may allow a set of languages). It is re-validated server-side
        // on every grading path, so a tampered value can only fall back to the
        // primary language — see resolve_language().
        // Soft anti-cheat events are no longer a response variable: they are written
        // to a server-side append-only log during the attempt (see the proctor module
        // and qtype_codeatelier\local\integrity\proctor_log), so they cannot be forged
        // or cleared through the form.
        return ['answer' => PARAM_RAW, 'score' => PARAM_RAW_TRIMMED, 'language' => PARAM_ALPHANUMEXT];
    }

    /**
     * The set of languages a student may choose from. Always includes the primary
     * language; an empty/unset languages column means single-language.
     *
     * @return string[] the allowed language codes, primary first
     */
    public function get_allowed_languages() {
        $allowed = [];
        if ($this->languages !== null && trim((string) $this->languages) !== '') {
            foreach (explode(',', (string) $this->languages) as $lang) {
                $lang = trim($lang);
                if ($lang !== '' && $lang !== $this->language && !in_array($lang, $allowed, true)) {
                    $allowed[] = $lang;
                }
            }
        }
        // The primary language always leads the set.
        array_unshift($allowed, $this->language);
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

    /**
     * Whether this is a multi-file project question (judge-only). The student's
     * answer is then a canonical JSON file set rather than a single code string.
     *
     * @return bool
     */
    public function is_project() {
        return $this->scenario === 'project';
    }

    /**
     * Whether the student's answer is a canonical JSON file set rather than a
     * single code string. True for multi-file projects (judge tier) and for web
     * (frontend) questions (practice tier), which both carry several source files.
     *
     * @return bool
     */
    public function uses_file_set() {
        return $this->scenario === 'project' || $this->scenario === 'web';
    }

    /**
     * Decode the project file set from a response's answer (canonical JSON).
     *
     * @param array $response the response data
     * @return array name => content (empty on a malformed or empty answer)
     */
    public function project_files_from_response(array $response) {
        $answer = isset($response['answer']) ? (string) $response['answer'] : '';
        return \qtype_codeatelier\judge\project_packager::decode_files($answer);
    }

    public function get_correct_response() {
        // There is no single canonical solution to display.
        return null;
    }

    public function is_complete_response(array $response) {
        if ($this->is_judge()) {
            if ($this->is_project()) {
                // A project is gradable once the entry file carries some code.
                $files = $this->project_files_from_response($response);
                $entry = (string) $this->entryfile;
                return isset($files[$entry]) && trim($files[$entry]) !== '';
            }
            if ($this->scenario === 'web') {
                // A web answer is gradable once it carries a file set.
                return !empty($this->project_files_from_response($response));
            }
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
            $prevresponse,
            $newresponse,
            'language'
        );
        if ($this->is_judge()) {
            // The grade derives from the code (and language); a re-check that only
            // changes the (ignored) client score is the same gradable response.
            return $samelanguage && question_utils::arrays_same_at_key_missing_is_blank(
                $prevresponse,
                $newresponse,
                'answer'
            );
        }
        return $samelanguage
                && question_utils::arrays_same_at_key_missing_is_blank(
                    $prevresponse,
                    $newresponse,
                    'answer'
                )
                && question_utils::arrays_same_at_key_missing_is_blank(
                    $prevresponse,
                    $newresponse,
                    'score'
                );
    }

    public function summarise_response(array $response) {
        if ($this->uses_file_set()) {
            $files = $this->project_files_from_response($response);
            return $files ? shorten_text(implode(', ', array_keys($files)), 200) : null;
        }
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
        return [$fraction, question_state::graded_state_for_fraction($fraction)];
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
            return [0.0, question_state::$needsgrading];
        }
        $fraction = max(0.0, min(1.0, (float) $result['fraction']));
        return [$fraction, question_state::graded_state_for_fraction($fraction)];
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
            $this->memorylimit !== null ? (int) $this->memorylimit : null
        );
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
            return ['cputime' => $runner->get_cpu_time_limit(), 'memory' => $runner->get_memory_limit()];
        }
        return [
            'cputime' => $this->cputimelimit !== null ? (float) $this->cputimelimit
                    : \qtype_codeatelier\judge\judge0_runner::default_cpu_time_limit(),
            'memory' => $this->memorylimit !== null ? (int) $this->memorylimit
                    : \qtype_codeatelier\judge\judge0_runner::default_memory_limit(),
        ];
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
        if ($this->scenario === 'web') {
            return $this->get_web_judge_result($code);
        }
        $runner = $this->judge_runner();
        if ($runner === null) {
            return null;
        }
        $language = $this->resolve_language($language);
        $limits = $this->judge_limits($runner);
        $schemasql = (string) $this->schemasql;
        $allornothing = !empty($this->allornothing);
        $hash = \qtype_codeatelier\judge\result_cache::key(
            $language,
            (string) $code,
            $this->tests,
            $limits,
            $schemasql,
            $allornothing,
            $this->data_files_hash(),
            (string) $this->scenario,
            (string) $this->entrypoint,
            (string) $this->functionsignature,
            (string) $this->entryfile,
            (string) $this->checkersource,
            (string) $this->checkerlang
        );
        $cached = \qtype_codeatelier\judge\result_cache::get($hash);
        if ($cached !== null) {
            return $cached;
        }
        $runner->set_additional_files($this->data_files_zip());
        $grader = new \qtype_codeatelier\judge\judge_grader($runner);
        $result = $grader->grade(
            (string) $code,
            $language,
            $this->tests,
            $schemasql,
            $allornothing,
            (string) $this->scenario,
            (string) $this->entrypoint,
            (string) $this->functionsignature,
            (string) $this->entryfile,
            (string) $this->checkersource,
            (string) $this->checkerlang
        );
        if (empty($result['infrastructure'])) {
            \qtype_codeatelier\judge\result_cache::put($hash, $result);
        }
        return $result;
    }

    /**
     * The configured web-judge runner, or the test-injected one.
     *
     * @return \qtype_codeatelier\judge\web_judge_runner|null null if no endpoint is configured
     */
    public function web_judge_runner() {
        if ($this->webjudgerunner !== null) {
            return $this->webjudgerunner;
        }
        return \qtype_codeatelier\judge\web_judge_runner::from_config();
    }

    /**
     * The content-cache key for a web (frontend) submission. The verdict depends
     * only on the file set + the assertions (carried in the test signature) +
     * all-or-nothing; there are no sandbox limits, so they are empty.
     *
     * @param string $code the canonical file-set JSON
     * @return string
     */
    protected function web_cache_key($code) {
        return \qtype_codeatelier\judge\result_cache::key(
            'javascript',
            (string) $code,
            $this->tests,
            [],
            '',
            !empty($this->allornothing),
            '',
            'web',
            '',
            '',
            ''
        );
    }

    /**
     * Grade a web answer authoritatively via the web-judge service, memoised.
     * May make a network call on a cache miss — not for a render path.
     *
     * @param string $code the canonical file-set JSON
     * @return array|null the grading result, or null if no endpoint is configured
     */
    protected function get_web_judge_result($code) {
        $runner = $this->web_judge_runner();
        if ($runner === null) {
            return null;
        }
        $hash = $this->web_cache_key($code);
        $cached = \qtype_codeatelier\judge\result_cache::get($hash);
        if ($cached !== null) {
            return $cached;
        }
        $grader = new \qtype_codeatelier\judge\web_judge_grader($runner);
        $result = $grader->grade((string) $code, $this->tests, !empty($this->allornothing));
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
        $visible = [];
        foreach ($this->tests as $test) {
            if ((int) $test->visibility === 1) {
                $visible[] = $test;
            }
        }
        if (!$visible) {
            return ['verdict' => 'AC', 'compileoutput' => '', 'testresults' => []];
        }
        $runner->set_additional_files($this->data_files_zip());
        $grader = new \qtype_codeatelier\judge\judge_grader($runner);
        return $grader->grade(
            (string) $code,
            $this->resolve_language($language),
            $visible,
            (string) $this->schemasql,
            !empty($this->allornothing),
            (string) $this->scenario,
            (string) $this->entrypoint,
            (string) $this->functionsignature,
            (string) $this->entryfile,
            (string) $this->checkersource,
            (string) $this->checkerlang
        );
    }

    /**
     * Run the code once against a student-supplied input on the judge tier — the
     * "run with my own input" scratch console. Formative: no grade, no tests.
     *
     * @param string $code the student submission
     * @param string|null $language the chosen language
     * @param string $input the student's own input (stdin, or a JSON argument array)
     * @return array|null the scratch result, or null if no endpoint is configured
     */
    public function run_scratch($code, $language, $input) {
        $runner = $this->judge_runner();
        if ($runner === null) {
            return null;
        }
        $runner->set_additional_files($this->data_files_zip());
        $grader = new \qtype_codeatelier\judge\judge_grader($runner);
        return $grader->run_scratch(
            (string) $code,
            $this->resolve_language($language),
            (string) $this->scenario,
            (string) $this->entrypoint,
            (string) $this->functionsignature,
            (string) $this->schemasql,
            (string) $input,
            (string) $this->entryfile
        );
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
        if ($this->scenario === 'web') {
            return \qtype_codeatelier\judge\result_cache::get($this->web_cache_key($code));
        }
        $limits = $this->judge_limits($this->judge_runner());
        $hash = \qtype_codeatelier\judge\result_cache::key(
            $this->resolve_language($language),
            (string) $code,
            $this->tests,
            $limits,
            (string) $this->schemasql,
            !empty($this->allornothing),
            $this->data_files_hash(),
            (string) $this->scenario,
            (string) $this->entrypoint,
            (string) $this->functionsignature,
            (string) $this->entryfile,
            (string) $this->checkersource,
            (string) $this->checkerlang
        );
        return \qtype_codeatelier\judge\result_cache::get($hash);
    }

    /**
     * The support/data files stored on this question, sorted by path.
     *
     * @return \stored_file[] the non-directory files (empty when none)
     */
    protected function data_files() {
        if (empty($this->contextid) || empty($this->id)) {
            return [];
        }
        return get_file_storage()->get_area_files(
            $this->contextid,
            'qtype_codeatelier',
            'datafiles',
            $this->id,
            'filepath, filename',
            false
        );
    }

    /**
     * A content hash of the support/data files, so editing a file invalidates the
     * judge result cache. Empty when there are no files.
     *
     * @return string
     */
    protected function data_files_hash() {
        $signature = [];
        foreach ($this->data_files() as $file) {
            $signature[] = $file->get_filepath() . $file->get_filename() . ':' . $file->get_contenthash();
        }
        if (!$signature) {
            return '';
        }
        sort($signature);
        return sha1(implode("\n", $signature));
    }

    /**
     * Package the support/data files into a base64 ZIP for Judge0 additional_files,
     * or null when there are none. The files appear in the working directory.
     *
     * @return string|null
     */
    protected function data_files_zip() {
        $files = $this->data_files();
        if (!$files) {
            return null;
        }
        $map = [];
        foreach ($files as $file) {
            $map[ltrim($file->get_filepath() . $file->get_filename(), '/')] = $file;
        }
        $tmp = make_request_directory() . '/datafiles.zip';
        $packer = get_file_packer('application/zip');
        if (!$packer->archive_to_pathname($map, $tmp)) {
            return null;
        }
        return base64_encode(file_get_contents($tmp));
    }
}
