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

    /** @var string[] dynamic languages: JSON maps onto native values, no signature needed. */
    const DYNAMIC = ['python', 'javascript', 'ruby', 'php', 'typescript'];

    /** @var string[] static languages: a typed signature drives generated glue. */
    const STATIC = ['java', 'cpp', 'go', 'rust', 'csharp', 'kotlin', 'c'];

    /** @var string[] static languages that grade scalar-only signatures (no list/map). */
    const SCALAR_ONLY = ['c'];

    /** @var string[] every language whose function scenario the judge tier supports. */
    const LANGUAGES = ['python', 'javascript', 'ruby', 'php', 'typescript',
        'java', 'cpp', 'go', 'rust', 'csharp', 'kotlin', 'c'];

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
     * Whether a function question in this language needs a declared signature
     * (the static languages do; the dynamic ones do not).
     *
     * @param string $language a CodeAtelier language key
     * @return bool
     */
    public static function needs_signature(string $language): bool {
        return in_array($language, self::STATIC, true);
    }

    /**
     * Build a complete sandbox program that calls the student's function on
     * stdin-supplied arguments and prints the JSON-encoded return value.
     *
     * @param string $language a supported language key
     * @param string $code the student's source (defining the entrypoint)
     * @param string $entrypoint the function name to call
     * @param signature|null $sig the typed signature (required for static languages)
     * @return string the full program submitted to the sandbox
     */
    public static function source(string $language, string $code, string $entrypoint, ?signature $sig = null): string {
        if (in_array($language, self::STATIC, true)) {
            if ($sig === null) {
                throw new \coding_exception("static-language function grading needs a signature: {$language}");
            }
            return self::static_source($language, $code, $entrypoint, $sig);
        }
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

            case 'typescript':
                // tsc compiles to Node; `declare` avoids needing @types/node, and the
                // `as any` cast bypasses arg-type checking so any signature calls cleanly.
                return $code . "\n\n"
                    . "declare const require: any;\n"
                    . "const _ca_fs = require('fs');\n"
                    . "let _ca_args: any = JSON.parse(_ca_fs.readFileSync(0, 'utf8') || '[]');\n"
                    . "if (!Array.isArray(_ca_args)) { _ca_args = [_ca_args]; }\n"
                    . "console.log(JSON.stringify(({$entrypoint} as any)(..._ca_args)));\n";

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

    /**
     * Build a static-language harness: read each typed argument from the line
     * protocol, call the entrypoint, and print the typed return in the protocol
     * the PHP side decodes. The student writes only the function (as the language's
     * starter shows); we wrap it with the generated I/O.
     *
     * @param string $language a static language key
     * @param string $code the student's function
     * @param string $entrypoint the function name
     * @param signature $sig the typed signature
     * @return string the full program
     */
    private static function static_source(string $language, string $code, string $entrypoint, signature $sig): string {
        $d = self::static_dialect($language);
        if ($d === null) {
            throw new \coding_exception("Unsupported static function language: {$language}");
        }
        $reads = [];
        $args = [];
        foreach ($sig->params() as $i => $type) {
            $reads[] = call_user_func($d['read'], $type, $i);
            $args[] = "p{$i}";
        }
        $call = call_user_func($d['call'], $entrypoint, implode(', ', $args), $sig);
        $print = call_user_func($d['print'], $sig->return_type());
        // Whitespace is insignificant in these languages, so a uniform 4-space body
        // indent is fine; the per-language read/print methods produce the real logic.
        $body = implode("\n    ", array_merge($reads, [$call, $print]));
        return $d['preamble'] . "\n\n" . $code . "\n\n"
            . $d['open'] . "\n    " . $body . "\n" . $d['close'] . "\n";
    }

    /**
     * The per-language code-generation "dialect": the program scaffold and the
     * read/call/print pieces. One builder ({@see static_source()}) assembles them,
     * so a new type (or language) is a few lines of data, not another generator.
     *
     * Each dialect: preamble (everything before the student code — includes, the
     * `_l()` line reader, and the class header for class-based languages), open (the
     * main entry's opening), close (closing the main and any class), call (builds
     * `_r = entrypoint(args)` in the language's idiom), and the read/print callables.
     *
     * @param string $language a static language key
     * @return array|null the dialect, or null if unsupported
     */
    private static function static_dialect(string $language): ?array {
        $dialects = [
            'cpp' => [
                'preamble' => "#include <bits/stdc++.h>\nusing namespace std;\n"
                    . "static string _l(){ string s; getline(cin, s); return s; }",
                'open' => "int main(){",
                'close' => "    return 0;\n}",
                'call' => fn($e, $a, $s) => "auto _r = {$e}({$a});",
                'read' => [self::class, 'cpp_read'], 'print' => [self::class, 'cpp_print'],
            ],
            'c' => [
                'preamble' => "#include <stdio.h>\n#include <stdlib.h>\n#include <string.h>\n"
                    . "static char _buf[1<<20];\n"
                    . "static char* _l(){ if(fgets(_buf,sizeof(_buf),stdin)){ size_t n=strlen(_buf);"
                    . " while(n>0 && (_buf[n-1]=='\\n'||_buf[n-1]=='\\r')) _buf[--n]=0; } else { _buf[0]=0; } return _buf; }",
                'open' => "int main(void){",
                'close' => "    return 0;\n}",
                'call' => fn($e, $a, $s) => self::c_type($s->return_type()) . " _r = {$e}({$a});",
                'read' => [self::class, 'c_read'], 'print' => [self::class, 'c_print'],
            ],
            'java' => [
                'preamble' => "import java.util.*;\nimport java.io.*;\npublic class Main {\n"
                    . "static BufferedReader _br = new BufferedReader(new InputStreamReader(System.in));\n"
                    . "static String _l() throws IOException { return _br.readLine(); }",
                'open' => "public static void main(String[] _argv) throws Exception {",
                'close' => "}\n}",
                'call' => fn($e, $a, $s) => self::java_type($s->return_type()) . " _r = {$e}({$a});",
                'read' => [self::class, 'java_read'], 'print' => [self::class, 'java_print'],
            ],
            'go' => [
                'preamble' => "package main\nimport (\"bufio\"; \"fmt\"; \"os\"; \"strconv\"; \"strings\")\n"
                    . "var _rd = bufio.NewReader(os.Stdin)\n"
                    . "func _l() string { s, _ := _rd.ReadString('\\n'); return strings.TrimRight(s, \"\\r\\n\") }\n"
                    // Force-use the imports so an all-string harness still compiles.
                    . "var _ = strconv.Atoi\nvar _ = strings.TrimSpace\nvar _ = fmt.Print",
                'open' => "func main(){",
                'close' => "}",
                'call' => fn($e, $a, $s) => "_r := {$e}({$a})",
                'read' => [self::class, 'go_read'], 'print' => [self::class, 'go_print'],
            ],
            'rust' => [
                'preamble' => "use std::io::Read;",
                'open' => "fn main(){\n    let mut _input = String::new();\n"
                    . "    std::io::stdin().read_to_string(&mut _input).unwrap();\n"
                    . "    let mut _it = _input.lines();",
                'close' => "}",
                'call' => fn($e, $a, $s) => "let _r = {$e}({$a});",
                'read' => [self::class, 'rust_read'], 'print' => [self::class, 'rust_print'],
            ],
            'csharp' => [
                'preamble' => "using System;\nusing System.Globalization;\nclass Program {\n"
                    . "static string _l(){ return Console.ReadLine(); }",
                'open' => "static void Main(){",
                'close' => "}\n}",
                'call' => fn($e, $a, $s) => "var _r = {$e}({$a});",
                'read' => [self::class, 'csharp_read'], 'print' => [self::class, 'csharp_print'],
            ],
            'kotlin' => [
                'preamble' => "import java.io.BufferedReader\nimport java.io.InputStreamReader\n"
                    . "val _br = BufferedReader(InputStreamReader(System.`in`))\nfun _l(): String = _br.readLine()",
                'open' => "fun main() {",
                'close' => "}",
                'call' => fn($e, $a, $s) => "val _r = {$e}({$a})",
                'read' => [self::class, 'kotlin_read'], 'print' => [self::class, 'kotlin_print'],
            ],
        ];
        return $dialects[$language] ?? null;
    }

    /**
     * C harness. C handles arrays awkwardly, so the function scenario is scalar-only
     * (validated in the form); the line protocol still applies, one scalar per line.
     *
     * @param string $code the student's function
     * @param string $entrypoint the function name
     * @param signature $sig the typed signature (scalar params and return)
     * @return string the full program
     */
    private static function c_type(string $scalar): string {
        return ['int' => 'int', 'long' => 'long long', 'double' => 'double',
            'bool' => 'int', 'string' => 'char*'][$scalar];
    }

    private static function c_read(string $type, int $i): string {
        self::require_scalar($type, 'c');
        $read = ['int' => 'atoi(_l())', 'long' => 'atoll(_l())', 'double' => 'atof(_l())',
            'bool' => '(atoi(_l()) != 0)', 'string' => 'strdup(_l())'];
        return self::c_type($type) . " p{$i} = {$read[$type]};";
    }

    private static function c_print(string $type): string {
        self::require_scalar($type, 'c');
        return ['int' => 'printf("%d", _r);', 'long' => 'printf("%lld", _r);',
            'double' => 'printf("%.10g", _r);', 'bool' => 'printf("%d", _r ? 1 : 0);',
            'string' => 'printf("%s", _r);'][$type];
    }

    /**
     * Assert a type is a bare scalar (for the scalar-only static languages), or throw.
     *
     * @param string $type a validated signature type
     * @param string $language the language key (for the message)
     * @return string the type, when scalar
     * @throws \coding_exception when the type is a list or map
     */
    private static function require_scalar(string $type, string $language): string {
        if (!signature::is_scalar($type)) {
            throw new \coding_exception("{$language} function grading supports scalar types only: {$type}");
        }
        return $type;
    }

    /**
     * The Java type for a signature type.
     *
     * @param string $type a signature type
     * @return string
     */
    private static function java_type(string $type): string {
        $element = signature::list_element($type);
        if ($element !== null) {
            return self::java_scalar($element) . '[]';
        }
        return self::java_scalar($type);
    }

    /**
     * The Java scalar type.
     *
     * @param string $scalar a scalar type
     * @return string
     */
    private static function java_scalar(string $scalar): string {
        return ['int' => 'int', 'long' => 'long', 'double' => 'double',
            'bool' => 'boolean', 'string' => 'String'][$scalar];
    }

    /**
     * A Java expression that parses one scalar from the string $expr.
     *
     * @param string $scalar the scalar type
     * @param string $expr the source string expression (a line)
     * @return string
     */
    private static function java_parse(string $scalar, string $expr): string {
        switch ($scalar) {
            case 'int':
                return "Integer.parseInt(({$expr}).trim())";
            case 'long':
                return "Long.parseLong(({$expr}).trim())";
            case 'double':
                return "Double.parseDouble(({$expr}).trim())";
            case 'bool':
                return "({$expr}).trim().equals(\"1\")";
            default: // string
                return "({$expr})";
        }
    }

    /**
     * Java statement(s) reading parameter $i of the given type.
     *
     * @param string $type the parameter type
     * @param int $i the parameter index
     * @return string
     */
    private static function java_read(string $type, int $i): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return self::java_scalar($type) . " p{$i} = " . self::java_parse($type, '_l()') . ';';
        }
        $et = self::java_scalar($element);
        return "int _n{$i} = Integer.parseInt(_l().trim()); {$et}[] p{$i} = new {$et}[_n{$i}]; "
            . "for (int _k = 0; _k < _n{$i}; _k++) p{$i}[_k] = " . self::java_parse($element, '_l()') . ';';
    }

    /**
     * Java statement(s) printing the return value `_r` in the decode protocol.
     *
     * @param string $type the return type
     * @return string
     */
    private static function java_print(string $type): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return $type === 'bool'
                ? 'System.out.print(_r ? 1 : 0);'
                : 'System.out.print(_r);';
        }
        $elem = $element === 'bool' ? '(_r[_k] ? 1 : 0)' : '_r[_k]';
        return "System.out.print(_r.length); "
            . "for (int _k = 0; _k < _r.length; _k++) { System.out.print(\"\\n\"); System.out.print({$elem}); }";
    }

    // ---- C++ ---------------------------------------------------------------

    private static function cpp_scalar(string $scalar): string {
        return ['int' => 'int', 'long' => 'long long', 'double' => 'double',
            'bool' => 'bool', 'string' => 'string'][$scalar];
    }

    private static function cpp_parse(string $scalar, string $expr): string {
        switch ($scalar) {
            case 'int':
                return "stoi({$expr})";
            case 'long':
                return "stoll({$expr})";
            case 'double':
                return "stod({$expr})";
            case 'bool':
                return "(stoi({$expr}) != 0)";
            default: // string
                return "({$expr})";
        }
    }

    private static function cpp_read(string $type, int $i): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return self::cpp_scalar($type) . " p{$i} = " . self::cpp_parse($type, '_l()') . ';';
        }
        $et = self::cpp_scalar($element);
        return "int _n{$i} = stoi(_l()); vector<{$et}> p{$i}(_n{$i}); "
            . "for (int _k = 0; _k < _n{$i}; _k++) p{$i}[_k] = " . self::cpp_parse($element, '_l()') . ';';
    }

    private static function cpp_print(string $type): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return $type === 'bool' ? 'cout << (_r ? 1 : 0);' : 'cout << _r;';
        }
        $elem = $element === 'bool' ? '(_r[_k] ? 1 : 0)' : '_r[_k]';
        return "cout << _r.size(); "
            . "for (size_t _k = 0; _k < _r.size(); _k++) { cout << \"\\n\" << {$elem}; }";
    }

    // ---- Go ----------------------------------------------------------------

    private static function go_scalar(string $scalar): string {
        return ['int' => 'int', 'long' => 'int64', 'double' => 'float64',
            'bool' => 'bool', 'string' => 'string'][$scalar];
    }

    private static function go_read(string $type, int $i): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return self::go_read_scalar($type, "p{$i}");
        }
        $et = self::go_scalar($element);
        return "_n{$i}, _ := strconv.Atoi(strings.TrimSpace(_l())); p{$i} := make([]{$et}, _n{$i}); "
            . "for _k := 0; _k < _n{$i}; _k++ { " . self::go_read_scalar($element, "p{$i}[_k]", true) . " }";
    }

    /**
     * A Go statement reading one scalar into $name. With $indexed the target is an
     * existing slice element (assigned with =), otherwise a new variable (:=).
     */
    private static function go_read_scalar(string $scalar, string $name, bool $indexed = false): string {
        $asgn = $indexed ? '=' : ':=';
        switch ($scalar) {
            case 'int':
                return "{$name}, _ {$asgn} strconv.Atoi(strings.TrimSpace(_l()));";
            case 'long':
                return "{$name}, _ {$asgn} strconv.ParseInt(strings.TrimSpace(_l()), 10, 64);";
            case 'double':
                return "{$name}, _ {$asgn} strconv.ParseFloat(strings.TrimSpace(_l()), 64);";
            case 'bool':
                return "{$name} {$asgn} strings.TrimSpace(_l()) == \"1\";";
            default: // string
                return "{$name} {$asgn} _l();";
        }
    }

    private static function go_print(string $type): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return $type === 'bool' ? 'if _r { fmt.Print(1) } else { fmt.Print(0) }' : 'fmt.Print(_r);';
        }
        $body = $element === 'bool'
            ? 'if _v { fmt.Print(1) } else { fmt.Print(0) }'
            : 'fmt.Print(_v);';
        return "fmt.Print(len(_r)); for _, _v := range _r { fmt.Print(\"\\n\"); {$body} }";
    }

    // ---- Rust --------------------------------------------------------------

    private static function rust_scalar(string $scalar): string {
        return ['int' => 'i64', 'long' => 'i64', 'double' => 'f64',
            'bool' => 'bool', 'string' => 'String'][$scalar];
    }

    private static function rust_read(string $type, int $i): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return self::rust_read_scalar($type, "p{$i}");
        }
        $et = self::rust_scalar($element);
        $push = self::rust_value($element);
        return "let _n{$i}: usize = _it.next().unwrap().trim().parse().unwrap(); "
            . "let mut p{$i}: Vec<{$et}> = Vec::new(); "
            . "for _ in 0.._n{$i} { p{$i}.push({$push}); }";
    }

    private static function rust_read_scalar(string $scalar, string $name): string {
        return "let {$name}: " . self::rust_scalar($scalar) . " = " . self::rust_value($scalar) . ';';
    }

    /** A Rust expression reading and converting the next line to the scalar. */
    private static function rust_value(string $scalar): string {
        switch ($scalar) {
            case 'bool':
                return '(_it.next().unwrap().trim() == "1")';
            case 'string':
                return '_it.next().unwrap().to_string()';
            default: // i64 / f64
                return '_it.next().unwrap().trim().parse().unwrap()';
        }
    }

    private static function rust_print(string $type): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return $type === 'bool'
                ? 'print!("{}", if _r {1} else {0});'
                : 'print!("{}", _r);';
        }
        $elem = $element === 'bool' ? 'if *_e {1} else {0}' : '_e';
        return "print!(\"{}\", _r.len()); for _e in &_r { print!(\"\\n{}\", {$elem}); }";
    }

    // ---- C# ----------------------------------------------------------------

    private static function csharp_scalar(string $scalar): string {
        return ['int' => 'int', 'long' => 'long', 'double' => 'double',
            'bool' => 'bool', 'string' => 'string'][$scalar];
    }

    private static function csharp_parse(string $scalar, string $expr): string {
        switch ($scalar) {
            case 'int':
                return "int.Parse(({$expr}).Trim())";
            case 'long':
                return "long.Parse(({$expr}).Trim())";
            case 'double':
                return "double.Parse(({$expr}).Trim(), CultureInfo.InvariantCulture)";
            case 'bool':
                return "(({$expr}).Trim() == \"1\")";
            default: // string
                return "({$expr})";
        }
    }

    private static function csharp_read(string $type, int $i): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return self::csharp_scalar($type) . " p{$i} = " . self::csharp_parse($type, '_l()') . ';';
        }
        $et = self::csharp_scalar($element);
        return "int _n{$i} = int.Parse(_l().Trim()); {$et}[] p{$i} = new {$et}[_n{$i}]; "
            . "for (int _k = 0; _k < _n{$i}; _k++) p{$i}[_k] = " . self::csharp_parse($element, '_l()') . ';';
    }

    private static function csharp_print(string $type): string {
        $element = signature::list_element($type);
        $fmt = function ($expr, $scalar) {
            if ($scalar === 'bool') {
                return "({$expr} ? 1 : 0)";
            }
            if ($scalar === 'double') {
                return "((double){$expr}).ToString(CultureInfo.InvariantCulture)";
            }
            return $expr;
        };
        if ($element === null) {
            return 'Console.Write(' . $fmt('_r', $type) . ');';
        }
        return "Console.Write(_r.Length); "
            . "for (int _k = 0; _k < _r.Length; _k++) { Console.Write(\"\\n\"); Console.Write("
            . $fmt('_r[_k]', $element) . "); }";
    }

    // ---- Kotlin ------------------------------------------------------------

    private static function kotlin_arraytype(string $scalar): string {
        return ['int' => 'IntArray', 'long' => 'LongArray', 'double' => 'DoubleArray',
            'bool' => 'BooleanArray', 'string' => 'Array<String>'][$scalar];
    }

    private static function kotlin_value(string $scalar): string {
        switch ($scalar) {
            case 'int':
                return '_l().trim().toInt()';
            case 'long':
                return '_l().trim().toLong()';
            case 'double':
                return '_l().trim().toDouble()';
            case 'bool':
                return '(_l().trim() == "1")';
            default: // string
                return '_l()';
        }
    }

    private static function kotlin_read(string $type, int $i): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return "val p{$i} = " . self::kotlin_value($type);
        }
        $arr = self::kotlin_arraytype($element);
        // IntArray(n){...} / Array(n){...} run the initialiser in index order.
        $ctor = $element === 'string' ? 'Array' : $arr;
        return "val _n{$i} = _l().trim().toInt(); val p{$i} = {$ctor}(_n{$i}) { " . self::kotlin_value($element) . ' }';
    }

    private static function kotlin_print(string $type): string {
        $element = signature::list_element($type);
        if ($element === null) {
            return $type === 'bool' ? 'print(if (_r) 1 else 0)' : 'print(_r)';
        }
        $elem = $element === 'bool' ? 'print(if (_e) 1 else 0)' : 'print(_e)';
        return "print(_r.size); for (_e in _r) { print(\"\\n\"); {$elem} }";
    }
}
