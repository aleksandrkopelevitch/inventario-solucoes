{{-- Who created this page and who saved it last (App\View\Components\Documentation\PageAuthorship).
     Relative times, with the exact one in the tooltip — the app runs in UTC.
     A page older than the authorship columns, or written by the GitBook
     import, has a date and no name: said as "autor não registrado" rather
     than left blank, so it does not read as a rendering bug. --}}
<div id="{{ $domId }}" class="mb-4 space-y-1.5 border-b border-line px-2 pb-4 text-[13px] leading-snug text-muted">
    @foreach ([['Criada', $created], ['Atualizada', $updated]] as [$verb, $event])
        @if ($event)
            <p title="{{ $event->exact }}">
                {{ $verb }}
                @if ($event->who)
                    por <span class="font-medium text-ink">{{ $event->who }}</span>
                @else
                    <span class="text-faint">(autor não registrado)</span>
                @endif
                <span class="block text-xs text-faint">{{ $event->when }}</span>
            </p>
        @endif
    @endforeach
</div>
