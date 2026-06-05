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
 * Builds the judge-tier harness for the FUNCTION scenario.
 *
 * The student writes a function; to grade it on the server sandbox we wrap their
 * code in a complete program that reads the test's arguments as a JSON array on
 * stdin, calls the named entrypoint with those arguments, and prints the return
 * value as JSON on stdout. The shared grader's `structured` mode then compares
 * that JSON against the expected value — exactly the contract the in-browser
 * worker uses, so a function question grades identically on both tiers.
 *
 * Only the dynamic languages are supported here: JSON maps cleanly onto their
 * native values with no type information. Static languages need a declared
 * signature to (de)serialize and are added separately.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class function_harness {

    /** @var string[] the languages whose function scenario the judge tier supports. */
    const LANGUAGES = ['python', 'javascript', 'ruby', 'php'];

    /**
     * Whether the judge tier can grade a function-scenario question in this language.
     *
     * @param string $language a CodeAtelier language key
     * @return bool
     */
    public static function supports(string $language): bool {
        return in_array($language, self::LANGUAGES, true);
    }

    /**
     * Build a complete sandbox program that calls the student's function on
     * stdin-supplied arguments and prints the JSON-encoded return value.
     *
     * @param string $language a supported dynamic language key
     * @param string $code the student's source (defining the entrypoint)
     * @param string $entrypoint the function name to call
     * @return string the full program submitted to the sandbox
     */
    public static function source(string $language, string $code, string $entrypoint): string {
        switch ($language) {
            case 'python':
                return $code . "\n\n"
                    . "import sys as _ca_sys, json as _ca_json\n"
                    . "_ca_args = _ca_json.loads(_ca_sys.stdin.read() or \"[]\")\n"
                    . "if not isinstance(_ca_args, list):\n    _ca_args = [_ca_args]\n"
                    . "print(_ca_json.dumps({$entrypoint}(*_ca_args)))\n";

            case 'javascript':
                return $code . "\n\n"
                    . "const _ca_fs = require('fs');\n"
                    . "let _ca_args = JSON.parse(_ca_fs.readFileSync(0, 'utf8') || '[]');\n"
                    . "if (!Array.isArray(_ca_args)) { _ca_args = [_ca_args]; }\n"
                    . "console.log(JSON.stringify({$entrypoint}(..._ca_args)));\n";

            case 'ruby':
                return $code . "\n\n"
                    . "require 'json'\n"
                    . "_ca_input = STDIN.read\n"
                    . "_ca_args = JSON.parse(_ca_input.empty? ? '[]' : _ca_input)\n"
                    . "_ca_args = [_ca_args] unless _ca_args.is_a?(Array)\n"
                    . "puts JSON.generate(send(:{$entrypoint}, *_ca_args))\n";

            case 'php':
                // Judge0 runs a .php file, so the program must open with <?php. Strip
                // a leading tag the student may have typed, then add exactly one.
                $body = preg_replace('/^\s*<\?php\s*/', '', $code);
                return "<?php\n" . $body . "\n\n"
                    . "\$_ca_in = stream_get_contents(STDIN);\n"
                    . "\$_ca_args = json_decode(\$_ca_in === '' ? '[]' : \$_ca_in, true);\n"
                    . "if (!is_array(\$_ca_args)) { \$_ca_args = [\$_ca_args]; }\n"
                    . "echo json_encode(call_user_func_array('{$entrypoint}', \$_ca_args));\n";

            default:
                throw new \coding_exception("Unsupported judge-tier function language: {$language}");
        }
    }
}
