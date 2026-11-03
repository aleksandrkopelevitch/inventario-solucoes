{{-- Data comes from App\View\Components\Flowspec\LifecyclePanel::render():
     $message and $chatTitle are the component's public properties, the rest is
     the array it passes. No `@props` here — on a CLASS component that directive
     re-reads the names off `$attributes`, which does not carry them, and the
     view dies echoing a Closure from a line that looks correct. --}}

@php
    $formId = 'lifecycle-form-' . $message->id;
    $unsettled = $run && ! $run->status->settled();
@endphp

<div id="{{ $domId }}" class="mt-3 rounded-field border border-line bg-canvas">
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2">
        <span class="text-xs font-medium text-muted">Ciclo de vida na Digibee</span>

        @if ($run)
            <span @class([
                'rounded-full px-1.5 py-0.5 text-[10px] font-medium',
                'border border-line bg-raised text-muted' => ! $run->status->settled(),
                'border border-line bg-surface text-ink' => $run->status->settled(),
            ])>{{ $run->status->label() }}</span>

            {{-- O gatilho que a execução pediu. As três colunas eram gravadas
                 "para uma execução que falhou dizer o que foi pedido" e nada
                 as lia de volta — então uma falha por cron errado não mostrava
                 o cron. É a única coisa aqui que o operador pode ter digitado
                 errado, e a que ele precisa ver para corrigir na re-execução. --}}
            <span class="font-mono text-[10px] text-faint">
                {{ $run->pipeline_name }} · {{ $run->environment }}@if ($run->trigger_kind) · {{ $run->trigger_kind }}@endif
            </span>

            @if ($run->trigger_cron || $run->trigger_event)
                <span class="rounded-full border border-line bg-raised px-1.5 py-0.5 font-mono text-[10px] text-muted">
                    {{ $run->trigger_cron ?: $run->trigger_event }}
                </span>
            @endif
        @endif

        {{-- The poll marker lives inside the slot, so a swap that settles the
             run removes it and the client stops on its own. --}}
        @if ($unsettled)
            {{-- `seen` rides in the URL: the endpoint renders the panel only
                 when a round landed since, and each new round is a new URL,
                 so the ceiling counts ticks without progress. --}}
            <span
                data-ak-poll="{{ route('flowspec.lifecycle.status', ['chat' => $chat, 'message' => $message, 'seen' => count($run->roundList())]) }}"
                data-ak-poll-interval="4000"
                data-ak-poll-give-up="A execução está demorando mais que o esperado — atualize a página para conferir."
                class="ml-auto inline-flex items-center gap-1 text-[10px] text-faint"
            >
                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-accent"></span>
                acompanhando
            </span>
        @endif
    </div>

    <div class="space-y-2.5 px-3 py-2.5">
        {{-- As rodadas primeiro, o formulário depois — e ele REAPARECE
             quando a execução assenta, porque o endpoint aceita outra a
             partir daí (`PipelineRunController::store()` só recusa
             enquanto há uma em andamento, e `reapStale()` existe para
             soltar uma que morreu). Escondê-lo para sempre deixava sem
             saída justamente o erro mais provável — um cron ou um nome
             de evento errado, que só se descobre depois de rodar. --}}
        @if ($run)
            @forelse ($run->roundList() as $round)
                <div class="rounded-field border border-line bg-surface px-2.5 py-2">
                    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                        {{-- Read defensively: these rows are JSON written by
                             whichever version of the job ran, and a deploy can
                             land while a run is mid-flight. A missing key must
                             not 500 the whole conversation. --}}
                        <span class="font-medium text-ink">Rodada {{ $round['round'] ?? '?' }}</span>
                        <span class="rounded-full border border-line bg-raised px-1.5 py-0.5 text-[10px] text-muted">
                            chegou até {{ $round['reached'] ?? '—' }}
                        </span>
                        <span class="text-[10px] text-faint">{{ $round['label'] ?? '' }}</span>
                    </div>

                    @if (! empty($round['evidence'] ?? null))
                        <ul class="mt-1.5 space-y-1">
                            @foreach ($round['evidence'] ?? [] as $line)
                                <li class="text-[10px] leading-relaxed text-muted">— {{ $line }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="text-[11px] text-faint">
                    {{ $unsettled ? 'Ainda não há rodadas — a primeira escrita e implantação levam alguns minutos.' : 'Nenhuma rodada foi registrada.' }}
                </p>
            @endforelse

            @if ($run->error)
                <p class="rounded-field border border-hot-line bg-hot-soft px-2.5 py-2 text-[11px] text-ink">
                    {{ $run->error }}
                </p>
            @endif

            @if ($run->status->settled() && $run->readiness)
                <div class="rounded-field border border-line bg-surface px-2.5 py-2">
                    <p class="text-[11px] font-medium text-ink">
                        {{ ($run->readiness['ready'] ?? false) ? 'Pronto para promover' : 'Ainda não dá para promover' }}
                        @if ($run->readiness['version'] ?? null)
                            <span class="font-mono text-[10px] text-faint">{{ $run->readiness['version'] ?? '' }}</span>
                        @endif
                    </p>

                    @foreach ([...($run->readiness['blockers'] ?? []), ...($run->readiness['notes'] ?? [])] as $line)
                        <p class="mt-1 text-[10px] leading-relaxed text-muted">— {{ $line }}</p>
                    @endforeach
                </div>
            @endif

            @if ($run->endpoint)
                <p class="font-mono text-[10px] break-all text-faint">{{ $run->endpoint }}</p>
            @endif
        @endif

        @if (! $run || $run->status->settled())
            @can('run', $chat)
                @if ($run)
                    <p class="text-[11px] leading-relaxed text-faint">
                        Executar de novo cria outro pipeline ao lado do anterior (o mesmo nome com
                        <span class="font-mono">-v2</span>, <span class="font-mono">-v3</span>…) — uma execução daqui
                        nunca escreve num pipeline que já existe. Use isso para corrigir o que a execução
                        acima apontou, como um cron ou um nome de evento errado.
                    </p>
                @else
                    <p class="text-[11px] leading-relaxed text-faint">
                        Cria um pipeline com esse flowSpec, implanta em
                        <span class="font-medium text-muted">test</span>, roda a bateria e corrige com o que
                        acontecer. Se o nome já existir na Digibee, cria ao lado com
                        <span class="font-mono">-v1</span> — nunca sobrescreve. Cada rodada é uma implantação
                        de verdade, e nada nessa plataforma apaga pipeline.
                    </p>
                @endif

                <form id="{{ $formId }}" class="flex flex-wrap items-end gap-2">
                    @csrf

                    <x-forms.field label="Pipeline" class="min-w-[12rem] flex-1">
                        <x-forms.input name="pipeline_name" value="{{ $run?->pipeline_name ?? $suggestedName }}" />
                    </x-forms.field>

                    <x-forms.field label="Ambiente">
                        <x-forms.select name="environment">
                            @foreach ($environments as $environment)
                                <option value="{{ $environment }}">{{ $environment }}</option>
                            @endforeach
                        </x-forms.select>
                    </x-forms.field>

                    {{-- O gatilho. Sem ele o pipeline nasce com `triggerSpec`
                         vazio e a Digibee recusa publicar — o erro que ela
                         devolve fala de um campo `type` que não existe, e foi
                         onde toda execução automática morreu até aqui.

                         Os dois campos ao lado são os únicos valores que não
                         saem do flowSpec: um cron chutado não falha, roda na
                         hora errada; um nome de evento inventado escuta um
                         tópico que ninguém publica. Cada um aparece só com o
                         seu tipo, e o Form Request exige o par. --}}
                    <x-forms.field label="Gatilho">
                        <x-forms.select name="trigger_kind" data-ak-trigger-kind>
                            @foreach ($triggerKinds as $kind)
                                <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                            @endforeach
                        </x-forms.select>
                    </x-forms.field>

                    <x-forms.field label="Cron" class="min-w-[10rem]" data-ak-trigger-when="scheduler" hidden>
                        <x-forms.input name="trigger_cron" placeholder="0 */15 * * * *" />
                    </x-forms.field>

                    <x-forms.field label="Evento" class="min-w-[10rem]" data-ak-trigger-when="event" hidden>
                        <x-forms.input name="trigger_event" placeholder="pedido.criado" />
                    </x-forms.field>

                    {{-- O botão numa linha própria: com os campos de gatilho o
                         formulário quebra, e `items-end` numa linha só o
                         deixava espremido ao lado deles. --}}
                    <div class="flex w-full items-center justify-end gap-3 pt-1">
                        <x-forms.button
                            form="{{ $formId }}"
                            data-ak-ajax="{{ $formId }}"
                            data-ak-action="{{ route('flowspec.lifecycle.store', ['chat' => $chat, 'message' => $message]) }}"
                        >Executar</x-forms.button>
                    </div>
                </form>

                {{-- Mostra o campo do tipo escolhido e esconde o outro. O
                     escondido sai do formulário junto (`disabled`), senão um
                     cron digitado e depois trocado para evento ainda seria
                     enviado — e a validação, que rejeita um cron sem o seu
                     tipo, recusaria a execução por um campo invisível. --}}
                <script>
                    (function () {
                        const form = document.getElementById(@js($formId));
                        const kind = form.querySelector('[data-ak-trigger-kind]');

                        function sync() {
                            form.querySelectorAll('[data-ak-trigger-when]').forEach((field) => {
                                const wanted = field.dataset.akTriggerWhen === kind.value;
                                field.hidden = ! wanted;
                                field.querySelectorAll('input').forEach((input) => { input.disabled = ! wanted; });
                            });
                        }

                        kind.addEventListener('change', sync);
                        sync();
                    })();
                </script>
            @else
                <p class="text-[11px] text-faint">
                    Executar o ciclo de vida exige perfil de editor.
                </p>
            @endcan
        @endif
    </div>
</div>
