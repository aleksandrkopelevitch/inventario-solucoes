@php
    $editing = $company->exists;
    $action = $editing
        ? route('companies.update', ['company' => $company, 'filter' => $filters ?? []])
        : route('companies.store', ['filter' => $filters ?? []]);
    $logoUrl = $company->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo_path) : null;
@endphp

<div class="flex items-center justify-between border-b border-line px-5 py-4">
    <h2 class="font-display text-lg font-semibold text-ink">{{ $editing ? 'Editar empresa' : 'Nova empresa' }}</h2>
    <a href="#" data-ak-panel-close class="rounded-field p-1 text-xl leading-none text-faint hover:text-ink">&times;</a>
</div>

<form id="company-form" class="flex flex-1 flex-col overflow-hidden">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
        <x-forms.field label="Nome" for="c-name" name="name" required>
            <x-forms.input id="c-name" name="name" :value="old('name', $company->name)" required />
        </x-forms.field>

        <x-forms.field label="Tipo" for="c-kind" name="kind" required>
            <x-forms.select id="c-kind" name="kind" required>
                @foreach ($kinds as $case)
                    <option value="{{ $case->value }}" @selected(old('kind', $company->kind?->value ?? 'vendor') === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>

        <x-forms.field label="Site" for="c-website" name="website">
            <x-forms.input id="c-website" name="website" type="url" :value="old('website', $company->website)" placeholder="https://" />
        </x-forms.field>

        <x-forms.field label="Notas" for="c-notes" name="notes"
            hint="Aceita Markdown: **negrito**, - lista, [link](url).">
            <x-forms.textarea id="c-notes" name="notes" rows="3">{{ old('notes', $company->notes) }}</x-forms.textarea>
        </x-forms.field>

        <x-forms.field label="Logo" name="logo">
            <x-forms.image-upload name="logo" :value="$logoUrl" size="h-24 w-24" />
        </x-forms.field>
    </div>

    <div class="border-t border-line px-5 py-4">
        <x-forms.button data-ak-ajax="company-form" data-ak-action="{{ $action }}" class="w-full">Salvar</x-forms.button>
    </div>
</form>
