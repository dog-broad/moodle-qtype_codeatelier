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
 * Packages a multi-file project for Judge0's "Multi-file program" language (id 89).
 *
 * A project's source files plus a generated compile + run script are zipped and
 * sent as the submission's additional_files; Judge0 unpacks them, runs compile,
 * then runs the program with the test's stdin. This is the one Judge0 primitive
 * that compiles arbitrary multi-file source sets uniformly across languages (the
 * per-language defaults only compile the single source_code file).
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class project_packager {
    /** @var string[] languages a project may be authored in (judge-tier only). */
    const LANGUAGES = ['c', 'cpp', 'java', 'python', 'javascript', 'go', 'rust', 'php',
        'typescript', 'csharp', 'kotlin', 'ruby'];

    /**
     * Whether a language can be used for a multi-file project.
     *
     * @param string $language a CodeAtelier language key
     * @return bool
     */
    public static function supports(string $language): bool {
        return in_array($language, self::LANGUAGES, true);
    }

    /**
     * Decode a canonical project file set (a student answer or a starter set) from
     * JSON into an ordered name => content map. Malformed input yields an empty set.
     *
     * @param string $json the canonical JSON array of [{name, content}, ...]
     * @return array name => content, preserving file order
     */
    public static function decode_files(string $json): array {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $files = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && isset($entry['name']) && trim((string) $entry['name']) !== '') {
                $files[(string) $entry['name']] = isset($entry['content']) ? (string) $entry['content'] : '';
            }
        }
        return $files;
    }

    /**
     * Encode a name => content map to the canonical project JSON.
     *
     * @param array $files name => content
     * @return string the canonical JSON array
     */
    public static function encode_files(array $files): string {
        $list = [];
        foreach ($files as $name => $content) {
            $list[] = ['name' => (string) $name, 'content' => (string) $content];
        }
        return json_encode($list);
    }

    /**
     * Decode the language-keyed project workspace blob
     * `{envs: {<lang>: {entry, files: [{name, starter, solution}]}}}` into a map
     * `[lang => ['entry' => string, 'files' => [name => ['starter','solution']]]]`,
     * preserving file order. Malformed input yields an empty map.
     *
     * @param string $json the projectfiles blob
     * @return array
     */
    public static function decode_envs(string $json): array {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['envs']) || !is_array($decoded['envs'])) {
            return [];
        }
        $out = [];
        foreach ($decoded['envs'] as $lang => $env) {
            if (!is_array($env)) {
                continue;
            }
            $files = [];
            foreach ((is_array($env['files'] ?? null) ? $env['files'] : []) as $f) {
                if (is_array($f) && isset($f['name']) && trim((string) $f['name']) !== '') {
                    $files[(string) $f['name']] = [
                        'starter' => isset($f['starter']) ? (string) $f['starter'] : '',
                        'solution' => isset($f['solution']) ? (string) $f['solution'] : '',
                    ];
                }
            }
            $entry = (isset($env['entry']) && isset($files[(string) $env['entry']]))
                ? (string) $env['entry'] : (string) (array_key_first($files) ?? '');
            $out[(string) $lang] = ['entry' => $entry, 'files' => $files];
        }
        return $out;
    }

    /**
     * The starter file set for one environment: name => starter content.
     *
     * @param array $env an entry from decode_envs()
     * @return array name => content
     */
    public static function starter_files(array $env): array {
        $out = [];
        foreach (($env['files'] ?? []) as $name => $layers) {
            $out[(string) $name] = (string) ($layers['starter'] ?? '');
        }
        return $out;
    }

    /**
     * The reference (solution) file set for one environment: each file's solution
     * content, falling back to its starter where the solution layer is empty.
     *
     * @param array $env an entry from decode_envs()
     * @return array name => content
     */
    public static function reference_files(array $env): array {
        $out = [];
        foreach (($env['files'] ?? []) as $name => $layers) {
            $sol = (string) ($layers['solution'] ?? '');
            $out[(string) $name] = $sol !== '' ? $sol : (string) ($layers['starter'] ?? '');
        }
        return $out;
    }

    /**
     * Build the base64 ZIP Judge0 (language 89) unpacks: the project's source files
     * plus a generated compile and run script for the language and entry file.
     *
     * @param string $language a CodeAtelier language key (must be a project language)
     * @param array $files name => content of the project source files
     * @param string $entryfile the entry/main file name
     * @param array $extrafiles optional support/data files (path => content) placed in
     *     the working directory alongside the sources; source files win on a collision
     * @return string the base64-encoded ZIP archive
     * @throws \coding_exception if the language is not a project language
     */
    public static function package(string $language, array $files, string $entryfile, array $extrafiles = []): string {
        if (!self::supports($language)) {
            throw new \coding_exception("Unsupported project language: {$language}");
        }
        // Support files first so the project sources + generated scripts override on
        // the unlikely name collision.
        $archive = $extrafiles + $files + self::scripts($language, $entryfile);

        $tmp = tempnam(sys_get_temp_dir(), 'caproj') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($archive as $name => $content) {
            $zip->addFromString((string) $name, (string) $content);
        }
        $zip->close();
        $base64 = base64_encode(file_get_contents($tmp));
        unlink($tmp);
        return $base64;
    }

    /**
     * The compile and run shell scripts for a project. Compiled languages build all
     * sources into a single executable; interpreted languages skip compilation and
     * run the entry file directly. The entry file is the run target (and, for Java,
     * names the main class).
     *
     * @param string $language a project language key
     * @param string $entryfile the entry/main file name
     * @return array ['compile' => script, 'run' => script]
     */
    protected static function scripts(string $language, string $entryfile): array {
        $entry = self::shell_arg($entryfile);
        switch ($language) {
            case 'c':
                return [
                    'compile' => "#!/bin/sh\ngcc -O2 -std=c11 " . self::find('c') . " -o a.out\n",
                    'run' => "#!/bin/sh\n./a.out\n",
                ];
            case 'cpp':
                return [
                    'compile' => "#!/bin/sh\ng++ -O2 -std=c++17 " . self::find('cpp', 'cc', 'cxx') . " -o a.out\n",
                    'run' => "#!/bin/sh\n./a.out\n",
                ];
            case 'java':
                // Compile every .java (any subdir) into out/, then run the entry's
                // fully-qualified class: the run script reads the entry's package
                // declaration (default package -> the bare class name).
                return [
                    'compile' => "#!/bin/sh\nmkdir -p out\njavac -d out " . self::find('java') . "\n",
                    'run' => "#!/bin/sh\n" . self::jvm_class_var($entryfile, '.java', false)
                        . "java -cp out \"\$CLASS\"\n",
                ];
            case 'python':
                return [
                    'compile' => "#!/bin/sh\nexit 0\n",
                    'run' => "#!/bin/sh\npython3 {$entry}\n",
                ];
            case 'javascript':
                return [
                    'compile' => "#!/bin/sh\nexit 0\n",
                    'run' => "#!/bin/sh\nnode {$entry}\n",
                ];
            case 'go':
                // Build every .go into one binary; Go needs a writable cache. A
                // genuinely multi-package layout needs a module (out of scope) —
                // flat or single-package-in-a-subdir works.
                return [
                    'compile' => "#!/bin/sh\n" . self::path_prepend('go-*')
                        . "export GOCACHE=\$(pwd)/.gocache GOPATH=\$(pwd)/.go\ngo build -o a.out "
                        . self::find('go') . "\n",
                    'run' => "#!/bin/sh\n./a.out\n",
                ];
            case 'rust':
                // The rustc compiler builds the modules the entry file declares with `mod`.
                return [
                    'compile' => "#!/bin/sh\n" . self::path_prepend('rust-*') . "rustc -O {$entry} -o a.out\n",
                    'run' => "#!/bin/sh\n./a.out\n",
                ];
            case 'php':
                return [
                    'compile' => "#!/bin/sh\nexit 0\n",
                    'run' => "#!/bin/sh\n" . self::path_prepend('php-*') . "php {$entry}\n",
                ];
            case 'typescript':
                // Compile every .ts to CommonJS, then run the entry's emitted .js
                // (same path, .js extension).
                $entryjs = self::shell_arg(preg_replace('/\.ts$/', '', $entryfile) . '.js');
                return [
                    'compile' => "#!/bin/sh\ntsc --module commonjs --target es2019 " . self::find('ts') . "\n",
                    'run' => "#!/bin/sh\nnode {$entryjs}\n",
                ];
            case 'csharp':
                // Mono: compile all .cs (any subdir) into one exe and run it.
                return [
                    'compile' => "#!/bin/sh\n" . self::path_prepend('mono-*')
                        . "mcs -out:a.exe " . self::find('cs') . "\n",
                    'run' => "#!/bin/sh\n" . self::path_prepend('mono-*') . "mono a.exe\n",
                ];
            case 'kotlin':
                // Compile every .kt into a jar, then run the entry's JVM class: its
                // package (read at run time) plus the basename (first letter upper)
                // plus "Kt" — Main.kt -> MainKt, app/Main.kt (package app) -> app.MainKt.
                return [
                    'compile' => "#!/bin/sh\n" . self::path_prepend('kotlin-*')
                        . "kotlinc " . self::find('kt') . " -include-runtime -d a.jar\n",
                    'run' => "#!/bin/sh\n" . self::jvm_class_var($entryfile, '.kt', true)
                        . "java -cp a.jar \"\$CLASS\"\n",
                ];
            case 'ruby':
                return [
                    'compile' => "#!/bin/sh\nexit 0\n",
                    'run' => "#!/bin/sh\n" . self::path_prepend('ruby-*') . "ruby {$entry}\n",
                ];
            default:
                throw new \coding_exception("Unsupported project language: {$language}");
        }
    }

    /**
     * A `find` expression listing every source file of the given extension(s) in any
     * subdirectory, so a nested project (e.g. src/Main.java) compiles. Unquoted so
     * the shell word-splits the result into separate compiler arguments.
     *
     * @param string ...$exts one or more file extensions (without the dot)
     * @return string the shell `$(find ...)` expression
     */
    protected static function find(string ...$exts): string {
        $names = [];
        foreach ($exts as $ext) {
            $names[] = "-name '*." . $ext . "'";
        }
        $expr = count($names) > 1 ? '\( ' . implode(' -o ', $names) . ' \)' : $names[0];
        return "\$(find . -type f {$expr})";
    }

    /**
     * A shell snippet assigning $CLASS to the JVM class to run for a JVM-language
     * entry file: its package declaration (read from the file at run time, default
     * package if none) plus the entry basename. Kotlin upper-cases the basename's
     * first letter and appends "Kt".
     *
     * @param string $entryfile the entry file path
     * @param string $ext the source extension to strip ('.java' or '.kt')
     * @param bool $kotlin whether to apply Kotlin's file-class naming
     * @return string the shell snippet (ends with a newline)
     */
    protected static function jvm_class_var(string $entryfile, string $ext, bool $kotlin): string {
        $base = preg_replace('/' . preg_quote($ext, '/') . '$/', '', basename($entryfile));
        $classname = $kotlin ? ucfirst($base) . 'Kt' : $base;
        $entry = self::shell_arg($entryfile);
        $class = self::shell_arg($classname);
        // Read a leading `package x.y;` (Java) / `package x.y` (Kotlin) from the entry.
        return "PKG=\$(grep -m1 -E '^[[:space:]]*package[[:space:]]' {$entry} 2>/dev/null"
            . " | sed -e 's/.*package[[:space:]]*//' -e 's/[[:space:]]*;.*//' -e 's/[[:space:]]//g');\n"
            . "CLASS={$class}; [ -n \"\$PKG\" ] && CLASS=\"\$PKG.{$classname}\";\n";
    }

    /**
     * A shell snippet that prepends the first matching /usr/local/<glob>/bin to
     * PATH, so a tool installed under Judge0's versioned layout is found without
     * hardcoding a version. A no-op when nothing matches (the tool is already on
     * PATH).
     *
     * @param string $glob a directory glob under /usr/local, e.g. 'go-*'
     * @return string the shell snippet (ends with a newline)
     */
    protected static function path_prepend(string $glob): string {
        return "D=\$(ls -d /usr/local/{$glob}/bin 2>/dev/null | head -1); [ -n \"\$D\" ] && PATH=\"\$D:\$PATH\";\n";
    }

    /**
     * Single-quote a file name for safe use in a generated shell script.
     *
     * @param string $value a file name
     * @return string the single-quoted argument
     */
    protected static function shell_arg(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
