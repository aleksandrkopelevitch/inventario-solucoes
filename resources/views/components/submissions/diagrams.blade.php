{{-- The four drawings, as four slots. Drawn kinds link out to the canvas's
     own page; C4 kinds take an upload right here. --}}
<div id="{{ $domId }}" class="flex flex-col gap-4">
<div class="grid gap-4 sm:grid-cols-2">
    @foreach ($rows as $row)
        @php
            $kind = $row['kind'];
            $diagram = $row['diagram'];
            $formId = 'diagram-upload-' . $kind->value;
        @endphp

        <article class="group/row flex flex-col rounded-card border border-line bg-surface p-5 shadow-card">
            <header class="mb-1 flex flex-wrap items-center gap-2">
                <h3 class="font-display text-sm font-bold text-ink">{{ $kind->label() }}</h3>

                @if ($row['filled'])
                    <span class="inline-flex items-center gap-1 rounded-full bg-cat-emerald-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-cat-emerald-ink ring-1 ring-cat-emerald-line">
                        <x-heroicon-o-check class="size-3" /> Pronto
                    </span>
                @else
                    <span class="rounded-full bg-raised px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted">Em branco</span>
                @endif
            </header>

            <p class="text-xs text-muted">{{ $kind->hint() }}</p>

            <div class="mt-3 flex min-h-32 flex-1 items-center justify-center overflow-hidden rounded-field border border-line bg-raised">
                @if ($row['picture'] && $diagram)
                    <img src="{{ route('submissions.diagrams.picture.show', [$submission, $diagram]) }}"
                         alt="{{ $kind->label() }}" class="max-h-48 w-full object-contain" />
                @else
                    <span class="px-4 py-6 text-center text-xs text-faint">
                        {{ $kind->isDrawn() ? 'Nada desenhado ainda.' : 'Nenhuma imagem enviada.' }}
                    </span>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if ($kind->isDrawn())
                    <x-forms.button type="button" variant="glass"
                        onclick="window.location='{{ route('submissions.diagrams.edit', [$submission, $diagram]) }}'">
                        <x-heroicon-o-pencil-square class="size-4" />
                        {{ $row['filled'] ? 'Editar no canvas' : 'Desenhar' }}
                    </x-forms.button>
                @elseif ($canEdit)
                    {{-- The label doubles as the picker: an "Escolher arquivo"
                         input plus a separate "Enviar" button is the step users
                         skip, so the change event uploads straight away
                         (data-ak-cati-diagram-upload). --}}
                    <form id="{{ $formId }}" class="contents" enctype="multipart/form-data">
                        @csrf
                        <x-forms.input type="file" name="image" class="!hidden"
                            accept=".png,.jpg,.jpeg,.webp,.svg"
                            data-ak-cati-diagram-upload="{{ route('submissions.diagrams.upload.store', [$submission, $diagram]) }}" />
                    </form>
                    <x-forms.button type="button" variant="glass" data-ak-cati-diagram-pick="{{ $formId }}">
                        <x-heroicon-o-arrow-up-tray class="size-4" />
                        {{ $row['filled'] ? 'Substituir' : 'Enviar imagem' }}
                    </x-forms.button>

                    @if ($row['filled'])
                        <form id="{{ $formId }}-remove" class="hidden">
                            @csrf
                            @method('DELETE')
                        </form>
                        <x-forms.button type="button" variant="ghost" class="!px-2.5 !text-xs"
                            data-ak-ajax="{{ $formId }}-remove"
                            data-ak-action="{{ route('submissions.diagrams.upload.destroy', [$submission, $diagram]) }}"
                            data-ak-confirm="Remover este diagrama?">
                            <x-heroicon-o-trash class="size-4" /> Remover
                        </x-forms.button>
                    @endif
                @endif
            </div>
        </article>
    @endforeach
</div>

{{-- "O que muda" — the AS IS compared with the TO BE.

     `SubmissionDiagramKind`'s docblock says these two are DRAWN rather than
     uploaded because a picture is "diffable against nothing". This is that
     diff: two validated specs walked by `archify compare`, which reports what
     was added, removed, changed, moved and rerouted. No model is involved and
     none should be — the same two canvases must produce the same answer every
     time, which is the only reason it is worth putting in front of a committee.

     Offered only once both canvases hold something (`$canCompare`): an empty
     AS IS is a legitimate state, and "everything was added" is what the TO BE
     already says on its own. --}}
@if ($canCompare || $delta)
    <article class="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 shadow-card">
        <header class="flex flex-wrap items-center gap-2">
            <h3 class="font-display text-sm font-bold text-ink">O que muda</h3>
            <span class="text-xs text-muted">AS IS × TO BE, bloco a bloco</span>
        </header>

        @if ($delta)
            @php($blocks = $deltaCounts['components'] ?? [])
            @php($links = $deltaCounts['connections'] ?? [])

            {{-- The receipt's own counts, stated rather than implied. An
                 unchanged pair says so in words instead of showing an empty
                 row of zeros. --}}
            @if ($blocks || $links)
                <ul class="flex flex-wrap gap-1.5">
                    @foreach ([['Blocos', $blocks], ['Ligações', $links]] as [$family, $counts])
                        @foreach ($counts as $kind => $count)
                            <li class="rounded-full bg-raised px-2.5 py-1 text-[11px] font-semibold text-ink">
                                {{ $family }}: {{ $count }} {{ ['added' => 'a mais', 'removed' => 'a menos', 'changed' => 'alterado(s)', 'moved' => 'movido(s)', 'rerouted' => 'com rota nova'][$kind] ?? $kind }}
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            @else
                <p class="text-xs text-muted">Os dois desenhos dizem a mesma coisa — nenhuma diferença de topologia.</p>
            @endif
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @if ($delta)
                <a href="{{ route('submissions.topology-delta.show', $submission) }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 rounded-field border border-line px-3 py-1.5 text-xs font-semibold text-ink hover:bg-accent-soft">
                    <x-heroicon-o-arrows-right-left class="size-4" /> Abrir comparação
                </a>
            @endif

            @if ($canEdit && $canCompare)
                <form id="submission-topology-delta" class="contents">
                    <x-forms.button variant="{{ $delta ? 'ghost' : 'glass' }}" class="!px-3 !py-1.5 !text-xs"
                        data-ak-ajax="submission-topology-delta"
                        data-ak-action="{{ route('submissions.topology-delta.store', $submission) }}">
                        <x-heroicon-o-arrow-path class="size-4" />
                        {{ $delta ? 'Gerar de novo' : 'Comparar os dois desenhos' }}
                    </x-forms.button>
                </form>
            @endif
        </div>

        @if ($delta)
            <p class="text-[11px] text-muted">
                Gerado em
                {{ \Illuminate\Support\Carbon::parse($delta->getCustomProperty('generated_at'))->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                — os desenhos podem ter mudado desde então.
            </p>
        @endif
    </article>
@endif
</div>
