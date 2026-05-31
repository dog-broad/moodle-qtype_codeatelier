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

A teacher writes a question by choosing a language and filling in a table of test cases — each with an input, an expected result, a matching rule (exact, trimmed, regular expression, or numeric tolerance), a weight for partial credit, and whether it's shown to students. No execution templates to write.

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
