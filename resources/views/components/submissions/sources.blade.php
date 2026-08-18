<div id="{{ $domId }}" class="rounded-card border border-line bg-surface p-5 shadow-card">
    <h2 class="font-display text-sm font-bold text-ink">Material</h2>
    <p class="mt-0.5 text-xs text-muted">Decks antigos, PDFs, imagens e links. O texto é lido no upload.</p>

    @if ($sources->isEmpty())
        <p class="mt-3 text-sm text-muted">Nada anexado ainda.</p>
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

    @if ($canEdit)
        <form id="submission-source-form" class="mt-4 flex flex-col gap-2" enctype="multipart/form-data">
            @csrf
            <x-forms.field label="Anexar arquivo" for="submission-source-file" hint="PDF, PPTX, DOCX, texto ou imagem — até 20 MB.">
                <x-forms.input type="file" id="submission-source-file" name="file" />
            </x-forms.field>
            <x-forms.field label="Ou um link" for="submission-source-url">
                <x-forms.input type="url" id="submission-source-url" name="url" placeholder="https://…" />
            </x-forms.field>
            <x-forms.button class="self-start"
                data-ak-ajax="submission-source-form"
                data-ak-action="{{ route('submissions.sources.store', $submission) }}">
                Anexar
            </x-forms.button>
        </form>
    @endif
</div>
