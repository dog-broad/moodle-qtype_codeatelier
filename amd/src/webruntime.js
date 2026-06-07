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

/**
 * Frontend (web) scenario runtime for qtype_codeatelier.
 *
 * Composes the student's HTML/CSS/JS file set into a single document and renders
 * it in a sandboxed iframe for a debounced live preview. The iframe is an opaque
 * origin (sandbox="allow-scripts" with no allow-same-origin), so student scripts
 * run contained and cannot reach the host Moodle page — which is why DOM grading
 * (a later step) runs *inside* the frame, the same model the secure Playwright
 * tier will use. This module owns the composition so both share one definition.
 *
 * Practice tier only: the preview is for formative feedback, never secure grading.
 *
 * @module qtype_codeatelier/webruntime
 */

/** @var {Number} how long to wait after the last edit before re-rendering. */
const REFRESH_DELAY_MS = 300;

/**
 * Parse a canonical file set from JSON. Returns [] on anything malformed.
 *
 * @param {String} json
 * @return {Array} array of {name, content}
 */
const parseFiles = (json) => {
    let decoded;
    try {
        decoded = JSON.parse(json);
    } catch (e) {
        return [];
    }
    if (!Array.isArray(decoded)) {
        return [];
    }
    return decoded
        .filter((f) => f && typeof f.name === 'string')
        .map((f) => ({name: String(f.name), content: typeof f.content === 'string' ? f.content : ''}));
};

/**
 * Insert markup before a closing tag, or fall back to a position if absent.
 *
 * @param {String} doc the HTML document
 * @param {String} closing e.g. '</head>'
 * @param {String} markup
 * @param {String} fallback 'prepend' or 'append'
 * @return {String}
 */
const injectBefore = (doc, closing, markup, fallback) => {
    if (!markup) {
        return doc;
    }
    const re = new RegExp(closing, 'i');
    if (re.test(doc)) {
        return doc.replace(re, markup + closing);
    }
    return fallback === 'prepend' ? markup + doc : doc + markup;
};

/**
 * The HTML file that is the page root: index.html if present, else the first
 * .html/.htm file.
 *
 * @param {Array} files
 * @return {Object|null} {name, content}
 */
const rootHtml = (files) => {
    const index = files.find((f) => f.name.toLowerCase() === 'index.html');
    if (index) {
        return index;
    }
    return files.find((f) => /\.html?$/i.test(f.name)) || null;
};

/**
 * Compose the file set into one self-contained HTML document: every .css inlined
 * into a <style>, every .js into a <script>, and local <link>/<script src> refs
 * to those files stripped (an iframe srcdoc has no server to resolve them).
 * Inline <style>/<script> the author wrote in the HTML are left untouched.
 *
 * @param {Array} files array of {name, content}
 * @return {String} the document for iframe srcdoc
 */
export const compose = (files) => {
    const list = Array.isArray(files) ? files : [];
    const root = rootHtml(list);
    let doc = root ? root.content : '<!doctype html><html><head></head><body></body></html>';

    const css = list.filter((f) => /\.css$/i.test(f.name)).map((f) => f.content).join('\n');
    const js = list.filter((f) => /\.m?js$/i.test(f.name)).map((f) => f.content).join('\n');

    // Drop refs to the local files we are about to inline (they can't load in srcdoc).
    doc = doc.replace(/<link\b[^>]*\brel=["']?stylesheet["']?[^>]*>/gi, '');
    doc = doc.replace(/<script\b[^>]*\bsrc=["'][^"']*\.m?js["'][^>]*>\s*<\/script>/gi, '');

    const styleTag = css ? '<style>' + css.replace(/<\/style>/gi, '<\\/style>') + '</style>' : '';
    doc = injectBefore(doc, '</head>', styleTag, 'prepend');

    const scriptTag = js ? '<script>' + js.replace(/<\/script>/gi, '<\\/script>') + '</script>' : '';
    doc = injectBefore(doc, '</body>', scriptTag, 'append');

    return doc;
};

/**
 * Mount a debounced live preview of the file set held in a textarea (the canonical
 * JSON the file-tab editor keeps in sync).
 *
 * @param {HTMLElement} container where to mount the preview iframe
 * @param {HTMLTextAreaElement} answerTa the field holding the canonical JSON file set
 * @param {Object} config the controller config
 * @param {Object} strings localised labels
 * @return {Object} {render, scheduleRefresh, destroy}
 */
export const setupPreview = (container, answerTa, config, strings) => {
    let timer = null;

    const iframe = document.createElement('iframe');
    iframe.className = 'codeatelier-preview-frame';
    // Opaque origin: scripts run but cannot reach the host page or the network's
    // credentialed context. Practice-tier containment, not a security boundary.
    iframe.setAttribute('sandbox', 'allow-scripts');
    iframe.setAttribute('title', (strings && strings.preview) || 'Preview');
    container.appendChild(iframe);

    const render = () => {
        iframe.srcdoc = compose(parseFiles(answerTa.value));
    };

    const scheduleRefresh = () => {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(render, REFRESH_DELAY_MS);
    };

    render();

    return {
        render,
        scheduleRefresh,
        destroy: () => {
            if (timer) {
                clearTimeout(timer);
            }
            if (iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        },
    };
};
