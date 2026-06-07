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
 * Judge-tier runner: runs student code in a Judge0 sandbox.
 *
 * This is the server-side implementation of the runner seam (the practice tier's
 * in-browser Web Worker is the other one). It submits each test case to a Judge0
 * instance, which compiles and runs the code under enforced CPU-time and memory
 * limits, and returns a per-case execution result: the captured output plus an
 * execution OUTCOME (compiled? ran to completion? timed out? crashed?).
 *
 * It deliberately does not decide AC/WA. Correctness is the matching spec's job,
 * interpreted from the same declarative rules on both tiers, so a given answer
 * grades identically in the browser and on the server. Judge0 tells us whether
 * the program ran; our matcher decides whether the output is right.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class judge0_runner {

    /** @var array CodeAtelier language key => Judge0 language id (Judge0 CE 1.13.1). */
    private const LANGUAGE_IDS = [
        'c' => 50,
        'cpp' => 54,
        'java' => 62,
        'python' => 71,
        'javascript' => 63,
        'sql' => 82,
        'go' => 60,
        'rust' => 73,
        'php' => 68,
        'typescript' => 74,
        'csharp' => 51,
        'kotlin' => 78,
        'ruby' => 72,
    ];

    /** @var int the Judge0 "Multi-file program" language id (project scenario). */
    const MULTIFILE_LANGUAGE_ID = 89;

    /** @var string base URL of the Judge0 instance (no trailing slash). */
    private $baseurl;

    /** @var string|null optional auth token (self-host token or hosted-gateway key). */
    private $apikey;

    /** @var float CPU-time limit in seconds per run. */
    private $cputimelimit;

    /** @var int memory limit in kilobytes per run. */
    private $memorylimit;

    /** @var string|null base64 ZIP of support/data files, added to each submission. */
    private $additionalfiles = null;

    /**
     * @param string $baseurl base URL of the Judge0 instance
     * @param string|null $apikey optional auth token
     * @param float $cputimelimit CPU-time limit in seconds
     * @param int $memorylimit memory limit in kilobytes
     */
    public function __construct(string $baseurl, ?string $apikey = null,
            float $cputimelimit = 2.0, int $memorylimit = 128000) {
        $this->baseurl = rtrim($baseurl, '/');
        $this->apikey = $apikey;
        $this->cputimelimit = $cputimelimit;
        $this->memorylimit = $memorylimit;
    }

    /**
     * Attach support/data files to every submission (a base64 ZIP Judge0 unpacks
     * into the working directory). Null clears them.
     *
     * @param string|null $base64zip the base64-encoded ZIP, or null
     */
    public function set_additional_files(?string $base64zip): void {
        $this->additionalfiles = $base64zip;
    }

    /** @var float the built-in fallback CPU-time limit in seconds. */
    const DEFAULT_CPU_TIME_LIMIT = 2.0;

    /** @var int the built-in fallback memory limit in kilobytes. */
    const DEFAULT_MEMORY_LIMIT = 128000;

    /**
     * Build a runner from the plugin's admin settings, optionally with
     * per-question limit overrides (null falls back to the site default, which
     * itself falls back to the built-in default).
     *
     * @param float|null $cputimelimit per-question CPU-time limit in seconds, or null
     * @param int|null $memorylimit per-question memory limit in kilobytes, or null
     * @return self|null the configured runner, or null if no base URL is set
     */
    public static function from_config(?float $cputimelimit = null, ?int $memorylimit = null): ?self {
        $baseurl = get_config('qtype_codeatelier', 'judge0baseurl');
        if (empty($baseurl)) {
            return null;
        }
        $apikey = get_config('qtype_codeatelier', 'judge0apikey');
        $cpu = $cputimelimit ?? self::default_cpu_time_limit();
        $memory = $memorylimit ?? self::default_memory_limit();
        return new self($baseurl, $apikey !== false && $apikey !== '' ? $apikey : null, $cpu, $memory);
    }

    /**
     * @return float the site-default CPU-time limit (admin setting, or built-in)
     */
    public static function default_cpu_time_limit(): float {
        $value = get_config('qtype_codeatelier', 'judge0cputimelimit');
        return ($value !== false && $value !== '') ? (float) $value : self::DEFAULT_CPU_TIME_LIMIT;
    }

    /**
     * @return int the site-default memory limit in kilobytes (admin setting, or built-in)
     */
    public static function default_memory_limit(): int {
        $value = get_config('qtype_codeatelier', 'judge0memorylimit');
        return ($value !== false && $value !== '') ? (int) $value : self::DEFAULT_MEMORY_LIMIT;
    }

    /**
     * @return float the per-run CPU-time limit in seconds
     */
    public function get_cpu_time_limit(): float {
        return $this->cputimelimit;
    }

    /**
     * @return int the per-run memory limit in kilobytes
     */
    public function get_memory_limit(): int {
        return $this->memorylimit;
    }

    /**
     * Map a CodeAtelier language key to a Judge0 language id.
     *
     * @param string $language a CodeAtelier language key
     * @return int|null the Judge0 language id, or null if unsupported on the judge tier
     */
    public static function language_id(string $language): ?int {
        return self::LANGUAGE_IDS[$language] ?? null;
    }

    /** @var int how long to poll a batch for completion before giving up (seconds). */
    const POLL_TIMEOUT = 90;

    /** @var int milliseconds between batch status polls. */
    const POLL_INTERVAL_MS = 500;

    /**
     * Probe the Judge0 instance for the admin status page.
     *
     * Calls /about (reachable? what version?) and, when reachable, /workers (the
     * sandbox's own queue depth). Read-only; never touches a submission.
     *
     * @return array {ok: bool, version?: string, workers?: array, error?: string}
     */
    public function ping(): array {
        $about = $this->request('GET', '/about', null);
        if (isset($about['_error'])) {
            return ['ok' => false, 'error' => (string) $about['_error']];
        }
        $result = ['ok' => true, 'version' => (string) ($about['version'] ?? '')];
        $workers = $this->request('GET', '/workers', null);
        // /workers is a list of queue descriptors (available/active/idle counts);
        // a deployment may not expose it, so its absence is not a failure.
        if (is_array($workers) && !isset($workers['_error'])) {
            $result['workers'] = $workers;
        }
        return $result;
    }

    /**
     * Run student code against a set of cases.
     *
     * All cases are submitted to Judge0 in a single BATCH and run in parallel
     * across its workers, then polled to completion — far faster and far kinder to
     * the sandbox than one blocking submission per case. Execution only: comparing
     * output against the expected value is the grader's job.
     *
     * @param string $code the student source (the default for every case)
     * @param string $language a CodeAtelier language key (c, cpp, java, python, javascript, sql)
     * @param array $cases the test cases, each ['input' => stdin] and/or ['source' => full source override]
     * @return array per-case results aligned by index ({outcome, stdout, stderr,
     *   compileoutput, time, memory, statusid, statusdesc}; outcome is one of
     *   ran | ce | tle | mle | re | internal)
     */
    public function run(string $code, string $language, array $cases): array {
        $languageid = self::language_id($language);
        if ($languageid === null) {
            throw new \coding_exception("Unsupported judge-tier language: {$language}");
        }
        $cases = array_values($cases);
        if (!$cases) {
            return [];
        }

        $submissions = [];
        foreach ($cases as $case) {
            // A case may override the whole source (the SQL scenario varies the
            // source per test — schema + query + checker — rather than stdin).
            $source = isset($case['source']) ? (string) $case['source'] : $code;
            $stdin = isset($case['input']) ? (string) $case['input'] : '';
            $submission = [
                'source_code' => base64_encode($source),
                'language_id' => $languageid,
                'stdin' => base64_encode($stdin),
                'cpu_time_limit' => $this->cputimelimit,
                'memory_limit' => $this->memorylimit,
            ];
            if ($this->additionalfiles !== null) {
                // Judge0 unpacks this base64 ZIP into the working directory.
                $submission['additional_files'] = $this->additionalfiles;
            }
            $submissions[] = $submission;
        }

        $responses = $this->submit_batch($submissions);
        if (isset($responses['_error'])) {
            // An infrastructure failure applies to every case; the grader routes
            // this to needs-grading rather than a faked zero.
            return array_fill(0, count($cases), self::internal_result($responses['_error']));
        }

        $results = [];
        foreach ($cases as $i => $case) {
            $results[] = (isset($responses[$i]) && is_array($responses[$i]))
                ? $this->map_response($responses[$i])
                : self::internal_result('judge0 returned no result for a case');
        }
        return $results;
    }

    /**
     * Run a multi-file project (Judge0 language 89): the same source package is run
     * for every case with the case's stdin. The package is a base64 ZIP of the
     * project's source files plus a compile and run script (see project_packager).
     * Correctness against the expected output is the grader's job.
     *
     * @param string $base64zip the project package (additional_files for every case)
     * @param array $cases the test cases, each ['input' => stdin]
     * @return array per-case results aligned by index (same shape as {@see run()})
     */
    public function run_multifile(string $base64zip, array $cases): array {
        $cases = array_values($cases);
        if (!$cases) {
            return [];
        }
        $submissions = [];
        foreach ($cases as $case) {
            $stdin = isset($case['input']) ? (string) $case['input'] : '';
            $submissions[] = [
                'source_code' => base64_encode(''),
                'language_id' => self::MULTIFILE_LANGUAGE_ID,
                'additional_files' => $base64zip,
                'stdin' => base64_encode($stdin),
                'cpu_time_limit' => $this->cputimelimit,
                'memory_limit' => $this->memorylimit,
            ];
        }
        $responses = $this->submit_batch($submissions);
        if (isset($responses['_error'])) {
            return array_fill(0, count($cases), self::internal_result($responses['_error']));
        }
        $results = [];
        foreach ($cases as $i => $case) {
            $results[] = (isset($responses[$i]) && is_array($responses[$i]))
                ? $this->map_response($responses[$i])
                : self::internal_result('judge0 returned no result for a case');
        }
        return $results;
    }

    /**
     * Map a single Judge0 submission response to a per-case result.
     *
     * @param array $response one decoded Judge0 submission
     * @return array {outcome, stdout, stderr, compileoutput, time, memory, statusid, statusdesc}
     */
    private function map_response(array $response): array {
        $statusid = (int) ($response['status']['id'] ?? 0);
        $memory = isset($response['memory']) ? (int) $response['memory'] : null;
        return [
            'outcome' => $this->outcome_for($statusid, $memory),
            // stdout drives grading, so it is never altered; stderr and the compiler
            // output are student-facing diagnostics, so the sandbox noise is trimmed.
            'stdout' => self::decode($response['stdout'] ?? null),
            'stderr' => self::clean_diagnostic(self::decode($response['stderr'] ?? null)),
            'compileoutput' => self::clean_diagnostic(self::decode($response['compile_output'] ?? null)),
            'time' => isset($response['time']) ? (float) $response['time'] : null,
            'memory' => $memory,
            'statusid' => $statusid,
            'statusdesc' => (string) ($response['status']['description'] ?? ''),
        ];
    }

    /**
     * Tidy a student-facing diagnostic: drop the sandbox box path so messages read
     * as plain file names (Main.java:5 rather than /box/Main.java:5), and cap very
     * long output so one runaway error message does not flood the page.
     *
     * @param string $text the raw stderr or compiler output
     * @return string
     */
    private static function clean_diagnostic(string $text): string {
        if ($text === '') {
            return $text;
        }
        $text = str_replace('/box/', '', $text);
        $max = 4000;
        if (strlen($text) > $max) {
            $text = rtrim(substr($text, 0, $max)) . "\n…";
        }
        return $text;
    }

    /**
     * A result row for an infrastructure failure (sandbox unreachable / internal).
     *
     * @param string $message the failure detail
     * @return array
     */
    private static function internal_result(string $message): array {
        return [
            'outcome' => 'internal', 'stdout' => '', 'stderr' => '', 'compileoutput' => '',
            'time' => null, 'memory' => null, 'statusid' => 0, 'statusdesc' => $message,
        ];
    }

    /**
     * Translate a Judge0 status (and reported memory) into an execution outcome.
     *
     * Judge0 CE has no memory-limit-exceeded status: an OOM kill surfaces as a
     * runtime error with the peak memory capped at the configured limit. So a
     * runtime error whose memory is at the limit is reclassified as MLE.
     *
     * @param int $statusid the Judge0 submission status id
     * @param int|null $memory the reported peak memory in kilobytes
     * @return string ran | ce | tle | mle | re | internal
     */
    private function outcome_for(int $statusid, ?int $memory): string {
        // 3 Accepted and 4 Wrong Answer both mean "ran to completion" here: we send
        // no expected_output, so output correctness is the matcher's call, not Judge0's.
        if ($statusid === 3 || $statusid === 4) {
            return 'ran';
        }
        if ($statusid === 5) {
            return 'tle';
        }
        if ($statusid === 6) {
            return 'ce';
        }
        // 7-12: SIGSEGV / SIGXFSZ / SIGFPE / SIGABRT / NZEC / other runtime error.
        if ($statusid >= 7 && $statusid <= 12) {
            // An OOM kill is a runtime error whose memory sits at the limit.
            if ($memory !== null && $memory >= $this->memorylimit * 0.99) {
                return 'mle';
            }
            return 're';
        }
        // 1 In Queue, 2 Processing (not expected with wait=true), 13 Internal, 14 Exec Format.
        return 'internal';
    }

    /**
     * Decode a base64 field from a Judge0 response, tolerating a null/missing field.
     *
     * @param string|null $value the base64 value or null
     * @return string the decoded string ('' when absent)
     */
    private static function decode(?string $value): string {
        return $value === null ? '' : (string) base64_decode($value);
    }

    /**
     * Submit a batch of cases (async) and poll until all have finished.
     *
     * @param array $submissions the per-case submission payloads
     * @return array the decoded submissions aligned by index, or ['_error' => message]
     */
    private function submit_batch(array $submissions): array {
        $created = $this->request('POST', '/submissions/batch?base64_encoded=true&wait=false',
                json_encode(['submissions' => $submissions]));
        if (isset($created['_error'])) {
            return $created;
        }
        if (!is_array($created) || count($created) !== count($submissions)) {
            return ['_error' => 'judge0 batch returned an unexpected response'];
        }
        $tokens = [];
        foreach ($created as $entry) {
            if (!is_array($entry) || empty($entry['token'])) {
                return ['_error' => 'judge0 batch did not return a token for every case'];
            }
            $tokens[] = $entry['token'];
        }
        return $this->poll_batch($tokens);
    }

    /**
     * Poll a batch of tokens until every submission has finished (status > 2).
     *
     * @param array $tokens the submission tokens, in case order
     * @return array the finished submissions aligned by index, or ['_error' => message]
     */
    private function poll_batch(array $tokens): array {
        $query = '/submissions/batch?base64_encoded=true&tokens=' . implode(',', $tokens)
                . '&fields=token,status,stdout,stderr,compile_output,time,memory';
        $deadline = time() + self::POLL_TIMEOUT;
        do {
            $decoded = $this->request('GET', $query, null);
            if (isset($decoded['_error'])) {
                return $decoded;
            }
            $subs = $decoded['submissions'] ?? null;
            if (!is_array($subs) || count($subs) !== count($tokens)) {
                return ['_error' => 'judge0 batch poll returned an unexpected response'];
            }
            $pending = false;
            foreach ($subs as $sub) {
                // 1 In Queue, 2 Processing — still running.
                if ((int) ($sub['status']['id'] ?? 0) <= 2) {
                    $pending = true;
                    break;
                }
            }
            if (!$pending) {
                return $subs;
            }
            usleep(self::POLL_INTERVAL_MS * 1000);
        } while (time() < $deadline);

        return ['_error' => 'judge0 batch timed out'];
    }

    /**
     * Make one JSON request to Judge0.
     *
     * @param string $method GET or POST
     * @param string $path the path + query relative to the base URL
     * @param string|null $body the request body for POST
     * @return array the decoded JSON, or ['_error' => message] on failure
     */
    private function request(string $method, string $path, ?string $body): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // ignoresecurity: the base URL is an admin-configured, trusted judge server,
        // commonly on a private network. Moodle's SSRF blocklist exists to stop
        // user-supplied URLs reaching internal services; it does not apply to a
        // trusted server-to-server endpoint an admin deliberately configured.
        $curl = new \curl(['ignoresecurity' => true]);
        $headers = ['Content-Type: application/json'];
        if (!empty($this->apikey)) {
            // Covers a self-hosted auth token and a hosted-gateway key alike.
            $headers[] = 'X-Auth-Token: ' . $this->apikey;
            $headers[] = 'X-RapidAPI-Key: ' . $this->apikey;
        }
        $curl->setHeader($headers);
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10]);

        $url = $this->baseurl . $path;
        $raw = ($method === 'POST') ? $curl->post($url, $body) : $curl->get($url);

        if ($curl->get_errno()) {
            return ['_error' => 'judge0 connection failed: ' . $curl->error];
        }
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode < 200 || $httpcode >= 300) {
            return ['_error' => "judge0 http {$httpcode}"];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['_error' => 'judge0 returned an invalid response'];
        }
        return $decoded;
    }
}
