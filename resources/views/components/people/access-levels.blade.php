{{-- An account's level in each module (App\Enums\AccessModule), shown on the
     accounts roster and on a person's Acesso card.

     Every module reads as text — "Catálogo · Leitor" — and turns into a
     Leitor/Editor select for an admin (`editable`), saved one module at a time
     through `users.access.update`. An admin account gets one line instead of
     four: it is an Editor everywhere by definition, and four locked selects
     would only invite the question of why they cannot be changed. --}}
@props(['account', 'editable' => false])

@php
    $levelOptions = array_map(
        fn (\App\Enums\AccessLevel $level) => ['value' => $level->value, 'label' => $level->label()],
        \App\Enums\AccessLevel::cases(),
    );
@endphp

<div {{ $attributes->class(['flex flex-wrap items-center gap-1.5']) }}>
    @if ($account->isAdmin())
        <span class="text-[11px] text-muted">Editor em todos os módulos, com exclusão e administração.</span>
    @else
        @foreach (\App\Enums\AccessModule::cases() as $module)
            @php $level = $account->accessLevel($module); @endphp
            <x-ui.inline-edit
                name="{{ $module->value }}"
                type="select"
                :options="$levelOptions"
                :value="$level->value"
                :nullable="false"
                :action="route('users.access.update', $account)"
                :editable="$editable"
                :label="$module->label()"
                edit-class="min-w-32"
                class="shrink-0">
                <span title="{{ $module->label() }}" @class([
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px]',
                    'border-lime-line bg-lime-soft text-lime-ink' => $level === \App\Enums\AccessLevel::Editor,
                    'border-line bg-canvas text-muted' => $level === \App\Enums\AccessLevel::Reader,
                    'border-dashed border-line-2 bg-surface text-faint' => $level === \App\Enums\AccessLevel::None,
                ])>
                    <span class="font-medium">{{ $module->shortLabel() }}</span>
                    <span aria-hidden="true">·</span>
                    <span class="font-semibold">{{ $level->label() }}</span>
                </span>
            </x-ui.inline-edit>
        @endforeach
    @endif
</div>
