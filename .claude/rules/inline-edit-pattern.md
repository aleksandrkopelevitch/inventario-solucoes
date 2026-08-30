---
paths:
  - "resources/js/modules/inline-edit.js"
  - "resources/views/components/ui/inline-edit*.blade.php"
  - "resources/views/people/**"
  - "resources/views/companies/**"
  - "resources/views/solutions/**"
  - "app/Http/Controllers/Inventory/PersonController.php"
  - "app/Http/Controllers/Inventory/CompanyController.php"
  - "app/Http/Controllers/Inventory/SolutionController.php"
  - "app/Http/Controllers/SolutionIntegrationController.php"
---

### Inline edit — a datum that links somewhere: the ↗ navigates, the text edits

On a page where every datum is editable, a datum that also points at another
record (a person's company, the system a "Sistemas" row links to) has two
competing gestures over the same words. The rule, app-wide:

- **The text always belongs to the editor** — same gesture as every other
  datum on the page, so nothing is an exception the user has to learn. Since
  2026-08-14 that gesture is a **double click** (or the pencil button beside
  the value), which is also what keeps the single click free for reading.
- **Navigation moves to an ↗ icon** (`x-ui.external-link`,
  `heroicon-o-arrow-top-right-on-square`) next to the value, always visible
  (unlike the pencil, which appears on hover — and is permanently visible only
  where there is no hover, `pointer-coarse`).

So pass `<x-ui.inline-edit :link="route(...)" link-label="empresa">` and render
the value as **plain text inside the slot — never as its own `<a>`**. Two click
targets over the same words is precisely the ambiguity the split removes. The
earlier `trigger="icon"` prop did the opposite (link stayed, only a pencil
edited) and is gone — the pencil is back, but as one of two ways in, not as the
only one.

Two things this leans on, easy to break by accident:

- `data-ak-inline-edit-link` on the anchor is load-bearing. The ↗ sits *inside*
  the read block, which double-clicks into the editor, so `inline-edit.js`'s
  `dblclick` listener bails out early on it — an impatient double click on the
  icon would otherwise open the editor on top of the navigation the user asked
  for. The `click` listener checks it too, for a creator's read block (the one
  read mode that IS a single-click trigger).
- `x-ui.external-link` carries **no color utility on purpose** (it inherits the
  surrounding text color), and `x-ui.inline-edit` renders it in *both* branches
  — the read-only one included, since a viewer has no editor and the ↗ is then
  the only way to reach the linked page.

Reference implementations: `People\DetailHeader` (company), `People\Systems`
(one row's system, whose editor re-points the link — that's why
`people.solutions.update` is the one route in that trio with
`scopeBindings()`), `Companies\DetailHeader` (the website, the only outbound
link in the app — hence the `link-attributes` prop carrying
`target`/`rel`), and `Solutions\DetailHeader` (the vendor chip).

### Inline edit — the editor has to look like the value it replaces

The whole promise of an edit-in-place page is that nothing moves and nothing
changes register: you double-click words and keep looking at the same words.
Four things carry that, and each is easy to undo by accident:

- **Typography.** `x-ui.inline-edit`'s `input-class` prop (per-field:
  an `inputClass` key inside `fields`) makes the editor wear the read value's
  type — the three headers pass their h1's own
  `!font-display !text-[28px]/[32px] !font-bold …`. Skip it and a 32px heading
  is retyped in 14px body text, which is the single most jarring thing this
  page can do. Everything read at the app's default `text-sm` needs nothing.
