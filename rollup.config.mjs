import {nodeResolve} from '@rollup/plugin-node-resolve';
import terser from '@rollup/plugin-terser';

// Bundles the CodeMirror 6 editor (an ES-module-only library) into a single
// minified AMD module that Moodle's requirejs loader can serve from amd/build/.
// We pre-bundle and pre-minify here rather than feeding CodeMirror through
// Moodle's own grunt/minifier: CodeMirror 6 is modular and tree-shakeable, so
// the bundle stays small, and shipping a built AMD module is the supported way
// to use a large third-party library in Moodle.
export default {
    input: 'amd/src/editor.js',
    output: {
        file: 'amd/build/editor.min.js',
        format: 'amd',
        sourcemap: false,
    },
    plugins: [nodeResolve(), terser()],
};
