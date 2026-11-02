/**
 * Keeps a chat thread's newest message in view — but only when the THREAD
 * changed.
 *
 * The three chats used to scroll their thread to the bottom on every init(),
 * which runs after EVERY slot swap on the page: attaching a document while
 * reading an earlier reply swapped the context slot, and the reader lost their
 * place. Here the jump happens on the first sight of a thread container (a
 * fresh page or a swapped thread slot) and whenever its content grew; a swap
 * elsewhere leaves the scroll position alone.
 */

const seenHeight = new WeakMap()

export function keepNewestInView(scroll) {
    if (!scroll) return

    const before = seenHeight.get(scroll)
    if (before === undefined || scroll.scrollHeight > before) {
        scroll.scrollTop = scroll.scrollHeight
    }
    seenHeight.set(scroll, scroll.scrollHeight)
}
