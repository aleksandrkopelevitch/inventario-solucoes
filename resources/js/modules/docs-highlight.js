// docs-highlight.js — syntax highlighting for read-only documentation code
// blocks. Deliberately NOT a globalModules entry: it is a helper that
// docs-code.js pulls in with a dynamic `import()` only when the page actually
// has a `<pre>`, so Vite emits it as its own chunk and a page with no code
// never downloads highlight.js at all.
//
// Only the languages this corpus uses are registered (the full `lib/common`
// bundle carries ~40). Registering one also registers its aliases — `html`
// resolves through `xml`, `yml` through `yaml`, `js` through `javascript` —
// so the alias table below is only about the LABEL shown in the panel header.
//
// The colors live in app.css (`--mk-*` + `.hljs-*`), not here.

import hljs from 'highlight.js/lib/core'

import bash from 'highlight.js/lib/languages/bash'
import csharp from 'highlight.js/lib/languages/csharp'
import diff from 'highlight.js/lib/languages/diff'
import dockerfile from 'highlight.js/lib/languages/dockerfile'
import http from 'highlight.js/lib/languages/http'
import ini from 'highlight.js/lib/languages/ini'
import java from 'highlight.js/lib/languages/java'
import javascript from 'highlight.js/lib/languages/javascript'
import json from 'highlight.js/lib/languages/json'
import markdown from 'highlight.js/lib/languages/markdown'
import php from 'highlight.js/lib/languages/php'
import python from 'highlight.js/lib/languages/python'
import sql from 'highlight.js/lib/languages/sql'
import typescript from 'highlight.js/lib/languages/typescript'
import xml from 'highlight.js/lib/languages/xml'
import yaml from 'highlight.js/lib/languages/yaml'

hljs.registerLanguage('bash', bash)
hljs.registerLanguage('csharp', csharp)
hljs.registerLanguage('diff', diff)
hljs.registerLanguage('dockerfile', dockerfile)
hljs.registerLanguage('http', http)
hljs.registerLanguage('ini', ini)
hljs.registerLanguage('java', java)
hljs.registerLanguage('javascript', javascript)
hljs.registerLanguage('json', json)
hljs.registerLanguage('markdown', markdown)
hljs.registerLanguage('php', php)
hljs.registerLanguage('python', python)
hljs.registerLanguage('sql', sql)
hljs.registerLanguage('typescript', typescript)
hljs.registerLanguage('xml', xml)
hljs.registerLanguage('yaml', yaml)

// A fence with NO language still gets detection, but from a deliberately tiny
// subset, and only above a threshold. Both numbers were measured, not guessed:
// every unlabeled block in the dev corpus (35 of them) was run through
// `highlightAuto` while tuning this.
//
// The subset is THREE languages because greedy grammars are what produce
// nonsense here — `bash` and `php` claimed a bare Azure DevOps URL at
// relevance 22 and 14, and `sql` claimed a Markdown table at 13. Dropping
// them cost nothing: every SQL block in the corpus is fenced ```sql, and a
// declared language never goes through this path at all.
const AUTO_SUBSET = ['xml', 'json', 'yaml']

// Below this, the best guess is noise rather than a reading of the block — a
// URL, a file path, a diagram drawn in box characters, three words of output.
// At 10 the four real SOAP envelopes (150/110/13) and the two real JSON bodies
// (132/61) are painted and everything else in the corpus is left alone.
const AUTO_MIN_RELEVANCE = 10

// The panel header names the language only when the AUTHOR declared it — a
// detected one would be us guessing out loud on their behalf. Keys are the
// fence tokens people actually write, not highlight.js's internal names.
const LABELS = {
    bash: 'Bash',
    cs: 'C#',
    csharp: 'C#',
    css: 'CSS',
    diff: 'Diff',
    dockerfile: 'Dockerfile',
    groovy: 'Groovy',
    html: 'HTML',
    http: 'HTTP',
    ini: 'INI',
    java: 'Java',
    javascript: 'JavaScript',
    js: 'JavaScript',
    json: 'JSON',
    markdown: 'Markdown',
    md: 'Markdown',
    php: 'PHP',
    properties: 'Properties',
    py: 'Python',
    python: 'Python',
    sh: 'Shell',
    shell: 'Shell',
    sql: 'SQL',
    ts: 'TypeScript',
    typescript: 'TypeScript',
    xml: 'XML',
    yaml: 'YAML',
    yml: 'YAML',
}

/** The fence token the author wrote, e.g. `language-json` → `json`. */
function declaredLanguage(code) {
    const match = /(?:^|\s)(?:language|lang)-([\w#+.-]+)/i.exec(code.className || '')

    return match ? match[1].toLowerCase() : null
}

/**
 * Paints one `<pre>`'s `<code>` and returns the label its panel header should
 * show — the declared language's display name, or null when the author didn't
 * declare one (whether or not detection found something).
 *
 * @param {HTMLElement} pre
 * @returns {string|null}
 */
export function highlightBlock(pre) {
    const code = pre.querySelector('code') ?? pre
    const source = code.textContent ?? ''
    if (source.trim() === '') return null

    const declared = declaredLanguage(code)

    if (declared && hljs.getLanguage(declared)) {
        code.innerHTML = hljs.highlight(source, {language: declared, ignoreIllegals: true}).value
        code.classList.add('hljs')

        return LABELS[declared] ?? declared.toUpperCase()
    }

    // Unlabeled (or labeled with something we don't ship a grammar for).
    const guess = hljs.highlightAuto(source, AUTO_SUBSET)
    if (guess.relevance >= AUTO_MIN_RELEVANCE) {
        code.innerHTML = guess.value
        code.classList.add('hljs')
    }

    return declared ? (LABELS[declared] ?? declared.toUpperCase()) : null
}
