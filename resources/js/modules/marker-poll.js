/**
 * Polls a status URL while its MARKER is on the page — the one polling loop
 * every queued job's screen uses (AGENTS.md § Queue & Jobs).
 *
 *   <div data-ak-poll="{{ $statusUrl }}"
 *        data-ak-poll-interval="2500"
 *        data-ak-poll-max="300"
 *        data-ak-poll-give-up="A resposta está demorando…">
 *
 * The marker is rendered INSIDE the slot the endpoint swaps, so the swap that
 * settles the job removes it, and `init()` (re-run after every swap) stops the
 * loop. The server's side of the contract is one rule: answer
 * `{updatableSlots: [...]}` when there is something to show, and none while
 * there is not — every status endpoint here already did, which is why the four
 * copies this replaced (the three chats and the pipeline lifecycle) could
 * become one.
 *
 * Three things the copies got wrong and this does not:
 *
 * - **One loop per URL, not one per module.** The committee screen renders two
 *   markers at once (a chat reply and a pre-review), and a module-level timer
 *   bound to whichever came first left the other waiting behind it.
 * - **A tick never overlaps the next.** A `setTimeout` chained after each
 *   response, rather than `setInterval` around an async fetch: a slow answer
 *   could land after a newer one and swap stale markup back in.
 * - **A late answer is dropped.** A response that arrives after its marker is
 *   gone (the slot was swapped meanwhile) is not applied.
 *
 * Past `data-ak-poll-max` ticks it stops, shows the give-up text (never poll
 * forever in silence: the worker may be down) and dispatches
 * `ak:poll-gave-up` with `{url, marker}`, for a screen that has something to
 * undo — docs-chat unlocks its editor.
 */

import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'

const DEFAULT_INTERVAL = 2500
const DEFAULT_MAX = 300
const DEFAULT_GIVE_UP = 'Está demorando mais que o esperado — atualize a página para conferir.'

/** url → {timer, attempts} for every marker currently on the page. */
const pollers = new Map()

/**
 * URLs that hit the ceiling. Their marker is still on the page (nothing swapped
 * it away), so without this the next swap ANYWHERE re-ran init() and started
 * them over — a second give-up Toast, and polling that never really stopped.
 * Forgotten once the marker leaves the page, so a later one polls afresh.
 */
const gaveUp = new Set()

function markerFor(url) {
    return [...document.querySelectorAll('[data-ak-poll]')].find((m) => m.dataset.akPoll === url) ?? null
}

function settings(marker) {
    return {
        interval: Number(marker.dataset.akPollInterval) || DEFAULT_INTERVAL,
        max: Number(marker.dataset.akPollMax) || DEFAULT_MAX,
        giveUp: marker.dataset.akPollGiveUp || DEFAULT_GIVE_UP,
    }
}

function stop(url) {
    const poller = pollers.get(url)
    if (!poller) return
    clearTimeout(poller.timer)
    pollers.delete(url)
}

function schedule(url, poller) {
    const marker = markerFor(url)
    if (!marker) {
        stop(url)
        return
    }
    poller.timer = setTimeout(() => tick(url, poller), settings(marker).interval)
}

async function tick(url, poller) {
    const marker = markerFor(url)
    if (pollers.get(url) !== poller || !marker) {
        stop(url)
        return
    }

    const {max, giveUp} = settings(marker)

    poller.attempts += 1
    if (poller.attempts > max) {
        stop(url)
        gaveUp.add(url)
        Toast.show(giveUp, 'warning')
        document.dispatchEvent(new CustomEvent('ak:poll-gave-up', {detail: {url, marker}}))
        return
    }

    try {
        const response = await ajaxModule.init('GET', url)
        const data = await response.json()

        // Stopped or replaced while the request was out: this answer is stale.
        if (pollers.get(url) !== poller) return

        if (Array.isArray(data.updatableSlots) && data.updatableSlots.length) {
            // Re-runs init(), which stops this loop when the swap removed the
            // marker and keeps it when the new markup carries the same one.
            updateSlots(data)
        }
    } catch (_) {
        // transient failure — the next tick retries, up to the ceiling
    }

    if (pollers.get(url) === poller) schedule(url, poller)
}

export function init() {
    const urls = new Set([...document.querySelectorAll('[data-ak-poll]')].map((m) => m.dataset.akPoll))

    for (const url of [...pollers.keys()]) {
        if (!urls.has(url)) stop(url)
    }
    for (const url of [...gaveUp]) {
        if (!urls.has(url)) gaveUp.delete(url)
    }

    urls.forEach((url) => {
        if (!url || pollers.has(url) || gaveUp.has(url)) return
        const poller = {timer: null, attempts: 0}
        pollers.set(url, poller)
        schedule(url, poller)
    })
}
