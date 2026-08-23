{{-- The four drawings, as four slots. Drawn kinds link out to the canvas's
     own page; C4 kinds take an upload right here. --}}
<div id="{{ $domId }}" class="grid gap-4 sm:grid-cols-2">
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
