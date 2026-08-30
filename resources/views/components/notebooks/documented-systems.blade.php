@php
    $names = $linked->pluck('name')->implode(', ');
    $canEdit = auth()->user()?->can('update', $notebook) ?? false;
@endphp

{{-- `relative` is what the popover below anchors to — it must be THIS block and
     not the rail around it, or the panel would hang off the rail's full height
     instead of off the sentence it edits. --}}
<div id="{{ $domId }}" class="relative mb-4 border-b border-line pb-4">
    @if ($canEdit)
        {{-- One flex item, not two: x-forms.button wraps the slot in a
             `flex items-center` row, so a lead-in and a list passed as separate
             elements would sit side by side and never wrap. Inside a single
             item the text wraps like any other text — which it has to, since
             this is a 13rem rail and a caderno can name several systems. --}}
        <x-forms.button type="button" variant="ghost"
            data-ak-toggle="docs-solutions-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
            title="Editar os sistemas deste caderno"
            class="group !w-full !justify-start !rounded-field !px-2 !py-1.5 !text-left !text-[13px] !font-normal !leading-relaxed">
            <span class="text-muted">
                @if ($linked->isEmpty())
                    Esse caderno ainda não contempla nenhum sistema.
                @else
                    Esse caderno contempla o(s) sistema(s):
                    <span class="font-medium text-ink">{{ $names }}</span>
                @endif
                {{-- Inline rather than a flex sibling, so it trails the LAST
                     line of the sentence instead of floating beside the block.
                     Same contract as x-ui.inline-edit's pencil: it holds its
                     space at zero opacity, so revealing it never reflows. --}}
                <x-heroicon-o-pencil class="ml-0.5 inline size-3 shrink-0 -translate-y-px opacity-0 transition-opacity group-hover:opacity-70 group-focus-visible:opacity-70" />
            </span>
        </x-forms.button>

        {{-- The popover the top bar's "Soluções documentadas" icon used to open,
             moved here whole. `right-0` with a width wider than the rail makes
             it open LEFTWARDS, over the reading column — the only direction
             with room, and the one that keeps it beside the sentence. --}}
        <div id="docs-solutions-dropdown"
             class="hidden absolute right-0 top-full z-20 mt-1.5 w-96 rounded-field border border-line bg-surface p-4 shadow-xl">
            <x-notebooks.linked-solutions :notebook="$notebook" />
        </div>
    @else
        <p class="px-2 py-1.5 text-[13px] font-normal leading-relaxed text-muted">
            @if ($linked->isEmpty())
                Esse caderno ainda não contempla nenhum sistema.
            @else
                Esse caderno contempla o(s) sistema(s):
                <span class="font-medium text-ink">{{ $names }}</span>
            @endif
        </p>
    @endif
</div>
