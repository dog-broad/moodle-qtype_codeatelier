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
 * Content-addressed memo for judge-tier grading results.
 *
 * Judge grading is deterministic for a given (language, code, test set, limits),
 * so the result is cached under a hash of exactly those inputs. The submit path
 * computes once and stores; review and regrade read the stored result instead of
 * re-running the sandbox — review pages never touch Judge0 and stay fast even if
 * the sandbox is briefly down. The stored blob holds only verdicts and pass/fail,
 * never expected outputs, so a hidden test is never revealed by the cache.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_cache {

    /** @var int the result-blob shape version; bump to invalidate older cached entries. */
    const BLOB_VERSION = 6;

    /**
     * The cache key for a submission: a hash over everything that can change the
     * verdict. If the teacher edits a test the hash changes and the stale entry
     * is simply never read.
     *
     * @param string $language the judge language key
     * @param string $code the student submission
     * @param array $tests the authored test cases
     * @param array $limits the runner limits ['cputime' => float, 'memory' => int]
     * @param string $schemasql the SQL scenario's schema + seed (empty for non-SQL)
     * @param bool $allornothing whether the question grades all-or-nothing
     * @param string $datafileshash a content hash of the support/data files (empty for none)
     * @param string $scenario the scenario (function/entrypoint change the harness, so the result)
     * @param string $entrypoint the function name called (function scenario)
     * @return string a 40-char SHA-1
     */
    public static function key(string $language, string $code, array $tests, array $limits,
            string $schemasql = '', bool $allornothing = false, string $datafileshash = '',
            string $scenario = 'stdinstdout', string $entrypoint = ''): string {
        $signature = [];
        foreach (array_values($tests) as $test) {
            $signature[] = [
                (string) $test->input,
                (string) $test->expected,
                (string) $test->matchmode,
                isset($test->tolerance) ? (string) $test->tolerance : '',
                (string) $test->weight,
                (string) $test->visibility,
                // The checker query is part of what runs for a SQL mutation test.
                isset($test->checkerquery) ? (string) $test->checkerquery : '',
            ];
        }
        return sha1(json_encode([self::BLOB_VERSION, $language, $code, $signature, $limits, $schemasql,
                $allornothing, $datafileshash, $scenario, $entrypoint]));
    }

    /**
     * Read a cached result.
     *
     * @param string $hash the content hash
     * @return array|null the decoded grading result, or null on a miss
     */
    public static function get(string $hash): ?array {
        global $DB;
        $record = $DB->get_record('qtype_codeatelier_judgecache', ['contenthash' => $hash]);
        if (!$record) {
            return null;
        }
        $result = json_decode($record->resultblob, true);
        return is_array($result) ? $result : null;
    }

    /**
     * Store a result, ignoring a race where another request stored it first.
     *
     * @param string $hash the content hash
     * @param array $result the grading result to memoise
     */
    public static function put(string $hash, array $result): void {
        global $DB;
        if ($DB->record_exists('qtype_codeatelier_judgecache', ['contenthash' => $hash])) {
            return;
        }
        $record = (object) [
            'contenthash' => $hash,
            'fraction' => (float) ($result['fraction'] ?? 0),
            'verdict' => (string) ($result['verdict'] ?? 'WA'),
            'resultblob' => json_encode($result),
            'timecreated' => time(),
        ];
        try {
            $DB->insert_record('qtype_codeatelier_judgecache', $record);
        } catch (\dml_exception $e) {
            // A concurrent submit won the unique-hash race; its result is identical.
            return;
        }
    }
}
