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
    ];

    /** @var string base URL of the Judge0 instance (no trailing slash). */
    private $baseurl;

    /** @var string|null optional auth token (self-host token or hosted-gateway key). */
    private $apikey;

    /** @var float CPU-time limit in seconds per run. */
    private $cputimelimit;

    /** @var int memory limit in kilobytes per run. */
    private $memorylimit;

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

    /**
     * Run student code against a set of cases.
     *
     * Execution only — comparing output against the expected value is the grader's
     * job, from the same declarative spec the browser grader uses.
     *
     * @param string $code the student source
     * @param string $language a CodeAtelier language key (c, cpp, java, python, javascript)
     * @param array $cases the test cases, each at least ['input' => stdin]
     * @return array per-case results aligned by index (see run_case())
     */
    public function run(string $code, string $language, array $cases): array {
        $languageid = self::language_id($language);
        if ($languageid === null) {
            throw new \coding_exception("Unsupported judge-tier language: {$language}");
        }
        $results = [];
        foreach ($cases as $case) {
            $stdin = isset($case['input']) ? (string) $case['input'] : '';
            $results[] = $this->run_case($code, $languageid, $stdin);
        }
        return $results;
    }

    /**
     * Submit one case to Judge0 synchronously and map the response.
     *
     * @param string $code the student source
     * @param int $languageid the Judge0 language id
     * @param string $stdin standard input for the run
     * @return array {outcome, stdout, stderr, compileoutput, time, memory, statusid, statusdesc}
     *   where outcome is one of: ran | ce | tle | mle | re | internal
     */
    public function run_case(string $code, int $languageid, string $stdin): array {
        $response = $this->submit([
            'source_code' => base64_encode($code),
            'language_id' => $languageid,
            'stdin' => base64_encode($stdin),
            'cpu_time_limit' => $this->cputimelimit,
            'memory_limit' => $this->memorylimit,
        ]);

        if (isset($response['_error'])) {
            return [
                'outcome' => 'internal',
                'stdout' => '',
                'stderr' => '',
                'compileoutput' => '',
                'time' => null,
                'memory' => null,
                'statusid' => 0,
                'statusdesc' => $response['_error'],
            ];
        }

        $statusid = (int) ($response['status']['id'] ?? 0);
        $memory = isset($response['memory']) ? (int) $response['memory'] : null;
        return [
            'outcome' => $this->outcome_for($statusid, $memory),
            'stdout' => self::decode($response['stdout'] ?? null),
            'stderr' => self::decode($response['stderr'] ?? null),
            'compileoutput' => self::decode($response['compile_output'] ?? null),
            'time' => isset($response['time']) ? (float) $response['time'] : null,
            'memory' => $memory,
            'statusid' => $statusid,
            'statusdesc' => (string) ($response['status']['description'] ?? ''),
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
     * POST a submission to Judge0 and wait for the result.
     *
     * @param array $payload the submission body
     * @return array the decoded Judge0 response, or ['_error' => message] on failure
     */
    private function submit(array $payload): array {
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

        $url = $this->baseurl . '/submissions?base64_encoded=true&wait=true';
        $raw = $curl->post($url, json_encode($payload));

        if ($curl->get_errno()) {
            return ['_error' => 'judge0 connection failed: ' . $curl->error];
        }
        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
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
