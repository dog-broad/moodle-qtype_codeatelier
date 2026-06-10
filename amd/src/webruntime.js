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
 * The trusted in-frame harness for DOM-assertion grading. Injected before the
 * student code so it captures a reference to postMessage first; it evaluates a
 * declarative assertion against the rendered document and posts the result back
 * to the parent. The SAME declarative spec is what the secure (Playwright) tier
 * will evaluate in page context, so the shape is parity-ready. Kept as a string
 * because it runs inside the sandboxed frame, not in this module.
 */
const HARNESS = `
(function(){
  var send = window.parent.postMessage.bind(window.parent);
  function q(s){ try { return document.querySelector(s); } catch(e){ return null; } }
  function qa(s){ try { return Array.prototype.slice.call(document.querySelectorAll(s)); } catch(e){ return []; } }
  function getProp(el, prop){
    if (prop === 'text') return (el.textContent || '').trim();
    if (prop.indexOf('attr:') === 0) return el.getAttribute(prop.slice(5));
    if (prop.indexOf('style:') === 0) return getComputedStyle(el)[prop.slice(6)];
    return null;
  }
  function cmp(actual, matcher, expected){
    var a = String(actual === null || actual === undefined ? '' : actual);
    var e = String(expected === null || expected === undefined ? '' : expected);
    if (matcher === 'contains') return a.indexOf(e) !== -1;
    if (matcher === 'regex') { try { return new RegExp(e).test(a); } catch(x){ return false; } }
    return a === e;
  }
  function evalAssertion(t){
    var prop = (t.property || 'text');
    var matcher = (t.matcher || 'equals');
    try {
      if (prop === 'exists') {
        var present = !!q(t.selector);
        var expectPresent = !/^(false|0|no)$/i.test(String(t.expected || '').trim());
        return { actual: present ? 'present' : 'absent', pass: present === expectPresent };
      }
      if (prop === 'count') {
        var n = qa(t.selector).length;
        return { actual: n, pass: cmp(n, matcher, t.expected) };
      }
      var el = q(t.selector);
      if (!el) return { actual: null, pass: false, missing: true };
      var actual = getProp(el, prop);
      return { actual: actual, pass: cmp(actual, matcher, t.expected) };
    } catch (err) {
      return { actual: null, pass: false, error: String(err && err.message || err) };
    }
  }
  function runAction(a){
    var el = q(a.selector);
    if (!el) return;
    if (a.type === 'click') { el.click(); }
    else if (a.type === 'type') { el.value = a.value; el.dispatchEvent(new Event('input', {bubbles:true})); }
  }
  // Build a reasonably stable CSS selector for an element: prefer its id, then a
  // unique class, then a path of tag:nth-of-type steps up to an id or the body.
  function selectorFor(el){
    if (!el || el === document.body || el === document.documentElement) return 'body';
    if (el.id) return '#' + el.id;
    var tag = el.tagName.toLowerCase();
    if (el.classList && el.classList.length){
      var cls = '.' + Array.prototype.slice.call(el.classList).join('.');
      if (document.querySelectorAll(tag + cls).length === 1) return tag + cls;
    }
    var parts = [];
    var node = el;
    while (node && node.nodeType === 1 && node !== document.body){
      if (node.id){ parts.unshift('#' + node.id); break; }
      var t = node.tagName.toLowerCase();
      var parent = node.parentNode;
      if (parent){
        var sibs = Array.prototype.filter.call(parent.children, function(c){ return c.tagName === node.tagName; });
        if (sibs.length > 1){ t += ':nth-of-type(' + (sibs.indexOf(node) + 1) + ')'; }
      }
      parts.unshift(t);
      node = parent;
    }
    return parts.join(' > ');
  }
  var pickHover = null;
  function clearHover(){ if (pickHover){ pickHover.style.outline = pickHover.__caOutline || ''; pickHover = null; } }
  function onPickOver(e){
    clearHover();
    pickHover = e.target;
    pickHover.__caOutline = pickHover.style.outline;
    e.target.style.outline = '2px solid #1f6fd6';
  }
  function onPickClick(e){
    e.preventDefault(); e.stopPropagation();
    clearHover();
    send({ kind: 'picked', selector: selectorFor(e.target), text: (e.target.textContent || '').trim().slice(0, 200) }, '*');
    return false;
  }
  function setPick(on){
    document.removeEventListener('click', onPickClick, true);
    document.removeEventListener('mouseover', onPickOver, true);
    clearHover();
    document.body.style.cursor = on ? 'crosshair' : '';
    if (on){
      document.addEventListener('click', onPickClick, true);
      document.addEventListener('mouseover', onPickOver, true);
    }
  }
  var hlEl = null;
  function highlight(selector){
    if (hlEl){ hlEl.style.outline = hlEl.__caHl || ''; hlEl = null; }
    var el = q(selector);
    if (el){
      hlEl = el; el.__caHl = el.style.outline;
      el.style.outline = '3px solid #d63384'; el.style.outlineOffset = '2px';
      el.scrollIntoView({block: 'center'});
    }
    send({ kind: 'highlighted', found: !!el }, '*');
  }
  window.addEventListener('message', function(ev){
    var msg = ev.data || {};
    if (msg.kind === 'grade') {
      try { (msg.actions || []).forEach(runAction); } catch(e){}
      send({ kind: 'results', results: (msg.assertions || []).map(evalAssertion) }, '*');
    } else if (msg.kind === 'pickmode') {
      setPick(!!msg.on);
    } else if (msg.kind === 'highlight') {
      highlight(msg.selector);
    }
  });
  window.__caHarness = { ready: function(){ send({ kind: 'ready' }, '*'); } };
})();`;

