# CodeAtelier

A Moodle question type for writing, running, and grading student code — with a modern editor and a tier that needs **no execution server at all**.

CodeAtelier lives inside the Moodle quiz engine (it is a `qtype` plugin), so it inherits grading, attempts, the question bank, randomization, review, and Safe Exam Browser lockdown. It is built to grow from instant in-browser practice into a full, trustworthy online judge.

## Two tiers

CodeAtelier runs a question in one of two tiers:

| Tier | Where code runs | Languages | Best for |
|---|---|---|---|
| **Practice** | the student's browser (WebAssembly) | Python, SQL, JavaScript | instant practice and formative feedback, with **no server to deploy** |
| **Judge** | a server sandbox | Python, SQL, JavaScript, C, C++, Java, … | authoritative grading with real time/memory limits and secret tests |

**The practice tier is for practice.** Because the test cases are sent to the browser to run the code, a determined student can read them — so the in-browser tier is never used for real grading. Graded assessment runs on the judge tier, where tests stay on the server and the result is computed where it can't be tampered with.

## Why it exists

The established option for code questions in Moodle is powerful but shows its age in two places: a dated code editor, and a hard dependency on a separate execution server that small institutions struggle to stand up. CodeAtelier answers both — a modern editor, and a practice tier that runs entirely in the browser so you can start with zero infrastructure — then earns the heavier features (compiled languages, performance verdicts, secret tests) on the judge tier.

## Status

Early development. The first release is the **practice tier**: a modern in-browser code question (Python, SQL, JavaScript) with instant feedback and no execution server. Authoritative server-side grading, compiled languages, and full judge verdicts (Accepted / Wrong Answer / Time Limit Exceeded / Memory Limit Exceeded / Runtime Error / Compile Error) follow on the judge tier.

## Editor

CodeAtelier uses **CodeMirror 6** for the code editor — modern, lightweight, and accessible. It is lazy-loaded so it never stalls Moodle's JavaScript build the way a very large editor bundle can.

## Authoring

A teacher writes the prompt in Moodle's normal question-text editor, then fills a structured code-data section: the language and scenario (standard input/output, a function to call, or an SQL query), optional starter code and SQL schema, and a table of test cases. Each test case has an input, an expected result, a matching rule (exact, trimmed, regular expression, numeric tolerance, deep-equal structured value, or SQL result set), a weight for partial credit, visibility to students, and an optional feedback note. A **live preview** shows exactly what students will see, and **run-to-capture** fills the expected outputs by running a reference solution. No execution templates to write.

## Requirements

- Moodle 4.x or 5.x.
- For the judge tier: a code-execution sandbox (configured per site).

## Installation

Copy this plugin into `question/type/codeatelier/` in your Moodle tree (the directory **must** be named `codeatelier`), then visit **Site administration → Notifications** to complete the install.

```
git clone https://github.com/dog-broad/moodle-qtype_codeatelier.git question/type/codeatelier
```

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
