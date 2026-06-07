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

namespace qtype_codeatelier;

use qtype_codeatelier\judge\project_packager;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the multi-file project packager (Judge0 language 89).
 *
 * Pure logic: decode/encode of the canonical file set, and the ZIP/script build.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\judge\project_packager
 */
final class project_packager_test extends \advanced_testcase {

    public function test_decode_files_preserves_order_and_content(): void {
        $json = json_encode(array(
            array('name' => 'main.cpp', 'content' => "int main(){}\n"),
            array('name' => 'helper.h', 'content' => "int add(int,int);\n"),
        ));
        $files = project_packager::decode_files($json);
        $this->assertSame(array('main.cpp', 'helper.h'), array_keys($files));
        $this->assertSame("int add(int,int);\n", $files['helper.h']);
    }

    public function test_decode_files_tolerates_malformed_input(): void {
        $this->assertSame(array(), project_packager::decode_files(''));
        $this->assertSame(array(), project_packager::decode_files('not json'));
        $this->assertSame(array(), project_packager::decode_files('{"name":"x"}'));
        // A nameless entry is dropped; a content-less entry becomes empty string.
        $files = project_packager::decode_files(json_encode(array(
            array('content' => 'orphan'),
            array('name' => 'a.py'),
        )));
        $this->assertSame(array('a.py' => ''), $files);
    }

    public function test_encode_decode_round_trip(): void {
        $files = array('Main.java' => "class Main{}\n", 'Util.java' => "class Util{}\n");
        $this->assertSame($files, project_packager::decode_files(project_packager::encode_files($files)));
    }

    public function test_supports_only_project_languages(): void {
        $this->assertTrue(project_packager::supports('cpp'));
        $this->assertTrue(project_packager::supports('java'));
        $this->assertFalse(project_packager::supports('sql'));
        $this->assertFalse(project_packager::supports('kotlin'));
    }

    public function test_package_zip_contains_sources_and_scripts(): void {
        $files = array(
            'main.cpp' => "#include \"h.h\"\nint main(){}\n",
            'h.h' => "int add(int,int);\n",
        );
        $base64 = project_packager::package('cpp', $files, 'main.cpp');
        $entries = $this->zip_entries($base64);

        $this->assertArrayHasKey('main.cpp', $entries);
        $this->assertArrayHasKey('h.h', $entries);
        $this->assertArrayHasKey('compile', $entries);
        $this->assertArrayHasKey('run', $entries);
        $this->assertSame("#include \"h.h\"\nint main(){}\n", $entries['main.cpp']);
        // C++ compiles every source into one executable, then runs it.
        $this->assertStringContainsString('g++', $entries['compile']);
        $this->assertStringContainsString('*.cpp', $entries['compile']);
        $this->assertStringContainsString('./a.out', $entries['run']);
    }

    public function test_package_java_run_names_the_main_class(): void {
        $entries = $this->zip_entries(project_packager::package('java',
                array('Main.java' => "public class Main{}\n", 'Helper.java' => "class Helper{}\n"),
                'Main.java'));
        $this->assertStringContainsString('javac', $entries['compile']);
        // The run command launches the entry file's class, not the .java file name.
        $this->assertStringContainsString("java 'Main'", $entries['run']);
        $this->assertStringNotContainsString('Main.java', $entries['run']);
    }

    public function test_package_python_runs_the_entry_file(): void {
        $entries = $this->zip_entries(project_packager::package('python',
                array('app.py' => "print(1)\n", 'lib.py' => "x=1\n"), 'app.py'));
        $this->assertStringContainsString('exit 0', $entries['compile']);
        $this->assertStringContainsString("python3 'app.py'", $entries['run']);
    }

    public function test_package_rejects_unsupported_language(): void {
        $this->expectException(\coding_exception::class);
        project_packager::package('kotlin', array('a.kt' => 'fun main(){}'), 'a.kt');
    }

    /**
     * Decode a base64 ZIP into a name => content map for assertions.
     *
     * @param string $base64 the base64-encoded ZIP
     * @return array name => content
     */
    private function zip_entries(string $base64): array {
        $tmp = tempnam(sys_get_temp_dir(), 'catest') . '.zip';
        file_put_contents($tmp, base64_decode($base64));
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $entries = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tmp);
        return $entries;
    }
}
