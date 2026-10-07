---
paths:
  - "resources/js/modules/ecosystem-map.js"
  - "resources/js/modules/graph-canvas/*.js"
  - "resources/views/components/ecosystem-map.blade.php"
  - "resources/views/solutions/map.blade.php"
  - "app/Http/Controllers/SolutionMapController.php"
  - "app/Services/DiagramGraphService.php"
  - "resources/views/components/attribute-options/group-list.blade.php"
---

### The ecosystem map is a picture at SOLUTION level

`/map` is a `<canvas>` (`ecosystem-map.js`, renderer adapted from "Second
Brain" by Jay E / RoboNuggets under CC BY 4.0 — the visible credit at the bottom
of the map is the license, never tidy it away). Since 2026-10-07, by the user's
call:

- **No drill-down.** A click selects and opens a card; it never unfolds a
  system into its diagrams or a diagram into its chain — that was "detail of
  the detail" for a picture of the whole ecosystem. The payload carries no
  drawings any more (`DiagramGraphService::globalMap()` returns `nodes`,
  `edges`, `hostings`); `edges[].diagrams` (name, slug, url) is all that is
  left of them, for the card an arrow opens.
- **One arrow per PAIR of systems**, whatever number of diagrams and flows
  connect them (`dedupePairs()`), carrying the direction of all of them:
  `unidirectional` always means source → target (the pair is oriented by the
  first flow seen), `bidirectional` gets a head at each end. Arrows are
  straight — the per-index curvature the reference used is what read as a
  tangle.
- **No filters.** The bar above the canvas is the VIEW selector and, on the
  hosting view, the arrows toggle. An old URL still sending `?status=`,
  `?category=` or `?directorate=` gets the full map. The grouped readings
  (directorate/owner/vendor/category) were removed with the filters.

### "Por hospedagem"

One container per hosting, the systems laid out on a grid inside it
(`hostingLayout()` in the JS). A system's container is `hostingOf()`: its
**Cloud** when set, else its **Hospedagem** (environment), else "Não
informado" — so the view always accounts for every system. It shows the WHOLE
catalog (`?view=hosting` → `globalMap(wholeCatalog: true)`), connected or not;
the links view only the systems some diagram connects.

A container's colour and picture are the attribute option's own
(`attribute_options.color`, `image_path` — hosting groups only,
`AttributeGroup::isHosting()`), edited in the attributes modal; a value with no
colour is `AttributeOption::DEFAULT_COLOR`. Pictures are plain public-disk
files under `hosting-images/`, same rule as logos (JPG/PNG/WebP, 2 MB, no SVG).

Arrows on that view default to **between containers, one per link** the
systems have (parallel ones fanned out by `ARROW_SPREAD`); "Setas por solução"
draws them between the systems. A link inside ONE container is always drawn
between its systems — there is no box to leave.

The search finds systems only and marks the hit with an orange ring
(`SEARCH_ORANGE`) until Esc, centring the camera on its DESTINATION
(`targetOf()`), never its live position — blocks ease toward their targets, so
the live coordinates right after a layout describe a picture not drawn yet.

### Legible far out, never worse up close

Everything on the canvas lives in world units and scales with the zoom; a few
things have a FLOOR in screen px so the far view still reads (2026-10-07):

- A system's radius never drops under `MIN_NODE_PX` — `drawnRadius()`, which
  hit-testing and the arrows' trimming use too, so what you can click is what
  you see.
- **A system with a logo is drawn as the logo** — on its own, in its own
  proportions, no orb, no glow, no plate (the user's call). It is the thing on
  the canvas recognisable at any distance. Rings (selection, search) wrap it at
  1.25× the radius.
- Names are cut to their cell on the hosting view (`CELL_W`, cells wider than
  tall because names are horizontal), hidden only when the cell has under
  `LABEL_MIN_ROOM_PX`; their size grows with √zoom between 9 and 16px, so up
  close they keep pace with the blocks.
- Container titles and pictures are sized in WORLD units (`TITLE_WORLD`,
  `ICON_WORLD`) with screen floors (`TITLE_MIN_PX`, `ICON_MIN_PX`). They used to
  be capped in screen px instead, which made them SHRINK relative to the box
  as you zoomed in. When the floor makes the title taller than its strip it
  grows upward into the gap above, and it never runs past its own container's
  width (the count goes first, then the title is cut) — far out, neighbours
  otherwise ran into each other.
