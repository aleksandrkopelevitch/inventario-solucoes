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
            <span
                data-ak-lifecycle-poll="{{ route('flowspec.lifecycle.status', ['chat' => $chat, 'message' => $message]) }}"
                data-ak-lifecycle-seen="{{ count($run->roundList()) }}"
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
                        Executar de novo é outra implantação de verdade, sobre o mesmo pipeline.
                        Use isso para corrigir o que a execução acima apontou — um cron ou um nome
                        de evento errado, por exemplo — sem precisar de um pipeline novo.
                    </p>
                @else
                    <p class="text-[11px] leading-relaxed text-faint">
                        Escreve esse flowSpec num pipeline real, implanta em
                        <span class="font-medium text-muted">test</span>, roda a bateria e corrige com o que
                        acontecer. Cada rodada é uma implantação de verdade — e nada nessa plataforma apaga
                        pipeline.
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
                            {{-- Só faz sentido contra um pipeline que já
                                 existe: um recém-criado não tem gatilho
                                 "próprio" para manter, e o Form Request
                                 recusa o par (`required_if:creates,1`). A
                                 cola abaixo tira esta opção quando "Criar"
                                 está marcado, para o erro não ser a primeira
                                 notícia. --}}
                            <option value="" data-ak-trigger-keep>Manter o do pipeline</option>
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

                    {{-- A caixa e o botão numa linha própria: com os campos de
                         gatilho o formulário passou a quebrar, e `items-end`
                         numa linha só deixava o rótulo do checkbox atrás do
                         botão. --}}
                    <div class="flex w-full items-center justify-between gap-3 pt-1">
                        <x-forms.label class="!flex cursor-pointer items-center gap-2 !text-xs">
                            <x-forms.checkbox name="creates" value="1" data-ak-trigger-creates />
                            Criar se não existir
                        </x-forms.label>

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
                     tipo, recusaria a execução por um campo invisível.

                     Marcar "Criar se não existir" também tira "Manter o do
                     pipeline" da lista: não existe gatilho a manter num
                     pipeline que ainda não existe, e criar um sem gatilho
                     deixa na realm um nome indeployável que nada apaga. --}}
                <script>
                    (function () {
                        const form = document.getElementById(@js($formId));
                        const kind = form.querySelector('[data-ak-trigger-kind]');
                        const creates = form.querySelector('[data-ak-trigger-creates]');
                        const keep = kind.querySelector('[data-ak-trigger-keep]');

                        function sync() {
                            keep.hidden = creates.checked;
                            keep.disabled = creates.checked;
                            // Um pipeline novo não pode ficar sem tipo: se
                            // "Manter" estava escolhido, cai no primeiro real.
                            if (creates.checked && kind.value === '') kind.selectedIndex = 1;

                            form.querySelectorAll('[data-ak-trigger-when]').forEach((field) => {
                                const wanted = field.dataset.akTriggerWhen === kind.value;
                                field.hidden = ! wanted;
                                field.querySelectorAll('input').forEach((input) => { input.disabled = ! wanted; });
                            });
                        }

                        kind.addEventListener('change', sync);
                        creates.addEventListener('change', sync);
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
