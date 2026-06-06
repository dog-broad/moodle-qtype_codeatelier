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
 * Editing form for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CodeAtelier question editing form: a language picker and a test-case table.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_edit_form extends question_edit_form {

    protected function definition_inner($mform) {
        // When editing an existing question, link to its submissions report.
        if (!empty($this->question->id)) {
            $reporturl = new moodle_url('/question/type/codeatelier/report.php',
                    array('id' => $this->question->id));
            $mform->addElement('static', 'reportlink', '',
                    html_writer::link($reporturl, get_string('viewreport', 'qtype_codeatelier')));
        }

        $mform->addElement('select', 'tier', get_string('tier', 'qtype_codeatelier'),
                $this->tier_options());
        $mform->addHelpButton('tier', 'tier', 'qtype_codeatelier');
        $mform->setDefault('tier', $this->get_default_value('tier', 'practice'));

        // Practice-tier grades are browser-computed and forgeable: warn the author
        // not to use this tier for graded assessment. Shown only for the practice tier.
        $mform->addElement('static', 'practicewarning', '',
                html_writer::div(get_string('practicewarning', 'qtype_codeatelier'), 'alert alert-warning'));
        $mform->hideIf('practicewarning', 'tier', 'neq', 'practice');

        $mform->addElement('select', 'language', get_string('language', 'qtype_codeatelier'),
                $this->language_options());
        $mform->addHelpButton('language', 'language', 'qtype_codeatelier');
        $mform->setDefault('language', $this->get_default_value('language', 'python'));

        // The set of languages the student may choose from. Empty means
        // single-language (just the primary Language above).
        $mform->addElement('autocomplete', 'languages', get_string('languages', 'qtype_codeatelier'),
                $this->language_options(), array('multiple' => true));
        $mform->addHelpButton('languages', 'languages', 'qtype_codeatelier');

        $mform->addElement('select', 'scenario', get_string('scenario', 'qtype_codeatelier'),
                $this->scenario_options());
        $mform->addHelpButton('scenario', 'scenario', 'qtype_codeatelier');
        $mform->setDefault('scenario', $this->get_default_value('scenario', 'stdinstdout'));
        // Both tiers support all three scenarios; the per-scenario language rules
        // (e.g. judge function = dynamic languages only) are enforced in validation().

        $mform->addElement('text', 'entrypoint', get_string('entrypoint', 'qtype_codeatelier'),
                array('size' => 40));
        $mform->setType('entrypoint', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('entrypoint', 'entrypoint', 'qtype_codeatelier');
        $mform->hideIf('entrypoint', 'scenario', 'neq', 'function');

        $mform->addElement('text', 'functionsignature', get_string('functionsignature', 'qtype_codeatelier'),
                array('size' => 40));
        $mform->setType('functionsignature', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('functionsignature', 'functionsignature', 'qtype_codeatelier');
        $mform->hideIf('functionsignature', 'scenario', 'neq', 'function');

        $mform->addElement('textarea', 'startercode', get_string('startercode', 'qtype_codeatelier'),
                array('rows' => 6, 'cols' => 60, 'spellcheck' => 'false'));
        $mform->setType('startercode', PARAM_RAW);
        $mform->addHelpButton('startercode', 'startercode', 'qtype_codeatelier');

        // Per-language starter code for the additional allowed languages (the
        // primary language uses the Starter code field above). The picker + editor
        // are driven by the authoring JS, which keeps the JSON map in the hidden
        // 'starters' field; this section hides itself when only one language is
        // allowed. starterlang/starteredit are UI only and are not saved.
        $mform->addElement('select', 'starterlang', get_string('starterlang', 'qtype_codeatelier'),
                $this->language_options());
        $mform->addElement('textarea', 'starteredit', get_string('starterother', 'qtype_codeatelier'),
                array('rows' => 6, 'cols' => 60, 'spellcheck' => 'false'));
        $mform->setType('starteredit', PARAM_RAW);
        $mform->addElement('hidden', 'starters', '', array('id' => 'id_starters'));
        $mform->setType('starters', PARAM_RAW);

        $mform->addElement('textarea', 'schemasql', get_string('schemasql', 'qtype_codeatelier'),
                array('rows' => 6, 'cols' => 60, 'spellcheck' => 'false'));
        $mform->setType('schemasql', PARAM_RAW);
        $mform->addHelpButton('schemasql', 'schemasql', 'qtype_codeatelier');
        $mform->hideIf('schemasql', 'scenario', 'neq', 'sql');

        // A reference solution (in the primary language). The authoring JS can run
        // it to capture the Expected outputs and to validate the question; on the
        // judge tier a reference that fails any test blocks the save.
        $mform->addElement('textarea', 'referencesolution', get_string('referencesolution', 'qtype_codeatelier'),
                array('rows' => 6, 'cols' => 60, 'spellcheck' => 'false'));
        $mform->setType('referencesolution', PARAM_RAW);
        $mform->addHelpButton('referencesolution', 'referencesolution', 'qtype_codeatelier');

        // Support/data files: judge tier only. They are placed in the working
        // directory next to the program so it can read them at run time.
        $mform->addElement('filemanager', 'datafiles', get_string('datafiles', 'qtype_codeatelier'),
                null, qtype_codeatelier::datafiles_options());
        $mform->addHelpButton('datafiles', 'datafiles', 'qtype_codeatelier');

        // Per-question judge limits (blank = site default). Judge tier only.
        $mform->addElement('text', 'cputimelimit', get_string('cputimelimit', 'qtype_codeatelier'),
                array('size' => 10));
        $mform->setType('cputimelimit', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('cputimelimit', 'cputimelimit', 'qtype_codeatelier');
        $mform->hideIf('cputimelimit', 'tier', 'neq', 'judge');

        $mform->addElement('text', 'memorylimit', get_string('memorylimit', 'qtype_codeatelier'),
                array('size' => 10));
        $mform->setType('memorylimit', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('memorylimit', 'memorylimit', 'qtype_codeatelier');
        $mform->hideIf('memorylimit', 'tier', 'neq', 'judge');

        $mform->addElement('advcheckbox', 'allornothing', get_string('allornothing', 'qtype_codeatelier'),
                get_string('allornothing_label', 'qtype_codeatelier'));
        $mform->setDefault('allornothing', 0);
        $mform->addHelpButton('allornothing', 'allornothing', 'qtype_codeatelier');

        $mform->addElement('header', 'testcasesheader', get_string('testcases', 'qtype_codeatelier'));
        $mform->setExpanded('testcasesheader');

        $this->add_test_case_fields($mform);

        $this->add_preview_section($mform);

        $this->add_interactive_settings();
    }

    /**
     * Add the live-preview section: a refresh button and the pane the JS renders
     * the real student workbench into.
     *
     * @param MoodleQuickForm $mform the form being built
     */
    protected function add_preview_section($mform) {
        global $PAGE;

        $mform->addElement('header', 'previewheader', get_string('preview', 'qtype_codeatelier'));
        $mform->setExpanded('previewheader', false);

        $previewid = 'codeatelier_preview';
        $refresh = html_writer::tag('button', get_string('previewrefresh', 'qtype_codeatelier'),
                array('type' => 'button', 'class' => 'btn btn-secondary', 'id' => $previewid . '_btn'));
        $capture = html_writer::tag('button', get_string('captureexpected', 'qtype_codeatelier'),
                array('type' => 'button', 'class' => 'btn btn-secondary ml-2', 'id' => $previewid . '_capture'));
        $status = html_writer::tag('span', '', array('class' => 'codeatelier-status ml-2',
                'id' => $previewid . '_status', 'role' => 'status', 'aria-live' => 'polite'));
        $pane = html_writer::div('', 'codeatelier-preview', array('id' => $previewid));
        $mform->addElement('static', 'codeatelier_previewarea', '',
                html_writer::tag('p', get_string('previewintro', 'qtype_codeatelier'), array('class' => 'text-muted'))
                . html_writer::tag('p', get_string('captureexpected_help', 'qtype_codeatelier'),
                        array('class' => 'text-muted'))
                . $refresh . $capture . $status . $pane);

        $PAGE->requires->js_call_amd('qtype_codeatelier/author', 'init', array(array(
            'languageid' => 'id_language',
            'languagesid' => 'id_languages',
            'starterlangid' => 'id_starterlang',
            'startereditid' => 'id_starteredit',
            'startersid' => 'id_starters',
            'referencesolutionid' => 'id_referencesolution',
            'tierid' => 'id_tier',
            'scenarioid' => 'id_scenario',
            'entrypointid' => 'id_entrypoint',
            'startercodeid' => 'id_startercode',
            'schemasqlid' => 'id_schemasql',
            'questiontextid' => 'id_questiontext',
            'previewbtnid' => $previewid . '_btn',
            'captureid' => $previewid . '_capture',
            'statusid' => $previewid . '_status',
            'previewid' => $previewid,
            'contextid' => $this->context->id,
            'previewcontext' => qtype_codeatelier::ui_labels(),
            'strings' => qtype_codeatelier::ui_strings(),
            'capturestrings' => array(
                'running' => get_string('running', 'qtype_codeatelier'),
                'capturedone' => get_string('capturedone', 'qtype_codeatelier'),
                'captureerrors' => get_string('captureerrors', 'qtype_codeatelier'),
                'capturenotests' => get_string('capturenotests', 'qtype_codeatelier'),
                'capturenoreference' => get_string('capturenoreference', 'qtype_codeatelier'),
                'referencewarning' => get_string('referencewarning', 'qtype_codeatelier'),
                'referenceverifyfail' => get_string('referenceverifyfail', 'qtype_codeatelier'),
            ),
            'pyodidebaseurl' => get_config('qtype_codeatelier', 'pyodidebaseurl'),
            'sqljsbaseurl' => get_config('qtype_codeatelier', 'sqljsbaseurl'),
            'timeoutms' => 5000,
        )));
    }

    /**
     * Add the repeated test-case rows.
     *
     * @param MoodleQuickForm $mform the form being built
     */
    protected function add_test_case_fields($mform) {
        $repeated = array();
        $repeated[] = $mform->createElement('static', 'testcaselabel', '',
                get_string('testcaseno', 'qtype_codeatelier', '{no}'));
        $repeated[] = $mform->createElement('textarea', 'testinput',
                get_string('input', 'qtype_codeatelier'), array('rows' => 3, 'cols' => 60));
        $repeated[] = $mform->createElement('textarea', 'testexpected',
                get_string('expected', 'qtype_codeatelier'), array('rows' => 3, 'cols' => 60));
        $repeated[] = $mform->createElement('select', 'matchmode',
                get_string('matchmode', 'qtype_codeatelier'), $this->matchmode_options());
        $repeated[] = $mform->createElement('text', 'tolerance',
                get_string('tolerance', 'qtype_codeatelier'), array('size' => 10));
        $repeated[] = $mform->createElement('text', 'testweight',
                get_string('weight', 'qtype_codeatelier'), array('size' => 10));
        $repeated[] = $mform->createElement('select', 'testvisibility',
                get_string('visibility', 'qtype_codeatelier'), $this->visibility_options());
        $repeated[] = $mform->createElement('textarea', 'checkerquery',
                get_string('checkerquery', 'qtype_codeatelier'), array('rows' => 2, 'cols' => 60));
        $repeated[] = $mform->createElement('textarea', 'testfeedback',
                get_string('testfeedback', 'qtype_codeatelier'), array('rows' => 2, 'cols' => 60));

        // Note: do not set a 'default' for the matchmode/testweight/testvisibility
        // elements here. For repeated elements a default overrides the values
        // loaded by data_preprocessing(), so an edited question would silently
        // reset them. The selects fall back to their first option for new rows;
        // weight is author-entered and checked in validation().
        $repeatedoptions = array();
        $repeatedoptions['testinput']['type'] = PARAM_RAW;
        $repeatedoptions['testexpected']['type'] = PARAM_RAW;
        $repeatedoptions['matchmode']['type'] = PARAM_ALPHANUMEXT;
        $repeatedoptions['tolerance']['type'] = PARAM_RAW_TRIMMED;
        $repeatedoptions['tolerance']['disabledif'] = array('matchmode', 'neq', 'float');
        $repeatedoptions['testweight']['type'] = PARAM_RAW_TRIMMED;
        $repeatedoptions['testvisibility']['type'] = PARAM_INT;
        $repeatedoptions['checkerquery']['type'] = PARAM_RAW;
        $repeatedoptions['checkerquery']['hideif'] = array('scenario', 'neq', 'sql');
        $repeatedoptions['testfeedback']['type'] = PARAM_RAW;

        if (!empty($this->question->options->tests)) {
            $repeats = count($this->question->options->tests);
        } else {
            $repeats = 2;
        }

        $this->repeat_elements($repeated, $repeats, $repeatedoptions, 'option_repeats',
                'option_add_tests', 1, get_string('addmoretestcases', 'qtype_codeatelier'), true);
    }

    /**
     * @return array scenario code => display name
     */
    protected function scenario_options() {
        return array(
            'stdinstdout' => get_string('scenario_stdinstdout', 'qtype_codeatelier'),
            'function' => get_string('scenario_function', 'qtype_codeatelier'),
            'sql' => get_string('scenario_sql', 'qtype_codeatelier'),
        );
    }

    /**
     * @return array tier code => display name
     */
    protected function tier_options() {
        return array(
            'practice' => get_string('tier_practice', 'qtype_codeatelier'),
            'judge' => get_string('tier_judge', 'qtype_codeatelier'),
        );
    }

    /**
     * The full language set. Practice supports python/sql/javascript; judge
     * supports c/cpp/java/python/javascript. The chosen tier/language pair is
     * checked in {@see validation()}.
     *
     * @return array language code => display name
     */
    protected function language_options() {
        return array(
            'python' => get_string('lang_python', 'qtype_codeatelier'),
            'sql' => get_string('lang_sql', 'qtype_codeatelier'),
            'javascript' => get_string('lang_javascript', 'qtype_codeatelier'),
            'c' => get_string('lang_c', 'qtype_codeatelier'),
            'cpp' => get_string('lang_cpp', 'qtype_codeatelier'),
            'java' => get_string('lang_java', 'qtype_codeatelier'),
            'go' => get_string('lang_go', 'qtype_codeatelier'),
            'rust' => get_string('lang_rust', 'qtype_codeatelier'),
            'php' => get_string('lang_php', 'qtype_codeatelier'),
            'typescript' => get_string('lang_typescript', 'qtype_codeatelier'),
            'csharp' => get_string('lang_csharp', 'qtype_codeatelier'),
            'kotlin' => get_string('lang_kotlin', 'qtype_codeatelier'),
            'ruby' => get_string('lang_ruby', 'qtype_codeatelier'),
        );
    }

    /** @var array languages that run in the browser (practice tier). */
    const PRACTICE_LANGUAGES = array('python', 'sql', 'javascript');

    /** @var array languages that run in the server sandbox (judge tier). */
    const JUDGE_LANGUAGES = array('c', 'cpp', 'java', 'python', 'javascript', 'sql',
            'go', 'rust', 'php', 'typescript', 'csharp', 'kotlin', 'ruby');

    /**
     * @return array matching-mode code => display name
     */
    protected function matchmode_options() {
        return array(
            'exact' => get_string('match_exact', 'qtype_codeatelier'),
            'trimmed' => get_string('match_trimmed', 'qtype_codeatelier'),
            'regex' => get_string('match_regex', 'qtype_codeatelier'),
            'float' => get_string('match_float', 'qtype_codeatelier'),
            'structured' => get_string('match_structured', 'qtype_codeatelier'),
            'resultset' => get_string('match_resultset', 'qtype_codeatelier'),
            'resultset_ordered' => get_string('match_resultset_ordered', 'qtype_codeatelier'),
        );
    }

    /**
     * @return array visibility value => display name
     */
    protected function visibility_options() {
        return array(
            1 => get_string('visible_shown', 'qtype_codeatelier'),
            0 => get_string('visible_hidden', 'qtype_codeatelier'),
        );
    }

    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);

        // The allowed-language set is stored as a comma-separated string; the
        // multi-select wants an array.
        $question->languages = (isset($question->languages) && trim((string) $question->languages) !== '')
                ? explode(',', $question->languages) : array();

        // Prepare the support/data files draft area from the saved files.
        $draftid = file_get_submitted_draft_itemid('datafiles');
        file_prepare_draft_area($draftid, $this->context->id, 'qtype_codeatelier', 'datafiles',
                !empty($question->id) ? (int) $question->id : null, qtype_codeatelier::datafiles_options());
        $question->datafiles = $draftid;

        if (empty($question->options->tests)) {
            return $question;
        }

        $question->testinput = array();
        $question->testexpected = array();
        $question->matchmode = array();
        $question->tolerance = array();
        $question->testweight = array();
        $question->testvisibility = array();
        $question->checkerquery = array();
        $question->testfeedback = array();

        foreach (array_values($question->options->tests) as $i => $test) {
            $question->testinput[$i] = $test->input;
            $question->testexpected[$i] = $test->expected;
            $question->matchmode[$i] = $test->matchmode;
            $question->tolerance[$i] = $test->tolerance;
            $question->testweight[$i] = 0 + $test->weight;
            $question->testvisibility[$i] = $test->visibility;
            $question->checkerquery[$i] = $test->checkerquery;
            $question->testfeedback[$i] = $test->feedback;
        }

        return $question;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $tier = isset($data['tier']) ? $data['tier'] : 'practice';
        $scenario = isset($data['scenario']) ? $data['scenario'] : 'stdinstdout';
        $tierlangs = $tier === 'judge' ? self::JUDGE_LANGUAGES : self::PRACTICE_LANGUAGES;

        // The full allowed set: the primary language plus the multi-select choices.
        $allowed = array($data['language']);
        if (!empty($data['languages']) && is_array($data['languages'])) {
            foreach ($data['languages'] as $lang) {
                if (!in_array($lang, $allowed, true)) {
                    $allowed[] = $lang;
                }
            }
        }

        // Every allowed language must belong to the chosen tier.
        foreach ($allowed as $lang) {
            if (!in_array($lang, $tierlangs, true)) {
                $errors['languages'] = ($tier === 'judge')
                    ? get_string('languagenotjudge', 'qtype_codeatelier')
                    : get_string('languagenotpractice', 'qtype_codeatelier');
                break;
            }
        }

        // Scenario constraints. SQL is single-language on BOTH tiers (the source is
        // the schema plus one query). Function grading needs a per-language harness:
        // in-browser for Python/JS on the practice tier; on the judge tier the dynamic
        // languages the sandbox harness covers (static languages need a signature).
        if (!isset($errors['languages'])) {
            if ($scenario === 'sql') {
                if ($allowed !== array('sql')) {
                    $errors['languages'] = get_string('sqlsinglelanguage', 'qtype_codeatelier');
                }
            } else if ($scenario === 'function') {
                $functionlangs = ($tier === 'judge')
                    ? \qtype_codeatelier\judge\function_harness::LANGUAGES
                    : array('python', 'javascript');
                $needssig = false;
                foreach ($allowed as $lang) {
                    if (!in_array($lang, $functionlangs, true)) {
                        $errors['languages'] = get_string('functionlanguageset', 'qtype_codeatelier');
                        break;
                    }
                    if (\qtype_codeatelier\judge\function_harness::needs_signature($lang)) {
                        $needssig = true;
                    }
                }
                // A static-language function question needs a valid typed signature.
                if (!isset($errors['languages']) && $needssig) {
                    $sig = isset($data['functionsignature']) ? trim($data['functionsignature']) : '';
                    if ($sig === '') {
                        $errors['functionsignature'] = get_string('signaturerequired', 'qtype_codeatelier');
                    } else if (!\qtype_codeatelier\judge\signature::is_valid($sig)) {
                        $errors['functionsignature'] = get_string('signatureinvalid', 'qtype_codeatelier');
                    }
                }
            }
        }

        // The per-language starter map is JS-managed; a non-empty value must be JSON.
        if (!empty($data['starters'])) {
            json_decode($data['starters']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors['starteredit'] = get_string('startersinvalidjson', 'qtype_codeatelier');
            }
        }

        // A function question needs the name of the function to call, on either tier.
        if ($data['scenario'] === 'function' && trim($data['entrypoint']) === '') {
            $errors['entrypoint'] = get_string('entrypointrequired', 'qtype_codeatelier');
        }

        // Per-question limits, when given, must be positive numbers.
        foreach (array('cputimelimit', 'memorylimit') as $limit) {
            $value = isset($data[$limit]) ? trim($data[$limit]) : '';
            if ($value !== '' && (!is_numeric($value) || (float) $value <= 0)) {
                $errors[$limit] = get_string('limitpositive', 'qtype_codeatelier');
            }
        }

        $count = 0;
        foreach ($data['testinput'] as $key => $input) {
            $expected = $data['testexpected'][$key];
            if (trim($input) === '' && trim($expected) === '') {
                continue;
            }
            $count++;

            if (trim($data['testweight'][$key]) === '' || !is_numeric($data['testweight'][$key])) {
                $errors["testweight[$key]"] = get_string('weightnumeric', 'qtype_codeatelier');
            }
            if ($data['matchmode'][$key] === 'float') {
                $tolerance = $data['tolerance'][$key];
                if (trim($tolerance) === '' || !is_numeric($tolerance)) {
                    $errors["tolerance[$key]"] = get_string('tolerancenumeric', 'qtype_codeatelier');
                }
            }
        }

        if ($count === 0) {
            $errors['testinput[0]'] = get_string('notenoughtests', 'qtype_codeatelier');
        }

        // Validate-on-save: a judge-tier reference solution must pass every test or
        // the save is rejected (the practice tier is warned client-side instead — the
        // server cannot run browser code). Skips silently with no endpoint or an
        // unreachable sandbox; the grade memo makes an unchanged re-save instant.
        // Skip when the question uses data files: validate-on-save runs the reference
        // through Judge0 but cannot package the not-yet-saved draft files, so it
        // would wrongly fail a reference that reads them.
        if ($tier === 'judge' && $count > 0 && !isset($errors['languages'])
                && trim((string) ($data['referencesolution'] ?? '')) !== ''
                && !$this->has_draft_data_files($data)) {
            $errors = $this->validate_reference($data, $errors);
        }

        return $errors;
    }

    /**
     * Run the judge-tier reference solution against every authored test and add an
     * error if it does not pass them all.
     *
     * @param array $data the submitted form data
     * @param array $errors the errors collected so far
     * @return array the errors, possibly with a referencesolution entry added
     */
    /**
     * Whether the submitted form has data files in its draft area.
     *
     * @param array $data the submitted form data
     * @return bool
     */
    protected function has_draft_data_files($data) {
        if (empty($data['datafiles'])) {
            return false;
        }
        $info = file_get_draft_area_info($data['datafiles']);
        return !empty($info['filecount']);
    }

    protected function validate_reference($data, $errors) {
        question_bank::load_question_definition_classes('codeatelier');
        $question = new qtype_codeatelier_question();
        $question->language = $data['language'];
        $question->tier = 'judge';
        $question->scenario = $data['scenario'];
        $question->schemasql = ($data['scenario'] === 'sql') ? ($data['schemasql'] ?? '') : null;
        $question->cputimelimit = (isset($data['cputimelimit']) && trim($data['cputimelimit']) !== '')
                ? (float) $data['cputimelimit'] : null;
        $question->memorylimit = (isset($data['memorylimit']) && trim($data['memorylimit']) !== '')
                ? (int) $data['memorylimit'] : null;
        $question->languages = null;
        $question->tests = $this->tests_from_form($data);

        $result = $question->get_judge_result($data['referencesolution'], $data['language']);
        if ($result === null || !empty($result['infrastructure'])) {
            // No endpoint, or the sandbox is unreachable: cannot validate, so allow
            // the save rather than block on infrastructure.
            return $errors;
        }
        if (($result['verdict'] ?? '') !== 'AC' || ($result['fraction'] ?? 0) < 1.0) {
            $errors['referencesolution'] = $this->reference_error_message($result);
        }
        return $errors;
    }

    /**
     * Build test-case objects from the submitted form data, skipping blank rows
     * exactly as {@see qtype_codeatelier::save_question_options()} does.
     *
     * @param array $data the submitted form data
     * @return array test-case stdClass objects
     */
    protected function tests_from_form($data) {
        $tests = array();
        foreach ($data['testinput'] as $key => $input) {
            $expected = $data['testexpected'][$key];
            if (trim($input) === '' && trim($expected) === '') {
                continue;
            }
            $tests[] = (object) array(
                'input' => $input,
                'expected' => $expected,
                'matchmode' => $data['matchmode'][$key],
                'tolerance' => isset($data['tolerance'][$key]) ? $data['tolerance'][$key] : null,
                'weight' => $data['testweight'][$key],
                'visibility' => $data['testvisibility'][$key],
                'checkerquery' => isset($data['checkerquery'][$key]) ? $data['checkerquery'][$key] : null,
            );
        }
        return $tests;
    }

    /**
     * A localised "the reference failed" message naming the first failing test, or
     * surfacing the compiler output on a compile error.
     *
     * @param array $result a judge_grader result
     * @return string
     */
    protected function reference_error_message($result) {
        if (($result['verdict'] ?? '') === 'CE') {
            return get_string('referencecompileerror', 'qtype_codeatelier',
                    shorten_text(trim((string) ($result['compileoutput'] ?? '')), 200));
        }
        foreach ($result['testresults'] as $tr) {
            if (empty($tr['passed'])) {
                return get_string('referencefailed', 'qtype_codeatelier', $tr['index'] + 1);
            }
        }
        return get_string('referencefailed', 'qtype_codeatelier', 1);
    }

    public function qtype() {
        return 'codeatelier';
    }
}
