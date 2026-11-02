@props([
    'publicUrl',             // the live magic link, or null while there is none
    'shareUrl',              // POST: generates it
    'unshareUrl',            // DELETE: revokes it
    'formId',                // prefix for the two hidden forms; unique on the page
    'shared',                // PT-BR: what a reader of the link sees
    'unshared',              // PT-BR: the invitation to generate one
    'fieldLabel' => 'Link público',
])

{{-- "Link público (sem login)" — the magic link of a caderno and of the
     solutions spreadsheet, which are the same control with different words.
     Behaviour is the app's general hooks, not a module of its own: the two
     hidden forms go through ajax-post.js (data-ak-ajax, with its confirm), and
     the copy button through copy-field.js. That used to be docs-share.js, which
     re-implemented both. The revoke is a link-styled action on a bodyless form,
     one of the raw-button exceptions in AGENTS.md. --}}
<div {{ $attributes }}>
    <h4 class="text-sm font-semibold text-ink">Link público (sem login)</h4>

    <form id="{{ $formId }}-share" class="hidden">@csrf</form>
    <form id="{{ $formId }}-unshare" class="hidden">@csrf @method('DELETE')</form>

    @if ($publicUrl)
        <p class="mt-1 text-sm text-muted">{{ $shared }}</p>

        <x-ui.copy-field class="mt-3" :value="$publicUrl" :label="$fieldLabel"
            message="Link copiado." copy-label="Copiar link" />

        <div class="mt-3 flex items-center justify-between gap-2">
            <a href="{{ $publicUrl }}" target="_blank" rel="noopener"
                class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline">
                <x-heroicon-o-arrow-top-right-on-square class="size-4" /> Abrir
            </a>
            <button type="button"
                data-ak-ajax="{{ $formId }}-unshare" data-ak-action="{{ $unshareUrl }}"
                data-ak-confirm="Revogar o acesso público? O link atual deixará de funcionar."
                class="text-xs font-medium text-crit hover:underline">
                Revogar acesso
            </button>
        </div>
    @else
        <p class="mt-1 text-sm text-muted">{{ $unshared }}</p>

        <x-forms.button type="button" data-ak-ajax="{{ $formId }}-share" data-ak-action="{{ $shareUrl }}"
            class="mt-3 !h-9 w-full !text-sm">
            Gerar link público
        </x-forms.button>
    @endif
</div>
