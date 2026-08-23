{{-- Where the submission is. Reported, never enforced — see
     App\Support\Cati\SubmissionStages for why this isn't a real stepper. --}}
<ol id="{{ $domId }}" class="-mx-1 flex items-stretch gap-1 overflow-x-auto pl-0">
    @foreach ($stages as $stage)
        <li class="flex min-w-0 flex-1 items-center gap-2 px-1">
            <span @class([
                'flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                'bg-accent text-white shadow-sm'                            => $stage['state'] === 'done',
                'bg-surface text-accent ring-2 ring-accent'                 => $stage['state'] === 'current',
                'bg-raised text-faint ring-1 ring-line'                     => $stage['state'] === 'pending',
            ])>
                @if ($stage['state'] === 'done')
                    <x-heroicon-o-check class="size-3.5" stroke-width="3" />
                @else
                    {{ $loop->iteration }}
                @endif
            </span>

            {{-- The hint is a title, not a second line: at any width where the
                 tab switcher shares this bar, four two-line stages truncate to
                 "Decks, PDFs e…" — which says less than nothing. --}}
            <span @class([
                'min-w-0 flex-1 truncate text-sm leading-tight',
                'font-semibold text-ink' => $stage['state'] === 'current',
                'font-medium text-body'  => $stage['state'] === 'done',
                'text-faint'             => $stage['state'] === 'pending',
            ]) title="{{ $stage['hint'] }}">{{ $stage['label'] }}</span>

            @unless ($loop->last)
                <span @class([
                    'hidden h-px w-6 shrink-0 sm:block',
                    'bg-accent' => $stage['state'] === 'done',
                    'bg-line'   => $stage['state'] !== 'done',
                ])></span>
            @endunless
        </li>
    @endforeach
</ol>
