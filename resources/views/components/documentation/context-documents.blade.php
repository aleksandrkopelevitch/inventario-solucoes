<div id="{{ $domId }}" class="flex flex-wrap items-center gap-1.5">
    @forelse ($documents as $media)
        <div class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-line bg-surface py-1 pl-1 pr-1 text-xs shadow-sm has-checked:border-accent-line has-checked:bg-accent-soft">
            <x-forms.checkbox data-ak-context-doc :value="$media->id" checked class="shrink-0" />
            <x-heroicon-o-document-text class="size-3.5 shrink-0 text-muted" />
            <span class="max-w-[9rem] truncate font-medium text-ink" title="{{ $media->file_name }} · {{ $media->human_readable_size }}">
                {{ $media->file_name }}
            </span>

            <form id="ctx-remove-{{ $media->id }}">
                @csrf
                @method('DELETE')
            </form>
            <x-forms.button type="button" variant="ghost" class="!size-5 !rounded-full !p-0 shrink-0 text-muted hover:!bg-crit-soft hover:!text-crit"
                data-ak-ajax="ctx-remove-{{ $media->id }}"
                data-ak-action="{{ route('notebooks.context.destroy', [$notebook, $media->id]) }}"
                data-ak-confirm="Remover este documento de contexto?"
                aria-label="Remover documento" title="Remover">
                <x-heroicon-o-x-mark class="size-3" />
            </x-forms.button>
        </div>
    @empty
        <p class="text-xs text-muted">Nenhum documento de contexto ainda.</p>
    @endforelse
</div>
