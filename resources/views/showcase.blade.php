<x-layouts.layout title="Componentes">
    <div class="mx-auto max-w-3xl">
        <div class="mb-6">
            <span class="inline-flex items-center rounded-md bg-accent px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] text-white">Design system</span>
            <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Componentes de formulário</h1>
            <p class="mt-1 text-sm text-muted">Identidade Leo Madeiras — verde institucional e lime, tipografia Barlow.</p>
        </div>

        <div class="space-y-6 rounded-card border border-line bg-surface p-8 text-body shadow-[0_1px_3px_rgba(20,58,34,0.04),0_14px_32px_-16px_rgba(20,58,34,0.12)]">
            <form id="showcase-form" class="space-y-6">
                @csrf

                <x-forms.field label="Input" for="sc-input" name="input" hint="x-forms.input dentro de x-forms.field">
                    <x-forms.input id="sc-input" name="input" placeholder="Digite algo" />
                </x-forms.field>

                <x-forms.field label="Select" for="sc-select" name="select">
                    <x-forms.select id="sc-select" name="select">
                        <option value="">Selecione…</option>
                        <option value="a">Opção A</option>
                        <option value="b">Opção B</option>
                    </x-forms.select>
                </x-forms.field>

                <x-forms.field label="Textarea" for="sc-textarea" name="textarea">
                    <x-forms.textarea id="sc-textarea" name="textarea" rows="3" placeholder="Texto longo" />
                </x-forms.field>

                <x-forms.field label="File" for="sc-file" name="file">
                    <x-forms.file id="sc-file" name="file" />
                </x-forms.field>

                <x-forms.field label="Checkbox">
                    <label class="flex items-center gap-2 text-sm text-body">
                        <x-forms.checkbox name="checkbox" /> Aceito os termos
                    </label>
                </x-forms.field>

                <x-forms.field label="Radio group">
                    <x-forms.radio-group>
                        <x-forms.radio name="radio" value="sim">Sim</x-forms.radio>
                        <x-forms.radio name="radio" value="nao">Não</x-forms.radio>
                    </x-forms.radio-group>
                </x-forms.field>

                <hr class="border-line">
                <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.1em] text-muted">Novos (Etapa 0)</p>

                <x-forms.field label="Toggle (booleano)">
                    <x-forms.toggle name="toggle">Ativo</x-forms.toggle>
                </x-forms.field>

                <x-forms.field label="Image upload">
                    <x-forms.image-upload name="imagem" />
                </x-forms.field>

                <x-forms.field label="Chips (seleção múltipla com papel)" hint="Digite e pressione Enter para adicionar">
                    <x-forms.chips
                        name="responsaveis"
                        :items="[['value' => 'ana', 'label' => 'Ana', 'role' => 'owner']]"
                        :roles="[['value' => 'owner', 'label' => 'Responsável'], ['value' => 'contributor', 'label' => 'Colaborador']]"
                    />
                </x-forms.field>

                <div class="pt-2">
                    <x-forms.button type="button">Botão</x-forms.button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.layout>
