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
