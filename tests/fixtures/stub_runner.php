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

defined('MOODLE_INTERNAL') || die();

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

    /**
     * @param array $rows the per-case result rows run() should return
     */
    public function __construct(array $rows) {
        parent::__construct('http://stub.invalid', null, 2.0, 128000);
        $this->rows = $rows;
    }

    public function run(string $code, string $language, array $cases): array {
        $this->calls++;
        $this->lastlanguage = $language;
        return $this->rows;
    }

    /**
     * @return int how many times the runner was invoked
     */
    public function call_count(): int {
        return $this->calls;
    }

    /**
     * @return string|null the language the most recent run() received
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
    public static function row(string $outcome, string $stdout = '', array $extra = array()): array {
        return array_merge(array(
            'outcome' => $outcome,
            'stdout' => $stdout,
            'stderr' => '',
            'compileoutput' => '',
            'time' => 0.01,
            'memory' => 1000,
            'statusid' => 3,
            'statusdesc' => '',
        ), $extra);
    }
}
