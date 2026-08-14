@php
    $editing = $solution->exists;
    $action = $editing
        ? route('solutions.update', ['solution' => $solution, 'filter' => $filters ?? []])
        : route('solutions.store', ['filter' => $filters ?? []]);
    $logoUrl = $solution->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($solution->logo_path) : null;
@endphp

<div class="flex items-center justify-between border-b border-line px-5 py-4">
    <h2 class="font-display text-lg font-semibold text-ink">{{ $editing ? 'Editar solução' : 'Nova solução' }}</h2>
    <a href="#" data-ak-panel-close class="rounded-field p-1 text-xl leading-none text-faint hover:text-ink">&times;</a>
</div>

<form id="solution-form" class="flex flex-1 flex-col overflow-hidden">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
        <x-forms.field label="Nome" for="sol-name" name="name" required>
            <x-forms.input id="sol-name" name="name" :value="old('name', $solution->name)" required />
        </x-forms.field>

        <x-forms.field label="Descrição" for="sol-desc" name="description"
            hint="Aceita Markdown: **negrito**, - lista, [link](url).">
            <x-forms.textarea id="sol-desc" name="description" rows="2">{{ old('description', $solution->description) }}</x-forms.textarea>
        </x-forms.field>

        <x-forms.field label="Fornecedor" for="sol-vendor" name="vendor_company_id">
            <x-forms.select id="sol-vendor" name="vendor_company_id">
                <option value="">Sem fornecedor</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}" @selected((string) old('vendor_company_id', $solution->vendor_company_id) === (string) $company->id)>{{ $company->name }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>

        <div class="grid grid-cols-2 gap-3">
            <x-forms.field label="Categoria" for="sol-category" name="category" required>
                <x-forms.select id="sol-category" name="category" required data-ak-attribute-select="category"
                    data-ak-attribute-options-url="{{ route('attribute-options.options', 'category') }}">
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}" @selected(old('category', $solution->category) === $option->value)>{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>

            <x-forms.field label="Status" for="sol-status" name="status" required>
                <x-forms.select id="sol-status" name="status" required data-ak-attribute-select="status"
                    data-ak-attribute-options-url="{{ route('attribute-options.options', 'status') }}">
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}" @selected(old('status', $solution->status ?? 'active') === $option->value)>{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>
        </div>

        <x-forms.field label="Diretoria" for="sol-dir" name="directorate">
            <x-forms.select id="sol-dir" name="directorate" data-ak-attribute-select="directorate"
                data-ak-attribute-options-url="{{ route('attribute-options.options', 'directorate') }}">
                <option value="">—</option>
                @foreach ($directorates as $option)
                    <option value="{{ $option->value }}" @selected(old('directorate', $solution->directorate) === $option->value)>{{ $option->label }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>

        <div class="grid grid-cols-2 gap-3">
            <x-forms.field label="Hospedagem" for="sol-env" name="environment">
                <x-forms.select id="sol-env" name="environment" data-ak-attribute-select="environment"
                    data-ak-attribute-options-url="{{ route('attribute-options.options', 'environment') }}">
                    <option value="">—</option>
                    @foreach ($environments as $option)
                        <option value="{{ $option->value }}" @selected(old('environment', $solution->environment) === $option->value)>{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>

            <x-forms.field label="Cloud" for="sol-cloud" name="cloud">
                <x-forms.select id="sol-cloud" name="cloud" data-ak-attribute-select="cloud"
                    data-ak-attribute-options-url="{{ route('attribute-options.options', 'cloud') }}">
                    <option value="">—</option>
                    @foreach ($clouds as $option)
                        <option value="{{ $option->value }}" @selected(old('cloud', $solution->cloud) === $option->value)>{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <x-forms.field label="Contrato" for="sol-contract" name="contract_status">
                <x-forms.select id="sol-contract" name="contract_status" data-ak-attribute-select="contract_status"
                    data-ak-attribute-options-url="{{ route('attribute-options.options', 'contract_status') }}">
                    <option value="">—</option>
                    @foreach ($contractStatuses as $option)
                        <option value="{{ $option->value }}" @selected(old('contract_status', $solution->contract_status) === $option->value)>{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>

            <x-forms.field label="Suporte" for="sol-support" name="support_type">
                <x-forms.select id="sol-support" name="support_type" data-ak-attribute-select="support_type"
                    data-ak-attribute-options-url="{{ route('attribute-options.options', 'support_type') }}">
                    <option value="">—</option>
                    @foreach ($supportTypes as $option)
                        <option value="{{ $option->value }}" @selected(old('support_type', $solution->support_type) === $option->value)>{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>
        </div>

        <x-forms.field label="Criticidade" for="sol-crit" name="criticality">
            <x-forms.select id="sol-crit" name="criticality" data-ak-attribute-select="criticality"
                data-ak-attribute-options-url="{{ route('attribute-options.options', 'criticality') }}">
                <option value="">—</option>
                @foreach ($criticalities as $option)
                    <option value="{{ $option->value }}" @selected(old('criticality', $solution->criticality) === $option->value)>{{ $option->label }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>

        <a href="#" data-ak-modal-open="main-modal" data-ak-modal-url="{{ route('attribute-options.index') }}"
           class="inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline">
            <x-heroicon-o-adjustments-horizontal class="size-3.5" /> Gerenciar valores de atributos
        </a>

        <x-forms.field label="Nota de suporte x operação" for="sol-opnote" name="support_operation_note"
            hint="Sinaliza gap entre suporte contratado e operação real. Aceita Markdown.">
            <x-forms.textarea id="sol-opnote" name="support_operation_note" rows="2">{{ old('support_operation_note', $solution->support_operation_note) }}</x-forms.textarea>
        </x-forms.field>

        <x-forms.field label="Logo" name="logo">
            <x-forms.image-upload name="logo" :value="$logoUrl" size="h-24 w-24" />
        </x-forms.field>
    </div>

    <div class="border-t border-line px-5 py-4">
        <x-forms.button data-ak-ajax="solution-form" data-ak-action="{{ $action }}" class="w-full">Salvar</x-forms.button>
    </div>
</form>
