---
paths:
  - "resources/views/**"
---

### Never write a component TAG inside a Blade file's comment

The component-tag compiler runs over the raw file text before anything else,
and it does **not** know what a comment is. So naming a component the way you
would in prose — with the angle brackets — inside a `@php`/`@props` block's
`//` comment (or a `{{-- --}}` one) compiles into a real component
invocation, dumped in the middle of the PHP it was sitting in:

```blade
{{-- ❌ Compiles `<x-ui.inline-edit-field>` into an actual @component(...) call
     spliced into the @props array — "Undefined variable $component" +
     "Call to a member function withAttributes() on null", pointing at a
     compiled view, with nothing wrong at the line the error names. --}}
@props([
    // see the notes on <x-ui.inline-edit-field>
    'name' => null,
])

{{-- ✅ Same sentence, no brackets --}}
@props([
    // see the notes on x-ui.inline-edit-field
    'name' => null,
])
```

Hit on 2026-08-14 while building `x-ui.inline-edit`. Refer to components in
comments as `x-ui.thing` (or by view path), never `<x-ui.thing>`.

### Two directives written adjacently — `@endif@endforeach` — do not compile

Blade matches a directive with `\B@`, so an `@` preceded by a **word
character** is not seen as one. Write the closing directives of a nested
loop-plus-conditional back to back — which is exactly what you must do when the
output may contain no whitespace between the pieces (highlighted search
segments, inline badges) — and the second one is never compiled: it lands in
the rendered PHP verbatim, the loop is never closed, and the view dies with
`syntax error, unexpected end of file` naming a file with nothing visibly wrong
in it.

```blade
{{-- ❌ `@endforeach` reaches the output as literal text --}}
@foreach ($segments as $s)@if ($s['match'])<mark>{{ $s['text'] }}</mark>@else{{ $s['text'] }}@endif@endforeach
```

There is no separator that fixes it: anything that renders (a space, a newline)
puts real whitespace inside the sentence, and a Blade **comment** between them
does not work either — comments are stripped BEFORE statements are compiled, so
the two directives are adjacent again by the time it matters. (Note the
ordering, because it is the opposite of the component-tag rule below:
`ComponentTagCompiler` runs BEFORE comments are stripped, which is why a
`<x-...>` tag inside a comment DOES compile. Statements run after. Neither
pass knows what a comment is, they just disagree about when.)

**Build the string in PHP instead** — a View Component with `e()` per piece,
echoed through a one-line view. `App\View\Components\Documentation\SearchHighlight`
is the reference implementation (and `x-ui.highlight` is the older, single-term
version of the same idea). Hit on 2026-08-26 building the documentation search
palette.

### Never use `@json()` for a `data-ak-*` (or any) HTML attribute value

`@json($value)` written as a quoted attribute — `data-ak-filters='@json($value)'`
— **silently fails to compile whenever that attribute sits on a Blade
component tag** (`<x-forms.select data-ak-filters='@json($value)'>`).
Laravel's `ComponentTagCompiler` treats static (non-`:`-prefixed) attribute
values on `<x-...>` tags as opaque strings and never re-runs the directive
compiler over them — the literal text `@json($value)` reaches the browser,
`JSON.parse()` on it throws, and whatever JS behavior that config was
driving (filters, search, tabs, chips, …) just doesn't happen. No error is
visible anywhere except in the raw rendered HTML.

The `:attr="json_encode($value)"` dynamic-attribute form looks like the fix,
but it has the mirror-image bug: it only compiles on `<x-...>` component
tags — on a **plain** HTML tag (`<div>`, `<button>`) the `:`-prefix is left
completely untouched as literal text.

There is no version of `@json()` or `:attr=` that is safe on both plain tags
and component tags. **Always use the universal form instead — a normal
Blade echo inside a double-quoted attribute:**

```blade
{{-- ✅ Works identically on a plain <div> and on any <x-...> component tag --}}
<div data-ak-chips="{{ json_encode($config) }}">
<x-forms.select data-ak-filters="{{ json_encode($filterBind) }}">

{{-- ❌ Never do this — breaks on component tags, and the failure is invisible in the source --}}
<x-forms.select data-ak-filters='@json($filterBind)'>

{{-- ❌ Never do this either — breaks on plain HTML tags --}}
<div data-ak-chips=":data-ak-chips="json_encode($config)"">
```

`{{ }}` is a plain textual substitution Blade always applies, regardless of
whether it's inside a component tag's attribute or a plain tag's — unlike
`@directive()` and the `:`-prefix binding, which are special-cased by two
different, mutually incompatible compiler passes.

Real incident (2026-07-02): this silently broke **all** filter and search UI on
`/solutions`, `/companies` and `/people` — every
`<x-forms.select>`/`<x-forms.input>`/`<x-forms.checkbox>` carried
`data-ak-filters='@json($filterBind)'`. Pest feature tests did not catch it:
they call controllers directly with `filter[...]` query params, bypassing the
Blade/JS rendering layer entirely.

### Never echo a `ComponentAttributeBag` inside an x-component tag's attributes

Same compiler, third variant. Forwarding a bag of attributes by echoing it —
`{{ new \Illuminate\View\ComponentAttributeBag($extra) }}` — is the normal way
to splat attributes onto a **plain** tag (`x-forms.image-upload` does exactly
that on its `<input>`s). Put the same echo in the attribute area of an
`<x-...>` tag and `ComponentTagCompiler` fails to parse that tag: it emits the
whole `<x-ui.external-link …>` **verbatim into the HTML** — no exception, no
log line, just a component that silently never renders (and, if the bag were
the only thing making it work, a feature that quietly does nothing).

**Pass the array as a prop and echo the bag inside the child, on its own plain
tag:**

```blade
{{-- ✅ parent: a plain dynamic prop --}}
<x-ui.external-link :href="$link" :extra-attributes="$linkAttributes" />

{{-- ✅ child (external-link.blade.php): the echo lives on a plain <a> --}}
<a href="{{ $href }}" {{ new \Illuminate\View\ComponentAttributeBag($extraAttributes) }} …>

{{-- ❌ parent: breaks the tag's compilation, invisibly --}}
<x-ui.external-link :href="$link" {{ new \Illuminate\View\ComponentAttributeBag($linkAttributes) }} />
```

Hit on 2026-08-14 wiring `target="_blank"`/`rel="noopener"` onto the company
website's ↗. A Pest render test caught it (`assertSee('data-ak-inline-edit-link')`
against the real page) — worth writing one whenever a component is expected to
render an attribute, since nothing else in the stack complains. Static
attributes on a component tag (`<x-ui.external-link target="_blank">`) are fine
as always; it's only the echoed bag that breaks.

And the sibling trap two sections up — never write a component TAG inside a
Blade comment — bit again on the same day, inside a comment explaining *this
very rule*: `<x-...>` in a `//` comment compiles into a real component
invocation and 500s with `Unable to locate a class or view for component [...]`.
Name components in comments as `x-ui.thing`, brackets omitted.
