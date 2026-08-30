@props(['ref', 'title', 'search', 'label', 'attached' => false])

{{-- One attachable document. `data-search` carries the container name too, so
     filtering by system finds its pages even when the words aren't in the
     title. `label` is what the composer's pill will read — the container plus
     the page, since "Visão geral" on its own says nothing.

     FOLDED, not merely lowercased: the filter that reads this attribute folds
     the term the same way (resources/js/modules/fold.js), so "integracao"
     finds "Integração" here exactly as it does in every search that goes to
     the database. Both sides of a comparison or neither. --}}
<li data-ak-fs-picker-row data-search="{{ \App\Support\Fold::text($search) }}">
    <x-forms.label @class([
        '!flex cursor-pointer items-start gap-2.5 rounded-field border border-line-2 px-3 py-2 !text-sm !font-normal !leading-5 !text-body transition-colors',
        'has-checked:border-accent has-checked:bg-accent-soft has-checked:!text-ink' => ! $attached,
        '!cursor-default border-dashed bg-raised !text-faint' => $attached,
    ])>
        <x-forms.checkbox name="documents[]" :value="$ref" :checked="$attached" :disabled="$attached"
            data-ak-fs-picker-item :data-picker-label="$label" class="mt-0.5 shrink-0" />
        <span class="min-w-0 flex-1">
            <span class="block truncate">{{ $title }}</span>
            @if ($attached)
                <span class="block text-[11px]">Já está no contexto</span>
            @endif
        </span>
    </x-forms.label>
</li>
