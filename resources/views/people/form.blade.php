@php
    $editing = $person->exists;
    $action = $editing
        ? route('people.update', ['person' => $person, 'filter' => $filters ?? []])
        : route('people.store', ['filter' => $filters ?? []]);
    $photoUrl = $person->photo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($person->photo_path) : null;

    $solutionItems = $editing
        ? $person->solutions->map(fn ($s) => ['value' => $s->id, 'label' => $s->name, 'role' => $s->pivot->role])->all()
        : [];
    $roleOptions = collect($roles)->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])->all();
    // Additional contacts (`Person::contacts()`) — the single email/phone in
    // the fields below is only half the story: a person can have several
    // contacts (e.g. 2 vendor emails), each with its own type. Without this
    // the form could never edit what the detail header already showed (see
    // the Solutions\DetailHeader equivalent, `components/people/detail-header.blade.php`).
    $contactItems = $editing
        ? $person->contacts->map(fn ($c) => ['id' => $c->id, 'type' => $c->type->value, 'value' => $c->value])->values()->all()
        : [];
@endphp

<div class="flex items-center justify-between border-b border-line px-5 py-4">
    <h2 class="font-display text-lg font-semibold text-ink">{{ $editing ? 'Editar pessoa' : 'Nova pessoa' }}</h2>
    <a href="#" data-ak-panel-close class="rounded-field p-1 text-xl leading-none text-faint hover:text-ink">&times;</a>
</div>

<form id="person-form" class="flex flex-1 flex-col overflow-hidden">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
        <x-forms.field label="Nome" for="p-name" name="name" required>
            <x-forms.input id="p-name" name="name" :value="old('name', $person->name)" required />
        </x-forms.field>

        <x-forms.field label="Empresa" for="p-company" name="company_id">
            <x-forms.select id="p-company" name="company_id">
                <option value="">—</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->id }}" @selected((string) old('company_id', $person->company_id) === (string) $company->id)>{{ $company->name }}</option>
                @endforeach
            </x-forms.select>
        </x-forms.field>

        <x-forms.field label="Cargo" for="p-job" name="job_title">
            <x-forms.input id="p-job" name="job_title" :value="old('job_title', $person->job_title)" />
        </x-forms.field>

        <div class="grid grid-cols-2 gap-3">
            <x-forms.field label="E-mail" for="p-email" name="email">
                <x-forms.input id="p-email" name="email" type="email" :value="old('email', $person->email)" />
            </x-forms.field>

            <x-forms.field label="Telefone" for="p-phone" name="phone">
                <x-forms.input id="p-phone" name="phone" :value="old('phone', $person->phone)" />
            </x-forms.field>
        </div>

        {{-- Additional contacts: repeatable type+value+label rows, besides the
             single e-mail/phone above — `resources/js/modules/person-contacts.js`
             adds/removes rows on the client; the server syncs
             `Person::contacts()` (`PersonController::syncContacts()`). --}}
        <x-forms.field label="Contatos adicionais" name="contacts"
            hint="Outros e-mails/telefones (ex.: mais de um contato do fornecedor).">
            {{-- Permanent marker, outside `data-ak-contacts-list` so add/remove-row
                 JS never touches it: guarantees the request always carries SOME
                 `contacts`-related key even when the user deletes every row, so
                 `PersonController::syncContacts()` can tell "the form's contacts
                 section was submitted, empty" apart from "this request never
                 mentioned contacts at all" (a real caller — see
                 PersonContactsSyncTest's "omits the key entirely" case). Without
                 this, removing every row submits no `contacts[...]` key at all
                 and the deletion silently never persists. --}}
            <input type="hidden" name="contacts_present" value="1">
            <div data-ak-contacts data-ak-contacts-next="{{ count($contactItems) }}" class="flex flex-col gap-2">
                <div data-ak-contacts-list class="flex flex-col gap-2 empty:hidden">
                    @foreach ($contactItems as $i => $contact)
                        <div data-ak-contact-row class="flex items-start gap-1.5">
                            <input type="hidden" name="contacts[{{ $i }}][id]" value="{{ $contact['id'] }}">
                            <div class="w-[104px] shrink-0">
                                <x-forms.select name="contacts[{{ $i }}][type]" class="!h-9 !py-0 !pl-2.5 !pr-6 !text-xs">
                                    @foreach ($contactTypes as $type)
                                        <option value="{{ $type->value }}" @selected($contact['type'] === $type->value)>{{ $type->label() }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                            <x-forms.input name="contacts[{{ $i }}][value]" value="{{ $contact['value'] }}"
                                placeholder="valor" class="!h-9 !flex-1 !py-0 !text-xs" />
                            <x-forms.button type="button" variant="ghost" data-ak-contact-remove title="Remover contato"
                                class="!h-9 !shrink-0 !p-2 !text-muted hover:!text-crit">
                                <x-heroicon-o-trash class="size-4" />
                            </x-forms.button>
                        </div>
                    @endforeach
                </div>
                <x-forms.button type="button" variant="ghost" data-ak-contact-add
                    class="!self-start !px-2 !py-1 !text-xs">
                    <x-heroicon-o-plus class="size-4" /> Adicionar contato
                </x-forms.button>
            </div>
        </x-forms.field>

        <x-forms.field label="Sistemas (papel por vínculo)" name="solutions"
            hint="Digite o nome da solução e escolha na lista. Vínculos existentes vêm pré-carregados.">
            {{-- Same "presence" marker as `contacts_present` above, for the same
                 reason: chips.js's hidden inputs live inside each removable chip,
                 so clearing every chip submits no `solutions[...]` key at all —
                 without this, `PersonController::syncSolutions()` can't tell that
                 apart from a request that never mentioned solutions, and the
                 removal silently never persists. Not added to the shared
                 `chips.blade.php` component itself: the flowSpec composer reuses
                 that same component with `required` per-row validation on
                 `solutions.*.value`/`documents.*.value`, which a blank sentinel
                 row would fail — this marker is a separate top-level field, not
                 part of the `solutions[]` array, so it can't collide with that. --}}
            <input type="hidden" name="solutions_present" value="1">
            <x-forms.chips name="solutions" :items="$solutionItems" :roles="$roleOptions"
                :search-url="route('solutions.search')" placeholder="Nome da solução…" />
        </x-forms.field>

        {{-- The same field is read back as formatted text (x-ui.markdown), so
             the panel says so too — the inline editor on the detail page
             carries the same line. --}}
        <x-forms.field label="Notas" for="p-notes" name="notes"
            hint="Aceita Markdown: **negrito**, - lista, [link](url).">
            <x-forms.textarea id="p-notes" name="notes" rows="2">{{ old('notes', $person->notes) }}</x-forms.textarea>
        </x-forms.field>

        <x-forms.field label="Foto" name="photo">
            <x-forms.image-upload name="photo" :value="$photoUrl" size="h-24 w-24" />
        </x-forms.field>
    </div>

    <div class="border-t border-line px-5 py-4">
        <x-forms.button data-ak-ajax="person-form" data-ak-action="{{ $action }}" class="w-full">Salvar</x-forms.button>
    </div>
</form>
