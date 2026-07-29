// docs-diff.js — client-side unified diff between two Markdown strings, used
// by the Documentation Assistant's review modal (docs-chat.js) to preview what
// a proposed draft changes before it replaces the editor content. No dependency: a small
// LCS over lines, with word-level refinement on the lines that changed.
//
// Nothing here is persisted — it only builds the read-only diff HTML shown in
// the review modal. Colors follow the theme tokens (crit = removed,
// accent = added), same convention as the app's semantic badges.

const MAX_CELLS = 4_000_000 // LCS is O(m·n) space; bail to a plain block diff above this.

// Only the three characters that can break out of a TEXT position, because that
// is the only place these strings are ever interpolated (never into an attribute
// value — the attributes in row() below are all literals). Keep it that way, or
// this needs quotes escaped too.
function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
}

// LCS-based diff over a sequence of comparable items (lines, or word tokens).
// Returns ops: {type: 'equal'|'del'|'ins', value}. Ties break towards 'del'
// so a replaced region reads as removals followed by additions.
function diffSequence(a, b) {
    const m = a.length
    const n = b.length
    // Flat Int32Array rather than an array-of-arrays: 4 bytes per cell instead
    // of ~8, and one allocation instead of m+1 of them — which is what keeps
    // MAX_CELLS affordable (16MB at the ceiling) on the main thread. Values are
    // bounded by min(m, n), so 32 bits is plenty.
    const stride = n + 1
    const dp = new Int32Array((m + 1) * stride)
    for (let i = m - 1; i >= 0; i--) {
        for (let j = n - 1; j >= 0; j--) {
            dp[i * stride + j] = a[i] === b[j]
                ? dp[(i + 1) * stride + j + 1] + 1
                : Math.max(dp[(i + 1) * stride + j], dp[i * stride + j + 1])
        }
    }

    const ops = []
    let i = 0
    let j = 0
    while (i < m && j < n) {
        if (a[i] === b[j]) {
            ops.push({type: 'equal', value: a[i]})
            i++
            j++
        } else if (dp[(i + 1) * stride + j] >= dp[i * stride + j + 1]) {
            ops.push({type: 'del', value: a[i]})
            i++
        } else {
            ops.push({type: 'ins', value: b[j]})
            j++
        }
    }
    while (i < m) ops.push({type: 'del', value: a[i++]})
    while (j < n) ops.push({type: 'ins', value: b[j++]})
    return ops
}

// Refines a changed line pair to highlight only the words that differ, keeping
// the shared words un-highlighted on both sides. Splitting on whitespace groups
// (kept as tokens) preserves spacing when re-joined.
function wordDiff(before, after) {
    const ops = diffSequence(before.split(/(\s+)/), after.split(/(\s+)/))
    let del = ''
    let ins = ''
    ops.forEach((op) => {
        const esc = escapeHtml(op.value)
        if (op.type === 'equal') {
            del += esc
            ins += esc
        } else if (op.type === 'del') {
            del += `<span class="rounded-sm bg-crit-line px-0.5 text-crit">${esc}</span>`
        } else {
            ins += `<span class="rounded-sm bg-accent-line px-0.5 text-accent">${esc}</span>`
        }
    })
    return [del, ins]
}

function row(kind, marker, markerClass, bgClass, html) {
    return (
        `<div class="flex gap-3 px-3 py-0.5 ${bgClass}" data-diff-row="${kind}">` +
        `<span class="w-3 shrink-0 select-none text-right ${markerClass}">${marker}</span>` +
        `<span class="min-w-0 flex-1 whitespace-pre-wrap break-words">${html || '​'}</span>` +
        '</div>'
    )
}

const equalRow = (text) => row('equal', '', 'text-faint', '', `<span class="text-muted">${escapeHtml(text)}</span>`)
const delRow = (html) => row('del', '−', 'text-crit', 'bg-crit-soft', `<span class="text-ink">${html}</span>`)
const insRow = (html) => row('ins', '+', 'text-accent', 'bg-accent-soft', `<span class="text-ink">${html}</span>`)

// Walks the line ops, pairing each run of removals with the following run of
// additions so those pairs get word-level refinement; unpaired leftovers on
// either side render as whole removed/added lines.
function renderRows(ops) {
    const rows = []
    let k = 0
    while (k < ops.length) {
        if (ops[k].type === 'equal') {
            rows.push(equalRow(ops[k].value))
            k++
            continue
        }

        const dels = []
        const ins = []
        while (k < ops.length && ops[k].type === 'del') dels.push(ops[k++].value)
        while (k < ops.length && ops[k].type === 'ins') ins.push(ops[k++].value)

        const paired = Math.min(dels.length, ins.length)
        for (let p = 0; p < paired; p++) {
            const [del, add] = wordDiff(dels[p], ins[p])
            rows.push(delRow(del))
            rows.push(insRow(add))
        }
        for (let p = paired; p < dels.length; p++) rows.push(delRow(escapeHtml(dels[p])))
        for (let p = paired; p < ins.length; p++) rows.push(insRow(escapeHtml(ins[p])))
    }
    return rows.join('')
}

/**
 * Builds the diff between two Markdown strings.
 *
 * @returns {{hasChanges: boolean, html: string}} `html` is the rendered rows
 *   (empty when there are no changes — the caller shows its own empty state).
 */
export function renderMarkdownDiff(before, after) {
    const a = String(before ?? '').replace(/\r\n/g, '\n').split('\n')
    const b = String(after ?? '').replace(/\r\n/g, '\n').split('\n')

    if (a.length * b.length > MAX_CELLS) {
        // Too large for the LCS matrix — fall back to "everything removed, then
        // everything added" so the preview still renders (rare: huge pages).
        const html = a.map((l) => delRow(escapeHtml(l))).join('') + b.map((l) => insRow(escapeHtml(l))).join('')
        return {hasChanges: true, html}
    }

    const ops = diffSequence(a, b)
    const hasChanges = ops.some((op) => op.type !== 'equal')
    return {hasChanges, html: hasChanges ? renderRows(ops) : ''}
}
