<div id="{{ $domId }}" class="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 shadow-card">
    <div>
        <h2 class="font-display text-sm font-bold text-ink">O que já sabemos</h2>
        <p class="mt-0.5 text-xs text-muted">Vem do catálogo — ninguém precisa perguntar.</p>

        @if ($facts === [])
            <p class="mt-3 text-sm text-muted">
                Vincule uma solução do catálogo para o comitê já saber categoria, nuvem, criticidade e integrações.
            </p>
        @else
            <dl class="mt-3 flex flex-col gap-1.5">
                @foreach ($facts as $fact)
                    <div class="flex items-baseline gap-2 text-sm">
                        <dt class="shrink-0 text-muted">{{ $fact['label'] }}</dt>
                        <dd class="min-w-0 flex-1 truncate font-medium text-ink">{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>

    <div class="border-t border-line pt-4">
        <h2 class="font-display text-sm font-bold text-ink">Falta responder</h2>

        @if ($missing === [])
            <p class="mt-2 inline-flex items-center gap-1.5 text-sm text-accent">
                <x-heroicon-o-check-circle class="size-4" />
                As seções obrigatórias estão preenchidas.
            </p>
        @else
            <ul class="mt-2 flex flex-col gap-1 pl-0">
                @foreach ($missing as $key)
                    <li class="flex items-center gap-2 text-sm text-ink">
                        <span class="size-1.5 shrink-0 rounded-full bg-hot"></span>
                        {{ \App\Enums\SubmissionSectionKey::from($key)->label() }}
                    </li>
                @endforeach
            </ul>
        @endif

        <ul class="mt-3 flex flex-col gap-1 border-t border-line pt-3 pl-0">
            @foreach ($structural as $item)
                <li class="flex items-center gap-2 text-xs {{ $item['satisfied'] ? 'text-muted' : 'text-ink' }}">
                    @if ($item['satisfied'])
                        <x-heroicon-o-check class="size-3.5 shrink-0 text-accent" />
                    @else
                        <x-heroicon-o-minus-small class="size-3.5 shrink-0 text-faint" />
                    @endif
                    {{ $item['label'] }}
                    @if ($item['value']) <span class="truncate text-faint">— {{ $item['value'] }}</span> @endif
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
