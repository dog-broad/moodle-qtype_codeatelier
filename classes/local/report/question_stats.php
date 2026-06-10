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

namespace qtype_codeatelier\local\report;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregate statistics for one CodeAtelier question across its attempts.
 *
 * Two tiers of reliability: durable basics (attempt counts, state
 * breakdown, score distribution, language breakdown, proctor flags) come from the
 * question-engine tables and always reflect reality; rich detail (verdict counts,
 * the per-test pass-rate table, time/memory) is read best-effort from the
 * content-addressed judge memo, so it covers only attempts whose stored result is
 * still live (the question is unedited and the cache entry not pruned). The
 * returned object reports that coverage so a teacher knows what the detail rests
 * on. No data is recomputed from the sandbox — a report never touches Judge0.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_stats {

    /** @var string[] latest-step states that count as a real, submitted attempt. */
    private const SUBMITTED_STATES = ['gradedright', 'gradedpartial', 'gradedwrong', 'needsgrading'];

    /**
     * Compute the statistics for a single question.
     *
     * @param int $questionid the CodeAtelier question id
     * @param int|null $usagecontextid restrict to attempts whose question usage is in
     *     this context (e.g. one quiz's module context); null aggregates every use
     * @return \stdClass the aggregate (see the build_* methods for the shape), or an
     *     empty aggregate if the question is not a CodeAtelier question
     */
    public static function for_question(int $questionid, ?int $usagecontextid = null): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $question = \question_bank::load_question($questionid);
        if (!($question instanceof \qtype_codeatelier_question)) {
            return self::empty_stats($questionid, null);
        }

        $stats = self::empty_stats($questionid, $question);

        $basics = self::load_basics($questionid, $usagecontextid);
        if (!$basics) {
            return $stats;
        }
        $responses = self::load_responses(array_keys($basics));

        $isjudge = $question->is_judge();
        $testcount = count($question->tests);
        $passes = array_fill(0, $testcount, 0);
        $withresult = 0;
        $timesum = 0.0;
        $timecount = 0;
        $scoresum = 0.0;

        foreach ($basics as $qaid => $basic) {
            $stats->totalattempts++;
            $vars = $responses[$qaid] ?? [];

            if ($basic->state === 'needsgrading') {
                $stats->needsgrading++;
            } else {
                $stats->graded++;
                $fraction = (float) $basic->fraction;
                $scoresum += $fraction;
                self::bucket_score($stats, $fraction);
            }

            // Language: the per-attempt pick, resolved against the allowed set; an
            // absent var (single-language question) resolves to the primary.
            $language = $question->resolve_language($vars['language'] ?? null);
            $stats->languages[$language] = ($stats->languages[$language] ?? 0) + 1;

            if ($isjudge && !empty($vars['proctorlog']) && self::has_proctor_events($vars['proctorlog'])) {
                $stats->proctorflagged++;
            }

            // Best-effort rich detail from the judge memo.
            if (!$isjudge) {
                continue;
            }
            $result = $question->cached_judge_result($vars['answer'] ?? '', $vars['language'] ?? null);
            if ($result === null) {
                continue;
            }
            $withresult++;
            $verdict = (string) ($result['verdict'] ?? 'WA');
            $stats->verdicts[$verdict] = ($stats->verdicts[$verdict] ?? 0) + 1;

            foreach ($result['testresults'] ?? [] as $tr) {
                $i = (int) ($tr['index'] ?? -1);
                if ($i >= 0 && $i < $testcount && !empty($tr['passed'])) {
                    $passes[$i]++;
                }
                if (isset($tr['time']) && $tr['time'] !== null) {
                    $timesum += (float) $tr['time'];
                    $timecount++;
                    $stats->timing->maxtimems = max($stats->timing->maxtimems, (float) $tr['time'] * 1000);
                }
                if (isset($tr['memory']) && $tr['memory'] !== null) {
                    $stats->timing->maxmemorykb = max($stats->timing->maxmemorykb, (int) $tr['memory']);
                }
            }
        }

        $stats->meanscore = $stats->graded > 0 ? $scoresum / $stats->graded : null;
        $stats->timing->meantimems = $timecount > 0 ? ($timesum / $timecount) * 1000 : null;
        $stats->detailcoverage->withresult = $withresult;
        $stats->detailcoverage->total = $stats->totalattempts;

        $stats->pertest = self::build_pertest($question, $passes, $withresult);
        return $stats;
    }

    /**
     * The most recent submissions of a question, newest first, for drill-down.
     *
     * Each row carries the grade, the chosen language, the best-effort verdict
     * (from the memo), and — for quiz attempts — the student and a link to the
     * native attempt review (where the full per-test diagnostics already render).
     *
     * @param int $questionid
     * @param int|null $usagecontextid restrict to one quiz's attempts, or null for all
     * @param int $limit max rows
     * @return \stdClass[] one row per attempt
     */
    public static function recent_submissions(int $questionid, ?int $usagecontextid = null,
            int $limit = 50): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $question = \question_bank::load_question($questionid);
        if (!($question instanceof \qtype_codeatelier_question)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal(self::SUBMITTED_STATES, SQL_PARAMS_NAMED, 'st');
        $params['qid'] = $questionid;
        $params['preview'] = 'core_question_preview';
        $contextwhere = '';
        if ($usagecontextid !== null) {
            $contextwhere = ' AND qu.contextid = :ctxid';
            $params['ctxid'] = $usagecontextid;
        }
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT qa.id AS qaid, qas.fraction AS fraction, qas.state AS state,
                       qas.timecreated AS submittedtime, qza.id AS quizattemptid, u.id AS userid,
                       $userfields
                  FROM {question_attempts} qa
                  JOIN {question_usages} qu ON qu.id = qa.questionusageid
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
             LEFT JOIN {quiz_attempts} qza ON qza.uniqueid = qu.id
             LEFT JOIN {user} u ON u.id = qza.userid
                 WHERE qa.questionid = :qid
                   AND qu.component <> :preview" . $contextwhere . "
                   AND qas.sequencenumber = (
                       SELECT MAX(s.sequencenumber)
                         FROM {question_attempt_steps} s
                        WHERE s.questionattemptid = qa.id)
                   AND qas.state $insql
              ORDER BY qas.timecreated DESC, qa.id DESC";
        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        $responses = self::load_responses(array_keys($records));

        $rows = [];
        foreach ($records as $qaid => $rec) {
            $vars = $responses[$qaid] ?? [];
            $verdict = null;
            if ($question->is_judge()) {
                $result = $question->cached_judge_result($vars['answer'] ?? '', $vars['language'] ?? null);
                if ($result !== null) {
                    $verdict = (string) ($result['verdict'] ?? '');
                }
            }
            $rows[] = (object) [
                'language' => $question->resolve_language($vars['language'] ?? null),
                'graded' => $rec->state !== 'needsgrading',
                'fraction' => $rec->state !== 'needsgrading' ? (float) $rec->fraction : null,
                'verdict' => $verdict,
                'userid' => $rec->userid ? (int) $rec->userid : null,
                'userfullname' => $rec->userid ? fullname($rec) : null,
                'quizattemptid' => $rec->quizattemptid ? (int) $rec->quizattemptid : null,
                'submittedtime' => (int) $rec->submittedtime,
            ];
        }
        return $rows;
    }

    /**
     * Every submission's code, for similarity analysis — one row per attempt, with the
     * submitting student, the chosen language, and the comparable source text.
     *
     * "Comparable source" unwraps a multi-file answer (project / web file set) into the
     * concatenated file contents, so those scenarios compare on code rather than JSON
     * envelope; single-file scenarios use the raw answer. Attempts with no resolvable
     * user (e.g. non-quiz usages) are still returned, labelled anonymously.
     *
     * @param int $questionid
     * @param int|null $usagecontextid restrict to one quiz's attempts, or null for all
     * @param int $max cap on the number of (most recent) submissions analysed
     * @return \stdClass[] each {key, label, language, code, qaid, quizattemptid}
     */
    public static function code_submissions(int $questionid, ?int $usagecontextid = null,
            int $max = 500): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $question = \question_bank::load_question($questionid);
        if (!($question instanceof \qtype_codeatelier_question)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal(self::SUBMITTED_STATES, SQL_PARAMS_NAMED, 'st');
        $params['qid'] = $questionid;
        $params['preview'] = 'core_question_preview';
        $contextwhere = '';
        if ($usagecontextid !== null) {
            $contextwhere = ' AND qu.contextid = :ctxid';
            $params['ctxid'] = $usagecontextid;
        }
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT qa.id AS qaid, qza.id AS quizattemptid, u.id AS userid,
                       $userfields
                  FROM {question_attempts} qa
                  JOIN {question_usages} qu ON qu.id = qa.questionusageid
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
             LEFT JOIN {quiz_attempts} qza ON qza.uniqueid = qu.id
             LEFT JOIN {user} u ON u.id = qza.userid
                 WHERE qa.questionid = :qid
                   AND qu.component <> :preview" . $contextwhere . "
                   AND qas.sequencenumber = (
                       SELECT MAX(s.sequencenumber)
                         FROM {question_attempt_steps} s
                        WHERE s.questionattemptid = qa.id)
                   AND qas.state $insql
              ORDER BY qa.id DESC";
        $records = $DB->get_records_sql($sql, $params, 0, $max);
        $responses = self::load_responses(array_keys($records));

        $rows = [];
        foreach ($records as $qaid => $rec) {
            $vars = $responses[$qaid] ?? [];
            $code = self::comparable_code($vars['answer'] ?? '');
            if (trim($code) === '') {
                continue;
            }
            $rows[] = (object) [
                'key' => (int) $qaid,
                'label' => $rec->userid ? fullname($rec) : get_string('report_unknownuser', 'qtype_codeatelier'),
                'language' => $question->resolve_language($vars['language'] ?? null),
                'code' => $code,
                'qaid' => (int) $qaid,
                'quizattemptid' => $rec->quizattemptid ? (int) $rec->quizattemptid : null,
            ];
        }
        return $rows;
    }

    /**
     * Unwrap a stored answer into comparable source text: a multi-file answer (a JSON
     * array of {name, content}) becomes its file contents concatenated in name order;
     * anything else is returned verbatim.
     *
     * @param string $answer the raw answer response var
     * @return string
     */
    private static function comparable_code(string $answer): string {
        $trimmed = ltrim($answer);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            return $answer;
        }
        $decoded = json_decode($answer, true);
        if (!is_array($decoded)) {
            return $answer;
        }
        $files = [];
        foreach ($decoded as $file) {
            if (is_array($file) && isset($file['name'], $file['content'])) {
                $files[(string) $file['name']] = (string) $file['content'];
            }
        }
        if (!$files) {
            return $answer;
        }
        ksort($files);
        return implode("\n", $files);
    }

    /**
     * The per-question stats for every CodeAtelier question used in a quiz,
     * scoped to that quiz's attempts, in slot order.
     *
     * Covers questions added directly to the quiz; randomly-selected questions
     * (question sets) are not enumerated here.
     *
     * @param int $quizid the quiz instance id
     * @param int $usagecontextid the quiz module context id
     * @return \stdClass[] one stats object per question
     */
    public static function for_quiz(int $quizid, int $usagecontextid): array {
        global $DB;
        $sql = "SELECT q.id AS qid, MIN(qs.slot) AS slot
                  FROM {quiz_slots} qs
                  JOIN {question_references} qr ON qr.itemid = qs.id
                   AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qs.quizid = :quizid
                   AND q.qtype = 'codeatelier'
                   AND qv.version = (
                       SELECT MAX(v.version) FROM {question_versions} v
                        WHERE v.questionbankentryid = qbe.id)
              GROUP BY q.id
              ORDER BY slot ASC";
        $rows = $DB->get_records_sql($sql, ['quizid' => $quizid]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::for_question((int) $row->qid, $usagecontextid);
        }
        return $out;
    }

    /**
     * Per attempt, the latest step's fraction and state — but only for attempts
     * whose latest state is a real submission.
     *
     * @param int $questionid
     * @param int|null $usagecontextid restrict to usages in this context, or null for all
     * @return array qaid => {fraction, state}
     */
    private static function load_basics(int $questionid, ?int $usagecontextid = null): array {
        global $DB;
        list($insql, $params) = $DB->get_in_or_equal(self::SUBMITTED_STATES, SQL_PARAMS_NAMED, 'st');
        $params['qid'] = $questionid;
        $params['preview'] = 'core_question_preview';
        $contextwhere = '';
        if ($usagecontextid !== null) {
            $contextwhere = ' AND qu.contextid = :ctxid';
            $params['ctxid'] = $usagecontextid;
        }
        // Exclude teacher previews (their own trial runs) — those are not student
        // submissions and would otherwise inflate the figures.
        $sql = "SELECT qa.id AS qaid, qas.fraction AS fraction, qas.state AS state
                  FROM {question_attempts} qa
                  JOIN {question_usages} qu ON qu.id = qa.questionusageid
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                 WHERE qa.questionid = :qid
                   AND qu.component <> :preview" . $contextwhere . "
                   AND qas.sequencenumber = (
                       SELECT MAX(s.sequencenumber)
                         FROM {question_attempt_steps} s
                        WHERE s.questionattemptid = qa.id)
                   AND qas.state $insql";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * The last-set value of each tracked response variable, per attempt. Read from
     * the step data directly (not via a usage load) so a large cohort stays cheap;
     * "last set" matters because a regrade step carries the new grade but not the
     * original answer.
     *
     * @param int[] $qaids the attempt ids
     * @return array qaid => [name => value]
     */
    private static function load_responses(array $qaids): array {
        global $DB;
        if (!$qaids) {
            return [];
        }
        list($inqa, $params) = $DB->get_in_or_equal($qaids, SQL_PARAMS_NAMED, 'qa');
        list($inname, $nameparams) = $DB->get_in_or_equal(
                ['answer', 'language', 'proctorlog', 'score'], SQL_PARAMS_NAMED, 'nm');
        $params += $nameparams;
        $sql = "SELECT qasd.id, qas.questionattemptid AS qaid, qas.sequencenumber AS seq,
                       qasd.name AS name, qasd.value AS value
                  FROM {question_attempt_step_data} qasd
                  JOIN {question_attempt_steps} qas ON qas.id = qasd.attemptstepid
                 WHERE qas.questionattemptid $inqa
                   AND qasd.name $inname
              ORDER BY qas.sequencenumber ASC";
        $out = [];
        $recordset = $DB->get_recordset_sql($sql, $params);
        foreach ($recordset as $row) {
            // Rows arrive in ascending sequence, so the later value overwrites the earlier.
            $out[(int) $row->qaid][$row->name] = $row->value;
        }
        $recordset->close();
        return $out;
    }

    /**
     * Build the per-test pass-rate table from the accumulated passes.
     *
     * @param \qtype_codeatelier_question $question
     * @param int[] $passes pass count per test index
     * @param int $withresult attempts that contributed a result
     * @return array list of {index, visible, passes, total, passrate}
     */
    private static function build_pertest($question, array $passes, int $withresult): array {
        $rows = [];
        foreach (array_values($question->tests) as $i => $test) {
            $rows[] = (object) [
                'index' => $i,
                'visible' => (int) $test->visibility === 1,
                'passes' => $passes[$i] ?? 0,
                'total' => $withresult,
                'passrate' => $withresult > 0 ? ($passes[$i] ?? 0) / $withresult : null,
            ];
        }
        return $rows;
    }

    /**
     * Add one graded fraction to the zero/partial/full distribution.
     *
     * @param \stdClass $stats
     * @param float $fraction
     */
    private static function bucket_score(\stdClass $stats, float $fraction): void {
        if ($fraction <= 0.0) {
            $stats->scoredist->zero++;
        } else if ($fraction >= 1.0) {
            $stats->scoredist->full++;
        } else {
            $stats->scoredist->partial++;
        }
    }

    /**
     * Whether a stored proctor log actually recorded at least one event.
     *
     * @param string $proctorlog the JSON log
     * @return bool
     */
    private static function has_proctor_events(string $proctorlog): bool {
        $events = json_decode($proctorlog, true);
        return is_array($events) && count($events) > 0;
    }

    /**
     * A zeroed aggregate.
     *
     * @param int $questionid
     * @param \qtype_codeatelier_question|null $question
     * @return \stdClass
     */
    private static function empty_stats(int $questionid, $question): \stdClass {
        return (object) [
            'questionid' => $questionid,
            'name' => $question ? $question->name : '',
            'tier' => $question ? ($question->is_judge() ? 'judge' : 'practice') : null,
            'totalattempts' => 0,
            'graded' => 0,
            'needsgrading' => 0,
            'meanscore' => null,
            'scoredist' => (object) ['zero' => 0, 'partial' => 0, 'full' => 0],
            'verdicts' => [],
            'languages' => [],
            'pertest' => [],
            'timing' => (object) ['maxtimems' => 0.0, 'maxmemorykb' => 0, 'meantimems' => null],
            'proctorflagged' => 0,
            'detailcoverage' => (object) ['withresult' => 0, 'total' => 0],
        ];
    }
}
