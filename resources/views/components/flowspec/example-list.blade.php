<div id="{{ $domId }}" class="space-y-3">
    @forelse ($examples as $example)
        <details class="rounded-field border border-line bg-canvas">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm">
                <span class="min-w-0 flex-1 truncate font-medium text-ink">{{ $example->name }}</span>
                @unless ($example->is_active)
                    <span class="shrink-0 rounded-full border border-line bg-raised px-2 py-0.5 text-[11px] text-muted">inativo</span>
                @endunless
                <span class="shrink-0 truncate text-[11px] text-faint">{{ implode(', ', $example->tags) }}</span>
                <span class="shrink-0 rounded-full border border-line bg-raised px-2 py-0.5 text-[11px] text-muted">{{ $example->source }}</span>
            </summary>

            <form id="flowspec-example-form-{{ $example->id }}" class="flex flex-col gap-3 border-t border-line p-3">
                @csrf
                @method('PATCH')
                <x-forms.field label="Nome" for="flowspec-example-name-{{ $example->id }}" name="name" required>
                    <x-forms.input id="flowspec-example-name-{{ $example->id }}" name="name" :value="$example->name" />
                </x-forms.field>
                <x-forms.field label="Descrição (o que o pipeline faz)" for="flowspec-example-description-{{ $example->id }}" name="description" required>
                    <x-forms.textarea id="flowspec-example-description-{{ $example->id }}" name="description" rows="2">{{ $example->description }}</x-forms.textarea>
                </x-forms.field>
                <x-forms.field label="Tags" for="flowspec-example-tags-{{ $example->id }}" name="tags" required hint="Segure Ctrl/Cmd para marcar mais de uma.">
                    <x-forms.select id="flowspec-example-tags-{{ $example->id }}" name="tags[]" multiple size="5">
                        @foreach ($tags as $tag)
                            <option value="{{ $tag->value }}" @selected(in_array($tag->value, $example->tags, true))>{{ $tag->value }}</option>
                        @endforeach
                    </x-forms.select>
                </x-forms.field>
                <x-forms.field label="flowSpec (JSON)" for="flowspec-example-json-{{ $example->id }}" name="flow_spec" required>
                    <x-forms.textarea id="flowspec-example-json-{{ $example->id }}" name="flow_spec" rows="10" class="!font-mono !text-[11.5px] !leading-relaxed">{{ json_encode($example->flow_spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</x-forms.textarea>
                </x-forms.field>
                <x-forms.toggle name="is_active" :checked="$example->is_active">Ativo (considerado na geração)</x-forms.toggle>
                <div class="flex items-center gap-2">
                    <x-forms.button data-ak-ajax="flowspec-example-form-{{ $example->id }}"
                        data-ak-action="{{ route('flowspec.examples.update', $example) }}"
                        class="!px-3 !py-1.5 text-xs">
                        Salvar
                    </x-forms.button>
                    <x-forms.button type="button" data-ak-ajax="flowspec-example-delete-{{ $example->id }}"
                        data-ak-action="{{ route('flowspec.examples.destroy', $example) }}"
                        data-ak-confirm="Remover &quot;{{ $example->name }}&quot; do corpus?"
                        class="!shrink-0 !bg-transparent !p-1.5 !text-faint !shadow-none hover:!bg-raised hover:!text-crit"
                        title="Excluir">
                        <x-heroicon-o-trash class="size-4" />
                    </x-forms.button>
                </div>
            </form>
        </details>
        <form id="flowspec-example-delete-{{ $example->id }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @empty
        <p class="text-sm text-muted">Nenhum exemplo no corpus ainda.</p>
    @endforelse

    {{-- Novo exemplo — colar um documento {meta, flowSpec} completo. --}}
    <form id="flowspec-example-create" class="flex flex-col gap-3 border-t border-line pt-4">
        @csrf
        <p class="text-sm font-semibold text-ink">Novo exemplo</p>
        <x-forms.field label="Nome" for="flowspec-example-new-name" name="name" required>
            <x-forms.input id="flowspec-example-new-name" name="name" placeholder="Ex.: Consulta de produto via REST" />
        </x-forms.field>
        <x-forms.field label="Descrição (o que o pipeline faz)" for="flowspec-example-new-description" name="description" required>
            <x-forms.textarea id="flowspec-example-new-description" name="description" rows="2" />
        </x-forms.field>
        <x-forms.field label="Tags" for="flowspec-example-new-tags" name="tags" required hint="Segure Ctrl/Cmd para marcar mais de uma.">
            <x-forms.select id="flowspec-example-new-tags" name="tags[]" multiple size="5">
                @foreach ($tags as $tag)
                    <option value="{{ $tag->value }}">{{ $tag->value }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>
        <x-forms.field label="flowSpec (JSON)" for="flowspec-example-new-json" name="flow_spec" required hint='Documento completo no formato {"meta": ..., "flowSpec": ...}.'>
            <x-forms.textarea id="flowspec-example-new-json" name="flow_spec" rows="10" class="!font-mono !text-[11.5px] !leading-relaxed" placeholder='{"meta": { ... }, "flowSpec": { ... }}' />
        </x-forms.field>
        <div>
            <x-forms.button data-ak-ajax="flowspec-example-create"
                data-ak-action="{{ route('flowspec.examples.store') }}"
                class="!px-3 !py-1.5 text-xs">
                Adicionar ao corpus
            </x-forms.button>
        </div>
    </form>
</div>
