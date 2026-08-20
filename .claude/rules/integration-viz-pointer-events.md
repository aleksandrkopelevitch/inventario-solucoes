---
paths:
  - "resources/js/modules/integration-viz.js"
  - "resources/views/components/solutions/integration-viz.blade.php"
---

### F3 canvas gestures run on POINTER events — never add a `mouse*` listener

Every gesture in `integration-viz.js` (block drag, drag-an-arrow-out-of-a-port,
retarget an arrow tip, move/resize a swimlane, move a post-it, pan the canvas)
is registered as `pointerdown`/`pointermove`/`pointerup`, which fire for mouse,
touch and pen with one code path. There is exactly ONE drag dispatcher — two
`window` listeners switching on `drag.type` — plus per-element `pointerdown`
initiators that only assign `drag = {...}`.

**Don't reintroduce `mousedown` on anything inside the viewport.** Touch events
are a separate stream from mouse events, so a `mousedown`-based
`stopPropagation()` guard does nothing for a finger: the touch reaches the
viewport anyway and pans the canvas instead of dragging the block. That exact
bug is why the old dedicated `touchstart`/`touchmove`/`touchend` pan was
REMOVED rather than kept alongside the pointer listeners (keeping both makes
the pan run *simultaneously* with the drag). `.ak-viz-viewport` sets
`touch-action: none`, which is what stops the browser from stealing the gesture
to scroll. The two remaining `mousedown` listeners are the autocomplete
suggestion rows, whose `preventDefault()` preserves input focus and whose
parent editor already stops propagation.

`pointercancel` must stay wired next to `pointerup`: a touch pointer can be
cancelled by the browser (system gesture, second finger) without ever firing
`pointerup`, and since a drag lives in `drag` until an end-event clears it,
dropping that leaves the canvas stuck mid-drag until a reload. On cancel only
the *actions* are abandoned (completing a link, retargeting a tip, click-to-
select); anything already moved keeps its new position and is marked dirty.

Two consequences worth remembering when adding UI here: hover-only affordances
don't exist on touch (ports are revealed by `.is-selected` too, which is why
tap-to-select-then-drag works), and `dblclick` has no touch equivalent — so
anything reachable only by double-click needs a second path, which is what
`data-viz-toolbar-rename` is for. Precision targets get a bigger hit area via
`@media (pointer: coarse)` in `integration-viz.blade.php`.