- **Chrome — the editor draws no box.** Since 2026-08-14 `x-ui.inline-edit-field`'s
  one `$chrome` string has **no border, no white fill, no shadow and no focus
  ring**: the open editor's ground IS read mode's own hover wash (`--ie-wash`,
  which simply doesn't leave), plus a 1.5px rule underneath (`--ie-rule`). The
  reason is that hover promised one thing and clicking used to deliver another
  (a white bordered box with a shadow) — a jump of register in the middle of a
  page you're reading. Three details are load-bearing:
  - `var(--ie-wash)` is read **on the control**, never aliased into a `:root`
    variable of its own. A custom property's `var()` is substituted on the
    element that DECLARES it, so `--ie-edit-bg: var(--ie-wash)` in `:root`
    would hand the whole app the ink tint and lose the translucent white the
    three detail headers set on their pastel strip. (Verified: the editor on a
    header strip computes `rgba(255,255,255,.5)`, on a white card ink-5%.)
  - the rule is an **inset box-shadow, not a `border-bottom`** — a border adds
    1.5px of height and shoves the text up as the editor opens. It's repeated
    in the `focus:` variant only to beat `x-forms.input`'s own accent ring,
    which would otherwise be the state the user actually sees (the editor opens
    focused).
  - `placeholder:!italic` is not decoration: with no border, a blank field's
    editor would be a caret alone on the page, so the italic faint placeholder
    (the field's label) is what anchors the eye — the same treatment read mode
    gives "Não informado". A `select` leans on `x-forms.select`'s chevron for
    the same reason, which is why `!pr-7` keeps its gutter.

  Every utility in `$chrome` is `!`-marked — it has to beat the same utility
  inside `x-forms.input`/`select`/`textarea`, and both strings land in one class
  attribute where CSS order, not authoring order, decides. (Leading-`!` still
  compiles in Tailwind 4.3, including inside a variant — `focus:!shadow-[…]`
  emits correctly; confirmed against the built stylesheet.)
- **Geometry.** Confirm/cancel (`x-ui.inline-edit-actions`) are two BARE glyphs
  (`size-3.5`, no fill, no ring, no border) absolutely positioned in the editor
  row's bottom-right corner, so they are not flex items and cost the field no
  gap. The row reserves `pr-9` for them — enough that a long value can never run
  underneath one, and still a third of what the pair used to take as two 28px
  pills with an accent fill. They had ONE job and were sized for a form: this is
  a punctual edit, and Enter/Esc (Ctrl+Enter in a textarea) is the first way to
  finish it, which is what the tooltips say. A real target comes back under
  `pointer-coarse`, where no hover exists — the same deal read mode's pencil
  makes. There is one placement now, not two: a corner is a corner whether the
  editor is one line or four, so the pair can no longer drift between layouts.
  The editor row's
  `-ml-1.5` cancels the input's own padding so the text doesn't slide sideways
  as it opens — that number, the editor's `!px-1.5` and read mode's
  `-mx-1.5 px-1.5` are **one measurement in three places**, and the alignment
  is only exact while they agree (measured: 0.0px of horizontal travel from
  read to edit on every field of the person header). The `file` editor's tile
  takes the size AND shape (`image-shape`) of the avatar/logo it replaces.
- **The wash.** The hover/focus tint is `--ie-wash` (app.css), overridden to a
  translucent white by the three detail headers' gradient strips and by the
  Solution's notes post-it — an ink tint reads as a grey smudge over a pastel
  or a warm ground. It is now the edit ground too, so a ground that overrides
  it moves both states at once, on purpose.

**Nothing on these pages looks like a form until you ask to edit it.** That
includes the Solution header's 8 attribute badges, which until 2026-08-15 were
the exception: bare `<select>`s that auto-saved on `change` (`solution-attributes.js`,
now deleted along with its `data-ak-solution-attribute` hook). They're
`x-ui.inline-edit type="select"` like everything else now — the read mode is the
same tone-coloured chip a viewer sees, the editor keeps the chip's typography
via `input-class`, and the endpoint stays `solutions.attributes.update` (they're
`attribute_options` values validated per group, not solution columns). If you
add another auto-saving control here, you're re-opening a question this page has
already answered.

Two traps, both hit on 2026-08-14 and both invisible in review:

- **Read mode must not carry a `display` utility.** `inline-edit.js` hides it
  by toggling Tailwind's `hidden`; a sibling `display` utility on the same
  element (`inline-block`, added so the wash would hug the text) turns that
  into a coin toss decided by stylesheet order — it lost, and every creator
  opened its editor UNDER a chip that was still on screen. Width and shape
  belong on the `<span>` inside the read block, which is where the wash lives
  for exactly this reason. Guarded by a test in `PersonInlineFieldUpdateTest`.
- **No percentage `max-width` on that span.** An inline-flex box already
  shrink-to-fits; `max-w-full` inside a shrink-to-fit parent (a cell of the
  contacts strip, a row of the systems list) resolves against a width that
  isn't known yet and collapses to min-content — "Não informado" breaks across
  two lines.

Behaviour that goes with it, in `inline-edit.js`: clicking away closes an
editor **only when nothing was typed** (a half-written value is what the user
can't get back), and opening a `select` calls `showPicker()` so choosing is one
gesture, like the photo tile that opens the file picker straight away.

The gesture that opens it is deliberately two-part, and the parts are not
interchangeable — don't collapse them back into a single click on the value:

- **Double click on the value**, so a single click keeps meaning what it means
  everywhere else (select a phone number to copy it, hit the ↗). `dblclick`
  clears the word the browser just selected before opening, or that highlight
  and the editor's own pre-selected value overlap and read as a glitch.
- **Single click on the pencil**, which is a real `<x-forms.button>` and
  therefore the path a keyboard (Tab, then Enter/Space — nothing in the module
  handles that) and a finger both take. That's why it's `focus-visible:opacity-100`
  and `pointer-coarse:opacity-100`/`!size-8`: a hover-only affordance doesn't
  exist on touch, and double-tap is not a gesture to rely on there.

The read block itself is no longer `role="button"`/`tabindex="0"` — only a
creator's chip is, since one click is genuinely its whole interaction.

### The three detail pages: one field endpoint each

`people.field.update`, `companies.field.update` and `solutions.field.update`
(+ `Update{Person,Company,Solution}FieldRequest`) are the same endpoint three
times: every rule is `sometimes` because the header sends only the field just
confirmed, `slug` is deliberately absent (renaming must not move the URL the
request is rendering), `prepareForValidation()` normalises `''` → `null` for
every nullable field (a multipart request can't carry a JSON null), and the
response returns that page's `DetailHeader::slot()` — never the catalog's index
slot, which doesn't exist on a detail page.

Two things to keep in step when touching them:

- Text-column rules must not be **stricter** than the panel's
  (`Update{Person,Company,Solution}Request`) for the same column, or a value
  the panel accepted can no longer be re-saved inline.
- `logo`/`photo` uploads ride along with a `{name}_action` hidden input
  (`x-forms.image-upload`'s "Remover"). It must be validated *and* excluded
  from the mass-assign array — leaving it in throws under
  `Model::shouldBeStrict()`'s `preventSilentlyDiscardingAttributes` (a 500 in
  dev/test). The panel `payload()` of all three does the same, which is what
  finally made that "Remover" button do something.

### Relations edited in place: one card = one slot = one pair of endpoints

Every detail page also attaches/detaches its relations without the panel. The
shape is always the same — a `+ Adicionar …` creator (`x-ui.inline-edit` with
`method="POST"`), a hover-revealed ✕ per row (**`x-ui.row-remove`**: the hidden
`<form>` with `@csrf`/`@method('DELETE')` plus the ghost `data-ak-ajax` button,
in one component), and a View Component whose `slot()` the mutation answers
with:

| card | relation | endpoints | slot |
|---|---|---|---|
| `People\Systems` | `person_solution` pivot (+ role badge) | `people.solutions.{store,update,destroy}` | `person-systems-slot` |
| `People\DetailHeader` (contacts strip) | `Person::contacts()` | `people.contacts.{store,update,destroy}` | `person-detail-header-slot` |
| `Companies\People` | `Person.company_id` | `companies.people.{store,destroy}` | `company-people-slot` |
| `Companies\ProvidedSolutions` | `Solution.vendor_company_id` | `companies.solutions.{store,destroy}` | `company-solutions-slot` |
| `Solutions\DetailHeader` (owners grid) | `person_solution` pivot | `solutions.people.{store,update,destroy}` | `solution-detail-header-slot` |

Things that are easy to get wrong here:

- **The pivot's unique index is `(person_id, solution_id, role)`**, so the
  DATABASE happily accepts the same person twice under two roles — while the
  rest of the app assumes one link per (person, solution)
  (`Person::solutions()` would list the solution twice, and
  `updateExistingPivot` would hit both rows). Both store requests
  (`StorePersonSolutionRequest`, `StoreSolutionPersonRequest`) carry a
  `Rule::unique` that is deliberately NOT scoped to the role. Don't "fix" it by
  adding the role.
- **A HasMany detach nulls a foreign key on the CHILD record**, so those routes
  are `scopeBindings()`: without it, `DELETE companies/{a}/people/{person}`
  would unlink someone who belongs to company `{b}`. The pivot detaches don't
  need it (`detach` no-ops on a row that isn't there). `{providedSolution}` is
  named for the relation the scoped binding resolves through — Company has no
  `solutions()`.
- **A pivot UPDATE does need scoping**, unlike its detach: `people.solutions.update`
  and `solutions.people.update` both READ the link they're replacing (the pivot's
  identity is the pair, so re-pointing one is detach + attach, carrying `role`
  and `is_primary` over). Without `scopeBindings()` the route would resolve a
  person/solution with no pivot at all and the "carry over" would read from thin
  air.
- **The card components use `loadMissing()`**, so the controller must `load()`
  the mutated relation *before* rendering the slot, or the card answers with the
  pre-mutation copy. That's why those helpers take a closure and reload first
  (`PersonController::fieldSaved`, `CompanyController::relationSaved`,
  `SolutionController::ownersSaved`).
- A picker that has **nothing left to offer** hides itself
  (`$canEdit && filled($options)`) instead of rendering a select whose only
  option is what's already there. A picker that can move a record away from
  another owner puts that owner in the option label (`Ana Silva — Outra
  Empresa`), so the move is never made blind.
- **A row that can be edited must never show two ✕ at once.** The moment an
  `x-ui.inline-edit` inside a row opens, its cancel lands next to the row's
  unlink — the same glyph, one recoverable and one not. `x-ui.row-remove`
  handles it for every card at once with
  `group-has-[[data-ak-inline-edit-form]:not(.hidden)]/row:invisible`, which
  reads the state `inline-edit.js` already publishes instead of inventing a
  second one. Two things it depends on: the enclosing row must be named
  **`group/row`** (the contacts strip said `group/contact` and the rule would
  have silently idled), and the utility is `invisible`, not `hidden` —
  `visibility` keeps the row's height (no reflow as the editor opens), never
  fights `x-forms.button`'s own `inline-flex`, beats the hover `opacity-100`
  without an `!` (different property), and drops the button from the tab order
  so Tab from the open field can't reach the destructive one.

The solution header speaks to **three** endpoints through the one
`x-ui.inline-edit` gesture — its own columns (`solutions.field.update`), the 8
attribute badges (`solutions.attributes.update`), and the owners grid
(`solutions.people.*`). Three requests, not three interactions: the header is
read-only text until something is double-clicked, everywhere.

The owners grid's row editor changes WHO holds a role, not which role — the role
is the column the row sits in, and a role picker inside a column already titled
with one has nowhere honest to live. Re-roling stays on the person's page (where
the role is a badge of its own), even though `UpdateSolutionPersonRequest`
validates `role` too, to stay symmetric with its mirror.

### An integration's name and status live in the editor's top bar — nowhere else

`Solutions\IntegrationMeta` (slot `integration-meta-slot`) renders both in the
top bar of the integration's unified documentation+diagram page, as two
`x-ui.inline-edit`s pointing at the same endpoint
(`solutions.integrations.update` / `UpdateIntegrationMetaRequest`, whose rules
are `sometimes` + `required` so one field can be confirmed without blanking the
other — same shape as the three detail pages' field requests). The response
returns that slot **and** `PagesNav::slot(...)`, since the rail beside it lists
the integration by name.

The canvas had a second editor for exactly these two fields (a pencil in its
own top bar opening a panel over the diagram) until 2026-08-17. It's gone, and
so is its `data-ak-statuses` payload and the canvas's own `data-viz-title` —
two editors of one field desync on the first edit, and the canvas title became
the stale copy the moment the name above it became editable. If you find
yourself adding a status control to the canvas again, this is the question
being reopened.

Everything else about the integration is still authored on the canvas: the
topology is the chain, and only `SyncIntegrationFromChain` derives from it.
