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
 * The CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * The CodeAtelier question type.
 *
 * A question carries a language and a list of test cases. The test cases (input,
 * expected output, matching rule, weight, visibility) are the declarative
 * matching spec the practice-tier and judge-tier graders both interpret. The
 * single per-question option (language) is handled by the base class via
 * extra_question_fields(); the test-case rows are stored in a dedicated table.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier extends question_type {
    /** @var string[] every language code the question type knows, in picker order. */
    const ALL_LANGUAGES = ['python', 'sql', 'javascript', 'c', 'cpp', 'java',
            'go', 'rust', 'php', 'typescript', 'csharp', 'kotlin', 'ruby'];

    /**
     * Filemanager / file-area options for the support-data files area.
     *
     * @return array
     */
    public static function datafiles_options() {
        return ['subdirs' => 1, 'maxfiles' => 20, 'maxbytes' => 1048576, 'accepted_types' => '*'];
    }

    /**
     * The options table and its columns saved with each question.
     *
     * @return array the table name followed by its option column names
     */
    public function extra_question_fields() {
        return ['qtype_codeatelier_options', 'language', 'tier', 'scenario', 'entrypoint',
                'startercode', 'schemasql', 'cputimelimit', 'memorylimit', 'languages', 'starters',
                'referencesolution', 'allornothing', 'functionsignature', 'projectfiles', 'entryfile',
                'checkerlang', 'checkersource'];
    }

    /**
     * Load the question's options plus its test cases.
     *
     * @param object $question the question being loaded
     * @return bool
     */
    public function get_question_options($question) {
        global $DB;
        parent::get_question_options($question);
        $question->options->tests = $DB->get_records(
            'qtype_codeatelier_tests',
            ['questionid' => $question->id],
            'sortorder ASC, id ASC'
        );
        return true;
    }

    /**
     * Save the question's options and test cases from the edit form.
     *
     * @param object $question the submitted question data
     * @return bool
     */
    public function save_question_options($question) {
        global $DB;

        // A blank per-question limit means "use the site default" — store null.
        $question->cputimelimit = (isset($question->cputimelimit) && trim((string) $question->cputimelimit) !== '')
                ? (float) $question->cputimelimit : null;
        $question->memorylimit = (isset($question->memorylimit) && trim((string) $question->memorylimit) !== '')
                ? (int) $question->memorylimit : null;

        // The allowed-language set is stored as a comma-separated string (the form
        // sends an array); the primary language is always a member. Per-language
        // starter code is a JSON map, pruned to the languages actually allowed.
        $primary = isset($question->language) ? $question->language : 'python';
        $allowed = [];
        foreach ((array) ($question->languages ?? []) as $lang) {
            $lang = trim((string) $lang);
            if ($lang !== '' && in_array($lang, self::ALL_LANGUAGES, true) && !in_array($lang, $allowed, true)) {
                $allowed[] = $lang;
            }
        }
        if (!in_array($primary, $allowed, true)) {
            array_unshift($allowed, $primary);
        }
        $question->languages = implode(',', $allowed);

        $decoded = isset($question->starters) && trim((string) $question->starters) !== ''
                ? json_decode((string) $question->starters, true) : null;
        $starters = [];
        if (is_array($decoded)) {
            foreach ($allowed as $lang) {
                // The primary language's starter lives in startercode, not the map.
                if ($lang !== $primary && isset($decoded[$lang]) && trim((string) $decoded[$lang]) !== '') {
                    $starters[$lang] = (string) $decoded[$lang];
                }
            }
        }
        // Project and web questions author per-language starters in the file workspace,
        // not the single-file per-language starter map, so that field stays empty.
        $isfileset = in_array($question->scenario ?? '', ['project', 'web'], true);
        $question->starters = (!$isfileset && $starters) ? json_encode($starters) : null;

        // The reference solution is a single-file scalar for function/stdin questions.
        // A project's reference lives in the workspace Solution layer (inside the
        // projectfiles blob) and a web question grades by DOM assertions, so neither
        // uses this field.
        $scenario = $question->scenario ?? '';
        $reference = isset($question->referencesolution) ? (string) $question->referencesolution : '';
        $question->referencesolution = ($scenario !== 'project' && trim($reference) !== '')
                ? $reference : null;

        $question->allornothing = !empty($question->allornothing) ? 1 : 0;

        // Project scenario: the per-language file environments (starter + solution +
        // entry) live in the projectfiles blob; the scalar entryfile column is legacy
        // (null for projects — the entry is per-language inside the blob). Store null
        // when the blob/file set is blank.
        $pf = isset($question->projectfiles) ? trim((string) $question->projectfiles) : '';
        $question->projectfiles = ($pf !== '' && $pf !== '[]' && $pf !== '{}') ? $pf : null;
        $question->entryfile = ($scenario !== 'project'
                && isset($question->entryfile) && trim((string) $question->entryfile) !== '')
                ? (string) $question->entryfile : null;

        // Custom checker (special judge): a blank checker source means "no custom
        // checker — use the declarative matching rule", so clear both columns
        // together (a stale language with no source must not look like a checker).
        $checkersource = isset($question->checkersource) ? (string) $question->checkersource : '';
        if (trim($checkersource) === '') {
            $question->checkersource = null;
            $question->checkerlang = null;
        } else {
            $question->checkersource = $checkersource;
            $checkerlang = isset($question->checkerlang) ? trim((string) $question->checkerlang) : '';
            $question->checkerlang = in_array($checkerlang, self::ALL_LANGUAGES, true) ? $checkerlang : 'python';
        }

        // Saves the language/tier/scenario options into qtype_codeatelier_options.
        parent::save_question_options($question);

        $oldtests = $DB->get_records(
            'qtype_codeatelier_tests',
            ['questionid' => $question->id],
            'id ASC'
        );

        // Test cases arrive from the editor as one JSON field. Expand it into the
        // per-field arrays the loop below already consumes, so the record build +
        // insert/update/delete path is reused unchanged.
        if (isset($question->testcasesjson)) {
            $decoded = json_decode((string) $question->testcasesjson, true);
            foreach (['testinput', 'testexpected', 'matchmode', 'tolerance', 'testweight',
                    'testvisibility', 'checkerquery', 'testdatasetsql', 'testfeedback', 'selector',
                    'assertproperty', 'assertmatcher', 'actions', 'assertionjson', 'subtask'] as $arrayfield) {
                $question->$arrayfield = [];
            }
            foreach (is_array($decoded) ? $decoded : [] as $test) {
                if (!is_array($test)) {
                    continue;
                }
                $question->testinput[] = (string) ($test['input'] ?? '');
                $question->testexpected[] = (string) ($test['expected'] ?? '');
                $question->matchmode[] = (string) ($test['matchmode'] ?? 'trimmed');
                $question->tolerance[] = (string) ($test['tolerance'] ?? '');
                $question->testweight[] = (string) ($test['weight'] ?? '1');
                $question->testvisibility[] = (int) ($test['visibility'] ?? 1);
                $question->checkerquery[] = (string) ($test['checkerquery'] ?? '');
                $question->testdatasetsql[] = (string) ($test['datasetsql'] ?? '');
                $question->testfeedback[] = (string) ($test['feedback'] ?? '');
                $question->selector[] = (string) ($test['selector'] ?? '');
                $question->assertproperty[] = (string) ($test['assertproperty'] ?? '');
                $question->assertmatcher[] = (string) ($test['assertmatcher'] ?? '');
                $question->actions[] = (string) ($test['actions'] ?? '');
                // A compound assertion tree may arrive as a JSON string (bulk import)
                // or an already-decoded object (the builder); normalise to a string.
                $assertion = $test['assertionjson'] ?? '';
                $question->assertionjson[] = is_array($assertion)
                    ? json_encode($assertion) : (string) $assertion;
                $question->subtask[] = (int) ($test['subtask'] ?? 0);
            }
        }

        $sortorder = 0;
        $isweb = (($question->scenario ?? '') === 'web');
        $inputs = isset($question->testinput) ? $question->testinput : [];
        foreach ($inputs as $key => $input) {
            $expected = isset($question->testexpected[$key]) ? $question->testexpected[$key] : '';
            $selector = isset($question->selector[$key]) ? trim((string) $question->selector[$key]) : '';
            $assertiontree = isset($question->assertionjson[$key])
                    ? trim((string) $question->assertionjson[$key]) : '';
            // Skip a completely blank row. A web assertion row is keyed by its flat
            // selector or its compound tree; every other scenario by its input/expected.
            if ($isweb) {
                if ($selector === '' && $assertiontree === '') {
                    continue;
                }
            } else if (trim($input) === '' && trim($expected) === '') {
                continue;
            }

            $matchmode = isset($question->matchmode[$key]) ? $question->matchmode[$key] : 'trimmed';
            $tolerance = null;
            if (
                $matchmode === 'float' && isset($question->tolerance[$key])
                    && trim($question->tolerance[$key]) !== ''
            ) {
                $tolerance = $question->tolerance[$key];
            }

            $record = new stdClass();
            $record->questionid = $question->id;
            $record->sortorder = $sortorder++;
            $record->input = $isweb ? '' : $input;
            $record->expected = $expected;
            $record->matchmode = $matchmode;
            $record->tolerance = $tolerance;
            $record->weight = $question->testweight[$key];
            $record->visibility = $question->testvisibility[$key];
            $record->checkerquery = isset($question->checkerquery[$key]) ? $question->checkerquery[$key] : null;
            // SQL scenario: an optional per-test dataset (replaces the question schema
            // for this test). Stored null when empty so grading falls back cleanly.
            $record->datasetsql = (isset($question->testdatasetsql[$key])
                    && trim((string) $question->testdatasetsql[$key]) !== '')
                ? $question->testdatasetsql[$key] : null;
            $record->feedback = isset($question->testfeedback[$key]) ? $question->testfeedback[$key] : null;
            // Web scenario: the DOM assertion (selector + property + matcher) and
            // optional interaction actions. Null for every other scenario.
            $record->selector = $isweb ? $selector : null;
            $record->assertproperty = ($isweb && isset($question->assertproperty[$key]))
                    ? trim((string) $question->assertproperty[$key]) : null;
            $record->assertmatcher = ($isweb && isset($question->assertmatcher[$key]))
                    ? $question->assertmatcher[$key] : null;
            $record->actions = ($isweb && isset($question->actions[$key])
                    && trim((string) $question->actions[$key]) !== '')
                    ? $question->actions[$key] : null;
            // Web scenario: optional compound assertion tree; when set it supersedes
            // the flat selector/property/matcher leaf for this test. Null otherwise.
            $record->assertionjson = ($isweb && isset($question->assertionjson[$key])
                    && trim((string) $question->assertionjson[$key]) !== '')
                    ? $question->assertionjson[$key] : null;
            // Subtask group id (IOI-style); 0 is ungrouped (per-test weighting).
            $record->subtask = (isset($question->subtask[$key]) && (int) $question->subtask[$key] > 0)
                    ? (int) $question->subtask[$key] : 0;

            $existing = array_shift($oldtests);
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('qtype_codeatelier_tests', $record);
            } else {
                $DB->insert_record('qtype_codeatelier_tests', $record);
            }
        }

        // Remove any test rows the author deleted from the form.
        foreach ($oldtests as $leftover) {
            $DB->delete_records('qtype_codeatelier_tests', ['id' => $leftover->id]);
        }

        // Persist the support/data files from the form's draft area.
        if (isset($question->datafiles) && isset($question->context)) {
            file_save_draft_area_files(
                $question->datafiles,
                $question->context->id,
                'qtype_codeatelier',
                'datafiles',
                (int) $question->id,
                self::datafiles_options()
            );
        }
    }

    /**
     * Move the question's data files when it moves to a new context.
     *
     * @param int $questionid
     * @param int $oldcontextid
     * @param int $newcontextid
     */
    public function move_files($questionid, $oldcontextid, $newcontextid) {
        parent::move_files($questionid, $oldcontextid, $newcontextid);
        get_file_storage()->move_area_files_to_new_context(
            $oldcontextid,
            $newcontextid,
            'qtype_codeatelier',
            'datafiles',
            $questionid
        );
    }

    /**
     * Delete the question's data files.
     *
     * @param int $questionid
     * @param int $contextid
     */
    protected function delete_files($questionid, $contextid) {
        parent::delete_files($questionid, $contextid);
        get_file_storage()->delete_area_files($contextid, 'qtype_codeatelier', 'datafiles', $questionid);
    }

    /**
     * Populate the runtime question instance from its loaded data.
     *
     * @param question_definition $question
     * @param object $questiondata
     */
    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        // The base class fills $question->language from extra_question_fields().
        $question->tests = array_values($questiondata->options->tests);
    }

    /**
     * Delete the question along with its test cases.
     *
     * @param int $questionid
     * @param int $contextid
     */
    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_codeatelier_tests', ['questionid' => $questionid]);
        parent::delete_question($questionid, $contextid);
    }

    /**
     * No meaningful random-guess baseline (the result is supplied with the response).
     *
     * @param object $questiondata
     * @return float|null
     */
    public function get_random_guess_score($questiondata) {
        // The result is supplied with the response, so there is no meaningful
        // random-guess baseline to report.
        return null;
    }

    /**
     * Export a question to Moodle XML. Fully custom (the base class assumes an
     * 'answers' child table this type does not have): writes every option column
     * plus a <testcase> per test case.
     *
     * @param object $question the question being exported
     * @param qformat_xml $format the export format
     * @param mixed $extra unused
     * @return string the XML fragment
     */
    public function export_to_xml($question, qformat_xml $format, $extra = null) {
        $fields = $this->extra_question_fields();
        array_shift($fields);

        $output = '';
        foreach ($fields as $field) {
            $value = isset($question->options->$field) ? $question->options->$field : '';
            $output .= "    <{$field}>" . $format->xml_escape((string) $value) . "</{$field}>\n";
        }

        foreach ($question->options->tests as $test) {
            $output .= "    <testcase>\n";
            foreach (
                ['input', 'expected', 'matchmode', 'tolerance', 'weight',
                    'visibility', 'checkerquery', 'datasetsql', 'feedback',
                    'selector', 'assertproperty', 'assertmatcher', 'actions',
                    'assertionjson', 'subtask'] as $field
            ) {
                $value = isset($test->$field) ? $test->$field : '';
                $output .= "      <{$field}>" . $format->xml_escape((string) $value) . "</{$field}>\n";
            }
            $output .= "    </testcase>\n";
        }

        // The support/data files travel as base64 <file> elements.
        $datafiles = get_file_storage()->get_area_files(
            $question->contextid,
            'qtype_codeatelier',
            'datafiles',
            $question->id,
            'filepath, filename',
            false
        );
        $output .= $format->write_files($datafiles);

        return $output;
    }

    /**
     * Import a question from Moodle XML written by {@see export_to_xml()}.
     *
     * @param array $data the xmlized question element
     * @param object $question unused (the in-progress question)
     * @param qformat_xml $format the import format
     * @param mixed $extra unused
     * @return object|bool the question object to save, or false if not our type
     */
    public function import_from_xml($data, $question, qformat_xml $format, $extra = null) {
        if (!isset($data['@']['type']) || $data['@']['type'] !== 'codeatelier') {
            return false;
        }

        $qo = $format->import_headers($data);
        $qo->qtype = 'codeatelier';

        $defaults = [
            'language' => 'python', 'tier' => 'practice', 'scenario' => 'stdinstdout',
            'entrypoint' => '', 'startercode' => '', 'schemasql' => '',
            'cputimelimit' => '', 'memorylimit' => '', 'languages' => '',
            'starters' => '', 'referencesolution' => '', 'allornothing' => 0,
            'functionsignature' => '', 'projectfiles' => '', 'entryfile' => '',
            'checkerlang' => '', 'checkersource' => '',
        ];
        $fields = $this->extra_question_fields();
        array_shift($fields);
        foreach ($fields as $field) {
            $default = isset($defaults[$field]) ? $defaults[$field] : '';
            $qo->$field = $format->getpath($data, ['#', $field, 0, '#'], $default);
        }
        // The save path expects the allowed-language SET as an array (the
        // multi-select value), not the stored comma string — casting a comma string
        // would collapse the whole set to one bad element.
        $qo->languages = ($qo->languages !== '' && $qo->languages !== null)
            ? array_values(array_filter(array_map('trim', explode(',', $qo->languages))))
            : [];

        foreach (
            ['testinput', 'testexpected', 'matchmode', 'tolerance',
                'testweight', 'testvisibility', 'checkerquery', 'testdatasetsql', 'testfeedback',
                'selector', 'assertproperty', 'assertmatcher', 'actions', 'assertionjson', 'subtask'] as $arr
        ) {
            $qo->$arr = [];
        }
        $testcases = isset($data['#']['testcase']) ? $data['#']['testcase'] : [];
        foreach ($testcases as $tc) {
            $qo->testinput[] = $format->getpath($tc, ['#', 'input', 0, '#'], '');
            $qo->testexpected[] = $format->getpath($tc, ['#', 'expected', 0, '#'], '');
            $qo->matchmode[] = $format->getpath($tc, ['#', 'matchmode', 0, '#'], 'trimmed');
            $qo->tolerance[] = $format->getpath($tc, ['#', 'tolerance', 0, '#'], '');
            $qo->testweight[] = $format->getpath($tc, ['#', 'weight', 0, '#'], '1');
            $qo->testvisibility[] = $format->getpath($tc, ['#', 'visibility', 0, '#'], 1);
            $qo->checkerquery[] = $format->getpath($tc, ['#', 'checkerquery', 0, '#'], '');
            $qo->testdatasetsql[] = $format->getpath($tc, ['#', 'datasetsql', 0, '#'], '');
            $qo->testfeedback[] = $format->getpath($tc, ['#', 'feedback', 0, '#'], '');
            $qo->selector[] = $format->getpath($tc, ['#', 'selector', 0, '#'], '');
            $qo->assertproperty[] = $format->getpath($tc, ['#', 'assertproperty', 0, '#'], '');
            $qo->assertmatcher[] = $format->getpath($tc, ['#', 'assertmatcher', 0, '#'], '');
            $qo->actions[] = $format->getpath($tc, ['#', 'actions', 0, '#'], '');
            $qo->assertionjson[] = $format->getpath($tc, ['#', 'assertionjson', 0, '#'], '');
            $qo->subtask[] = $format->getpath($tc, ['#', 'subtask', 0, '#'], 0);
        }

        // Rebuild the support/data files into a draft area so the normal save path
        // stores them on the imported question.
        $qo->datafiles = $this->import_datafiles($data);
        return $qo;
    }

    /**
     * Recreate the support/data <file> nodes of an imported question into a fresh
     * draft area, returning its item id (an empty draft when there are no files).
     *
     * @param array $data the xmlized question element
     * @return int the draft area item id
     */
    protected function import_datafiles($data) {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        $files = isset($data['#']['file']) ? $data['#']['file'] : [];
        if (!$files) {
            return $draftid;
        }
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        foreach ($files as $filexml) {
            if (empty($filexml['@']['name'])) {
                continue;
            }
            $record = (object) [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftid,
                'filepath' => isset($filexml['@']['path']) ? $filexml['@']['path'] : '/',
                'filename' => $filexml['@']['name'],
            ];
            $fs->create_file_from_string($record, base64_decode(isset($filexml['#']) ? $filexml['#'] : ''));
        }
        return $draftid;
    }

    /**
     * The JS string bundle passed to the workbench controller.
     *
     * Shared by the question renderer and the authoring form's live preview so
     * both drive the identical student UI from one source of truth.
     *
     * @return array string key => localised text
     */
    public static function ui_strings() {
        $keys = [
            'running' => 'running',
            'loading' => 'preparingruntime',
            'runerror' => 'runerror',
            'pass' => 'result_pass',
            'fail' => 'result_fail',
            'test' => 'result_test',
            'hidden' => 'result_hidden',
            'score' => 'result_score',
            'schematables' => 'schematables',
            'examples' => 'examples',
            'examplesregion' => 'examplesregion',
            'input' => 'input',
            'expected' => 'expected',
            'call' => 'result_call',
            'got' => 'result_got',
            'argslabel' => 'scratchargs',
            'argn' => 'argn',
            'argsheading' => 'argsheading',
            'scratchplaceholderfn' => 'scratchplaceholderfn',
            'scratchplaceholderio' => 'scratchplaceholderio',
            'output' => 'output',
            'stopped' => 'stopped',
            'noresult' => 'noresult',
            'rowsnote' => 'rowsnote',
            'resultdifflegend' => 'resultdifflegend',
            'usedataset' => 'usedataset',
            'usedefaultdata' => 'usedefaultdata',
            'activedataset' => 'activedataset',
            'teacherfeedback' => 'result_feedback',
            'stale' => 'stale',
            'degraded' => 'degraded',
            'focus' => 'focus',
            'exitfocus' => 'exitfocus',
            'focusmode' => 'focusmode',
            'useinput' => 'useinput',
            'preview' => 'preview',
            'check' => 'result_check',
            'checks' => 'webchecksheading',
            'assertexpect' => 'assertexpect',
            'assertafter' => 'assertafter',
            'assertexists' => 'assertexists',
            'assertabsent' => 'assertabsent',
            'assertall' => 'assertall',
            'assertany' => 'assertany',
            'assertnotof' => 'assertnotof',
            'assertnotfound' => 'assertnotfound',
            'showinpreview' => 'showinpreview',
            'previewpick' => 'previewpick',
            'pickelement' => 'pickelement',
            'pickcancel' => 'pickcancel',
            'pickaddrow' => 'pickaddrow',
            'pickfilled' => 'pickfilled',
            'projectfiles' => 'projectfiles',
            'filename' => 'projectfilename',
            'addfile' => 'projectaddfile',
            'deletefile' => 'projectdeletefile',
            'badfilename' => 'projectbadfilename',
            'language' => 'language',
            'layer' => 'projectlayer',
            'starterlayer' => 'projectlayerstarter',
            'solutionlayer' => 'projectlayersolution',
            'entryis' => 'projectentryis',
            'setentry' => 'projectsetentry',
            'servedpage' => 'projectservedpage',
            'import' => 'projectimport',
            'importplaceholder' => 'projectimportplaceholder',
            'importhelp' => 'projectimporthelp',
            'importadd' => 'projectimportadd',
            'importreplace' => 'projectimportreplace',
            'importdo' => 'projectimportdo',
            'importexamples' => 'projectimportexamples',
            'importextree' => 'projectimportextree',
            'importexfiles' => 'projectimportexfiles',
            'importorpick' => 'projectimportorpick',
            'importok' => 'projectimportok',
            'importskipped' => 'projectimportskipped',
            'importempty' => 'projectimportempty',
            'importreading' => 'projectimportreading',
        ];
        $strings = [];
        foreach ($keys as $jskey => $stringid) {
            $strings[$jskey] = get_string($stringid, 'qtype_codeatelier');
        }
        return $strings;
    }

    /**
     * The static label fields of the workbench template context (everything that
     * does not depend on a specific question's saved data).
     *
     * @return array template variable => localised text
     */
    public static function ui_labels() {
        return [
            'answerlabel' => get_string('answerlabel', 'qtype_codeatelier'),
            'loadingeditor' => get_string('loadingeditor', 'qtype_codeatelier'),
            'runlabel' => get_string('runlabel', 'qtype_codeatelier'),
            'focuslabel' => get_string('focus', 'qtype_codeatelier'),
            'checklabel' => get_string('checklabel', 'qtype_codeatelier'),
            'stoplabel' => get_string('stoplabel', 'qtype_codeatelier'),
            'consolelabel' => get_string('consolelabel', 'qtype_codeatelier'),
            'testslabel' => get_string('testslabel', 'qtype_codeatelier'),
            'outputtabslabel' => get_string('outputtabs', 'qtype_codeatelier'),
            'outputempty' => get_string('outputempty', 'qtype_codeatelier'),
            'practicenote' => get_string('practicenote', 'qtype_codeatelier'),
            'findlabel' => get_string('editorfind', 'qtype_codeatelier'),
            'formatlabel' => get_string('editorformat', 'qtype_codeatelier'),
            'shortcutstitle' => get_string('editorshortcutstitle', 'qtype_codeatelier'),
            'shortcutshtml' => get_string('editorshortcuts', 'qtype_codeatelier'),
            'themelabel' => get_string('editortheme', 'qtype_codeatelier'),
            'themeauto' => get_string('editortheme_auto', 'qtype_codeatelier'),
            'themelight' => get_string('editortheme_light', 'qtype_codeatelier'),
            'themedark' => get_string('editortheme_dark', 'qtype_codeatelier'),
            'themehighcontrast' => get_string('editortheme_hc', 'qtype_codeatelier'),
        ];
    }
}
