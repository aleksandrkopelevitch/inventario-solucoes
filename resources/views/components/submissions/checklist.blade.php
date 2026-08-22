{{-- What the committee will push back on. The catalog's facts and the
     per-section progress live in x-submissions.progress, next to the
     interview — see the note on App\View\Components\Submissions\Checklist. --}}
<div id="{{ $domId }}" class="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 shadow-card">
    <div>
        <h2 class="font-display text-sm font-bold text-ink">Itens estruturais</h2>
        <p class="mt-0.5 text-xs text-muted">O que a submissão precisa ter, além do texto das seções.</p>

        <ul class="mt-3 flex flex-col gap-1.5 pl-0">
            @foreach ($structural as $item)
                <li class="flex items-center gap-2 text-sm {{ $item['satisfied'] ? 'text-muted' : 'text-ink' }}">
                    @if ($item['satisfied'])
                        <x-heroicon-o-check class="size-4 shrink-0 text-accent" />
                    @else
                        <x-heroicon-o-minus-small class="size-4 shrink-0 text-faint" />
                    @endif
                    {{ $item['label'] }}
                    @if ($item['value']) <span class="truncate text-faint">— {{ $item['value'] }}</span> @endif
                </li>
            @endforeach
        </ul>
    </div>

    <div class="border-t border-line pt-4">
        <h2 class="font-display text-sm font-bold text-ink">Padrões corporativos</h2>
        <p class="mt-0.5 text-xs text-muted">O comitê só precisa discutir o que não estiver verde.</p>

        <ul class="mt-3 flex flex-col gap-2 pl-0">
            @foreach ($conformance as $check)
                <li class="flex items-start gap-2.5">
                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full {{ $check['verdict']->dotClass() }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-ink">{{ $check['label'] }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-medium {{ $check['verdict']->badgeClass() }}">
                                {{ $check['verdict']->label() }}
                            </span>
                        </span>
                        <span class="block text-xs text-faint">{{ $check['detail'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($deviations->isNotEmpty())
        <div class="border-t border-line pt-4">
            <h2 class="font-display text-sm font-bold text-ink">O comitê vai perguntar</h2>
            <p class="mt-0.5 text-xs text-muted">Derivado do cadastro, sem chamar modelo nenhum.</p>

            <ul class="mt-3 flex flex-col gap-3 pl-0">
                @foreach ($deviations as $rule)
                    <li class="flex gap-2.5">
                        <span @class([
                            'mt-1.5 size-1.5 shrink-0 rounded-full',
                            'bg-hot' => $rule['severity'] === 'high',
                            'bg-cat-amber' => $rule['severity'] === 'medium',
                            'bg-faint' => $rule['severity'] === 'low',
                        ])></span>
                        <span class="min-w-0">
                            <span class="block text-sm leading-snug text-ink">{{ $rule['question'] }}</span>
                            <span class="block text-xs text-faint">{{ $rule['why'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
