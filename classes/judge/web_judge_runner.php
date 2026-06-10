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
 * Client for the self-hosted web-judge service (a headless-browser grader).
 *
 * The secure counterpart of the in-browser web grading: the service renders the
 * student's file set in real Chromium and evaluates the SAME declarative DOM
 * assertions in page context, so the student cannot see or tamper with the
 * result and hidden assertions never reach the browser. The plugin posts the
 * file set + assertions and gets back each assertion's pass/fail; the grader
 * aggregates the weighted mark (tier parity with the practice grader).
 *
 * Mirrors {@see judge0_runner}: an admin-configured base URL, an optional auth
 * token, and a read-only health probe.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class web_judge_runner {
    /** @var string base URL of the web-judge service. */
    private $baseurl;

    /** @var string|null optional shared auth token. */
    private $token;

    /**
     * @param string $baseurl base URL of the web-judge service
     * @param string|null $token optional shared auth token
     */
    public function __construct(string $baseurl, ?string $token = null) {
        $this->baseurl = rtrim($baseurl, '/');
        $this->token = $token;
    }

    /**
     * Build a runner from the admin settings, or null when no endpoint is set.
     *
     * @return self|null
     */
    public static function from_config(): ?self {
        $baseurl = get_config('qtype_codeatelier', 'webjudgebaseurl');
        if (empty($baseurl)) {
            return null;
        }
        $token = get_config('qtype_codeatelier', 'webjudgetoken');
        return new self($baseurl, ($token !== false && $token !== '') ? $token : null);
    }

    /**
     * Health probe: is the service reachable, and what version?
     *
     * @return array {ok: bool, version?: string, error?: string}
     */
    public function ping(): array {
        $response = $this->request('GET', '/health', null);
        if (isset($response['_error'])) {
            return ['ok' => false, 'error' => (string) $response['_error']];
        }
        return ['ok' => true, 'version' => (string) ($response['version'] ?? '')];
    }

    /**
     * Grade a file set against a set of DOM assertions in the headless browser.
     *
     * @param array $files the student file set, each ['name' => ..., 'content' => ...]
     * @param array $assertions each ['selector', 'property', 'matcher', 'expected', 'actions']
     * @param int $timeoutms per-assertion render timeout in milliseconds
     * @return array {results: [{pass, actual, error}, ...]} aligned with $assertions,
     *   or {_error: message} when the service is unreachable or errors
     */
    public function grade(array $files, array $assertions, int $timeoutms = 5000): array {
        $payload = json_encode([
            'files' => array_values($files),
            'assertions' => array_values($assertions),
            'timeoutMs' => $timeoutms,
        ]);
        return $this->request('POST', '/grade', $payload);
    }

    /**
     * Make a JSON request to the service.
     *
     * @param string $method GET or POST
     * @param string $path path relative to the base URL
     * @param string|null $body JSON body for POST
     * @return array decoded JSON, or ['_error' => message] on failure
     */
    private function request(string $method, string $path, ?string $body): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        // ignoresecurity: the base URL is an admin-configured, trusted service,
        // typically on a private network — the same posture as the judge runner.
        $curl = new \curl(['ignoresecurity' => true]);
        $headers = ['Content-Type: application/json'];
        if (!empty($this->token)) {
            $headers[] = 'X-Auth-Token: ' . $this->token;
        }
        $curl->setHeader($headers);
        $curl->setopt(['CURLOPT_TIMEOUT' => 60, 'CURLOPT_CONNECTTIMEOUT' => 10]);

        $url = $this->baseurl . $path;
        $raw = ($method === 'POST') ? $curl->post($url, $body) : $curl->get($url);

        if ($curl->get_errno()) {
            return ['_error' => 'web-judge connection failed: ' . $curl->error];
        }
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($httpcode < 200 || $httpcode >= 300) {
            return ['_error' => "web-judge http {$httpcode}"];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['_error' => 'web-judge returned an invalid response'];
        }
        return $decoded;
    }
}
