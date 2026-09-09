@props([
    'message',
    'suite',     // App\Support\Digibee\Testing\PipelineTestSuite
    'coverage',  // ['runnable' => int, 'blocked' => int, 'total' => int]
    'json',      // the §3.4 testSuite document, pretty-printed
])

{{-- Collapsed by default: the battery is a second reading of the same
     document, and the JSON above it is what people came for. --}}
<details class="mt-3 rounded-field border border-line bg-canvas">
    <summary class="cursor-pointer px-3 py-2 text-xs font-medium text-muted hover:text-ink">
        Bateria de testes
        <span class="ml-1 text-faint">
            {{ $coverage['runnable'] }} de {{ $coverage['total'] }} {{ $coverage['total'] === 1 ? 'caso executável' : 'casos executáveis' }}
        </span>
    </summary>

    <div class="space-y-2 border-t border-line px-3 py-2.5">
        <p class="text-[11px] leading-relaxed text-faint">
            Derivada do flowSpec: o corpo de cada caso sai das referências
            {{-- `@{{ … }}` is the only way to print the syntax itself: a plain echo of a
                 string containing it is matched by Blade's own non-greedy echo regex,
                 which then fails to compile with an error naming the compiled view. --}}
            <code class="font-mono">@{{ message.* }}</code> e os placeholders nomeiam o campo — não são
            valores válidos. Um caso <span class="font-medium text-muted">pendente</span> é dívida de
            cobertura declarada, nunca um payload inventado.
        </p>

        <ul class="space-y-1.5">
            @foreach ($suite->cases as $case)
                <li @class([
                    'rounded-field border px-2.5 py-2',
                    'border-line bg-surface' => $case->runnable(),
                    'border-hot-line bg-hot-soft' => ! $case->runnable(),
                ])>
                    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                        <span class="font-medium text-ink">{{ $case->name }}</span>
                        <span class="rounded-full border border-line bg-raised px-1.5 py-0.5 text-[10px] text-muted">{{ $case->category->label() }}</span>
                        <span class="font-mono text-[10px] text-faint">{{ $case->method }} · {{ $case->expects->describe() }}</span>
                        @if ($case->expectsContentType)
                            <span class="font-mono text-[10px] text-faint">· {{ $case->expectsContentType }}</span>
                        @endif
                    </div>

                    @if ($case->covers)
                        <p class="mt-1 text-[11px] text-muted">Cobre: <span class="font-mono">{{ $case->covers }}</span></p>
                    @endif

                    @if (! $case->runnable())
                        <p class="mt-1 text-[11px] text-hot">{{ $case->blocked }}</p>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="relative">
            <pre id="flowspec-suite-{{ $message->id }}"
                 class="max-h-64 overflow-auto rounded-field border border-line bg-surface p-3 font-mono text-[11px] leading-relaxed text-body">{{ $json }}</pre>
            <x-forms.button type="button" variant="glass" class="!absolute right-2 top-2 !px-2.5 !py-1 !text-xs"
                data-ak-flowspec-copy="flowspec-suite-{{ $message->id }}">
                <x-heroicon-o-clipboard-document class="size-3.5" /> Copiar bateria
            </x-forms.button>
        </div>
    </div>
</details>
