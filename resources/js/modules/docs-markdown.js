// docs-markdown.js — conversão entre os blocos do Editor.js e o formato de
// armazenamento: Markdown + notação estendida estilo GitBook.
//
//   serialize(blocks) -> string  (ao salvar)
//   parse(markdown)   -> blocks[] (ao carregar)
//
// Nativos em Markdown: header, paragraph, list (ordered/unordered/checklist),
// quote, code, delimiter, table, image (<figure>). Sem Markdown nativo, via
// notação GitBook: hint ({% hint %}), tabs ({% tabs %}), file ({% file %})
// e diagram ({% diagram %} — citação de um desenho do catálogo, nossa).
//
// A mídia é referenciada por /files/{id} (rota files.show); os blocos image/
// attaches guardam `mediaId` para reconstruir esse caminho no Markdown.

import {DEFAULT_HINT_ICON} from './docs-tools/hint-icons'

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

// HTML inline (como o Editor.js guarda o texto rico) -> Markdown inline.
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
                out += `**${inner}**`
                break
            case 'I':
            case 'EM':
                out += `*${inner}*`
                break
            case 'CODE':
                out += '`' + child.textContent + '`'
                break
            case 'A':
                out += `[${inner}](${child.getAttribute('href') || ''})`
                break
            case 'BR':
                out += '  \n'
                break
            case 'MARK':
                out += `<mark>${inner}</mark>`
                break
            case 'U':
                out += `<u>${inner}</u>`
                break
            default:
                out += inner
        }
    })
    return out
}

// Markdown inline -> HTML inline (para alimentar o texto rico do Editor.js).
// Preserva HTML já existente (<mark>, <u>, <img>...) — só transforma a sintaxe
// Markdown. Protege os trechos de código para não formatar dentro deles.
export function inlineToHtml(md) {
    if (!md) return ''

    const codes = []
    let text = md.replace(/`([^`]+)`/g, (_, c) => {
        codes.push(c)
        return `\x00${codes.length - 1}\x00`
    })

    text = text
        .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
        .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<i>$2</i>')
        .replace(/(^|[^_])_([^_\n]+)_/g, '$1<i>$2</i>')
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>')

    return text.replace(/\x00(\d+)\x00/g, (_, i) => `<code>${escapeHtml(codes[i])}</code>`)
}

function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
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
    // Tabela sem cabeçalho ainda precisa da linha separadora após a 1ª linha.
    if (!withHeadings && rows.length) {
        out.splice(1, 0, `| ${Array.from({length: cols}, () => '---').join(' | ')} |`)
    }
    return out.join('\n')
}

// Larguras-preset persistidas em `data-width` no <figure>. 100% é o padrão e
// não escreve atributo (compat com docs antigos, que não tinham data-width).
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
    // `icon` só é gravado quando difere do padrão do estilo — mantém a notação
    // limpa e o conteúdo já salvo (sem icon) retrocompatível.
    const icon = (d.icon || '').trim()
    const iconAttr = icon && icon !== DEFAULT_HINT_ICON[style] ? ` icon="${escapeAttr(icon)}"` : ''
    return `{% hint style="${style}"${iconAttr} %}\n${text}\n{% endhint %}`
}

// Pares chave="valor" de uma string de atributos da notação GitBook (ordem livre).
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
            // Cada aba guarda blocos aninhados (Editor.js) — serializa recursivo.
            // Compat: abas antigas guardavam `content` (Markdown cru).
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

        // paragraph — junta linhas até uma linha em branco ou início de outro bloco
        const para = []
        while (i < n && lines[i].trim() !== '' && !startsNewBlock(lines[i])) {
            para.push(lines[i].trim())
            i++
        }
        blocks.push({type: 'paragraph', data: {text: inlineToHtml(para.join('\n'))}})
    }

    return blocks
}

// Uma linha que, no meio de um parágrafo, sinaliza o começo de outro bloco.
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
            // Conteúdo da aba vira blocos aninhados (parse recursivo).
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
    // Coleta as linhas contíguas da lista.
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

    // Constrói a árvore por indentação (cada 2 espaços = 1 nível).
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
    i += 2 // pula a linha separadora
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

// Deriva service/embed a partir da URL (YouTube, Vimeo, Figma). Mantém em sincronia
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
    // Serviço não suportado: cai para um link (degrada sem quebrar).
    return {type: 'paragraph', data: {text: `<a href="${escapeHtml(url)}">${escapeHtml(url)}</a>`}}
}

function decodeAttr(s) {
    return String(s).replace(/&quot;/g, '"').replace(/&amp;/g, '&')
}
