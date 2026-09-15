---
paths:
  - "app/View/Components/**"
  - "resources/js/modules/ajax-slot.js"
  - "resources/js/modules/execute-filters.js"
---

## AJAX and Updatable Slots

Use updatable slots when content can change dynamically after a user action (e.g., a list updated by a modal or side panel). Do **not** use for simple one-way forms like login or password reset — a redirect is enough there.

**When to use slots:**
- A list/table that can be edited via a popup or panel
- A widget (e.g., header counter) that reflects a mutation
- Any partial that needs to reflect server state without a full reload

**When NOT to use slots:**
- Login, password reset
- Single-step forms that always redirect after success
- Static content that never changes after load

**Pattern:**

```html
<!-- Blade: mark the updatable region with a stable id -->
<div id="users-index-slot">
    @foreach ($users as $user) ... @endforeach
</div>

<!-- Button that triggers AJAX — always use x-forms.button, never raw <button> -->
<x-forms.button data-ak-ajax="my-form" data-ak-action="{{ route('users.store') }}">
    Salvar
</x-forms.button>
```

```php
// Controller: return slot(s) after mutation
return response()->json([
    'message'        => 'Salvo com sucesso.',
    'updatableSlots' => [Users\Index::slot()],
]);
```

```php
// View Component: slot() renders fresh HTML for the region
public static function slot(): array
{
    return (new static)->toSlot('users-index-slot');
}
```

Render methods live on **View Components** (via `Renderable` trait), never on Models.

If the same HTML needs to replace two elements (e.g., a widget in header and sidebar), pipe-separate the IDs:

```php
public static function slot(): array
{
    return (new static)->toSlot('header-widget-slot|sidebar-widget-slot');
}
```

### Multiple *different* slots from one mutation

That pipe syntax is for the *same* HTML in two places. When a mutation can be
triggered from more than one page showing *different* content for the same
record (e.g., editing a Solution from its own detail page **or** from the
catalog list), return every slot the record could appear in — the client
`ajax-slot.js` silently no-ops on any id that isn't on the current page, so
it's safe to always send both:

```php
private function saved(string $message, ?Solution $solution = null): JsonResponse
{
    $slots = [SolutionsIndex::slot()];
    if ($solution) {
        $slots[] = DetailHeader::slot($solution); // present only when editing an existing record
    }

    return response()->json(['type' => 'success', 'message' => $message, 'updatableSlots' => $slots]);
}
```

If a "show" page has a section whose data could also change from create/update
elsewhere in the app, give that section its own View Component + slot id
(don't inline it in the page) so it's a real target for this pattern —
`Solutions\DetailHeader`, `People\DetailHeader`, `Companies\DetailHeader` are
the reference implementations. Auditing this is easy to skip: check every
controller that mutates a resource with its own detail page, and confirm the
mutation response includes that page's slot, not just the index.

The same pattern also applies to small derived widgets that live *outside*
the main index slot's DOM subtree but must update in lockstep with it on
every filter/search — e.g. the live result counter next to an `<h1>` and an
active-filter chips row above the grid. `Solutions\ResultsCount` and
`Solutions\FilterChips` are the reference implementations: both are tiny
View Components with their own slot id, both recompute from the same
`$filters` array, and `SolutionController::index()`/`saved()` always return
all three slots (`SolutionsIndex`, `ResultsCount`, `FilterChips`) together —
forgetting one leaves that widget stale after the next AJAX filter/search
even though the grid itself updates correctly. Both reuse
`Solution::scopeFilter()` (the same local scope as `Solutions\Index`'s
query) instead of re-deriving the filter conditions, so a new filter field
only needs to be added in one place.

### Preserving filters when a mutation refreshes a filtered index slot

When a side panel (create/edit) is opened *from* a filtered index page
(`/solutions?filter[category]=iam`) and the mutation's response re-renders
that page's index slot, the slot must be rebuilt with the **same filters**
the user had applied — otherwise saving/editing a record while filtered
silently resets the visible list to everything, even though the URL still
shows the filter applied. `{Resource}Index::slot()` defaults to `[]`
(unfiltered) whenever the caller doesn't explicitly pass filters, so this
bug reproduces easily if the plumbing below is skipped for a new resource.

The filters live only in the browser's address bar at the moment the panel
is opened — the controller has no other way to know them — so they're
carried explicitly through the URL, from the index card all the way back
to the mutation response:

1. The index page's create link and each card's edit link append the
   current `$filters` as a `filter` query param:
   `route('solutions.edit', ['solution' => $solution, 'filter' => $filters])`.
2. `create()`/`edit()` read `$request->query('filter', [])` and pass it into
   the panel's form view as `'filters'`.
3. The form's `$action` (the `data-ak-action` URL used by `ajax-post.js`)
   re-embeds the same filters:
   `route('solutions.update', ['solution' => $solution, 'filter' => $filters ?? []])`.
4. `store()`/`update()` read `$request->query('filter', [])` back out and
   pass it through `saved()` into `SolutionsIndex::slot($filters)`.

Reference implementation: `SolutionController`, `CompanyController`,
`PersonController` (all three follow this chain identically). `route()`
with an empty `filter` array produces no query string at all, so this is
safe to always do unconditionally, even when no filters are active.
