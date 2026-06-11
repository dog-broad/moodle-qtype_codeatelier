import {nodeResolve} from '@rollup/plugin-node-resolve';
import terser from '@rollup/plugin-terser';

// Bundles the plugin's front-end modules into amd/build/.
//
// - editor: the CodeMirror 6 editor, an ES-module-only library, bundled into a
//   single AMD module Moodle's requirejs can serve. We pre-bundle and pre-minify
//   here rather than feeding CodeMirror through Moodle's own grunt/minifier.
// - runner: the practice-tier runner seam (AMD), loaded by requirejs.
// - worker: the execution Web Worker. Built as a self-contained IIFE because a
//   Web Worker script is loaded with `new Worker(url)`, not the AMD loader.
export default [
    {
        input: 'amd/src/editor.js',
        output: {file: 'amd/build/editor.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        input: 'amd/src/runner.js',
        output: {file: 'amd/build/runner.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        input: 'amd/src/grader.js',
        output: {file: 'amd/build/grader.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        input: 'amd/src/diff.js',
        output: {file: 'amd/build/diff.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // Soft anti-cheat for judge-tier attempts: a small vanilla AMD module.
        input: 'amd/src/proctor.js',
        output: {file: 'amd/build/proctor.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // Judge-tier "run against examples": calls the server via core/ajax, kept external.
        input: 'amd/src/judgerun.js',
        external: (id) => id.startsWith('core/'),
        output: {file: 'amd/build/judgerun.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // Live grade-status poller for submitted judge attempts (core/ajax external).
        input: 'amd/src/gradestatus.js',
        external: (id) => id.startsWith('core/'),
        output: {file: 'amd/build/gradestatus.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // The multi-file project editor: a file-tab list over one CodeMirror,
        // reusing the editor as an AMD module (kept external).
        input: 'amd/src/projectfiles.js',
        external: (id) => id.startsWith('qtype_codeatelier/'),
        output: {file: 'amd/build/projectfiles.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // The web (frontend) scenario runtime: composes the file set and renders it
        // in a sandboxed live-preview iframe. Self-contained (no plugin deps).
        input: 'amd/src/webruntime.js',
        output: {file: 'amd/build/webruntime.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // The controller depends on the other plugin modules as AMD modules
        // (kept external so CodeMirror is not duplicated into this bundle).
        input: 'amd/src/control.js',
        external: (id) => id.startsWith('qtype_codeatelier/'),
        output: {file: 'amd/build/control.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // The scalable test-case editor: a self-contained vanilla module that
        // enhances the testcasesjson field into a paginated, searchable list.
        input: 'amd/src/testeditor.js',
        output: {file: 'amd/build/testeditor.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // The authoring helper reuses the editor and the workbench controller as
        // AMD modules and renders the student template via core/templates.
        input: 'amd/src/author.js',
        external: (id) => id.startsWith('qtype_codeatelier/') || id.startsWith('core/'),
        output: {file: 'amd/build/author.min.js', format: 'amd', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
    {
        // The execution worker is a module worker (new Worker(url, {type:'module'}))
        // so it can dynamically import the Pyodide ES module at runtime.
        input: 'amd/src/worker.js',
        output: {file: 'amd/build/worker.min.js', format: 'es', sourcemap: true, sourcemapExcludeSources: true},
        plugins: [nodeResolve(), terser()],
    },
];
