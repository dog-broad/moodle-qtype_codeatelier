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
 * The canonical DOM-assertion evaluator + action runner, as a source string.
 *
 * This is the single source of truth for what an assertion *means*: the practice
 * tier inlines it into the in-frame harness (below), and the secure judge is sent
 * this exact text in its grade request and injects it into the Playwright page —
 * so a given assertion produces the same verdict on both tiers by construction,
 * not by hand-kept mirrors. It is pure browser code with no closure over this
 * module. Functions are `ca`-prefixed so they cannot collide with student code.
 *
 * An assertion is a tree: `{all:[…]}` (AND) / `{any:[…]}` (OR) / `{not: node}`
 * over leaves; a node carrying a `selector` is a leaf
 * (`{selector, property, matcher, expected}`). A bare leaf is a one-node tree.
 */
export const WEB_EVAL_SOURCE = `
  function caQ(s){ try { return document.querySelector(s); } catch(e){ return null; } }
  function caQa(s){ try { return Array.prototype.slice.call(document.querySelectorAll(s)); } catch(e){ return []; } }
  function caVisible(el){
    if (!el || el.hidden) return false;
    var s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || parseFloat(s.opacity) === 0) return false;
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }
  function caGetProp(el, prop){
    if (prop === 'text') return (el.textContent || '').trim();
    if (prop === 'html') return el.innerHTML;
    if (prop === 'value') return ('value' in el) ? el.value : '';
    if (prop === 'checked') return !!el.checked;
    if (prop === 'disabled') return !!el.disabled;
    if (prop === 'visible') return caVisible(el);
    if (prop === 'classlist') return Array.prototype.slice.call(el.classList || []).join(' ');
    if (prop.indexOf('attr:') === 0) return el.getAttribute(prop.slice(5));
    if (prop.indexOf('style:') === 0) return getComputedStyle(el)[prop.slice(6)];
    return null;
  }
  function caCmp(actual, matcher, expected){
    if (matcher === 'gt' || matcher === 'lt' || matcher === 'gte' || matcher === 'lte'){
      var na = parseFloat(actual), ne = parseFloat(expected);
      if (isNaN(na) || isNaN(ne)) return false;
      if (matcher === 'gt') return na > ne;
      if (matcher === 'lt') return na < ne;
      if (matcher === 'gte') return na >= ne;
      return na <= ne;
    }
    var a = String(actual === null || actual === undefined ? '' : actual);
    var e = String(expected === null || expected === undefined ? '' : expected);
    if (matcher === 'contains') return a.indexOf(e) !== -1;
    if (matcher === 'not-contains') return a.indexOf(e) === -1;
    if (matcher === 'not-equals') return a !== e;
    if (matcher === 'has-class') return (' ' + a + ' ').indexOf(' ' + e + ' ') !== -1;
    if (matcher === 'regex') { try { return new RegExp(e).test(a); } catch(x){ return false; } }
    return a === e;
  }
  function caEvalLeaf(t){
    var prop = (t.property || 'text');
    var matcher = (t.matcher || 'equals');
    try {
      if (prop === 'exists') {
        var present = !!caQ(t.selector);
        var expectPresent = !/^(false|0|no)$/i.test(String(t.expected == null ? '' : t.expected).trim());
        return { actual: present ? 'present' : 'absent', pass: present === expectPresent };
      }
      if (prop === 'count') {
        var n = caQa(t.selector).length;
        return { actual: n, pass: caCmp(n, matcher, t.expected) };
      }
      var el = caQ(t.selector);
      if (!el) return { actual: null, pass: false, missing: true };
      var actual = caGetProp(el, prop);
      return { actual: actual, pass: caCmp(actual, matcher, t.expected) };
    } catch (err) {
      return { actual: null, pass: false, error: String(err && err.message || err) };
    }
  }
  // Recursive boolean composition. Returns {pass, op?, children?} for a node, or a
  // leaf result {pass, actual, missing?, error?}.
  function caEvalNode(node){
    if (node && Array.isArray(node.all)){
      var rall = node.all.map(caEvalNode);
      return { op: 'all', pass: rall.every(function(r){ return r.pass; }), children: rall };
    }
    if (node && Array.isArray(node.any)){
      var rany = node.any.map(caEvalNode);
      return { op: 'any', pass: rany.some(function(r){ return r.pass; }), children: rany };
    }
    if (node && node.not){
      var rnot = caEvalNode(node.not);
      return { op: 'not', pass: !rnot.pass, children: [rnot] };
    }
    return caEvalLeaf(node || {});
  }
  function caWait(ms){ return new Promise(function(res){ setTimeout(res, ms); }); }
  function caWaitFor(selector, timeout){
    return new Promise(function(res){
      var t0 = Date.now();
      (function poll(){
        if (caQ(selector)) return res(true);
        if (Date.now() - t0 >= timeout) return res(false);
        setTimeout(poll, 50);
      })();
    });
  }
  function caRunAction(a){
    if (a.type === 'wait') return caWait(Math.max(0, parseInt(a.ms, 10) || 0));
    if (a.type === 'wait-for') return caWaitFor(a.selector, Math.max(0, parseInt(a.ms, 10) || 3000));
    var el = caQ(a.selector);
    if (!el) return Promise.resolve();
    if (a.type === 'click') { el.click(); }
    else if (a.type === 'type') { el.value = a.value; el.dispatchEvent(new Event('input', {bubbles:true})); }
    else if (a.type === 'select') { el.value = a.value; el.dispatchEvent(new Event('change', {bubbles:true})); }
    else if (a.type === 'check') { el.checked = (a.value !== 'false'); el.dispatchEvent(new Event('change', {bubbles:true})); }
    else if (a.type === 'submit') { if (el.requestSubmit) { el.requestSubmit(); } else if (el.submit) { el.submit(); } }
    else if (a.type === 'key') {
      el.dispatchEvent(new KeyboardEvent('keydown', {key: a.value, bubbles:true}));
      el.dispatchEvent(new KeyboardEvent('keyup', {key: a.value, bubbles:true}));
    }
    return Promise.resolve();
  }
  function caRunActions(actions){
    return (actions || []).reduce(function(p, a){
      return p.then(function(){ return caRunAction(a); });
    }, Promise.resolve());
  }
`;

