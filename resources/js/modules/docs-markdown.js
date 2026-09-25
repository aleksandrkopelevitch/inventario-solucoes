// docs-markdown.js — the conversion between Editor.js blocks and the storage
// format: Markdown plus GitBook-style extended notation.
//
//   serialize(blocks) -> string  (on save)
//   parse(markdown)   -> blocks[] (on load)
//
// Native in Markdown: header, paragraph, list (ordered/unordered/checklist),
// quote, code, delimiter, table, image (<figure>). With no native Markdown,
// through GitBook notation: hint ({% hint %}), tabs ({% tabs %}), file
// ({% file %}) and diagram ({% diagram %} — a citation of a catalog drawing,
// ours).
//
// secret ({% secret %}…{% endsecret %}) is the only INLINE construct: it lives
// inside a block's text rather than as a block of its own, so it is handled in
// inlineToMd()/inlineToHtml() and not in serializeBlock()/parseLines().
//
// Media is referenced as /files/{id} (the files.show route); the image and
// attaches blocks keep `mediaId` so that path can be rebuilt in the Markdown.

import {DEFAULT_HINT_ICON} from './docs-tools/hint-icons'
import {SECRET_CLASS} from './docs-tools/secret-class'

/**
 * A fence's language token (```json, ```c#, ```objective-c), reduced to what
 * can go back into Markdown without breaking it: one lowercase word, no
 * spaces. Anything else becomes the empty string — a fence with no language is
 * a normal state, and a better one than a fence with junk stuck to it.
 *
 * It lives here rather than in the editor's tool because both ends of the
 * round trip have to agree: the one that reads a fence (parse) and the one
 * that writes it (serialize).
 */
export function normalizeLanguage(value) {
    const token = String(value ?? '').trim().toLowerCase()

    return /^[a-z0-9][a-z0-9+#._-]*$/.test(token) ? token : ''
}

/* ============================ inline ============================ */

// Inline HTML (how Editor.js stores rich text) -> inline Markdown.
export function inlineToMd(html) {
    const tpl = document.createElement('template')
    tpl.innerHTML = html ?? ''

    return nodeToMd(tpl.content).replace(/ /g, ' ')
}

function nodeToMd(node) {
    let out = ''
    node.childNodes.forEach((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            out += child.textContent
            return
        }
        if (child.nodeType !== Node.ELEMENT_NODE) return

        const inner = nodeToMd(child)
        switch (child.tagName) {
            case 'B':
            case 'STRONG':
                out += emphasis(inner, '**')
                break
            case 'I':
            case 'EM':
                out += emphasis(inner, '*')
                break
            case 'CODE': {
                // A protected value can live INSIDE the inline code, and the
                // construct has to come out with it: `textContent` flattened
                // `<code><span class="ak-secret-mark">` into `` `[[SECRET-n]]` ``,
                // the marker went back to the server with no `{% secret %}`
                // around it and the real value was written as plain text — the
                // protection vanished on save, with no error at all and with
                // the chip showing correctly in the editor right up to it.
                const marked = child.querySelector(`.${SECRET_CLASS}`)
                out += '`' + (marked ? wrapSecret(marked.textContent) : child.textContent) + '`'
                break
            }
            case 'A':
                out += `[${inner}](${linkDestination(child.getAttribute('href'))})`
                break
            case 'BR':
                out += '  \n'
                break
            case 'MARK':
                out += `<mark>${inner}</mark>`
                break
            case 'SPAN': {
                // The protected value (SecretInlineTool). textContent, not
                // `inner`: the body is a literal value — or the [[SECRET-n]]
                // marker the server hands to somebody who may not read the
                // value — and formatting inside it would mean nothing.
                if (! child.classList.contains(SECRET_CLASS)) {
                    out += inner
                    break
                }

                // Both possible nestings (code inside the secret, secret
                // inside the code) are written the SAME way: the backticks
                // outside, the construct inside. It is the only order
                // GitbookRenderer paints as a padlock within the <code> — and
                // it leaves the value clean, with the backticks never becoming
                // part of it.
                const code = child.querySelector('code')
                out += code
                    ? '`' + wrapSecret(code.textContent) + '`'
                    : wrapSecret(child.textContent)
                break
            }
            case 'U':
                out += `<u>${inner}</u>`
                break
            default:
                out += inner
        }
    })
    return out
}

