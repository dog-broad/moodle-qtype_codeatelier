# CodeAtelier

A Moodle question type for writing, running, and grading student code — with a modern editor and a tier that needs **no execution server at all**.

CodeAtelier lives inside the Moodle quiz engine (it is a `qtype` plugin), so it inherits grading, attempts, the question bank, randomization, review, and Safe Exam Browser lockdown. It is built to grow from instant in-browser practice into a full, trustworthy online judge.

## Two tiers

CodeAtelier runs a question in one of two tiers:

| Tier | Where code runs | Languages | Best for |
|---|---|---|---|
| **Practice** | the student's browser (WebAssembly, sandboxed iframe) | Python, SQL, JavaScript, HTML/CSS/JS | instant practice and formative feedback, with **no server to deploy** |
| **Judge** | a server sandbox (Judge0; a headless-browser service for web questions) | C, C++, C#, Java, Kotlin, Go, Rust, Python, JavaScript, TypeScript, PHP, Ruby, SQL, HTML/CSS/JS | authoritative grading with real time/memory limits and secret tests |

**The practice tier is for practice.** Because the test cases are sent to the browser to run the code, a determined student can read them — so the in-browser tier is never used for real grading. Graded assessment runs on the judge tier, where tests stay on the server and the result is computed where it can't be tampered with.

## Academic integrity & exam lockdown

Integrity is layered, and the layers map onto the tiers:

- **Authoritative grading (the real guarantee).** Judge-tier results are computed on the server against tests the student never receives, so a grade cannot be faked even if the editor is tampered with. Summative assessment runs on the judge tier.
- **Soft deterrents (detection, not prevention).** On graded (judge-tier) questions the editor blocks paste / copy / cut / right-click and records tab- or window-switches and paste attempts, summarised for the teacher on review. A web page cannot lock down a machine — the value here is the log and the friction, not a lock.
- **True lockdown — Safe Exam Browser (inherited, not reinvented).** Moodle integrates **Safe Exam Browser (SEB)** as a **quiz access rule** (core since Moodle 3.9). Configure SEB once in the quiz settings (*Quiz → Settings → Safe Exam Browser*); a quiz run under SEB locks the workstation, and every CodeAtelier question inside that quiz inherits the lockdown with no extra setup in the plugin. CodeAtelier deliberately does **not** reimplement operating-system lockdown — it leans on the platform's.

For a high-stakes exam: put judge-tier CodeAtelier questions in a quiz, enable Safe Exam Browser on that quiz, and rely on the server-side grade as the source of truth.

## Why it exists

The established option for code questions in Moodle is powerful but shows its age in two places: a dated code editor, and a hard dependency on a separate execution server that small institutions struggle to stand up. CodeAtelier answers both — a modern editor, and a practice tier that runs entirely in the browser so you can start with zero infrastructure — then earns the heavier features (compiled languages, performance verdicts, secret tests) on the judge tier.

## Status

Beta. Tested on Moodle 4.5 and 5.2 (PostgreSQL).

**Question scenarios**, each gradable on the tier(s) shown:

