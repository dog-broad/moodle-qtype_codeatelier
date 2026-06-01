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
 * CodeMirror 6 editor wrapper for qtype_codeatelier.
 * Lazy-loads CodeMirror on demand to avoid bloating Moodle's AMD minifier.
 *
 * @module qtype_codeatelier/editor
 */

define([], function() {
  'use strict';

  /**
   * Initialize a CodeMirror 6 editor in the given DOM element.
   *
   * @param {string} elementId - ID of the container element for the editor
   * @param {string} language - Language for syntax highlighting ('python', 'sql', 'javascript', etc.)
   * @param {string} initialValue - Initial code content
   * @returns {Promise<Object>} Promise resolving to editor instance with getValue() and focus() methods
   */
  const init = async function(elementId, language, initialValue) {
    const domElement = document.getElementById(elementId);
    if (!domElement) {
      console.error('Editor container not found:', elementId);
      return null;
    }

    try {
      // Lazy-load CodeMirror 6 bundles from CDN.
      // Using esm.sh for ES module distribution.
      const cmModule = await import('https://esm.sh/codemirror@6.0.1');
      const { EditorState, EditorView } = cmModule;

      // Language extensions for syntax highlighting.
      const languageModules = {
        python: () => import('https://esm.sh/@codemirror/lang-python@6.0.0'),
        sql: () => import('https://esm.sh/@codemirror/lang-sql@6.0.0'),
        javascript: () => import('https://esm.sh/@codemirror/lang-javascript@6.0.0'),
      };

      // Load language extension if available.
      let languageExtension = null;
      if (languageModules[language]) {
        try {
          const langModule = await languageModules[language]();
          const langName = language.charAt(0).toUpperCase() + language.slice(1);
          if (langModule[langName]) {
            languageExtension = langModule[langName]();
          }
        } catch (e) {
          console.warn('CodeMirror language extension not loaded for:', language, e);
        }
      }

      // Create editor state with language extension.
      const extensions = languageExtension ? [languageExtension] : [];
      const state = EditorState.create({
        doc: initialValue || '',
        extensions: [
          ...extensions,
          EditorView.lineNumbers(),
        ],
      });

      // Create the editor view and mount it.
      const view = new EditorView({
        state,
        parent: domElement,
      });

      // Store the view for potential later access.
      domElement._codeatelier_editor = {
        getValue: () => view.state.doc.toString(),
        setValue: (code) => {
          view.dispatch({
            changes: {
              from: 0,
              to: view.state.doc.length,
              insert: code,
            },
          });
        },
        focus: () => view.focus(),
        destroy: () => view.destroy(),
      };

      return domElement._codeatelier_editor;
    } catch (error) {
      console.error('Failed to initialize CodeMirror editor:', error);
      domElement.textContent = 'Failed to load editor. See console for details.';
      return null;
    }
  };

  return init;
});