/* ---------------------------------------------------------------------------
 * The emphasis rules.
 *
 * These have ONE job beyond working: agreeing with the reader. The read-only
 * side (`App\Support\GitbookRenderer`) hands every line to league/commonmark,
 * so CommonMark's delimiter rules are not one opinion about Markdown here —
 * they are what the published page will do. Where these regexes disagreed with
 * it, the editor and the reading screen showed two different documents, and
 * the editor's version is the one that got saved back.
 *
 * Reduced to the three rules that actually decide the common cases:
 *
 *   - a run OPENS only when a non-space follows it and CLOSES only when a
 *     non-space precedes it. `** texto **` is four literal asterisks, which is
 *     what the reader printed while this file was turning it into bold —
 *     and double-clicking a word hands us `<b>texto </b>` in every browser, so
 *     that was the most ordinary way to bold something in a callout;
 *   - `_` never opens or closes INSIDE a word, so `solution_id` stays
 *     `solution_id`. Two of them on one line (`**solution_id** e **user_id**`)
 *     used to produce `<b>solution<i>id</b> … <b>user</i>id</b>` — crossed
 *     tags the browser then repaired into something nobody wrote;
 *   - `**…**` may CONTAIN a lone `*`, so `**bold com *itálico* dentro**` is
 *     bold with italic in it rather than a paragraph with visible asterisks.
 *
 * `***x***` is matched first because the two rules below would otherwise split
 * it into overlapping <b>/<i> tags.
 * ------------------------------------------------------------------------ */
const BOLD_ITALIC = /\*\*\*(?!\s)([\s\S]+?)(?<!\s)\*\*\*/g
const BOLD = /\*\*(?!\s)([\s\S]+?)(?<!\s)\*\*/g
const BOLD_UNDERSCORE = /(^|[^\p{L}\p{N}_])__(?!\s)([\s\S]+?)(?<!\s)__(?![\p{L}\p{N}])/gu
const ITALIC = /(^|[^*])\*(?!\s)([^*\n]+?)(?<!\s)\*/g
const ITALIC_UNDERSCORE = /(^|[^\p{L}\p{N}_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\p{L}\p{N}])/gu

/**
 * An inline link, in either CommonMark spelling of the destination: bare
 * (`[x](/a/b)`, balanced parentheses allowed, which is what a Wikipedia URL
 * needs) or wrapped in angle brackets (`[x](<page:a#b c>)`, which is how a
 * destination carrying a space is written).
 */
const LINK = /\[([^\]]*)\]\((?:<([^<>]*)>|([^()]*(?:\([^()]*\)[^()]*)*))\)/g

