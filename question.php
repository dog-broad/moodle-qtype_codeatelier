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
 * CodeAtelier question definition class.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/questionbase.php');

/**
 * Represents a CodeAtelier question at runtime.
 *
 * The result is computed on the client and submitted as the 'score' response
 * variable (a fraction in [0, 1]). grade_response() clamps and returns it. The
 * score is part of the stored response, so a later regrade reads the same value
 * and returns the same fraction (the grade is idempotent under regrade).
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_question extends question_graded_automatically {

    /** @var string the practice-tier language: python, sql or javascript. */
    public $language;

    /** @var array the test cases (rows from qtype_codeatelier_tests). */
    public $tests = array();

    public function get_expected_data() {
        return array('score' => PARAM_RAW_TRIMMED);
    }

    public function get_correct_response() {
        return array('score' => '1');
    }

    public function is_complete_response(array $response) {
        return array_key_exists('score', $response) && $response['score'] !== ''
                && is_numeric($response['score']);
    }

    public function is_gradable_response(array $response) {
        return $this->is_complete_response($response);
    }

    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('pleaserun', 'qtype_codeatelier');
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_same_at_key_missing_is_blank(
                $prevresponse, $newresponse, 'score');
    }

    public function summarise_response(array $response) {
        if (!array_key_exists('score', $response) || $response['score'] === ''
                || !is_numeric($response['score'])) {
            return null;
        }
        return get_string('summariseresponse', 'qtype_codeatelier',
                format_float((float) $response['score'] * 100, 1));
    }

    public function grade_response(array $response) {
        $fraction = $this->fraction_from_response($response);
        return array($fraction, question_state::graded_state_for_fraction($fraction));
    }

    /**
     * Read the client-supplied score from the response and clamp it to [0, 1].
     *
     * @param array $response the response data
     * @return float the grade fraction
     */
    protected function fraction_from_response(array $response) {
        if (!array_key_exists('score', $response) || !is_numeric($response['score'])) {
            return 0.0;
        }
        return max(0.0, min(1.0, (float) $response['score']));
    }
}
