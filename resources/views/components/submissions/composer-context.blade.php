{{-- Material chips above the textarea: what the next message will carry.

     Its own slot (see App\View\Components\Submissions\ComposerContext) so
     attaching something mid-sentence never swaps the textarea out from under
     what is being typed. --}}
<div id="{{ $domId }}" class="flex flex-wrap items-center gap-1.5 px-3 pt-3 empty:hidden">
    @foreach ($sources as $source)
        @php
            // A file is sized as a file and a paste as text. Reporting "45
            // car." next to a `.md` reads as a broken upload; reporting "1 KB"
            // next to a paste answers a question nobody asked.
            $chars = mb_strlen((string) $source->extracted_text);
            $size = match (true) {
                $source->media !== null => $source->media->size >= 1048576
                    ? round($source->media->size / 1048576, 1) . ' MB'
                    : max(1, (int) round($source->media->size / 1024)) . ' KB',
                $chars >= 1000 => round($chars / 1000) . 'k',
                $chars > 0     => $chars . ' car.',
                default        => null,
            };
        @endphp

        <span @class([
            'group/chip inline-flex max-w-full items-center gap-1.5 rounded-full border py-1 pl-2.5 text-xs font-medium',
            'border-hot-line bg-hot-soft text-hot' => $source->hasSensitiveFindings(),
            'border-line-2 bg-raised text-body'    => ! $source->hasSensitiveFindings(),
            'pr-1'                                 => $canEdit,
            'pr-2.5'                               => ! $canEdit,
        ])>
            <x-dynamic-component :component="'heroicon-o-' . $source->kind->icon()" class="size-3.5 shrink-0" />

            <span class="truncate" title="{{ $source->label }} — {{ $source->extraction_state->label() }}">{{ $source->label }}</span>

            @if ($size)
                <span class="shrink-0 text-faint">· {{ $size }}</span>
            @endif

            @if ($source->hasSensitiveFindings())
                <x-heroicon-s-exclamation-triangle class="size-3.5 shrink-0"
                    title="Parece haver credencial neste material: {{ collect($source->sensitive_findings)->pluck('type')->implode(', ') }}" />
            @endif

            @if ($canEdit)
                <form id="composer-remove-source-{{ $source->id }}" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
                <x-forms.button type="button" variant="ghost"
                    class="!size-5 !shrink-0 !rounded-full !p-0 text-faint hover:!bg-crit-soft hover:!text-crit"
                    data-ak-ajax="composer-remove-source-{{ $source->id }}"
                    data-ak-action="{{ route('submissions.sources.destroy', [$submission, $source]) }}"
                    data-ak-confirm="Remover este material da submissão?"
                    aria-label="Remover {{ $source->label }}">
                    <x-heroicon-o-x-mark class="size-3" />
                </x-forms.button>
            @endif
        </span>
    @endforeach
</div>
