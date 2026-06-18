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

// Parser unit tests for the project-workspace import (run with `node --test`).
// The parsers are pure, so they are tested directly off the AMD source.

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    sanitisePath,
    parseTree,
    parseFilesWithContent,
    detectPasteFormat,
    EXAMPLE_TREE,
    EXAMPLE_FILES,
} from '../../amd/src/projectimport.js';

test('sanitisePath normalises and rejects unsafe paths', () => {
    assert.equal(sanitisePath('src/Main.java'), 'src/Main.java');
    assert.equal(sanitisePath('  ./src//Main.java  '), 'src/Main.java');
    assert.equal(sanitisePath('/abs/path.txt'), 'abs/path.txt');
    assert.equal(sanitisePath('a\\b.txt'), 'a/b.txt');
    assert.equal(sanitisePath('../escape.txt'), '');
    assert.equal(sanitisePath('a/../b.txt'), '');
    assert.equal(sanitisePath(''), '');
    assert.equal(sanitisePath(null), '');
});

test('parseTree: Unix box-drawing dialect with nesting', () => {
    const {files} = parseTree(
        'src/\n'
        + '├─ Main.java\n'
        + '├─ parser/\n'
        + '│   └─ LogParser.java\n'
        + '└─ Util.java\n'
        + 'access.log'
    );
    assert.deepEqual(files, [
        'src/Main.java',
        'src/parser/LogParser.java',
        'src/Util.java',
        'access.log',
    ]);
});

test('parseTree: heavy box-drawing (┣ ┗ ┃ ━) parses the same', () => {
    const {files} = parseTree(
        'app/\n'
        + '┣━ index.js\n'
        + '┗━ lib/\n'
        + '   ┗━ util.js'
    );
    assert.deepEqual(files, ['app/index.js', 'app/lib/util.js']);
});

test('parseTree: ASCII dialect (|-- +-- `--)', () => {
    const {files} = parseTree(
        'src/\n'
        + '|-- Main.java\n'
        + '|-- parser/\n'
        + '|   `-- LogParser.java\n'
        + '`-- Util.java'
    );
    assert.deepEqual(files, [
        'src/Main.java',
        'src/parser/LogParser.java',
        'src/Util.java',
    ]);
});

test('parseTree: plain 2-space indentation, trailing slash = folder', () => {
    const {files} = parseTree(
        'src/\n'
        + '  Main.java\n'
        + '  parser/\n'
        + '    LogParser.java\n'
        + 'access.log'
    );
    assert.deepEqual(files, [
        'src/Main.java',
        'src/parser/LogParser.java',
        'access.log',
    ]);
});

test('parseTree: tab indentation', () => {
    const {files} = parseTree('root/\n\ta.txt\n\tsub/\n\t\tb.txt');
    assert.deepEqual(files, ['root/a.txt', 'root/sub/b.txt']);
});

test('parseTree: bullet lists', () => {
    const {files} = parseTree(
        '- src/\n'
        + '  - Main.java\n'
        + '  * Util.java'
    );
    assert.deepEqual(files, ['src/Main.java', 'src/Util.java']);
});

test('parseTree: flat path-per-line (no indentation)', () => {
    const {files} = parseTree('src/Main.java\nsrc/parser/LogParser.java\naccess.log');
    assert.deepEqual(files, ['src/Main.java', 'src/parser/LogParser.java', 'access.log']);
});

test('parseTree: inline annotations are dropped', () => {
    const {files} = parseTree(
        'src/\n'
        + '  Main.java   # entry point\n'
        + '  Config.java   <- edit me'
    );
    assert.deepEqual(files, ['src/Main.java', 'src/Config.java']);
});

test('parseTree: de-duplicates and counts skipped comment lines', () => {
    const {files, skipped} = parseTree(
        '# a comment\n'
        + 'src/Main.java\n'
        + 'src/Main.java\n'
        + '\n'
        + '# trailing note'
    );
    assert.deepEqual(files, ['src/Main.java']);
    assert.equal(skipped, 2);
});

test('parseTree: empty folders are not emitted', () => {
    const {files} = parseTree('empty/\nother/\n  kept.txt');
    assert.deepEqual(files, ['other/kept.txt']);
});

test('parseFilesWithContent: question-spec format (**name** then fence)', () => {
    const out = parseFilesWithContent(
        '**src/Main.java**\n'
        + '```java\n'
        + 'public class Main {}\n'
        + '```\n\n'
        + '**src/Util.java**\n'
        + '```java\n'
        + 'class Util {}\n'
        + '```'
    );
    assert.deepEqual(out, [
        {name: 'src/Main.java', content: 'public class Main {}'},
        {name: 'src/Util.java', content: 'class Util {}'},
    ]);
});

test('parseFilesWithContent: filename from fence info-string', () => {
    const out = parseFilesWithContent('```src/app.py\nprint(1)\n```');
    assert.deepEqual(out, [{name: 'src/app.py', content: 'print(1)'}]);
});

test('parseFilesWithContent: markdown heading and tilde fences', () => {
    const out = parseFilesWithContent(
        '### config.json\n'
        + '~~~json\n'
        + '{"a": 1}\n'
        + '~~~'
    );
    assert.deepEqual(out, [{name: 'config.json', content: '{"a": 1}'}]);
});

test('parseFilesWithContent: preserves blank lines inside a block', () => {
    const out = parseFilesWithContent('`main.py`\n```\na = 1\n\nb = 2\n```');
    assert.deepEqual(out, [{name: 'main.py', content: 'a = 1\n\nb = 2'}]);
});

test('detectPasteFormat distinguishes fenced listings from trees', () => {
    assert.equal(detectPasteFormat('src/\n  Main.java'), 'tree');
    assert.equal(detectPasteFormat('**Main.java**\n```java\nx\n```'), 'files');
    assert.equal(detectPasteFormat('   ~~~\ncode\n~~~'), 'files');
});

test('shipped examples round-trip through their own parsers', () => {
    assert.deepEqual(parseTree(EXAMPLE_TREE).files, [
        'src/Main.java',
        'src/parser/LogParser.java',
        'src/Util.java',
        'access.log',
    ]);
    const files = parseFilesWithContent(EXAMPLE_FILES);
    assert.equal(files.length, 2);
    assert.equal(files[0].name, 'src/Main.java');
    assert.match(files[0].content, /public class Main/);
    assert.equal(files[1].name, 'src/Util.java');
});
