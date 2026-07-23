// Mobile navigation drawer — projects the section nav (icons + labels) as a
// left slide-in panel on small screens, where the desktop icon rail
// (`max-md:hidden`) is gone. Pure event delegation, so no per-element init.
//
// Triggers:
//   data-ak-mobile-nav-open   opens the drawer
//   data-ak-mobile-nav-close  closes it (overlay, close button, and each nav link)

const DRAWER_ID  = 'mobile-nav'
const OVERLAY_ID = 'mobile-nav-overlay'

function open() {
    const drawer  = document.getElementById(DRAWER_ID)
    const overlay = document.getElementById(OVERLAY_ID)
    if (!drawer || !overlay) return

    drawer.classList.remove('-translate-x-full')
    overlay.classList.remove('pointer-events-none', 'opacity-0')
    drawer.setAttribute('aria-hidden', 'false')
    // Lock the page scroll behind the drawer.
    document.body.classList.add('overflow-hidden')
}

function close() {
    const drawer  = document.getElementById(DRAWER_ID)
    const overlay = document.getElementById(OVERLAY_ID)
    if (!drawer || !overlay) return

    drawer.classList.add('-translate-x-full')
    overlay.classList.add('pointer-events-none', 'opacity-0')
    drawer.setAttribute('aria-hidden', 'true')
    document.body.classList.remove('overflow-hidden')
}

document.addEventListener('click', (e) => {
    if (!(e.target instanceof Element)) return

    if (e.target.closest('[data-ak-mobile-nav-open]')) {
        open()
        return
    }

    if (e.target.closest('[data-ak-mobile-nav-close]')) {
        close()
    }
})

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close()
})

// No-op — delegation is registered at module load. Kept so initAllModules()
// can call it safely after slot updates.
export function init() {}
