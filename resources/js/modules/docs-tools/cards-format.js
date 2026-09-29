// The shape of the "Cards" block — the values both ends of the round trip have
// to agree on. Leaf module with no imports, for the same reason
// `hint-icons.js` is one: docs-markdown.js reads these while serializing, and
// importing them from the tool itself would drag `cards.js` (and the picker it
// carries) into the global bundle instead of the on-demand editor chunk.
//
// Mirrored in PHP by App\Support\GitbookRenderer — keep the three lists in
// sync, since a value that only one side knows renders as the default on the
// other.

/** How many cards fit on one row on a wide screen. */
export const CARD_COLUMNS = [2, 3, 4]

export const DEFAULT_CARD_COLUMNS = 3

/**
 * How the logo sits inside the card's frame. Every card in a grid gets the
 * same frame (that is what makes the grid regular), so this is per CARD: a
 * wordmark, a small square icon and a screenshot each need a different answer
 * to the same box.
 *
 * - `contain`  — scaled to fit whole, centered, upscaled if small ("Ajustar")
 * - `cover`    — fills the frame and crops the overflow ("Preencher")
 * - `original` — natural size, never upscaled, only shrunk to fit ("Original")
 */
export const CARD_FITS = ['contain', 'cover', 'original']

export const DEFAULT_CARD_FIT = 'contain'

export const CARD_FIT_LABELS = {
    contain: 'Ajustar',
    cover: 'Preencher',
    original: 'Original',
}
