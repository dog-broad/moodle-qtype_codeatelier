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

/**
 * CodeAtelier question editing form: a language picker and a test-case table.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_edit_form extends question_edit_form {
    /**
     * Add the CodeAtelier-specific fields to the question edit form.
     *
     * @param \MoodleQuickForm $mform the form being built
     */
    protected function definition_inner($mform) {
        // When editing an existing question, link to its submissions report.
        if (!empty($this->question->id)) {
            $reporturl = new moodle_url(
                '/question/type/codeatelier/report.php',
                ['id' => $this->question->id]
            );
            $mform->addElement(
                'static',
                'reportlink',
                '',
                html_writer::link($reporturl, get_string('viewreport', 'qtype_codeatelier'))
            );
        }

        // How to put syntax-highlighted code in the question text. The attempt page
        // highlights any language-tagged <pre><code> block; this points authors at
        // the editor's HTML view, which works regardless of the editor's toolbar.
        $codeblocklangs = 'python, sql, javascript, typescript, c, cpp, java, go, rust, php, csharp, html, css';
        $codeblocksnippet = "<pre><code class=\"language-python\">\n"
            . "def add(a, b):\n    return a + b\n</code></pre>";
        $codeblockhelp = html_writer::start_tag('details', ['class' => 'codeatelier-codeblockhelp'])
            . html_writer::tag('summary', get_string('codeblockhelp', 'qtype_codeatelier'))
            . html_writer::tag('p', get_string('codeblockhelp_body', 'qtype_codeatelier', $codeblocklangs))
            . html_writer::tag('pre', s($codeblocksnippet))
            . html_writer::end_tag('details');
        $mform->addElement('static', 'codeblockhelp', '', $codeblockhelp);
        // The guidance is about the question text, so sit it right above that field
        // (which the parent form added before definition_inner ran).
        if ($mform->elementExists('questiontext')) {
            $mform->insertElementBefore($mform->removeElement('codeblockhelp', false), 'questiontext');
        }

        $mform->addElement(
            'select',
            'tier',
            get_string('tier', 'qtype_codeatelier'),
            $this->tier_options()
        );
        $mform->addHelpButton('tier', 'tier', 'qtype_codeatelier');
        $mform->setDefault('tier', $this->get_default_value('tier', 'practice'));

        // A trust badge: which grading tier is in effect (the authoring JS swaps the
        // wording on a tier change). Judge = secure/authoritative; practice = formative.
        $mform->addElement(
            'static',
            'tierbadge',
            '',
            html_writer::span('', '', ['id' => 'id_tierbadge'])
        );

        // Practice-tier grades are browser-computed and forgeable: warn the author
        // not to use this tier for graded assessment. Shown only for the practice tier.
        $mform->addElement(
            'static',
            'practicewarning',
            '',
            html_writer::div(get_string('practicewarning', 'qtype_codeatelier'), 'alert alert-warning')
        );
        $mform->hideIf('practicewarning', 'tier', 'neq', 'practice');

        $mform->addElement(
            'select',
            'language',
            get_string('language', 'qtype_codeatelier'),
            $this->language_options()
        );
        $mform->addHelpButton('language', 'language', 'qtype_codeatelier');
        $mform->setDefault('language', $this->get_default_value('language', 'python'));

        // The set of languages the student may choose from. Empty means
        // single-language (just the primary Language above).
        $mform->addElement(
            'autocomplete',
            'languages',
            get_string('languages', 'qtype_codeatelier'),
            $this->language_options(),
            ['multiple' => true]
        );
        $mform->addHelpButton('languages', 'languages', 'qtype_codeatelier');

        $mform->addElement(
            'select',
            'scenario',
            get_string('scenario', 'qtype_codeatelier'),
            $this->scenario_options()
        );
        $mform->addHelpButton('scenario', 'scenario', 'qtype_codeatelier');
        $mform->setDefault('scenario', $this->get_default_value('scenario', 'stdinstdout'));
        // Both tiers support all three scenarios; the per-scenario language rules
        // (e.g. judge function = dynamic languages only) are enforced in validation().

        $mform->addElement(
            'text',
            'entrypoint',
            get_string('entrypoint', 'qtype_codeatelier'),
            ['size' => 40]
        );
        $mform->setType('entrypoint', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('entrypoint', 'entrypoint', 'qtype_codeatelier');
        $mform->hideIf('entrypoint', 'scenario', 'neq', 'function');

        $mform->addElement(
            'text',
            'functionsignature',
            get_string('functionsignature', 'qtype_codeatelier'),
            ['size' => 40]
        );
        $mform->setType('functionsignature', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('functionsignature', 'functionsignature', 'qtype_codeatelier');
        $mform->hideIf('functionsignature', 'scenario', 'neq', 'function');

        // A structured builder (parameter/return type pickers) the authoring JS
        // renders here and keeps the signature field above in sync.
        $mform->addElement(
            'static',
            'signaturebuilder',
            '',
            html_writer::div('', 'codeatelier-sigbuilder', ['id' => 'id_signaturebuilder'])
        );
        $mform->hideIf('signaturebuilder', 'scenario', 'neq', 'function');

        $mform->addElement(
            'textarea',
            'startercode',
            get_string('startercode', 'qtype_codeatelier'),
            ['rows' => 6, 'cols' => 60, 'spellcheck' => 'false']
        );
        $mform->setType('startercode', PARAM_RAW);
        $mform->addHelpButton('startercode', 'startercode', 'qtype_codeatelier');

        // Per-language starter code for the additional allowed languages (the
        // primary language uses the Starter code field above). The picker + editor
        // are driven by the authoring JS, which keeps the JSON map in the hidden
        // 'starters' field; this section hides itself when only one language is
        // allowed. starterlang/starteredit are UI only and are not saved.
        $mform->addElement(
            'select',
            'starterlang',
            get_string('starterlang', 'qtype_codeatelier'),
            $this->language_options()
        );
        $mform->addElement(
            'textarea',
            'starteredit',
            get_string('starterother', 'qtype_codeatelier'),
            ['rows' => 6, 'cols' => 60, 'spellcheck' => 'false']
        );
        $mform->setType('starteredit', PARAM_RAW);
        $mform->addElement('hidden', 'starters', '', ['id' => 'id_starters']);
        $mform->setType('starters', PARAM_RAW);

        $mform->addElement(
            'textarea',
            'schemasql',
            get_string('schemasql', 'qtype_codeatelier'),
            ['rows' => 6, 'cols' => 60, 'spellcheck' => 'false']
        );
        $mform->setType('schemasql', PARAM_RAW);
        $mform->addHelpButton('schemasql', 'schemasql', 'qtype_codeatelier');
        $mform->hideIf('schemasql', 'scenario', 'neq', 'sql');

        // A soft reminder (not a hard requirement — a query may legitimately need
        // no tables, e.g. SELECT without FROM). Shown only for the SQL format.
        $mform->addElement(
            'static',
            'schemanote',
            '',
            html_writer::div(get_string('schemanote', 'qtype_codeatelier'), 'small text-muted')
        );
        $mform->hideIf('schemanote', 'scenario', 'neq', 'sql');

        // Project scenario (multi-file, judge tier only): the entry/main file name
        // and the starter file set. The file set is a JS-managed file-tab editor
        // (the same one the student attempt uses) that keeps this textarea synced
        // to the canonical JSON.
        $mform->addElement(
            'text',
            'entryfile',
            get_string('entryfile', 'qtype_codeatelier'),
            ['size' => 40, 'id' => 'id_entryfile']
        );
        $mform->setType('entryfile', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('entryfile', 'entryfile', 'qtype_codeatelier');
        // The entry/main file is now a per-language designation inside the project
        // workspace blob (the run badge), not a form field — keep the column for
        // back-compat but never show the element.
        $mform->hideIf('entryfile', 'scenario', 'neq', 'project');
        $mform->hideIf('entryfile', 'scenario', 'eq', 'project');

        $mform->addElement(
            'textarea',
            'projectfiles',
            get_string('projectfilesform', 'qtype_codeatelier'),
            ['rows' => 10, 'cols' => 60, 'spellcheck' => 'false', 'id' => 'id_projectfiles']
        );
        $mform->setType('projectfiles', PARAM_RAW);
        $mform->addHelpButton('projectfiles', 'projectfilesform', 'qtype_codeatelier');
        // The starter file set drives the project (multi-file build) and web
        // (frontend) scenarios; the single-file scenarios do not use it.
        $mform->hideIf('projectfiles', 'scenario', 'eq', 'stdinstdout');
        $mform->hideIf('projectfiles', 'scenario', 'eq', 'function');
        $mform->hideIf('projectfiles', 'scenario', 'eq', 'sql');

        // Single-file starter code does not apply to a file-set (project or web) question.
        $mform->hideIf('startercode', 'scenario', 'eq', 'project');
        $mform->hideIf('startercode', 'scenario', 'eq', 'web');
        // The web scenario authors its files directly in one language, so the
        // language set does not apply. A project now supports several languages
        // (each with its own file environment in the workspace), so its picker is
        // shown like the single-file scenarios.
        $mform->hideIf('languages', 'scenario', 'eq', 'web');
        // SQL is single-language (schema + one query), so additional languages
        // never apply — hide the picker rather than only rejecting it on save.
        $mform->hideIf('languages', 'scenario', 'eq', 'sql');

        // A reference solution (in the primary language). The authoring JS can run
        // it to capture the Expected outputs and to validate the question; on the
        // judge tier a reference that fails any test blocks the save.
        $mform->addElement(
            'textarea',
            'referencesolution',
            get_string('referencesolution', 'qtype_codeatelier'),
            ['rows' => 6, 'cols' => 60, 'spellcheck' => 'false']
        );
        $mform->setType('referencesolution', PARAM_RAW);
        $mform->addHelpButton('referencesolution', 'referencesolution', 'qtype_codeatelier');
        // A web (frontend) question grades against DOM assertions, not a reference
        // run. A project's reference is the workspace's Solution layer (per language,
        // in the projectfiles blob), not this single field — so hide it for both.
        $mform->hideIf('referencesolution', 'scenario', 'eq', 'web');
        $mform->hideIf('referencesolution', 'scenario', 'eq', 'project');

        // Custom checker (special judge): a program that decides AC/WA per test
        // instead of the declarative matching rule, for problems with many valid
        // answers. Judge-tier stdin/stdout only; it reads (input, expected, output)
        // as three files and prints an AC or WA token. Leave blank for no checker.
        $checkerlangs = $this->language_options();
        unset($checkerlangs['sql']);
        $mform->addElement(
            'select',
            'checkerlang',
            get_string('checkerlang', 'qtype_codeatelier'),
            $checkerlangs
        );
        $mform->setDefault('checkerlang', 'python');
        $mform->addHelpButton('checkerlang', 'checkerlang', 'qtype_codeatelier');
        $mform->hideIf('checkerlang', 'tier', 'neq', 'judge');
        $mform->hideIf('checkerlang', 'scenario', 'neq', 'stdinstdout');

        $mform->addElement(
            'textarea',
            'checkersource',
            get_string('checkersource', 'qtype_codeatelier'),
            ['rows' => 8, 'cols' => 60, 'spellcheck' => 'false']
        );
        $mform->setType('checkersource', PARAM_RAW);
        $mform->addHelpButton('checkersource', 'checkersource', 'qtype_codeatelier');
        $mform->hideIf('checkersource', 'tier', 'neq', 'judge');
        $mform->hideIf('checkersource', 'scenario', 'neq', 'stdinstdout');

        // Support/data files: judge tier only. They are placed in the working
        // directory next to the program so it can read them at run time.
        $mform->addElement(
            'filemanager',
            'datafiles',
            get_string('datafiles', 'qtype_codeatelier'),
            null,
            qtype_codeatelier::datafiles_options()
        );
        $mform->addHelpButton('datafiles', 'datafiles', 'qtype_codeatelier');

        // Per-question judge limits (blank = site default). Judge tier only.
        $mform->addElement(
            'text',
            'cputimelimit',
            get_string('cputimelimit', 'qtype_codeatelier'),
            ['size' => 10]
        );
        $mform->setType('cputimelimit', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('cputimelimit', 'cputimelimit', 'qtype_codeatelier');
        $mform->hideIf('cputimelimit', 'tier', 'neq', 'judge');

        $mform->addElement(
            'text',
            'memorylimit',
            get_string('memorylimit', 'qtype_codeatelier'),
            ['size' => 10]
        );
        $mform->setType('memorylimit', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('memorylimit', 'memorylimit', 'qtype_codeatelier');
        $mform->hideIf('memorylimit', 'tier', 'neq', 'judge');

        $mform->addElement(
            'advcheckbox',
            'allornothing',
            get_string('allornothing', 'qtype_codeatelier'),
            get_string('allornothing_label', 'qtype_codeatelier')
        );
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
        $refresh = html_writer::tag(
            'button',
            get_string('previewrefresh', 'qtype_codeatelier'),
            ['type' => 'button', 'class' => 'btn btn-secondary', 'id' => $previewid . '_btn']
        );
        $capture = html_writer::tag(
            'button',
            get_string('captureexpected', 'qtype_codeatelier'),
            ['type' => 'button', 'class' => 'btn btn-secondary ms-2', 'id' => $previewid . '_capture']
        );
        $status = html_writer::tag('span', '', ['class' => 'codeatelier-status ms-2',
                'id' => $previewid . '_status', 'role' => 'status', 'aria-live' => 'polite']);
        // The author config bundles every UI string and label, exceeding the
        // 1024-char cap Moodle enforces on js_call_amd arguments. Ship it on the
        // preview pane's data-config attribute; author.js reads it from the DOM.
        $authorconfig = [
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
            'projectfilesid' => 'id_projectfiles',
            'entryfileid' => 'id_entryfile',
            'tierbadgeid' => 'id_tierbadge',
            'tierbadges' => [
                'judge' => get_string('tierbadge_judge', 'qtype_codeatelier'),
                'practice' => get_string('tierbadge_practice', 'qtype_codeatelier'),
            ],
            'functionsignatureid' => 'id_functionsignature',
            'signaturebuilderid' => 'id_signaturebuilder',
            // The compiled languages: their function questions need a typed signature
            // and so are bounded to the flat type grammar. The JS uses this to gate the
            // signature UI on the chosen languages (dynamic languages take JSON args of
            // any shape and need no signature).
            'staticfunctionlangs' => \qtype_codeatelier\judge\function_harness::STATIC,
            'sigbuilderstrings' => [
                'returns' => get_string('sigbuilder_returns', 'qtype_codeatelier'),
                'addparam' => get_string('sigbuilder_addparam', 'qtype_codeatelier'),
                'removeparam' => get_string('sigbuilder_removeparam', 'qtype_codeatelier'),
                'noparams' => get_string('sigbuilder_noparams', 'qtype_codeatelier'),
                'param' => get_string('sigbuilder_param', 'qtype_codeatelier'),
                'dynamicnote' => get_string('sigbuilder_dynamicnote', 'qtype_codeatelier'),
                'staticnote' => get_string('sigbuilder_staticnote', 'qtype_codeatelier'),
            ],
            'questiontextid' => 'id_questiontext',
            'previewbtnid' => $previewid . '_btn',
            'captureid' => $previewid . '_capture',
            'statusid' => $previewid . '_status',
            'previewid' => $previewid,
            'contextid' => $this->context->id,
            'previewcontext' => qtype_codeatelier::ui_labels(),
            'strings' => qtype_codeatelier::ui_strings(),
            'capturestrings' => [
                'running' => get_string('running', 'qtype_codeatelier'),
                'capturedone' => get_string('capturedone', 'qtype_codeatelier'),
                'captureerrors' => get_string('captureerrors', 'qtype_codeatelier'),
                'capturenotests' => get_string('capturenotests', 'qtype_codeatelier'),
                'capturenoreference' => get_string('capturenoreference', 'qtype_codeatelier'),
                'referencewarning' => get_string('referencewarning', 'qtype_codeatelier'),
                'referenceverifyfail' => get_string('referenceverifyfail', 'qtype_codeatelier'),
                'capturefirsterror' => get_string('capturefirsterror', 'qtype_codeatelier'),
                'reviewheading' => get_string('capturereviewheading', 'qtype_codeatelier'),
                'itemok' => get_string('captureitemok', 'qtype_codeatelier'),
                'itemerror' => get_string('captureitemerror', 'qtype_codeatelier'),
                'test' => get_string('result_test', 'qtype_codeatelier'),
                'expected' => get_string('expected', 'qtype_codeatelier'),
                'rowsnote' => get_string('rowsnote', 'qtype_codeatelier'),
            ],
            'pyodidebaseurl' => get_config('qtype_codeatelier', 'pyodidebaseurl'),
            'sqljsbaseurl' => get_config('qtype_codeatelier', 'sqljsbaseurl'),
            'timeoutms' => 5000,
        ];
        $pane = html_writer::div('', 'codeatelier-preview', ['id' => $previewid, 'data-config' => json_encode($authorconfig)]);
        $mform->addElement(
            'static',
            'codeatelier_previewarea',
            '',
            html_writer::tag('p', get_string('previewintro', 'qtype_codeatelier'), ['class' => 'text-muted'])
                . html_writer::tag(
                    'p',
                    get_string('captureexpected_help', 'qtype_codeatelier'),
                    ['class' => 'text-muted']
                )
            . html_writer::div($refresh . $capture . $status, 'codeatelier-preview-controls')
            . $pane
        );

        $PAGE->requires->js_call_amd('qtype_codeatelier/author', 'init', [[
            'previewid' => $previewid,
        ]]);
    }

    /**
     * Add the test-case authoring field (one JSON field, enhanced by JS into a
     * scalable editor).
     *
     * @param MoodleQuickForm $mform the form being built
     */
    protected function add_test_case_fields($mform) {
        global $PAGE;
        // Test cases are authored as a single JSON field rather than ~14 form
        // inputs per case: testeditor.js enhances this textarea into a scalable
        // editor (paginated, searchable, with spreadsheet/CSV/JSON bulk import).
        // One field has no per-test field-count ceiling, so large sets save
        // reliably — the old per-row repeat form silently dropped fields once PHP's
        // max_input_vars was exceeded (~65 tests). With JS off the raw JSON in the
        // textarea is still editable.
        $config = [
            'scenarioid' => 'id_scenario',
            'entrypointid' => 'id_entrypoint',
            'matchmodes' => $this->matchmode_options(),
            'visibilities' => $this->visibility_options(),
            'assertmatchers' => $this->assertmatcher_options(),
            'strings' => $this->testeditor_strings(),
        ];
        $mform->addElement(
            'textarea',
            'testcasesjson',
            get_string('testcases', 'qtype_codeatelier'),
            [
                'rows' => 8,
                'class' => 'codeatelier-testcases-json',
                'data-role' => 'testcases-json',
                'data-config' => json_encode($config),
                'spellcheck' => 'false',
            ]
        );
        $mform->setType('testcasesjson', PARAM_RAW);

        $PAGE->requires->js_call_amd('qtype_codeatelier/testeditor', 'init', [[
            'fieldid' => 'id_testcasesjson',
        ]]);
    }

    /**
     * Localised strings handed to the test-case editor JS (via data-config).
     *
     * Column labels reuse the existing field strings; the rest are editor chrome.
     *
     * @return array string key => localised text
     */
    protected function testeditor_strings() {
        $keys = [
            'input', 'expected', 'matchmode', 'tolerance', 'weight', 'visibility',
            'callpreview', 'callpreviewhint',
            'subtask', 'checkerquery', 'testdataset', 'testfeedback', 'assertselector',
            'assertproperty', 'assertmatcher', 'assertactions',
            'editor_addtest', 'editor_edit', 'editor_delete', 'editor_moveup',
            'editor_movedown', 'editor_search', 'editor_count', 'editor_page',
            'editor_empty', 'editor_import', 'editor_importformat', 'editor_importhelp',
            'editor_template', 'editor_format_tsv', 'editor_format_csv', 'editor_format_json',
            'editor_paste', 'editor_upload', 'editor_append', 'editor_replace', 'editor_export',
            'editor_importok', 'editor_importerror', 'editor_done', 'editor_visible', 'editor_hidden',
        ];
        $strings = [];
        foreach ($keys as $key) {
            $strings[$key] = get_string($key, 'qtype_codeatelier');
        }
        return $strings;
    }

    /**
     * The selectable question-format (scenario) options.
     *
     * @return array scenario code => display name
     */
    protected function scenario_options() {
        return [
            'stdinstdout' => get_string('scenario_stdinstdout', 'qtype_codeatelier'),
            'function' => get_string('scenario_function', 'qtype_codeatelier'),
            'sql' => get_string('scenario_sql', 'qtype_codeatelier'),
            'project' => get_string('scenario_project', 'qtype_codeatelier'),
            'web' => get_string('scenario_web', 'qtype_codeatelier'),
        ];
    }

    /**
     * The selectable grading-mode (tier) options.
     *
     * @return array tier code => display name
     */
    protected function tier_options() {
        return [
            'practice' => get_string('tier_practice', 'qtype_codeatelier'),
            'judge' => get_string('tier_judge', 'qtype_codeatelier'),
        ];
    }

    /**
     * The full language set. Practice supports python/sql/javascript; judge
     * supports c/cpp/java/python/javascript. The chosen tier/language pair is
     * checked in {@see validation()}.
     *
     * @return array language code => display name
     */
    protected function language_options() {
        return [
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
        ];
    }

    /** @var array languages that run in the browser (practice tier). */
    const PRACTICE_LANGUAGES = ['python', 'sql', 'javascript'];

    /** @var array languages that run in the server sandbox (judge tier). */
    const JUDGE_LANGUAGES = ['c', 'cpp', 'java', 'python', 'javascript', 'sql',
            'go', 'rust', 'php', 'typescript', 'csharp', 'kotlin', 'ruby'];

    /**
     * The selectable matching-mode options.
     *
     * @return array matching-mode code => display name
     */
    protected function matchmode_options() {
        return [
            'exact' => get_string('match_exact', 'qtype_codeatelier'),
            'trimmed' => get_string('match_trimmed', 'qtype_codeatelier'),
            'regex' => get_string('match_regex', 'qtype_codeatelier'),
            'float' => get_string('match_float', 'qtype_codeatelier'),
            'structured' => get_string('match_structured', 'qtype_codeatelier'),
            'resultset' => get_string('match_resultset', 'qtype_codeatelier'),
            'resultset_ordered' => get_string('match_resultset_ordered', 'qtype_codeatelier'),
        ];
    }

    /**
     * The selectable test-visibility options.
     *
     * @return array visibility value => display name
     */
    protected function visibility_options() {
        return [
            1 => get_string('visible_shown', 'qtype_codeatelier'),
            0 => get_string('visible_hidden', 'qtype_codeatelier'),
        ];
    }

    /**
     * The matchers a web DOM assertion can use to compare the property value.
     *
     * @return array matcher code => display name
     */
    protected function assertmatcher_options() {
        return [
            'equals' => get_string('assertmatch_equals', 'qtype_codeatelier'),
            'contains' => get_string('assertmatch_contains', 'qtype_codeatelier'),
            'regex' => get_string('assertmatch_regex', 'qtype_codeatelier'),
        ];
    }

    /**
     * Whether a web DOM-assertion property is one the interpreter understands:
     * text, exists, count, or attr:<name> / style:<property>.
     *
     * @param string $property
     * @return bool
     */
    protected static function valid_assert_property($property) {
        $property = trim((string) $property);
        if (in_array($property, ['text', 'exists', 'count'], true)) {
            return true;
        }
        return (bool) preg_match('/^(attr|style):\S+/', $property);
    }

    /**
     * Prepare the loaded question data for display in the form.
     *
     * @param object $question the question being edited
     * @return object
     */
    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);

        // The allowed-language set is stored as a comma-separated string; the
        // multi-select wants an array.
        $question->languages = (isset($question->languages) && trim((string) $question->languages) !== '')
                ? explode(',', $question->languages) : [];

        // Prepare the support/data files draft area from the saved files.
        $draftid = file_get_submitted_draft_itemid('datafiles');
        file_prepare_draft_area(
            $draftid,
            $this->context->id,
            'qtype_codeatelier',
            'datafiles',
            !empty($question->id) ? (int) $question->id : null,
            qtype_codeatelier::datafiles_options()
        );
        $question->datafiles = $draftid;

        // Serialise the saved test cases into the single JSON field the editor
        // reads (one form input, not ~14 per case — see add_test_case_fields).
        $tests = [];
        foreach (array_values($question->options->tests ?? []) as $test) {
            $tests[] = [
                'input' => (string) $test->input,
                'expected' => (string) $test->expected,
                'matchmode' => (string) $test->matchmode,
                'tolerance' => ($test->tolerance === null || $test->tolerance === '')
                    ? '' : (string) (0 + $test->tolerance),
                'weight' => 0 + $test->weight,
                'visibility' => (int) $test->visibility,
                'feedback' => (string) $test->feedback,
                'checkerquery' => (string) $test->checkerquery,
                'datasetsql' => (string) ($test->datasetsql ?? ''),
                'selector' => (string) $test->selector,
                'assertproperty' => (string) $test->assertproperty,
                'assertmatcher' => (string) $test->assertmatcher,
                'actions' => (string) $test->actions,
                'subtask' => isset($test->subtask) ? (int) $test->subtask : 0,
            ];
        }
        $question->testcasesjson = json_encode($tests);

        return $question;
    }

    /**
     * Validate the submitted form data.
     *
     * @param array $data the submitted values
     * @param array $files the submitted files
     * @return array field name => error message
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $tier = isset($data['tier']) ? $data['tier'] : 'practice';
        $scenario = isset($data['scenario']) ? $data['scenario'] : 'stdinstdout';
        $tierlangs = $tier === 'judge' ? self::JUDGE_LANGUAGES : self::PRACTICE_LANGUAGES;

        // The full allowed set: the primary language plus the multi-select choices.
        $allowed = [$data['language']];
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
                if ($allowed !== ['sql']) {
                    $errors['languages'] = get_string('sqlsinglelanguage', 'qtype_codeatelier');
                }
            } else if ($scenario === 'function') {
                $functionlangs = ($tier === 'judge')
                    ? \qtype_codeatelier\judge\function_harness::LANGUAGES
                    : ['python', 'javascript'];
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
                        // A nested type (e.g. list<map<...>>) is the common reason a
                        // signature is rejected; point at the dynamic-language path.
                        $errors['functionsignature'] = preg_match('/<[^>]*</', $sig)
                            ? get_string('signaturenested', 'qtype_codeatelier')
                            : get_string('signatureinvalid', 'qtype_codeatelier');
                    } else if (array_intersect($allowed, \qtype_codeatelier\judge\function_harness::SCALAR_ONLY)) {
                        // C grades scalar parameters/return only (arrays are awkward in C).
                        $parsed = \qtype_codeatelier\judge\signature::parse($sig);
                        foreach (array_merge($parsed->params(), [$parsed->return_type()]) as $t) {
                            if (!\qtype_codeatelier\judge\signature::is_scalar($t)) {
                                $errors['functionsignature'] = get_string('signaturescalaronly', 'qtype_codeatelier');
                                break;
                            }
                        }
                    }
                }
            } else if ($scenario === 'project') {
                // A project is judge-tier only; one or more allowed languages, each
                // of which must support a multi-file build.
                if ($tier !== 'judge') {
                    $errors['scenario'] = get_string('projectjudgeonly', 'qtype_codeatelier');
                } else {
                    foreach ($allowed as $lang) {
                        if (!\qtype_codeatelier\judge\project_packager::supports($lang)) {
                            $errors['language'] = get_string('projectlanguageset', 'qtype_codeatelier');
                            break;
                        }
                    }
                }
            }
            // Web (frontend) questions grade on either tier: Practice mode in the
            // browser (formative), or Secure mode via the web-judge service.
        }

        // A project must carry, for each allowed language, a file environment with at
        // least one file and an entry file that is one of those files.
        if ($scenario === 'project' && !isset($errors['language'])) {
            $envs = \qtype_codeatelier\judge\project_packager::decode_envs(
                isset($data['projectfiles']) ? (string) $data['projectfiles'] : ''
            );
            foreach ($allowed as $lang) {
                $env = isset($envs[$lang]) ? $envs[$lang] : null;
                $names = ($env && is_array($env['files'] ?? null))
                    ? array_keys($env['files']) : [];
                if (!$names) {
                    $errors['projectfiles'] = get_string('projectfilesempty', 'qtype_codeatelier');
                    break;
                }
                $entry = $env['entry'] ?? '';
                if ($entry === '' || !in_array($entry, $names, true)) {
                    $errors['projectfiles'] = get_string('entryfilemissing', 'qtype_codeatelier');
                    break;
                }
            }
        }

        // A web question must carry a starter file set with an HTML page to render.
        if ($scenario === 'web') {
            $files = \qtype_codeatelier\judge\project_packager::decode_files(
                isset($data['projectfiles']) ? (string) $data['projectfiles'] : ''
            );
            $hashtml = false;
            foreach (array_keys($files) as $name) {
                if (preg_match('/\.html?$/i', $name)) {
                    $hashtml = true;
                    break;
                }
            }
            if (!$files) {
                $errors['projectfiles'] = get_string('projectfilesempty', 'qtype_codeatelier');
            } else if (!$hashtml) {
                $errors['projectfiles'] = get_string('webnohtml', 'qtype_codeatelier');
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
        foreach (['cputimelimit', 'memorylimit'] as $limit) {
            $value = isset($data[$limit]) ? trim($data[$limit]) : '';
            if ($value !== '' && (!is_numeric($value) || (float) $value <= 0)) {
                $errors[$limit] = get_string('limitpositive', 'qtype_codeatelier');
            }
        }

        // Test cases arrive as one JSON field (see add_test_case_fields). Decode
        // and validate it; all test-case errors attach to that single field.
        $count = 0;
        $isweb = ($scenario === 'web');
        $rawjson = (string) ($data['testcasesjson'] ?? '');
        $tests = ($rawjson === '') ? [] : json_decode($rawjson, true);
        if (!is_array($tests)) {
            $errors['testcasesjson'] = get_string('testcasesjsoninvalid', 'qtype_codeatelier');
            $tests = [];
        }
        foreach ($tests as $test) {
            if (!is_array($test)) {
                $errors['testcasesjson'] = get_string('testcasesjsoninvalid', 'qtype_codeatelier');
                break;
            }
            if ($isweb) {
                if (trim((string) ($test['selector'] ?? '')) === '') {
                    continue;
                }
                $count++;
                if (!self::valid_assert_property(trim((string) ($test['assertproperty'] ?? '')))) {
                    $errors['testcasesjson'] = get_string('assertpropertyinvalid', 'qtype_codeatelier');
                }
            } else {
                if (trim((string) ($test['input'] ?? '')) === '' && trim((string) ($test['expected'] ?? '')) === '') {
                    continue;
                }
                $count++;
                if (($test['matchmode'] ?? '') === 'float') {
                    $tolerance = $test['tolerance'] ?? '';
                    if (trim((string) $tolerance) === '' || !is_numeric($tolerance)) {
                        $errors['testcasesjson'] = get_string('tolerancenumeric', 'qtype_codeatelier');
                    }
                }
            }
            $weight = $test['weight'] ?? '';
            if (trim((string) $weight) === '' || !is_numeric($weight)) {
                $errors['testcasesjson'] = get_string('weightnumeric', 'qtype_codeatelier');
            }
        }

        // Every gradable question needs at least one test case (a web question, at
        // least one DOM assertion).
        if ($count === 0) {
            $errors['testcasesjson'] = $isweb
                ? get_string('noassertions', 'qtype_codeatelier')
                : get_string('notenoughtests', 'qtype_codeatelier');
        }

        $hasreference = ($scenario === 'project')
                ? self::project_reference_has_content($data['projectfiles'] ?? '')
                : (trim((string) ($data['referencesolution'] ?? '')) !== '');

        // A custom checker (special judge) is a judge-tier stdin/stdout feature,
        // written in a general-purpose judge language. It needs a reference
        // solution so validate-on-save can prove the checker accepts a correct
        // answer — the primary guard against shipping a broken checker.
        $checkersource = isset($data['checkersource']) ? trim((string) $data['checkersource']) : '';
        if ($checkersource !== '') {
            if ($tier !== 'judge' || $scenario !== 'stdinstdout') {
                $errors['checkersource'] = get_string('checkerscenario', 'qtype_codeatelier');
            } else {
                $checkerlang = isset($data['checkerlang']) ? $data['checkerlang'] : '';
                if (!in_array($checkerlang, self::JUDGE_LANGUAGES, true) || $checkerlang === 'sql') {
                    $errors['checkerlang'] = get_string('checkerlangrequired', 'qtype_codeatelier');
                }
                if (!$hasreference) {
                    $errors['referencesolution'] = get_string('checkerneedsreference', 'qtype_codeatelier');
                }
            }
        }

        // Validate-on-save: a judge-tier reference solution must pass every test or
        // the save is rejected (the practice tier is warned client-side instead — the
        // server cannot run browser code). Skips silently with no endpoint or an
        // unreachable sandbox; the grade memo makes an unchanged re-save instant.
        // Skip when the question uses data files: validate-on-save runs the reference
        // through Judge0 but cannot package the not-yet-saved draft files, so it
        // would wrongly fail a reference that reads them.
        // For a project the reference is a file set; only validate when it has real
        // content (the file-tab editor seeds an empty default that must not block).
        if (
            $tier === 'judge' && $count > 0 && !isset($errors['languages'])
                && !isset($errors['checkersource']) && !isset($errors['checkerlang'])
                && $hasreference && !$this->has_draft_data_files($data)
        ) {
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

    /**
     * Whether a project's environment blob carries any Solution-layer content, i.e.
     * a reference worth validating (an author who filled only starters has no
     * reference to run; the Solution layer is the reference).
     *
     * @param string $json the projectfiles blob
     * @return bool
     */
    protected static function project_reference_has_content($json) {
        $blob = json_decode((string) $json, true);
        if (!is_array($blob) || empty($blob['envs'])) {
            return false;
        }
        foreach ($blob['envs'] as $env) {
            foreach ((is_array($env['files'] ?? null) ? $env['files'] : []) as $f) {
                if (is_array($f) && trim((string) ($f['solution'] ?? '')) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Validate the reference solution by running it through the grade path.
     *
     * @param array $data the submitted values
     * @param array $errors the errors collected so far
     * @return array the errors, with any reference failure added
     */
    protected function validate_reference($data, $errors) {
        question_bank::load_question_definition_classes('codeatelier');
        $question = new qtype_codeatelier_question();
        $question->language = $data['language'];
        $question->tier = 'judge';
        $question->scenario = $data['scenario'];
        $question->schemasql = ($data['scenario'] === 'sql') ? ($data['schemasql'] ?? '') : null;
        // The project reference + entry are per-language inside the blob; project_entry
        // resolves the entry for the primary language from it.
        $question->projectfiles = ($data['scenario'] === 'project')
                ? (string) ($data['projectfiles'] ?? '') : null;
        $question->entryfile = null;
        $question->cputimelimit = (isset($data['cputimelimit']) && trim($data['cputimelimit']) !== '')
                ? (float) $data['cputimelimit'] : null;
        $question->memorylimit = (isset($data['memorylimit']) && trim($data['memorylimit']) !== '')
                ? (int) $data['memorylimit'] : null;
        $question->languages = null;
        // A custom checker is validated by the same reference run: the reference's
        // output must be ACCEPTED by the checker for every test.
        $question->checkersource = isset($data['checkersource']) ? (string) $data['checkersource'] : null;
        $question->checkerlang = isset($data['checkerlang']) ? (string) $data['checkerlang'] : null;
        $question->tests = $this->tests_from_form($data);

        // The reference code: for a project it is the primary language's Solution-layer
        // file set from the blob; otherwise the single reference field.
        if ($data['scenario'] === 'project') {
            $envs = \qtype_codeatelier\judge\project_packager::decode_envs($question->projectfiles);
            $env = isset($envs[$data['language']]) ? $envs[$data['language']]
                    : (reset($envs) ?: null);
            $referencecode = $env
                    ? \qtype_codeatelier\judge\project_packager::encode_files(
                        \qtype_codeatelier\judge\project_packager::reference_files($env))
                    : '[]';
        } else {
            $referencecode = $data['referencesolution'];
        }
        $result = $question->get_judge_result($referencecode, $data['language']);
        if ($result === null) {
            return $errors;
        }
        if (!empty($result['infrastructure'])) {
            // A broken checker surfaces as an infrastructure result whose message
            // names the checker — that is a question error the author must fix, so
            // block. A genuinely unreachable sandbox cannot be validated, so allow.
            if (stripos((string) ($result['message'] ?? ''), 'checker') !== false) {
                $errors['checkersource'] = get_string(
                    'checkerinvalid',
                    'qtype_codeatelier',
                    (string) $result['message']
                );
            }
            return $errors;
        }
        if (($result['verdict'] ?? '') !== 'AC' || ($result['fraction'] ?? 0) < 1.0) {
            $errors['referencesolution'] = $this->reference_error_message($result);
        }
        return $errors;
    }

    /**
     * Build test-case objects from the submitted form data, skipping blank rows
     * exactly as {@see qtype_codeatelier::save_question_options()} does. Test
     * cases arrive as one JSON field (see add_test_case_fields).
     *
     * @param array $data the submitted form data
     * @return array test-case stdClass objects
     */
    protected function tests_from_form($data) {
        $decoded = json_decode((string) ($data['testcasesjson'] ?? ''), true);
        $isweb = (($data['scenario'] ?? '') === 'web');
        $tests = [];
        foreach (is_array($decoded) ? $decoded : [] as $test) {
            if (!is_array($test)) {
                continue;
            }
            $input = (string) ($test['input'] ?? '');
            $expected = (string) ($test['expected'] ?? '');
            $selector = trim((string) ($test['selector'] ?? ''));
            // A web assertion row is keyed by its selector; every other scenario
            // by its input/expected.
            if ($isweb ? ($selector === '') : (trim($input) === '' && trim($expected) === '')) {
                continue;
            }
            $tests[] = (object) [
                'input' => $input,
                'expected' => $expected,
                'matchmode' => (string) ($test['matchmode'] ?? 'trimmed'),
                'tolerance' => ($test['tolerance'] ?? '') === '' ? null : $test['tolerance'],
                'weight' => $test['weight'] ?? 1,
                'visibility' => (int) ($test['visibility'] ?? 1),
                'checkerquery' => ($test['checkerquery'] ?? '') === '' ? null : (string) $test['checkerquery'],
                'datasetsql' => ($test['datasetsql'] ?? '') === '' ? null : (string) $test['datasetsql'],
                'selector' => $selector,
                'assertproperty' => (string) ($test['assertproperty'] ?? ''),
                'assertmatcher' => (string) ($test['assertmatcher'] ?? ''),
                'actions' => (string) ($test['actions'] ?? ''),
                'subtask' => (int) ($test['subtask'] ?? 0),
            ];
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
            return get_string(
                'referencecompileerror',
                'qtype_codeatelier',
                shorten_text(trim((string) ($result['compileoutput'] ?? '')), 200)
            );
        }
        foreach ($result['testresults'] as $tr) {
            if (empty($tr['passed'])) {
                return get_string('referencefailed', 'qtype_codeatelier', $tr['index'] + 1);
            }
        }
        return get_string('referencefailed', 'qtype_codeatelier', 1);
    }

    /**
     * The question type name this form edits.
     *
     * @return string
     */
    public function qtype() {
        return 'codeatelier';
    }
}
