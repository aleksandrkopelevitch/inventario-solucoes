{{-- The solutions spreadsheet on its magic link — the same body as the
     inventory's screen (x-solutions.spreadsheet), in a shell of its own: no
     sidebar, no account corner, nothing that would only exist for somebody
     signed in. --}}
<x-layouts.public-sheet title="Soluções">
    <x-solutions.spreadsheet
        :sheet="$sheet"
        :export-url="route('public.solutions.spreadsheet.export', $token)"
        storage-key="isol.solutions-sheet.shared">

        <x-slot:heading>
            <h1 class="shrink-0 font-display text-[17px] font-bold tracking-tight text-ink">Soluções</h1>
        </x-slot:heading>
    </x-solutions.spreadsheet>
</x-layouts.public-sheet>