/**
 * The trusted in-frame harness for DOM-assertion grading. Injected before the
 * student code so it captures a reference to postMessage first; it runs the
 * assertion's actions (awaiting waits) then evaluates the assertion tree against
 * the rendered document and posts the result back to the parent. The evaluator is
 * {@link WEB_EVAL_SOURCE}, shared verbatim with the judge. Kept as a string
 * because it runs inside the sandboxed frame, not in this module.
 */
const HARNESS = `
(function(){
  var send = window.parent.postMessage.bind(window.parent);
  ${WEB_EVAL_SOURCE}
  var q = caQ, qa = caQa;
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
      var report = function(){ send({ kind: 'results', results: (msg.assertions || []).map(caEvalNode) }, '*'); };
      caRunActions(msg.actions).then(report, report);
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
 * Parse the actions DSL (one per line, or ';'-separated). Verbs:
 *   click <selector>                 — click an element
 *   type <selector> <value>          — set an input's value (fires input)
 *   select <selector> <value>        — set a select's value (fires change)
 *   check <selector> [true|false]    — set a checkbox (default true, fires change)
 *   submit <selector>                — submit a form
 *   key <selector> <Key>             — dispatch keydown/keyup of <Key>
 *   wait <ms>                        — pause before the next step
 *   wait-for <selector> [ms]         — wait until a selector appears (default 3000ms)
 *
 * @param {String} text
 * @return {Array} array of {type, selector?, value?, ms?}
 */
export const parseActions = (text) => {
    if (!text) {
        return [];
    }
    // Split "verb rest" and "verb a b" forms.
    const head = (rest) => {
        const sp = rest.indexOf(' ');
        return sp === -1 ? [rest, ''] : [rest.slice(0, sp), rest.slice(sp + 1).trim()];
    };
    return String(text).split(/[\n;]+/).map((line) => line.trim()).filter(Boolean).map((line) => {
        const space = line.indexOf(' ');
        const verb = (space === -1 ? line : line.slice(0, space)).toLowerCase();
        const rest = space === -1 ? '' : line.slice(space + 1).trim();
        if (verb === 'click' || verb === 'submit') {
            return {type: verb, selector: rest};
        }
        if (verb === 'type' || verb === 'select' || verb === 'check' || verb === 'key') {
            const [sel, val] = head(rest);
            return {type: verb, selector: sel, value: val};
        }
        if (verb === 'wait') {
            return {type: 'wait', ms: rest};
        }
        if (verb === 'wait-for') {
            const [sel, ms] = head(rest);
            return {type: 'wait-for', selector: sel, ms: ms};
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
    const timer = setTimeout(() => finish({pass: false, error: 'timed out'}), 10000);
    window.addEventListener('message', onMessage);
    iframe.srcdoc = doc;
});

/**
 * The assertion tree for a test row. A compound check carries a parsed tree on
 * `assertion` (`{all|any|not|leaf}`); otherwise the flat columns form the single
 * leaf — so existing (flat) web questions keep working with no change.
 *
 * @param {Object} t a test row
 * @return {Object} an assertion node
 */
const assertionTree = (t) => {
    if (t.assertion && typeof t.assertion === 'object') {
        return t.assertion;
    }
    return {
        selector: t.selector,
        property: t.assertproperty || 'text',
        matcher: t.assertmatcher || 'equals',
        expected: t.expected,
    };
};

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
            const assertion = assertionTree(t);
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