/**
 * Compose the file set into one self-contained HTML document: every .css inlined
 * into a <style>, every .js into a <script>, and local <link>/<script src> refs
 * to those files stripped (an iframe srcdoc has no server to resolve them).
 * Inline <style>/<script> the author wrote in the HTML are left untouched.
 *
 * @param {Array} files array of {name, content}
 * @param {Object} [options] {harness} — inject the grading harness (and a ready
 *     signal after the student code) for in-frame assertion evaluation
 * @return {String} the document for iframe srcdoc
 */
export const compose = (files, options) => {
    const opts = options || {};
    const list = Array.isArray(files) ? files : [];
    const root = rootHtml(list);
    let doc = root ? root.content : '<!doctype html><html><head></head><body></body></html>';

    const css = list.filter((f) => /\.css$/i.test(f.name)).map((f) => f.content).join('\n');
    const js = list.filter((f) => /\.m?js$/i.test(f.name)).map((f) => f.content).join('\n');

    // Drop refs to the local files we are about to inline (they can't load in srcdoc).
    doc = doc.replace(/<link\b[^>]*\brel=["']?stylesheet["']?[^>]*>/gi, '');
    doc = doc.replace(/<script\b[^>]*\bsrc=["'][^"']*\.m?js["'][^>]*>\s*<\/script>/gi, '');

    // The harness goes first (before css and student code) so it captures the
    // postMessage reference before any student script could shadow it.
    const harnessTag = opts.harness ? '<script>' + HARNESS.replace(/<\/script>/gi, '<\\/script>') + '</script>' : '';
    const styleTag = css ? '<style>' + css.replace(/<\/style>/gi, '<\\/style>') + '</style>' : '';
    doc = injectBefore(doc, '</head>', harnessTag + styleTag, 'prepend');

    const scriptTag = js ? '<script>' + js.replace(/<\/script>/gi, '<\\/script>') + '</script>' : '';
    // The ready signal runs last, after the student code, so assertions see the
    // page in its loaded state.
    const readyTag = opts.harness ? '<script>window.__caHarness.ready();</script>' : '';
    doc = injectBefore(doc, '</body>', scriptTag + readyTag, 'append');

    return doc;
};

/**
 * Parse the actions DSL (one per line): `click <selector>` or
 * `type <selector> <value>`. Lines may also be separated by ';'.
 *
 * @param {String} text
 * @return {Array} array of {type, selector, value?}
 */
export const parseActions = (text) => {
    if (!text) {
        return [];
    }
    return String(text).split(/[\n;]+/).map((line) => line.trim()).filter(Boolean).map((line) => {
        const space = line.indexOf(' ');
        const verb = (space === -1 ? line : line.slice(0, space)).toLowerCase();
        const rest = space === -1 ? '' : line.slice(space + 1).trim();
        if (verb === 'click') {
            return {type: 'click', selector: rest};
        }
        if (verb === 'type') {
            const sp2 = rest.indexOf(' ');
            return (sp2 === -1)
                ? {type: 'type', selector: rest, value: ''}
                : {type: 'type', selector: rest.slice(0, sp2), value: rest.slice(sp2 + 1)};
        }
        return null;
    }).filter(Boolean);
};

/**
 * Render a harnessed document in a frame and evaluate one assertion (after its
 * actions). Resolves with {pass, actual, error?}.
 *
 * @param {HTMLIFrameElement} iframe the (reused) hidden grading frame
 * @param {String} doc the composed harnessed document
 * @param {Object} assertion {selector, property, matcher, expected}
 * @param {Array} actions parsed actions to run first
 * @return {Promise<Object>}
 */
const gradeOne = (iframe, doc, assertion, actions) => new Promise((resolve) => {
    let done = false;
    let requested = false;
    const finish = (result) => {
        if (done) {
            return;
        }
        done = true;
        window.removeEventListener('message', onMessage);
        clearTimeout(timer);
        resolve(result || {pass: false, error: 'no result'});
    };
    const onMessage = (ev) => {
        if (ev.source !== iframe.contentWindow) {
            return;
        }
        const msg = ev.data || {};
        if (msg.kind === 'ready') {
            requested = true;
            iframe.contentWindow.postMessage({kind: 'grade', actions: actions, assertions: [assertion]}, '*');
        } else if (msg.kind === 'results' && requested) {
            finish((msg.results && msg.results[0]) || {pass: false, error: 'no result'});
        }
    };
    const timer = setTimeout(() => finish({pass: false, error: 'timed out'}), 4000);
    window.addEventListener('message', onMessage);
    iframe.srcdoc = doc;
});

/**
 * Grade a web answer (file set) against its DOM assertions. Each assertion runs
 * in a freshly-rendered sandboxed frame (its own actions then its one check), so
 * assertions are isolated and independently scored. Returns the same
 * {fraction, verdict, testresults} shape the other scenarios produce.
 *
 * @param {Array} files the answer file set
 * @param {Array} tests the assertion rows ({selector, assertproperty, assertmatcher, expected, actions, weight, visibility})
 * @param {Object} [options] {allOrNothing}
 * @return {Promise<Object>} {fraction, verdict, testresults}
 */
export const gradeWeb = (files, tests, options) => {
    const opts = options || {};
    const iframe = document.createElement('iframe');
    iframe.setAttribute('sandbox', 'allow-scripts');
    iframe.setAttribute('title', 'grading');
    iframe.style.cssText = 'position:absolute;left:-9999px;top:0;width:1024px;height:768px;border:0;visibility:hidden';
    document.body.appendChild(iframe);
    const doc = compose(files, {harness: true});

    return (async() => {
        let totalweight = 0;
        let earned = 0;
        const testresults = [];
        for (let i = 0; i < tests.length; i++) {
            const t = tests[i];
            const assertion = {
                selector: t.selector,
                property: t.assertproperty || 'text',
                matcher: t.assertmatcher || 'equals',
                expected: t.expected,
            };
            const r = await gradeOne(iframe, doc, assertion, parseActions(t.actions));
            const weight = parseFloat(t.weight) || 0;
            totalweight += weight;
            const passed = !r.error && !!r.pass;
            if (passed) {
                earned += weight;
            }
            testresults.push({
                index: i,
                passed: passed,
                visibility: Number(t.visibility),
                actual: r.actual,
                error: r.error || null,
            });
        }
        if (iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);
        }
        let fraction = totalweight > 0 ? earned / totalweight : 0;
        if (opts.allOrNothing && fraction < 1) {
            fraction = 0;
        }
        const verdict = fraction >= 1 ? 'AC' : 'WA';
        return {fraction: fraction, verdict: verdict, testresults: testresults};
    })();
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
    let pickOn = false;
    let pickCb = null;

    const iframe = document.createElement('iframe');
    iframe.className = 'codeatelier-preview-frame';
    // Opaque origin: scripts run but cannot reach the host page or the network's
    // credentialed context. Practice-tier containment, not a security boundary.
    iframe.setAttribute('sandbox', 'allow-scripts');
    iframe.setAttribute('title', (strings && strings.preview) || 'Preview');
    container.appendChild(iframe);

    // The preview carries the harness so it can answer pick / highlight requests.
    const render = () => {
        iframe.srcdoc = compose(parseFiles(answerTa.value), {harness: true});
    };

    const scheduleRefresh = () => {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(render, REFRESH_DELAY_MS);
    };

    const onMessage = (ev) => {
        if (ev.source !== iframe.contentWindow) {
            return;
        }
        const msg = ev.data || {};
        if (msg.kind === 'ready' && pickOn) {
            // A fresh render dropped the previous harness; re-arm pick mode.
            iframe.contentWindow.postMessage({kind: 'pickmode', on: true}, '*');
        } else if (msg.kind === 'picked' && pickCb) {
            pickCb(msg.selector, msg.text);
        }
    };
    window.addEventListener('message', onMessage);

    render();

    return {
        render,
        scheduleRefresh,
        setPickMode: (on) => {
            pickOn = !!on;
            if (iframe.contentWindow) {
                iframe.contentWindow.postMessage({kind: 'pickmode', on: pickOn}, '*');
            }
        },
        onPick: (cb) => {
            pickCb = cb;
        },
        highlight: (selector) => {
            if (iframe.contentWindow) {
                iframe.contentWindow.postMessage({kind: 'highlight', selector: selector}, '*');
            }
        },
        destroy: () => {
            if (timer) {
                clearTimeout(timer);
            }
            window.removeEventListener('message', onMessage);
            if (iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        },
    };
};
