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
 * A judge-tier runner test double that returns scripted per-case results without
 * touching the network, and counts how many times it ran (to assert memoisation).
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_stub_runner extends \qtype_codeatelier\judge\judge0_runner {
    /** @var array the per-case result rows to return. */
    private $rows;

    /** @var int how many times run() has been called. */
    private $calls = 0;

    /** @var string|null the language passed to the most recent run() call. */
    private $lastlanguage = null;

    /** @var array|null the cases the most recent run() call received. */
    private $lastcases = null;

    /** @var string|null the base64 package passed to the most recent run_multifile() call. */
    private $lastmultifilezip = null;

    /** @var array|null scripted per-case checker results, or null if run_checker is unused. */
    private $checkerrows = null;

    /** @var array|null the cases the most recent run_checker() received. */
    private $lastcheckercases = null;

    /**
     * Create the stub with the rows its run() will return.
     *
     * @param array $rows the per-case result rows run() should return
     */
    public function __construct(array $rows) {
        parent::__construct('http://stub.invalid', null, 2.0, 128000);
        $this->rows = $rows;
    }

    /**
     * Script the per-case results the next run_checker() call returns.
     *
     * @param array $rows the checker result rows
     */
    public function set_checker_rows(array $rows): void {
        $this->checkerrows = $rows;
    }

    /**
     * Return the scripted checker rows and record the cases received.
     *
     * @param string $checkerlang
     * @param string $checkersource
     * @param array $cases
     * @return array
     */
    public function run_checker(string $checkerlang, string $checkersource, array $cases): array {
        $this->calls++;
        $this->lastcheckercases = array_values($cases);
        return $this->checkerrows ?? [];
    }

    /**
     * The cases the most recent run_checker() received.
     *
     * @return array|null
     */
    public function last_checker_cases() {
        return $this->lastcheckercases;
    }

    /**
     * Return the scripted result rows and record the language received.
     *
     * @param string $code
     * @param string $language
     * @param array $cases
     * @return array
     */
    public function run(string $code, string $language, array $cases): array {
        $this->calls++;
        $this->lastlanguage = $language;
        $this->lastcases = array_values($cases);
        return $this->rows;
    }

    /**
     * The cases the most recent run() received (each carries 'source' or 'input').
     *
     * @return array|null
     */
    public function last_cases() {
        return $this->lastcases;
    }

    /**
     * Return the scripted result rows and record the packaged ZIP received.
     *
     * @param string $base64zip
     * @param array $cases
     * @return array
     */
    public function run_multifile(string $base64zip, array $cases): array {
        $this->calls++;
        $this->lastmultifilezip = $base64zip;
        return $this->rows;
    }

    /**
     * The package the most recent run_multifile() received.
     *
     * @return string|null
     */
    public function last_multifile_zip() {
        return $this->lastmultifilezip;
    }

    /**
     * How many times the runner was invoked.
     *
     * @return int
     */
    public function call_count(): int {
        return $this->calls;
    }

    /**
     * The language the most recent run() received.
     *
     * @return string|null
     */
    public function last_language() {
        return $this->lastlanguage;
    }

    /**
     * Build a single per-case result row in the shape judge0_runner::run_case() returns.
     *
     * @param string $outcome ran | ce | tle | re | internal
     * @param string $stdout the captured stdout
     * @param array $extra overrides (e.g. compileoutput, statusdesc)
     * @return array
     */
    public static function row(string $outcome, string $stdout = '', array $extra = []): array {
        return array_merge([
            'outcome' => $outcome,
            'stdout' => $stdout,
            'stderr' => '',
            'compileoutput' => '',
            'time' => 0.01,
            'memory' => 1000,
            'statusid' => 3,
            'statusdesc' => '',
        ], $extra);
    }
}
