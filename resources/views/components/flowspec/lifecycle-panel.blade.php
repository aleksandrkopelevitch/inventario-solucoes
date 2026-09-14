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

            <span class="font-mono text-[10px] text-faint">{{ $run->pipeline_name }} · {{ $run->environment }}</span>
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
        @if (! $run)
            @can('run', $chat)
                <p class="text-[11px] leading-relaxed text-faint">
                    Escreve esse flowSpec num pipeline real, implanta em
                    <span class="font-medium text-muted">test</span>, roda a bateria e corrige com o que
                    acontecer. Cada rodada é uma implantação de verdade — e nada nessa plataforma apaga
                    pipeline.
                </p>

                <form id="{{ $formId }}" class="flex flex-wrap items-end gap-2">
                    @csrf

                    <x-forms.field label="Pipeline" class="min-w-[12rem] flex-1">
                        <x-forms.input name="pipeline_name" value="{{ $suggestedName }}" />
                    </x-forms.field>

                    <x-forms.field label="Ambiente">
                        <x-forms.select name="environment">
                            @foreach ($environments as $environment)
                                <option value="{{ $environment }}">{{ $environment }}</option>
                            @endforeach
                        </x-forms.select>
                    </x-forms.field>

                    <x-forms.checkbox name="creates" value="1" label="Criar se não existir" />

                    <x-forms.button
                        form="{{ $formId }}"
                        data-ak-ajax="{{ $formId }}"
                        data-ak-action="{{ route('flowspec.lifecycle.store', ['chat' => $chat, 'message' => $message]) }}"
                    >Executar</x-forms.button>
                </form>
            @else
                <p class="text-[11px] text-faint">
                    Executar o ciclo de vida exige perfil de editor.
                </p>
            @endcan
        @else
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
    </div>
</div>
