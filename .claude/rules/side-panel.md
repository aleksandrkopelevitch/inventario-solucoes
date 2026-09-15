---
paths:
  - "resources/js/modules/side-panel.js"
  - "resources/views/components/**"
  - "resources/views/components/layouts/**"
---

## Side Panel

Generic shell in `layout.blade.php`. Content is **always loaded via AJAX**
on open; **always cleared on close** (reverts to the 3-dot loading
placeholder).

```html
{{-- Open with overlay (default) --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}">
    Abrir
</button>

{{-- Open without overlay --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}" data-ak-panel-overlay="false">
    Abrir sem overlay
</button>

{{-- Wider panel — "small" (default, current width) | "medium" (1/2) | "large" (3/4) --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}" data-ak-panel-size="large">
    Abrir grande
</button>

{{-- Docked: an in-flow right COLUMN of the named host instead of a floating
     overlay. The host must be a flex row; the panel is moved into it as the
     last child and the page's own content shrinks beside it. --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}"
        data-ak-panel-dock="my-flex-row-id" data-ak-panel-size="large">
    Abrir na coluna
</button>

{{-- Close from inside the injected content --}}
<button data-ak-panel-close>Fechar</button>
```

The overlay (when visible) also closes the panel when clicked.

### Docked mode — same shell, in flow

`data-ak-panel-dock` is what the documentation reader's "Abrir especialista"
uses: talking to the assistant ABOUT a page while the page disappears behind
the panel was the problem it solves. `side-panel.js` moves `#side-panel` into
the host element, swaps the floating class list for an in-flow one, and adds a
drag handle on its left edge (also keyboard-resizable — it's a
`role="separator"`); the width is remembered in `localStorage`. Closing
collapses the column and restores the floating shell exactly as
`layout.blade.php` authored it, so nothing else has to know which mode was
used.

Three things follow from it being one shell rather than two:

- **`data-ak-panel-size` still matters on a docked trigger.** Below 1024px the
  dock is refused and the panel opens floating, which is the behavior that
  screen already had — a column with a 320px floor and a phone don't mix.
- **The host must be a flex row that can give up width** — the panel arrives as
  a `shrink-0` sibling, so something beside it needs `flex-1 min-w-0`.
- **The injected content wrapper is `flex-1 min-h-0`, not `h-full`.** A docked
  shell's height comes from stretching inside the host row, so there is no
  definite height for a percentage to resolve against.

A page whose layout reacts to the panel should measure the CONTAINER, not the
viewport: `documentation/edit.blade.php` marks its scroll area `@container` and
drops the "Nesta página" navigator at `@3xl`, because the viewport stopped
predicting how much room the reading column has the moment a panel could sit
beside it.

The endpoint must return `{ "content": "<html>" }`:
```php
return response()->json([
    'content' => view('module.panels.my-panel', $data)->render(),
]);
```

After injection, `initAllModules()` is called automatically. The
`side-panel.js` listener lives at the module level (outside `init()`), so
`init()` is a no-op — multiple `initAllModules()` calls are safe.
