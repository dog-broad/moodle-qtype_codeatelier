// Performance budget guard for the front-end bundles.
//
// CodeMirror 6 plus the eleven language grammars are pre-bundled into a single
// editor AMD module. That module is the plugin's one large front-end asset, so we
// keep an eye on it: this script runs after the rollup build and fails if a bundle
// grows past its budget, catching an accidental dependency bloat before it ships.
//
// The editor module is loaded by requirejs only on pages that render a CodeAtelier
// question (not site-wide), is served gzipped, and is cached after first load — so
// the budget is generous, a tripwire for regressions rather than a tight cap.
//
// Run automatically by `npm run build`; also `npm run size` on its own.
import {statSync, readdirSync} from 'node:fs';
import {gzipSync} from 'node:zlib';
import {readFileSync} from 'node:fs';

const BUILD_DIR = 'amd/build';

// Budgets in kilobytes (raw minified). Generous headroom over the current sizes.
const BUDGET = {
    'editor.min.js': 1200,   // CodeMirror 6 + 11 language grammars (~1.07 MB today).
    total: 1300,             // Everything in amd/build/*.min.js.
};

const files = readdirSync(BUILD_DIR).filter((f) => f.endsWith('.min.js'));
let total = 0;
let failed = false;
const rows = [];

for (const file of files) {
    const path = `${BUILD_DIR}/${file}`;
    const raw = statSync(path).size;
    const gz = gzipSync(readFileSync(path)).length;
    total += raw;
    const budget = BUDGET[file];
    const over = budget && raw / 1024 > budget;
    failed = failed || over;
    rows.push({file, raw, gz, budget, over});
}

rows.sort((a, b) => b.raw - a.raw);
const kb = (n) => (n / 1024).toFixed(1).padStart(8) + ' KB';

process.stdout.write('\nFront-end bundle sizes (raw / gzipped):\n');
for (const r of rows) {
    const tag = r.budget ? `  [budget ${r.budget} KB]${r.over ? ' OVER!' : ''}` : '';
    process.stdout.write(`  ${kb(r.raw)} / ${kb(r.gz)}  ${r.file}${tag}\n`);
}
const totalOver = total / 1024 > BUDGET.total;
failed = failed || totalOver;
process.stdout.write(`  ${kb(total)} total  [budget ${BUDGET.total} KB]${totalOver ? ' OVER!' : ''}\n`);

if (failed) {
    process.stderr.write('\nBundle budget exceeded. Trim a dependency or raise the budget deliberately.\n');
    process.exit(1);
}
process.stdout.write('\nWithin budget.\n');