// Inline Markdown -> inline HTML (to feed Editor.js's rich text). Existing
// HTML is preserved (<mark>, <u>, <img>…) — only the Markdown syntax is
// transformed.
export function inlineToHtml(md) {
    if (!md) return ''

    // Three things leave the string before any emphasis rule can see them,
    // each standing in as a control character: they are LITERALS, and `*` and
    // `_` are ordinary characters inside them.
    const codes = []
    let text = md.replace(/`([^`]+)`/g, (_, c) => {
        codes.push(c)
        return `\x00${codes.length - 1}\x00`
    })

    // A protected value, for the same reason as the code: the body is a
    // literal. A password holding `*` or `_` would turn into italics in the
    // editor and go back to the Markdown without those characters — the value
    // silently corrupted, in the editor of somebody who CAN read it.
    const secrets = []
    text = text.replace(/\{%\s*secret\s*%\}([\s\S]*?)\{%\s*endsecret\s*%\}/g, (_, value) => {
        secrets.push(value)
        return `\x01${secrets.length - 1}\x01`
    })

    // A link's DESTINATION — never its label, where emphasis is real. `_` and
    // `*` occur constantly in a path or a query string, and the rules below
    // rewrote `…/wiki/a_b_c` as `…/wiki/a<i>b</i>c`: a link that still looked
    // right in the editor and led nowhere.
    const hrefs = []
    text = text.replace(LINK, (_, label, angled, bare) => {
        hrefs.push(angled !== undefined ? angled : (bare ?? ''))

        return `[${label}](\x02${hrefs.length - 1}\x02)`
    })

    // Markdown's hard line break, before the emphasis rules so a run at the
    // end of a line isn't read as closing on whitespace. The hint block is
    // what depends on it — Enter inserts a <br> there (docs-tools/hint.js) and
    // inlineToMd writes it back as two trailing spaces — but a paragraph and a
    // table cell carry a break the same way.
    text = text.replace(/ {2,}\n/g, '<br>')

    text = text
        .replace(BOLD_ITALIC, '<i><b>$1</b></i>')
        .replace(BOLD, '<b>$1</b>')
        .replace(BOLD_UNDERSCORE, '$1<b>$2</b>')
        .replace(ITALIC, '$1<i>$2</i>')
        .replace(ITALIC_UNDERSCORE, '$1<i>$2</i>')

    text = text.replace(
        /\[([^\]]*)\]\(\x02(\d+)\x02\)/g,
        (_, label, i) => `<a href="${escapeHtmlAttr(hrefs[i])}">${label}</a>`,
    )

    // A destination whose link didn't survive the pass above (a `]` inside the
    // label, say) goes back as it came rather than leaving a control character
    // in the author's text.
    text = text.replace(/\x02(\d+)\x02/g, (_, i) => hrefs[i])

    text = text.replace(
        /\x01(\d+)\x01/g,
        (_, i) => `<span class="${SECRET_CLASS}">${escapeHtml(secrets[i])}</span>`,
    )

    return text.replace(/\x00(\d+)\x00/g, (_, i) => `<code>${codeToHtml(codes[i])}</code>`)
}

/**
 * `<b>`/`<i>` becoming `**…**` / `*…*`, with the tag's own whitespace moved
 * OUTSIDE the delimiters.
 *
 * It is the serializing half of the flanking rule described above: a run does
 * not close after a space, so `<b>Atenção </b>` written literally as
 * `**Atenção **` published four asterisks instead of bold. Every browser hands
 * us that trailing space when a word is selected by double-click, so this is
 * the ordinary case rather than the odd one.
 *
 * Emphasis around nothing but whitespace is dropped entirely — `****` is not
 * empty bold in any reader, it is four asterisks.
 */
function emphasis(inner, marker) {
    const [, lead, body, trail] = String(inner).match(/^(\s*)([\s\S]*?)(\s*)$/)

    return body === '' ? lead + trail : `${lead}${marker}${body}${marker}${trail}`
}

/**
 * The destination of an inline link, as it goes into the Markdown.
 *
 * Angle brackets are CommonMark's way of saying "all of this is the address",
 * and they are needed for whitespace and for a parenthesis the parser cannot
 * pair off: `[x](/a (b)` ends at the first `)` in every reader. A destination
 * that is already balanced is left bare, which is the spelling the whole
 * corpus (and the GitBook import) already has.
 */
function linkDestination(href) {
    const url = String(href ?? '').replace(/</g, '%3C').replace(/>/g, '%3E')

    return /\s/.test(url) || ! balancedParens(url) ? `<${url}>` : url
}

function balancedParens(value) {
    let depth = 0

    for (const char of value) {
        if (char === '(') depth++
        else if (char === ')' && --depth < 0) return false
    }

    return depth === 0
}

/** The construct, written in one place — both sides of the round trip use it. */
function wrapSecret(value) {
    return `{% secret %}${value}{% endsecret %}`
}

/**
 * The contents of an inline code span, becoming the editor's HTML.
 *
 * A `{% secret %}` inside backticks has to become a CHIP rather than raw text:
 * without that the author sees `{% secret %}[[SECRET-1]]{% endsecret %}` in the
 * middle of the code, has nothing to click to reveal it, and any touch-up in
 * there breaks the construct by hand.
 */
function codeToHtml(code) {
    const match = code.match(/^\s*\{%\s*secret\s*%\}([\s\S]*?)\{%\s*endsecret\s*%\}\s*$/)

    return match
        ? `<span class="${SECRET_CLASS}">${escapeHtml(match[1])}</span>`
        : escapeHtml(code)
}

function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

/** For a value going into a double-quoted HTML attribute (an href, here). */
function escapeHtmlAttr(s) {
    return escapeHtml(String(s ?? '')).replace(/"/g, '&quot;')
}

/* ============================ serialize ============================ */

export function serialize(blocks) {
    if (!Array.isArray(blocks)) return ''

    return blocks
        .map((block) => serializeBlock(block))
        .filter((chunk) => chunk !== null && chunk !== undefined)
        .join('\n\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim() + '\n'
}

function serializeBlock(block) {
    const d = block.data || {}
    switch (block.type) {
        case 'header':
            return `${'#'.repeat(Math.min(6, Math.max(1, d.level || 2)))} ${inlineToMd(d.text)}`
        case 'paragraph':
            return inlineToMd(d.text)
        case 'quote':
            return inlineToMd(d.text)
                .split('\n')
                .map((l) => `> ${l}`)
                .join('\n')
        case 'code':
            return '```' + normalizeLanguage(d.language) + '\n' + (d.code || '') + '\n```'
        case 'delimiter':
            return '***'
        case 'list':
            return serializeList(d.items || [], d.style || 'unordered', 0)
        case 'table':
            return serializeTable(d)
        case 'image':
            return serializeImage(d)
        case 'attaches':
            return `{% file src="${fileSrc(d.file)}" %}`
        case 'diagram':
            // A block with no diagram chosen serializes to nothing rather than
            // an empty citation the renderer would have to apologise for.
            return d.slug ? `{% diagram slug="${escapeAttr(d.slug)}" %}` : ''
        case 'embed':
            return `{% embed url="${escapeAttr(d.source || d.embed || '')}" %}`
        case 'hint':
            return serializeHint(d)
        case 'tabs':
            return serializeTabs(d)
        default:
            return null
    }
}

function serializeList(items, style, level) {
    const pad = '  '.repeat(level)
    let counter = 1

    return items
        .map((item) => {
            let marker
            if (style === 'ordered') marker = `${counter++}.`
            else if (style === 'checklist') marker = item.meta && item.meta.checked ? '- [x]' : '- [ ]'
            else marker = '-'

            let line = `${pad}${marker} ${inlineToMd(item.content)}`
            if (Array.isArray(item.items) && item.items.length) {
                line += '\n' + serializeList(item.items, style, level + 1)
            }
            return line
        })
        .join('\n')
}

function serializeTable(d) {
    const rows = d.content || []
    if (!rows.length) return ''

    const cell = (c) => inlineToMd(c).replace(/\|/g, '\\|').replace(/\n/g, ' ').trim()
    const cols = Math.max(...rows.map((r) => r.length))
    const withHeadings = d.withHeadings !== false

    const out = []
    rows.forEach((row, idx) => {
        const cells = []
        for (let i = 0; i < cols; i++) cells.push(cell(row[i] || ''))
        out.push(`| ${cells.join(' | ')} |`)
        if (idx === 0 && withHeadings) {
            out.push(`| ${cells.map(() => '---').join(' | ')} |`)
        }
    })
    // A headerless table still needs the separator row after the first line.
    if (!withHeadings && rows.length) {
        out.splice(1, 0, `| ${Array.from({length: cols}, () => '---').join(' | ')} |`)
    }
    return out.join('\n')
}

// Preset widths, persisted as `data-width` on the <figure>. 100% is the
// default and writes no attribute (compatible with older docs, which had no
// data-width at all).
const FIGURE_WIDTHS = [25, 50, 75]

function serializeImage(d) {
    const src = fileSrc(d.file)
    const alt = escapeAttr(d.caption || '')
    const cap = inlineToMd(d.caption || '')
    const w = Number(d.width)
    const attr = FIGURE_WIDTHS.includes(w) ? ` data-width="${w}"` : ''
    return `<figure${attr}><img src="${src}" alt="${alt}"><figcaption>${cap}</figcaption></figure>`
}

function serializeHint(d) {
    const style = d.style || 'info'
    const text = inlineToMd(d.text).trim()
    // `icon` is written only when it differs from the style's default — it
    // keeps the notation clean and content already saved (with no icon)
    // backward compatible.
    const icon = (d.icon || '').trim()
    const iconAttr = icon && icon !== DEFAULT_HINT_ICON[style] ? ` icon="${escapeAttr(icon)}"` : ''
    return `{% hint style="${style}"${iconAttr} %}\n${text}\n{% endhint %}`
}

// key="value" pairs of a GitBook-notation attribute string (free order).
function parseAttrs(raw) {
    const attrs = {}
    const re = /(\w+)="([^"]*)"/g
    let m
    while ((m = re.exec(raw)) !== null) attrs[m[1]] = m[2]
    return attrs
}

function serializeTabs(d) {
    const items = d.items || []
    const inner = items
        .map((t) => {
            // Each tab holds nested blocks (Editor.js) — serialize recursively.
            // Compat: older tabs held `content` (raw Markdown).
            const body = Array.isArray(t.blocks) ? serialize(t.blocks).trim() : (t.content || '').trim()
            return `{% tab title="${escapeAttr(t.title || '')}" %}\n${body}\n{% endtab %}`
        })
        .join('\n')
    return `{% tabs %}\n${inner}\n{% endtabs %}`
}

function fileSrc(file) {
    if (!file) return ''
    if (file.mediaId) return `/files/${file.mediaId}`
    return file.url || ''
}

function escapeAttr(s) {
    return String(s).replace(/"/g, '&quot;')
}

/* ============================ parse ============================ */

export function parse(markdown) {
    if (!markdown || !markdown.trim()) return []
    const lines = markdown.replace(/\r\n?/g, '\n').split('\n')
    return parseLines(lines)
}

function parseLines(lines) {
    const blocks = []
    let i = 0
    const n = lines.length

    while (i < n) {
        const line = lines[i]
        const trimmed = line.trim()

        if (trimmed === '') { i++; continue }

        // code fence
        let m = trimmed.match(/^(```|~~~)(.*)$/)
        if (m) {
            const fence = m[1]
            const code = []
            i++
            while (i < n && !lines[i].trim().startsWith(fence)) { code.push(lines[i]); i++ }
            if (i < n) i++
            // The fence's language token is CARRIED, not dropped. It used to be
            // matched and thrown away here while the serializer below always
            // wrote a bare ```, so every save quietly rewrote ```xml as ``` —
            // opening a page in the editor and pressing nothing but Salvar was
            // enough to strip the language off every block in it. Harmless
            // while nothing read it; since the reader highlights syntax
            // (docs-highlight.js) it is the difference between a colored block
            // and a grey one, and the label the panel header shows.
            blocks.push({type: 'code', data: {code: code.join('\n'), language: normalizeLanguage(m[2])}})
            continue
        }

        // hint
        m = trimmed.match(/^\{%\s*hint\s+(.*?)\s*%\}$/)
        if (m) {
            const attrs = parseAttrs(m[1])
            const [inner, next] = consumeUntil(lines, i + 1, 'hint')
            i = next
            blocks.push({
                type: 'hint',
                data: {style: attrs.style || 'info', icon: attrs.icon || '', text: inlineToHtml(inner.join('\n').trim())},
            })
            continue
        }

        // tabs
        if (/^\{%\s*tabs\s*%\}$/.test(trimmed)) {
            const [inner, next] = consumeUntil(lines, i + 1, 'tabs')
            i = next
            blocks.push({type: 'tabs', data: {items: parseTabs(inner)}})
            continue
        }

        // diagram — a citation of a drawing from the catalog. Only the slug
        // is stored; the name and the picture are resolved when rendered.
        m = trimmed.match(/^\{%\s*diagram\s+slug="([^"]*)"\s*%\}$/)
        if (m) {
            blocks.push({type: 'diagram', data: {slug: m[1]}})
            i++
            continue
        }

        // file
        m = trimmed.match(/^\{%\s*file\s+src="([^"]*)"\s*%\}$/)
        if (m) {
            blocks.push({type: 'attaches', data: {file: fileFromSrc(m[1]), title: ''}})
            i++
            continue
        }

        // embed (YouTube/Vimeo/Figma)
        m = trimmed.match(/^\{%\s*embed\s+url="([^"]*)"\s*%\}$/)
        if (m) {
            blocks.push(embedBlock(decodeAttr(m[1])))
            i++
            continue
        }

        // figure / img (bloco de imagem)
        if (/^<figure/.test(trimmed) || /^<img\s/i.test(trimmed)) {
            blocks.push(imageBlock(trimmed))
            i++
            continue
        }

        // heading
        m = trimmed.match(/^(#{1,6})\s+(.*)$/)
        if (m) {
            blocks.push({type: 'header', data: {text: inlineToHtml(m[2].trim()), level: m[1].length}})
            i++
            continue
        }

        // divider
        if (/^(\*\*\*+|---+|___+)$/.test(trimmed)) {
            blocks.push({type: 'delimiter', data: {}})
            i++
            continue
        }

        // quote
        if (/^>\s?/.test(trimmed)) {
            const buf = []
            while (i < n && /^>\s?/.test(lines[i].trim())) {
                buf.push(lines[i].trim().replace(/^>\s?/, ''))
                i++
            }
            blocks.push({type: 'quote', data: {text: inlineToHtml(buf.join('\n')), caption: '', alignment: 'left'}})
            continue
        }

        // table
        if (isTableRow(trimmed) && i + 1 < n && isTableSeparator(lines[i + 1].trim())) {
            const [table, next] = parseTable(lines, i)
            i = next
            blocks.push(table)
            continue
        }

        // list
        if (isListItem(line)) {
            const [list, next] = parseList(lines, i)
            i = next
            blocks.push(list)
            continue
        }

        // paragraph — joins lines until a blank one or the start of another block
        const para = []
        while (i < n && lines[i].trim() !== '' && !startsNewBlock(lines[i])) {
            // Trimmed, EXCEPT for the two trailing spaces that are Markdown's
            // hard line break: trimming the line whole turned every <br> a
            // paragraph carried into a plain space on the way back into the
            // editor, so the break survived one save and no more.
            para.push(/ {2,}$/.test(lines[i]) ? lines[i].trim() + '  ' : lines[i].trim())
            i++
        }
        blocks.push({type: 'paragraph', data: {text: inlineToHtml(para.join('\n'))}})
    }

    return blocks
}

