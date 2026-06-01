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
        output: {file: 'amd/build/editor.min.js', format: 'amd', sourcemap: false},
        plugins: [nodeResolve(), terser()],
    },
    {
        input: 'amd/src/runner.js',
        output: {file: 'amd/build/runner.min.js', format: 'amd', sourcemap: false},
        plugins: [nodeResolve(), terser()],
    },
    {
        input: 'amd/src/grader.js',
        output: {file: 'amd/build/grader.min.js', format: 'amd', sourcemap: false},
        plugins: [nodeResolve(), terser()],
    },
    {
        input: 'amd/src/worker.js',
        output: {file: 'amd/build/worker.min.js', format: 'iife', sourcemap: false},
        plugins: [nodeResolve(), terser()],
    },
];
