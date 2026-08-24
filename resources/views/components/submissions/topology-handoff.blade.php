{{-- Renders nothing until there is a handoff to make. A card that is always
     there and always empty teaches people to stop reading the column. --}}
<div id="{{ $domId }}">
    @if ($topology)
        @if ($topology->isPending())
            <div class="flex flex-col gap-3 rounded-card border border-cat-amber-line bg-cat-amber-soft p-5 shadow-card">
                <div class="flex items-start gap-2.5">
                    <x-heroicon-o-arrow-path-rounded-square class="mt-0.5 size-5 shrink-0 text-cat-amber-ink" />
                    <div class="min-w-0">
                        <h2 class="font-display text-sm font-bold text-ink">O catálogo ainda não reflete o TO BE</h2>
                        <p class="mt-0.5 text-xs text-cat-amber-ink">
                            O comitê aprovou um desenho de {{ $topology->nodeCount() }} blocos. Enquanto ele não for
                            aplicado, o mapa e o grafo do inventário continuam mostrando o cenário anterior.
                        </p>
                    </div>
                </div>

                @if ($canEdit)
                    <form id="apply-topology-form" class="flex flex-col gap-2">
                        @csrf

                        <x-forms.field label="Aplicar em" for="apply-topology-target"
                            hint="Uma proposta costuma descrever algo que ainda não existe — nesse caso, crie uma integração nova.">
                            <x-forms.select id="apply-topology-target" name="integration_id">
                                <option value="">Criar uma integração nova</option>
                                @foreach ($targets as $target)
                                    <option value="{{ $target->id }}">{{ $target->name }}</option>
                                @endforeach
                            </x-forms.select>
                        </x-forms.field>
                    </form>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-forms.button form="apply-topology-form"
                            data-ak-ajax="apply-topology-form"
                            data-ak-action="{{ route('submissions.topology.apply', [$submission, $topology]) }}"
                            data-ak-confirm="Isso sobrescreve a topologia da integração escolhida com o desenho aprovado. Continuar?">
                            <x-heroicon-o-check class="size-4" /> Aplicar ao catálogo
                        </x-forms.button>

                        {{-- "The catalog was already right" is a different claim
                             from "the catalog now says this", and the history has
                             to be able to tell them apart. --}}
                        <form id="dismiss-topology-form" class="hidden">@csrf</form>
                        <x-forms.button form="dismiss-topology-form" type="button" variant="ghost" class="!text-xs"
                            data-ak-ajax="dismiss-topology-form"
                            data-ak-action="{{ route('submissions.topology.dismiss', [$submission, $topology]) }}"
                            data-ak-confirm="Marcar como já refletida no catálogo, sem alterar topologia nenhuma?">
                            Já está refletido
                        </x-forms.button>
                    </div>
                @endif
            </div>
        @elseif ($topology->applied_at)
            <div class="flex items-start gap-2.5 rounded-card border border-cat-emerald-line bg-cat-emerald-soft p-4">
                <x-heroicon-o-check-circle class="mt-0.5 size-5 shrink-0 text-cat-emerald-ink" />
                <p class="min-w-0 text-xs text-cat-emerald-ink">
                    <span class="font-semibold">Topologia aplicada ao catálogo</span>
                    em {{ $topology->applied_at->format('d/m/Y') }}
                    @if ($topology->appliedBy) por {{ $topology->appliedBy->name }} @endif.
                    @if ($topology->integration && $submission->solution)
                        {{-- $submission->solution is the one eager-loaded by the
                             component; the topology's own is the same record. --}}
                        <a href="{{ route('solutions.integrations.docs.edit', [$submission->solution, $topology->integration]) }}"
                           class="font-medium underline">{{ $topology->integration->name }}</a>
                    @endif
                </p>
            </div>
        @else
            <div class="flex items-start gap-2.5 rounded-card border border-line bg-raised p-4">
                <x-heroicon-o-minus-circle class="mt-0.5 size-5 shrink-0 text-muted" />
                <p class="min-w-0 text-xs text-muted">
                    <span class="font-semibold text-ink">Sem mudança de topologia</span> —
                    marcada como já refletida no catálogo em {{ $topology->dismissed_at->format('d/m/Y') }}.
                    @if ($topology->dismissed_reason) “{{ $topology->dismissed_reason }}” @endif
                </p>
            </div>
        @endif
    @endif
</div>