// A line that, in the middle of a paragraph, signals the start of another block.
function startsNewBlock(line) {
    const t = line.trim()
    return (
        /^(#{1,6})\s+/.test(t) ||
        /^(```|~~~)/.test(t) ||
        /^\{%\s*(hint|tabs|file|embed|diagram)/.test(t) ||
        /^<figure/.test(t) ||
        /^<img\s/i.test(t) ||
        /^(\*\*\*+|---+|___+)$/.test(t) ||
        /^>\s?/.test(t) ||
        isListItem(line)
    )
}

function consumeUntil(lines, i, type) {
    const open = new RegExp(`^\\{%\\s*${type}(\\s|%)`)
    const close = new RegExp(`^\\{%\\s*end${type}\\s*%\\}$`)
    let depth = 1
    const inner = []
    while (i < lines.length) {
        const t = lines[i].trim()
        if (open.test(t)) depth++
        else if (close.test(t)) { depth--; if (depth === 0) { i++; break } }
        inner.push(lines[i])
        i++
    }
    return [inner, i]
}

function parseTabs(lines) {
    const items = []
    let i = 0
    while (i < lines.length) {
        const m = lines[i].trim().match(/^\{%\s*tab\s+title="([^"]*)"\s*%\}$/)
        if (m) {
            const [inner, next] = consumeUntil(lines, i + 1, 'tab')
            i = next
            // The tab's content becomes nested blocks (recursive parse).
            items.push({title: decodeAttr(m[1]), blocks: parseLines(inner)})
            continue
        }
        i++
    }
    return items.length ? items : [{title: 'Aba 1', blocks: []}]
}

function isListItem(line) {
    return /^\s*([-*+]|\d+\.)\s+/.test(line)
}

function parseList(lines, start) {
    // Collects the list's contiguous lines.
    const raw = []
    let i = start
    while (i < lines.length && (isListItem(lines[i]) || (lines[i].trim() !== '' && /^\s{2,}/.test(lines[i]) && raw.length))) {
        raw.push(lines[i])
        i++
    }

    let style = 'unordered'
    const first = raw[0].match(/^\s*([-*+]|\d+\.)\s+(\[[ xX]\]\s+)?/)
    if (first && /\d+\./.test(first[1])) style = 'ordered'
    if (raw.some((l) => /^\s*[-*+]\s+\[[ xX]\]/.test(l))) style = 'checklist'

    // Builds the tree by indentation (every 2 spaces = 1 level).
    const root = []
    const stack = [{level: -1, items: root}]

    raw.forEach((l) => {
        const m = l.match(/^(\s*)([-*+]|\d+\.)\s+(.*)$/)
        if (!m) return
        const level = Math.floor(m[1].length / 2)
        let content = m[3]
        const meta = {}
        const chk = content.match(/^\[([ xX])\]\s+(.*)$/)
        if (chk) { meta.checked = chk[1].toLowerCase() === 'x'; content = chk[2] }

        const item = {content: inlineToHtml(content), meta, items: []}

        while (stack.length > 1 && stack[stack.length - 1].level >= level) stack.pop()
        stack[stack.length - 1].items.push(item)
        stack.push({level, items: item.items})
    })

    return [{type: 'list', data: {style, items: root}}, i]
}

function isTableRow(t) { return t.startsWith('|') && t.includes('|', 1) }
function isTableSeparator(t) { return /^\|?[\s:|-]+\|?$/.test(t) && t.includes('-') }

function splitRow(t) {
    return t
        .replace(/^\|/, '')
        .replace(/\|$/, '')
        .split(/(?<!\\)\|/)
        .map((c) => inlineToHtml(c.replace(/\\\|/g, '|').trim()))
}

function parseTable(lines, start) {
    const content = []
    let i = start
    const header = splitRow(lines[i].trim())
    content.push(header)
    i += 2 // skips the separator row
    while (i < lines.length && isTableRow(lines[i].trim())) {
        content.push(splitRow(lines[i].trim()))
        i++
    }
    return [{type: 'table', data: {withHeadings: true, content}}, i]
}

function imageBlock(html) {
    const tpl = document.createElement('template')
    tpl.innerHTML = html
    const img = tpl.content.querySelector('img')
    const cap = tpl.content.querySelector('figcaption')
    const fig = tpl.content.querySelector('figure')
    const src = img ? img.getAttribute('src') || '' : ''
    const w = fig ? Number(fig.getAttribute('data-width')) : NaN
    return {
        type: 'image',
        data: {
            file: fileFromSrc(src),
            caption: cap ? cap.textContent : (img ? img.getAttribute('alt') || '' : ''),
            width: FIGURE_WIDTHS.includes(w) ? w : 100,
            withBorder: false,
            stretched: false,
            withBackground: false,
        },
    }
}

function fileFromSrc(src) {
    const m = String(src).match(/\/files\/(\d+)/)
    return m ? {url: src, mediaId: Number(m[1])} : {url: src}
}

// Derives service/embed from the URL (YouTube, Vimeo, Figma). Keep in sync
// com App\Support\GitbookRenderer::embedData() (render read-only).
export function embedData(url) {
    let m
    if ((m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([\w-]+)/))) {
        return {service: 'youtube', source: url, embed: `https://www.youtube.com/embed/${m[1]}`, width: 580, height: 320}
    }
    if ((m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/))) {
        return {service: 'vimeo', source: url, embed: `https://player.vimeo.com/video/${m[1]}`, width: 580, height: 320}
    }
    if (/figma\.com\/(file|proto|design|board)\//.test(url)) {
        return {service: 'figma', source: url, embed: `https://www.figma.com/embed?embed_host=share&url=${encodeURIComponent(url)}`, width: 580, height: 420}
    }
    return null
}

function embedBlock(url) {
    const e = embedData(url)
    if (e) return {type: 'embed', data: {...e, caption: ''}}
    // Unsupported service: falls back to a link (degrades without breaking).
    return {type: 'paragraph', data: {text: `<a href="${escapeHtml(url)}">${escapeHtml(url)}</a>`}}
}

function decodeAttr(s) {
    return String(s).replace(/&quot;/g, '"').replace(/&amp;/g, '&')
}
