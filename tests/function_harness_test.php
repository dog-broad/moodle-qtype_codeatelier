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
 * Tests for the judge-tier function harness builder.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\judge\function_harness
 */
final class function_harness_test extends \advanced_testcase {

    public function test_supports_dynamic_languages_only(): void {
        $this->assertTrue(function_harness::supports('python'));
        $this->assertTrue(function_harness::supports('javascript'));
        $this->assertTrue(function_harness::supports('ruby'));
        $this->assertTrue(function_harness::supports('php'));
        // Static languages need a signature; not yet supported.
        $this->assertFalse(function_harness::supports('java'));
        $this->assertFalse(function_harness::supports('cpp'));
        $this->assertFalse(function_harness::supports('sql'));
    }

    public function test_python_harness_reads_args_and_prints_return(): void {
        $src = function_harness::source('python', "def add(a, b):\n    return a + b", 'add');
        $this->assertStringContainsString("def add(a, b)", $src);
        $this->assertStringContainsString('_ca_json.loads', $src);
        $this->assertStringContainsString('add(*_ca_args)', $src);
        $this->assertStringContainsString('_ca_json.dumps', $src);
    }

    public function test_javascript_harness(): void {
        $src = function_harness::source('javascript', "function f(x){return x;}", 'f');
        $this->assertStringContainsString('JSON.parse', $src);
        $this->assertStringContainsString('f(..._ca_args)', $src);
        $this->assertStringContainsString('JSON.stringify', $src);
    }

    public function test_ruby_harness_calls_via_send(): void {
        $src = function_harness::source('ruby', "def f(x)\n  x\nend", 'f');
        $this->assertStringContainsString('JSON.parse', $src);
        $this->assertStringContainsString('send(:f, *_ca_args)', $src);
        $this->assertStringContainsString('JSON.generate', $src);
    }

    public function test_php_harness_has_exactly_one_open_tag(): void {
        // A student tag must be stripped, leaving exactly one opening tag.
        $src = function_harness::source('php', "<?php\nfunction f(\$x){return \$x;}", 'f');
        $this->assertSame(1, substr_count($src, '<?php'));
        $this->assertStringContainsString("call_user_func_array('f'", $src);
        $this->assertStringContainsString('json_encode', $src);
    }

    public function test_unsupported_language_throws(): void {
        $this->expectException(\coding_exception::class);
        function_harness::source('java', 'code', 'f');
    }
}
