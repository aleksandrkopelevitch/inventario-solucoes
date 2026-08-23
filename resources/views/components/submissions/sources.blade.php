{{-- The material, in full: what was read out of each file and anything in it
     that looks like a credential.

     Attaching happens in the composer's 📎 (or by pasting/dropping into it),
     never here — one door, next to the conversation that actually uses the
     material. This card is the manager: the whole list, its state, and the
     way out. --}}
<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-5 shadow-card">
    <div class="flex items-baseline justify-between gap-2">
        <h2 class="font-display text-sm font-bold text-ink">Material</h2>
        @if ($sources->isNotEmpty())
            <span class="text-xs tabular-nums text-muted">{{ $sources->count() }}</span>
        @endif
    </div>

    @if ($sources->isEmpty())
        <p class="mt-2 text-xs text-muted">
            Tem um deck ou documento antigo? Anexe pelo <x-heroicon-o-paper-clip class="inline size-3.5 align-text-bottom" />
            da conversa — ou cole o texto direto na caixa. O assistente lê e já chega com rascunho.
        </p>
    @else
        <ul class="mt-3 flex flex-col gap-2 pl-0">
            @foreach ($sources as $source)
                <li class="group/row flex items-center gap-2.5 rounded-field border border-line px-3 py-2">
                    <x-dynamic-component :component="'heroicon-o-' . $source->kind->icon()" class="size-4 shrink-0 text-muted" />

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm text-ink">
                            @if ($source->media)
                                <a href="{{ route('submissions.sources.show', [$submission, $source]) }}" class="text-ink no-underline hover:underline">{{ $source->label }}</a>
                            @elseif ($source->url)
                                <a href="{{ $source->url }}" target="_blank" rel="noopener noreferrer" class="text-ink no-underline hover:underline">{{ $source->label }}</a>
                            @else
                                {{ $source->label }}
                            @endif
                        </span>
                        <span class="block text-xs text-faint">{{ $source->extraction_state->label() }}</span>
                    </span>

                    @if ($source->hasSensitiveFindings())
                        <span class="shrink-0 rounded-full bg-hot-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-hot"
                              title="{{ collect($source->sensitive_findings)->pluck('type')->implode(', ') }}">
                            credencial?
                        </span>
                    @endif

                    @if ($canEdit)
                        <x-ui.row-remove
                            :id="'remove-source-' . $source->id"
                            :action="route('submissions.sources.destroy', [$submission, $source])"
                            confirm="Remover este material da submissão?"
                            :label="'Remover ' . $source->label"
                            size="small" />
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
