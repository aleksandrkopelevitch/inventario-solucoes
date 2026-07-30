<div id="{{ $domId }}" class="space-y-3">
    @if ($guidelines->isNotEmpty())
        <p @class([
            'rounded-field border px-3 py-2 text-xs',
            'border-hot-line bg-hot-soft text-hot' => $totalActiveChars > 40000,
            'border-line bg-canvas text-muted' => $totalActiveChars <= 40000,
        ])>
            {{ $guidelines->where('is_active', true)->count() }} {{ $guidelines->where('is_active', true)->count() === 1 ? 'diretriz ativa' : 'diretrizes ativas' }},
            ~{{ number_format($totalActiveChars / 1000, 1) }} mil caracteres somados
        </p>
    @endif

    @forelse ($guidelines as $guideline)
        <details class="rounded-field border border-line bg-canvas">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm">
                <span class="min-w-0 flex-1 truncate font-medium text-ink">{{ $guideline->title }}</span>
                @unless ($guideline->is_active)
                    <span class="shrink-0 rounded-full border border-line bg-raised px-2 py-0.5 text-[11px] text-muted">inativo</span>
                @endunless
                <span class="shrink-0 rounded-full border border-line bg-raised px-2 py-0.5 text-[11px] text-muted">{{ $guideline->source }}</span>
            </summary>

            <form id="flowspec-guideline-form-{{ $guideline->id }}" class="flex flex-col gap-3 border-t border-line p-3">
                @csrf
                @method('PATCH')
                <x-forms.field label="Título" for="flowspec-guideline-title-{{ $guideline->id }}" name="title" required>
                    <x-forms.input id="flowspec-guideline-title-{{ $guideline->id }}" name="title" :value="$guideline->title" />
                </x-forms.field>
                <x-forms.field label="Conteúdo (Markdown)" for="flowspec-guideline-content-{{ $guideline->id }}" name="content" required>
                    <x-forms.textarea id="flowspec-guideline-content-{{ $guideline->id }}" name="content" rows="14">{{ $guideline->content }}</x-forms.textarea>
                </x-forms.field>
                <x-forms.toggle name="is_active" :checked="$guideline->is_active">Ativa (sempre incluída na geração)</x-forms.toggle>
                <div class="flex items-center gap-2">
                    <x-forms.button data-ak-ajax="flowspec-guideline-form-{{ $guideline->id }}"
                        data-ak-action="{{ route('flowspec.guidelines.update', $guideline) }}"
                        class="!px-3 !py-1.5 text-xs">
                        Salvar
                    </x-forms.button>
                    <x-forms.button type="button" data-ak-ajax="flowspec-guideline-delete-{{ $guideline->id }}"
                        data-ak-action="{{ route('flowspec.guidelines.destroy', $guideline) }}"
                        data-ak-confirm="Remover &quot;{{ $guideline->title }}&quot; das diretrizes?"
                        class="!shrink-0 !bg-transparent !p-1.5 !text-faint !shadow-none hover:!bg-raised hover:!text-crit"
                        title="Excluir">
                        <x-heroicon-o-trash class="size-4" />
                    </x-forms.button>
                </div>
            </form>
        </details>
        <form id="flowspec-guideline-delete-{{ $guideline->id }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @empty
        <p class="text-sm text-muted">Nenhuma diretriz cadastrada ainda.</p>
    @endforelse

    {{-- Nova diretriz — um documento de texto por assunto, ex.: boas práticas Digibee. --}}
    <form id="flowspec-guideline-create" class="flex flex-col gap-3 border-t border-line pt-4">
        @csrf
        <p class="text-sm font-semibold text-ink">Nova diretriz</p>
        <x-forms.field label="Título" for="flowspec-guideline-new-title" name="title" required>
            <x-forms.input id="flowspec-guideline-new-title" name="title" placeholder="Ex.: Boas práticas Digibee" />
        </x-forms.field>
        <x-forms.field label="Conteúdo (Markdown)" for="flowspec-guideline-new-content" name="content" required hint="Sempre incluído na geração — escreva como uma nota de boas práticas.">
            <x-forms.textarea id="flowspec-guideline-new-content" name="content" rows="14" />
        </x-forms.field>
        <div>
            <x-forms.button data-ak-ajax="flowspec-guideline-create"
                data-ak-action="{{ route('flowspec.guidelines.store') }}"
                class="!px-3 !py-1.5 text-xs">
                Adicionar diretriz
            </x-forms.button>
        </div>
    </form>
</div>
