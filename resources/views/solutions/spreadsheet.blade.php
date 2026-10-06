{{-- The catalog as a read-only spreadsheet. Fluid and full height, like the
     map: the grid IS the content, and a hero above it would push the rows
     below the fold. The body is shared with the magic link
     (x-solutions.spreadsheet). --}}
<x-layouts.layout title="Planilha de soluções" :fluid="true">
    <x-solutions.spreadsheet
        :sheet="$sheet"
        :export-url="route('solutions.spreadsheet.export')"
        storage-key="isol.solutions-sheet.internal">

        <x-slot:heading>
            <a href="{{ route('solutions.index') }}"
               class="inline-flex shrink-0 items-center gap-1 text-[13px] font-medium text-muted no-underline hover:text-ink">
                <x-heroicon-o-arrow-left class="size-4" /> Soluções
            </a>
            <span class="text-line-2">/</span>
            <h1 class="shrink-0 font-display text-[17px] font-bold tracking-tight text-ink">Planilha</h1>
        </x-slot:heading>

        <x-slot:actions>
            @can('share', \App\Models\Solution::class)
                <div class="relative">
                    <x-forms.button type="button" variant="ghost"
                        data-ak-toggle="sheet-share-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                        class="!h-9 !w-9 !p-0" aria-label="Compartilhar planilha" title="Compartilhar planilha">
                        <x-heroicon-o-share class="size-5" />
                    </x-forms.button>
                    <div id="sheet-share-dropdown" class="hidden absolute right-0 top-full z-40 mt-1.5 w-80 rounded-field border border-line bg-surface p-4 shadow-xl">
                        <x-solutions.spreadsheet-share-panel />
                    </div>
                </div>
            @endcan
        </x-slot:actions>
    </x-solutions.spreadsheet>
</x-layouts.layout>
