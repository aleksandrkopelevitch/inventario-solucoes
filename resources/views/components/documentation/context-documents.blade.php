<div id="{{ $domId }}" class="space-y-2">
    @forelse ($documents as $media)
        <div class="flex items-center gap-3 rounded-field border border-line bg-surface px-3 py-2">
            <x-forms.checkbox data-ak-context-doc :value="$media->id" checked class="shrink-0" />

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-ink" title="{{ $media->file_name }}">{{ $media->file_name }}</p>
                <p class="text-xs text-muted">{{ $media->human_readable_size }}</p>
            </div>

            <form id="ctx-remove-{{ $media->id }}">
                @csrf
                @method('DELETE')
            </form>
            <x-forms.button type="button" variant="ghost" class="!h-8 !w-8 !p-0 shrink-0"
                data-ak-ajax="ctx-remove-{{ $media->id }}"
                data-ak-action="{{ route('solutions.docs.context.destroy', [$solution, $media->id]) }}"
                data-ak-confirm="Remover este documento de contexto?"
                aria-label="Remover documento" title="Remover">
                <x-heroicon-o-trash class="size-4" />
            </x-forms.button>
        </div>
    @empty
        <p class="rounded-field border border-dashed border-line px-3 py-4 text-center text-xs text-muted">
            Nenhum documento de contexto ainda. Anexe PDFs, imagens ou textos abaixo.
        </p>
    @endforelse
</div>
