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

/**
 * Tests for the judge-tier function harness builder.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\judge\function_harness
 */
final class function_harness_test extends \advanced_testcase {
    public function test_supports(): void {
        // Dynamic and static function languages are both supported (C is scalar-only).
        foreach (
            ['python', 'javascript', 'ruby', 'php', 'typescript',
                'java', 'cpp', 'go', 'rust', 'csharp', 'kotlin', 'c'] as $lang
        ) {
            $this->assertTrue(function_harness::supports($lang), $lang);
        }
        // SQL has no function harness.
        $this->assertFalse(function_harness::supports('sql'));
    }

    public function test_c_harness_scalar(): void {
        $sig = \qtype_codeatelier\judge\signature::parse('int, string -> int');
        $src = function_harness::source('c', "int f(int a, char* s){ return a; }", 'f', $sig);
        $this->assertStringContainsString('int p0 = atoi(_l());', $src);
        $this->assertStringContainsString('char* p1 = strdup(_l());', $src);
        $this->assertStringContainsString('f(p0, p1)', $src);
        $this->assertStringContainsString('printf("%d", _r);', $src);
    }

    public function test_c_harness_rejects_non_scalar(): void {
        $sig = \qtype_codeatelier\judge\signature::parse('list<int> -> int');
        $this->expectException(\coding_exception::class);
        function_harness::source('c', "int f(){ return 0; }", 'f', $sig);
    }

    public function test_static_harness_map(): void {
        $sig = \qtype_codeatelier\judge\signature::parse('map<string,int> -> map<string,int>');
        $cpp = function_harness::source('cpp', "map<string,int> f(map<string,int> m){ return m; }", 'f', $sig);
        $this->assertStringContainsString('map<string,int> p0', $cpp);
        $this->assertStringContainsString('p0[_key]', $cpp);
        $java = function_harness::source('java', "static java.util.Map<String,Integer> f(java.util.Map<String,Integer> m){ return m; }", 'f', $sig);
        $this->assertStringContainsString('java.util.Map<String,Integer>', $java);
        $this->assertStringContainsString('entrySet()', $java);
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

    public function test_typescript_harness(): void {
        $src = function_harness::source('typescript', 'function f(x: number){return x;}', 'f');
        $this->assertStringContainsString('declare const require', $src);
        $this->assertStringContainsString('(f as any)(..._ca_args)', $src);
    }

    public function test_needs_signature(): void {
        foreach (['java', 'cpp', 'go', 'rust', 'csharp', 'kotlin'] as $lang) {
            $this->assertTrue(function_harness::needs_signature($lang), $lang);
        }
        foreach (['python', 'javascript', 'ruby', 'php', 'typescript'] as $lang) {
            $this->assertFalse(function_harness::needs_signature($lang), $lang);
        }
    }

    public function test_static_language_without_signature_throws(): void {
        $this->expectException(\coding_exception::class);
        function_harness::source('java', 'static int f(int x){return x;}', 'f', null);
    }

    public function test_java_static_harness_reads_calls_prints(): void {
        $sig = signature::parse('int,int->int');
        $src = function_harness::source('java', 'static int add(int a, int b){return a+b;}', 'add', $sig);
        $this->assertStringContainsString('public class Main', $src);
        $this->assertStringContainsString('Integer.parseInt', $src);
        $this->assertStringContainsString('add(p0, p1)', $src);
        $this->assertStringContainsString('System.out.print(_r)', $src);
    }

    public function test_cpp_list_harness(): void {
        $sig = signature::parse('list<int>->list<int>');
        $src = function_harness::source('cpp', 'vector<int> rev(vector<int> xs){return xs;}', 'rev', $sig);
        $this->assertStringContainsString('vector<int> p0', $src);
        $this->assertStringContainsString('rev(p0)', $src);
        $this->assertStringContainsString('_r.size()', $src);
    }

    public function test_unsupported_language_throws(): void {
        $this->expectException(\coding_exception::class);
        function_harness::source('haskell', 'code', 'f');
    }
}
