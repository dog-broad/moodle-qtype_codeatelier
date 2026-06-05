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

// Runs the SHIPPED in-browser grader (amd/src/grader.js) against the shared
// matching corpus that the PHP server grader is also tested against. If both this
// file and tests/grader_test.php pass, the two interpreters agree on every case —
// the tier-parity guarantee the declarative spec exists for.
//
// No build deps: uses Node's built-in test runner (node --test). The grader is an
// authored AMD/ES module, so it is loaded by swapping its single named export for
// a CommonJS assignment and evaluating the real source in a fresh VM context.

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname, join} from 'node:path';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));

const source = readFileSync(join(here, '..', 'amd', 'src', 'grader.js'), 'utf8')
    .replace(/export\s*\{[^}]*\};?/, 'module.exports = {matches, grade};');
const moduleObj = {exports: {}};
vm.runInNewContext(source, {module: moduleObj});
const {matches, grade} = moduleObj.exports;

const corpus = JSON.parse(readFileSync(join(here, 'fixtures', 'matching_corpus.json'), 'utf8'));

test('matches() agrees with the shared corpus', () => {
    for (const c of corpus.matches) {
        assert.equal(
            matches(c.actual, c.expected, c.mode, c.tolerance), c.want,
            `matches case '${c.name}' (${c.mode}) disagreed with the corpus`);
    }
});

test('grade() agrees with the shared corpus', () => {
    for (const c of corpus.grade) {
        const graded = grade(c.results, c.tests, c.options || {});
        assert.ok(
            Math.abs(graded.fraction - c.wantfraction) < 1e-9,
            `grade case '${c.name}' fraction disagreed (got ${graded.fraction})`);
        assert.equal(graded.verdict, c.wantverdict,
            `grade case '${c.name}' verdict disagreed with the corpus`);
        c.wanttestresults.forEach((want, i) => {
            assert.equal(graded.testresults[i].status, want.status,
                `grade case '${c.name}' test ${i} status disagreed`);
            assert.equal(graded.testresults[i].passed, want.passed,
                `grade case '${c.name}' test ${i} passed disagreed`);
            assert.equal(graded.testresults[i].visibility, want.visibility,
                `grade case '${c.name}' test ${i} visibility disagreed`);
        });
    }
});