| Scenario | Practice | Judge |
|---|---|---|
| Standard input / output (complete program) | yes | yes |
| Function / unit-test (harness calls the student's function) | yes | yes, all languages, with typed signatures for static languages |
| SQL result set (including mutation + checker query) | yes | yes |
| Multi-file project (folder tree, file import, separate compilation) | — | yes |
| Web frontend (HTML/CSS/JS with declarative DOM assertions) | yes | yes |

**Grading.** Weighted test cases with partial credit; a declarative matching spec (exact, trimmed, regex, numeric tolerance, structured deep-equal, SQL result set) interpreted identically by the browser and server graders; the full verdict taxonomy (Accepted / Wrong Answer / Time Limit Exceeded / Memory Limit Exceeded / Runtime Error / Compile Error); per-question time and memory limits; custom checkers for problems with many valid answers; IOI-style subtasks with grouped partial scoring; student language choice.

**Scale.** Judge-tier grading is asynchronous: submitting an attempt never waits on the sandbox, a scheduled task drains the queue, and identical resubmissions are served from a result cache. Load-tested at 1000 simultaneous submissions. If the sandbox is unreachable the attempt stays *needs grading* — a grade is never faked.

**Authoring.** A test-case table instead of execution templates, live student preview, run-to-capture of expected outputs from a reference solution, bulk test-case import and large data files, per-test SQL datasets, and an author-by-example builder for web assertions.

**Teacher insight & integrity.** A submissions report per question and per quiz (score and verdict distributions, per-test pass rates, language breakdown, attempt drill-down); cohort similarity detection (fingerprint-based, run in-process, surfaced as clusters); a server-side proctor log of focus loss and blocked clipboard use on graded questions.

**Editor.** CodeMirror 6 with find/replace, multi-cursor and folding, focus mode, and selectable Auto / Light / Dark / High-contrast themes.

## Editor

CodeAtelier uses **CodeMirror 6** for the code editor — modern, lightweight, and accessible. It is lazy-loaded so it never stalls Moodle's JavaScript build the way a very large editor bundle can.

## Performance

The front end is pre-bundled with Rollup into `amd/build/`. CodeMirror 6 and the eleven language grammars are compiled into a single **editor** module — about **1.07 MB minified (~370 KB gzipped)**, the plugin's one large asset. It is loaded by Moodle's requirejs only on pages that render a CodeAtelier question (never site-wide), served gzipped, and cached after first load; every other module combined is ~17 KB gzipped. For comparison, this is well under a full Monaco bundle and on par with other rich web editors.

A **performance budget** guards against accidental bloat: `npm run size` (run automatically by `npm run build`) prints each bundle's raw and gzipped size and fails if a bundle exceeds its budget, so a stray dependency can't quietly balloon the editor. See `tools/bundle-budget.mjs`.

A future optimisation is to split the language grammars so a question loads only the one grammar it needs, rather than all eleven; it is deferred because it adds Rollup code-splitting and dynamic-import complexity through Moodle's AMD loader for a one-time, cached download.

## Authoring

A teacher writes the prompt in Moodle's normal question-text editor, then fills a structured code-data section: the language and scenario (standard input/output, a function to call, or an SQL query), optional starter code and SQL schema, and a table of test cases. Each test case has an input, an expected result, a matching rule (exact, trimmed, regular expression, numeric tolerance, deep-equal structured value, or SQL result set), a weight for partial credit, visibility to students, and an optional feedback note. A **live preview** shows exactly what students will see, and **run-to-capture** fills the expected outputs by running a reference solution. No execution templates to write.

## Requirements

- Moodle 4.5 or later (tested on 4.5 and 5.2).
- Practice tier: nothing beyond Moodle.
- Judge tier: a [Judge0](https://judge0.com/) instance (tested with v1.13.1), set under the plugin's admin settings.
- Web questions on the judge tier: the companion [codeatelier-webjudge](https://github.com/dog-broad/codeatelier-webjudge) service.

## Installation

Copy this plugin into `question/type/codeatelier/` in your Moodle tree (the directory **must** be named `codeatelier`; on Moodle 5.1+ the tree lives under `public/`), then visit **Site administration → Notifications** to complete the install.

```
git clone https://github.com/dog-broad/moodle-qtype_codeatelier.git question/type/codeatelier
```

Optional companion plugins add entry points to the submissions report:

- [`qbank_codeatelierreport`](https://github.com/dog-broad/codeatelier-qbank) — a question-bank action.
- [`quiz_codeatelier`](https://github.com/dog-broad/codeatelier-quizreport) — a quiz report tab.

## Accessibility

The editor and question interface are built to work with a screen reader and keyboard alone, and to honour reduced-motion preferences.

## License

GPLv3 or later. See [LICENSE](LICENSE).

### Third-party software

All bundled and runtime dependencies are GPLv3-compatible:

| Software | License | How it is used |
|---|---|---|
| [CodeMirror 6](https://codemirror.net/) (incl. `@codemirror/*` and `@lezer/*`) | MIT | Bundled into the editor AMD module at build time (see [`thirdpartylibs.xml`](thirdpartylibs.xml)). |
| [Pyodide](https://pyodide.org/) | MPL-2.0 | The Python runtime for practice-tier Python. **Not bundled** — loaded in the browser at runtime from a configurable URL (a pinned public CDN by default). |
| [sql.js](https://sql.js.org/) (SQLite) | MIT | The SQL runtime for practice-tier SQL. **Not bundled** — loaded in the browser at runtime from a configurable URL. |

Pyodide and sql.js are fetched by the browser, not shipped in this repository, and the source URL is a site admin setting so institutions can mirror them locally for offline or locked-down deployments.
