@props([
    // [['title' => , 'url' => , 'hasChildren' => bool, 'hasContent' => bool], ...]
    'pages' => [],
])

{{-- Navigation cards for a page's sub-pages, the way GitBook draws them.

     This is NAVIGATION, not content: it is rendered beside the Markdown and
     never enters `documentation`, so it can't be edited into a stale copy of
     the tree and it costs the editor nothing.

     It exists because the imported corpus leans on it. A GitBook parent page is
     very often EMPTY — "DM - Dados Mestres" is literally `# DM - Dados Mestres`
     and nothing else — because GitBook's own UI lists the children underneath.
     Import that page as-is and it reads as a dead end; the only way out is the
     rail. (Verified against the source: of the parent pages in
     "Dados • BigQuery • GCP", most carry a title and no body.) --}}
@if (! empty($pages))
    <nav class="mt-8" aria-label="Sub-páginas">
        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-faint">
            Nesta seção
        </h2>

        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach ($pages as $child)
                <a href="{{ $child['url'] }}"
                    class="group flex items-center gap-3 rounded-field border border-line bg-surface px-4 py-3 no-underline transition-[border-color,background-color,transform] hover:-translate-y-px hover:border-accent-line hover:bg-accent-soft/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
                    {{-- A folder for a branch, a page for a leaf: the one thing
                         worth knowing before clicking is whether it goes deeper. --}}
                    @if ($child['hasChildren'] ?? false)
                        <x-heroicon-o-folder class="size-4 shrink-0 text-faint" />
                    @else
                        <x-heroicon-o-document-text class="size-4 shrink-0 text-faint" />
                    @endif

                    <span @class([
                        'min-w-0 flex-1 truncate text-sm',
                        'font-medium text-ink' => $child['hasContent'] ?? true,
                        'italic text-muted' => ! ($child['hasContent'] ?? true),
                    ])>
                        {{ $child['title'] }}
                    </span>

                    <x-heroicon-o-chevron-right class="size-4 shrink-0 text-faint transition-transform group-hover:translate-x-0.5" />
                </a>
            @endforeach
        </div>
    </nav>
@endif
