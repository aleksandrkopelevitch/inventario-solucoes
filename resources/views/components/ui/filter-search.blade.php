@props([
    'id',
    'url',
    'placeholder',
    'value' => null,
    'name' => 'filter[search]',
])

{{--
    The search field of x-ui.filter-bar. Three things live inside the field
    itself, which is why it needs the relative wrapper:

    - the magnifier, which the field never had: with no icon, the only thing
      saying "this is a search" was the placeholder, and that goes away on the
      first keystroke;
    - the "mín. N letras" hint (`data-ak-search-hint`), which used to be a
      permanently reserved 16px line UNDER the field — blank almost always, on
      every list page in the app. It only ever appears while 1-2 characters are
      typed, so there is nothing at the field's right edge for it to collide
      with;
    - the in-flight spinner (`data-ak-filters-loading`).

    The hint sits at `right-9` and the spinner at `right-3` on purpose. They
    are near-exclusive — execute-search.js returns BEFORE firing a request
    while the term is too short — but deleting a character back down to 2 while
    the previous request is still in flight shows both, and stacking them would
    read as a glitch.

    Sizing: `basis-[22rem]` is what pushes the fifth select onto the bar's
    second line on /solutions. Drop it and all five crowd onto line one,
    squeezing the field down to ~307px, where its own placeholder truncates.
--}}
<div class="relative min-w-60 flex-1 basis-[22rem] sm:max-w-[26rem]">
    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-faint" />

    <x-forms.input type="search" :id="$id" :name="$name" :placeholder="$placeholder" :value="$value"
        class="!pl-9 !pr-9"
        data-ak-search-param="{{ $name }}"
        data-ak-search="{{ json_encode(['inputId' => $id, 'url' => $url]) }}" />

    <span data-ak-search-hint="{{ $id }}"
        class="pointer-events-none absolute right-9 top-1/2 -translate-y-1/2 bg-surface pl-2 text-xs text-hot"></span>

    <x-heroicon-o-arrow-path data-ak-filters-loading
        class="pointer-events-none absolute right-3 top-1/2 hidden size-4 -translate-y-1/2 animate-spin text-accent" />
</div>
