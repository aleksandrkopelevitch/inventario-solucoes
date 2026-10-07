// rail-scroll.js — the desktop rail's own vertical scroll (layout.blade.php).
//
// The rail is the viewport's height, and on a short screen its items no longer
// fit between the logo (pinned top) and the user's menu (pinned bottom). The
// middle region scrolls (`data-ak-rail-scroll`) with its native scrollbar
// hidden, and two arrows stand in for it:
//
//   - each is shown only while there is something to scroll to in its
//     direction — recomputed on scroll and whenever the region resizes;
//   - hovering one scrolls continuously until the pointer leaves or the end is
//     reached; a click (or Enter/Space — they are real buttons) moves one step,
//     which is the only way in without a hover.
//
// The items' hover labels are `position: fixed` (an absolute one would be
// clipped by the scrolling region), so their `top` is set here, on hover and
// again on scroll, from the item's own position.

const SPEED = 5   // px per frame while an arrow is hovered
const STEP = 160  // px per click

const initialized = new WeakSet()
let frame = null

export function init() {
    document.querySelectorAll('[data-ak-rail-scroll]').forEach((region) => {
        if (initialized.has(region)) return
        initialized.add(region)

        const sync = () => syncArrows(region)
        region.addEventListener('scroll', () => {
            sync()
            placeHoveredFlyout(region)
        }, { passive: true })
        new ResizeObserver(sync).observe(region)
        sync()
    })
}

function arrowsOf(region) {
    const rail = region.closest('[data-ak-rail]')

    return {
        up: rail?.querySelector('[data-ak-rail-arrow="up"]'),
        down: rail?.querySelector('[data-ak-rail-arrow="down"]'),
    }
}

function syncArrows(region) {
    const { up, down } = arrowsOf(region)
    const atTop = region.scrollTop <= 1
    const atBottom = region.scrollTop + region.clientHeight >= region.scrollHeight - 1

    if (up) up.hidden = atTop
    if (down) down.hidden = atBottom

    // An arrow that just vanished under the pointer would otherwise keep the
    // loop running against an edge.
    if ((atTop || atBottom) && frame) stopScrolling()
}

function regionOf(arrow) {
    return arrow.closest('[data-ak-rail]')?.querySelector('[data-ak-rail-scroll]')
}

function startScrolling(region, direction) {
    stopScrolling()
    const tick = () => {
        region.scrollTop += SPEED * direction
        frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
}

function stopScrolling() {
    if (frame) cancelAnimationFrame(frame)
    frame = null
}

function placeFlyout(link) {
    const flyout = link.querySelector('[data-ak-rail-flyout]')
    if (flyout) flyout.style.top = `${link.getBoundingClientRect().top}px`
}

function placeHoveredFlyout(region) {
    const hovered = region.querySelector('[data-ak-rail-link]:hover')
    if (hovered) placeFlyout(hovered)
}

document.addEventListener('mouseover', (e) => {
    if (!(e.target instanceof Element)) return

    const link = e.target.closest('[data-ak-rail-link]')
    if (link) placeFlyout(link)

    const arrow = e.target.closest('[data-ak-rail-arrow]')
    const region = arrow && regionOf(arrow)
    if (region && !frame) startScrolling(region, arrow.dataset.akRailArrow === 'up' ? -1 : 1)
})

document.addEventListener('mouseout', (e) => {
    if (!(e.target instanceof Element)) return
    const arrow = e.target.closest('[data-ak-rail-arrow]')
    if (arrow && !arrow.contains(e.relatedTarget)) stopScrolling()
})

document.addEventListener('click', (e) => {
    if (!(e.target instanceof Element)) return
    const arrow = e.target.closest('[data-ak-rail-arrow]')
    const region = arrow && regionOf(arrow)
    if (!region) return

    stopScrolling()
    region.scrollBy({ top: arrow.dataset.akRailArrow === 'up' ? -STEP : STEP, behavior: 'smooth' })
})
