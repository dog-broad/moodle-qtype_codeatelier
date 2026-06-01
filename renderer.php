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
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_renderer extends qtype_renderer {

    public function formulation_and_controls(question_attempt $qa,
            question_display_options $options) {

        global $PAGE;

        $question = $qa->get_question();
        $inputname = $qa->get_qt_field_name('score');
        $current = $qa->get_last_qt_var('score', '');

        $editorid = $inputname . '_editor';

        // Lazy-load CodeMirror 6 as an AMD module through Moodle's own JavaScript
        // loader (no external CDN). The editor is bundled to a single minified AMD
        // module by the plugin's build step, so Moodle's requirejs serves it like
        // any core module. The question's authored language selects the syntax mode.
        $language = !empty($question->language) ? $question->language : 'python';
        $PAGE->requires->js_call_amd('qtype_codeatelier/editor', 'init',
                [$editorid, $this->starter_code($language), $language]);

        $choices = array('1', '0.5', '0');
        $labels = array(
            '1' => get_string('optionaccepted', 'qtype_codeatelier'),
            '0.5' => get_string('optionpartial', 'qtype_codeatelier'),
            '0' => get_string('optionwrong', 'qtype_codeatelier'),
        );

        $optionsdata = array();
        foreach ($choices as $i => $value) {
            $optionsdata[] = array(
                'name' => $inputname,
                'id' => $inputname . '_' . $i,
                'value' => $value,
                'label' => $labels[$value],
                'checked' => ((string) $current === $value),
                'readonly' => $options->readonly,
            );
        }

        $context = array(
            'questiontext' => $question->format_questiontext($qa),
            'prompt' => get_string('spikeprompt', 'qtype_codeatelier'),
            'editorid' => $editorid,
            'options' => $optionsdata,
            'hasvalidationerror' => $qa->get_state() == question_state::$invalid,
            'validationerror' => $question->get_validation_error(array('score' => $current)),
        );

        return $this->render_from_template('qtype_codeatelier/spike_control', $context);
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
