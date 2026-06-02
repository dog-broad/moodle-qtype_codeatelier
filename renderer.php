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
 * CodeAtelier question renderer.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Generates the output for CodeAtelier questions.
 *
 * Renders the code editor, a run/check control, and a results area. The student
 * writes code (the 'answer' field); the browser runs and grades it client-side
 * and writes the fraction into the hidden 'score' field. The practice tier ships
 * the test cases to the browser, so it is for practice only — never secure
 * grading (see the integrity boundary).
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_renderer extends qtype_renderer {

    public function formulation_and_controls(question_attempt $qa,
            question_display_options $options) {
        global $PAGE;

        $question = $qa->get_question();
        $language = !empty($question->language) ? $question->language : 'python';

        $answername = $qa->get_qt_field_name('answer');
        $scorename = $qa->get_qt_field_name('score');
        $wrapperid = $answername . '_wrapper';

        $currentcode = $qa->get_last_qt_var('answer', '');
        if ($currentcode === '') {
            $currentcode = ($question->startercode !== null && $question->startercode !== '')
                ? $question->startercode
                : $this->starter_code($language);
        }
        $currentscore = $qa->get_last_qt_var('score', '');

        // Ship the test cases to the browser grader. This is the practice tier:
        // hidden tests are only "polite-hidden" because the client must grade.
        $tests = array();
        foreach ($question->tests as $test) {
            $tests[] = array(
                'input' => $test->input,
                'expected' => $test->expected,
                'matchmode' => $test->matchmode,
                'tolerance' => $test->tolerance,
                'weight' => $test->weight,
                'visibility' => (int) $test->visibility,
                'checkerquery' => $test->checkerquery,
                // Ship teacher feedback only for visible tests (hidden reveals nothing client-side).
                'feedback' => ((int) $test->visibility === 1) ? $test->feedback : null,
            );
        }

        $context = array(
            'questiontext' => $question->format_questiontext($qa),
            'wrapperid' => $wrapperid,
            'answername' => $answername,
            'answerid' => $answername,
            'answervalue' => $currentcode,
            'scorename' => $scorename,
            'scorevalue' => $currentscore,
            'readonly' => $options->readonly,
            'hasvalidationerror' => $qa->get_state() == question_state::$invalid,
            'validationerror' => $question->get_validation_error(array('score' => $currentscore)),
        ) + qtype_codeatelier::ui_labels();

        $PAGE->requires->js_call_amd('qtype_codeatelier/control', 'init', array(array(
            'wrapperid' => $wrapperid,
            'language' => $language,
            'scenario' => !empty($question->scenario) ? $question->scenario : 'stdinstdout',
            'entrypoint' => $question->entrypoint,
            'schemasql' => $question->schemasql,
            'pyodidebaseurl' => get_config('qtype_codeatelier', 'pyodidebaseurl'),
            'sqljsbaseurl' => get_config('qtype_codeatelier', 'sqljsbaseurl'),
            'tests' => $tests,
            'timeoutms' => 5000,
            'readonly' => (bool) $options->readonly,
            'strings' => qtype_codeatelier::ui_strings(),
        )));

        return $this->render_from_template('qtype_codeatelier/editor_control', $context);
    }

    /**
     * A language-appropriate placeholder shown in a fresh editor.
     *
     * @param string $language python, sql or javascript
     * @return string the starter source
     */
    protected function starter_code($language) {
        switch ($language) {
            case 'sql':
                return "-- Write your SQL query here\n";
            case 'javascript':
                return "// Write your JavaScript here\n";
            case 'python':
            default:
                return "# Write your Python code here\n";
        }
    }
}
