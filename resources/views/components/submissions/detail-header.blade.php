{{-- Deliberately slim. This page's subject is the conversation below it, and
     every pixel the header takes is a pixel of thread the person can't see —
     the old four-column dl pushed the composer under the fold on a laptop.
     Same fields, same inline editing, one wrapping row.

     Each read slot is wrapped in its own `@if`: x-ui.inline-edit shows its
     "Não informado" placeholder only when the slot renders NOTHING, and an
     empty `<span>` is content as far as that check is concerned. Without the
     guard a blank field is a label followed by dead space, with no hint that
     it can be filled in — which is exactly how all four looked on a fresh
     submission. --}}
<div id="{{ $domId }}" class="rounded-card border border-line bg-linear-to-br from-accent-soft to-surface px-5 py-4 shadow-card">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div class="min-w-0 flex-1">
            <p class="mb-0.5 text-[11px] font-semibold uppercase tracking-wider text-muted">Comitê de Arquitetura</p>

            <x-ui.inline-edit
                :action="$canEdit ? route('submissions.field.update', $submission) : null"
                name="name"
                label="Nome da submissão"
                :value="$submission->name"
                input-class="!font-display !text-[22px]/[28px] !font-bold !text-ink">
                <span class="font-display text-[22px]/[28px] font-bold text-ink">{{ $submission->name }}</span>
            </x-ui.inline-edit>
        </div>

        <x-ui.inline-edit
            :action="$canEdit ? route('submissions.field.update', $submission) : null"
            name="status"
            type="select"
            label="Situação"
            :nullable="false"
            :options="$statuses"
            :value="$submission->status->value">
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $submission->status->badgeClass() }}">
                {{ $submission->status->label() }}
            </span>
        </x-ui.inline-edit>
    </div>

    <dl class="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-2 border-t border-line/70 pt-3">
        <div class="flex min-w-0 items-baseline gap-2">
            <dt class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-muted">Solução</dt>
            <dd class="min-w-0">
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="solution_id"
                    type="select"
                    label="Solução"
                    :options="$solutions"
                    :value="$submission->solution_id"
                    :link="$submission->solution ? route('solutions.show', $submission->solution) : null"
                    link-label="solução">
                    @if ($submission->solution)<span class="text-sm text-ink">{{ $submission->solution->name }}</span>@endif
                </x-ui.inline-edit>
            </dd>
        </div>

        <div class="flex min-w-0 items-baseline gap-2">
            <dt class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-muted">Solicitante</dt>
            <dd class="min-w-0">
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="requester_person_id"
                    type="select"
                    label="Solicitante"
                    :options="$people"
                    :value="$submission->requester_person_id"
                    :link="$submission->requester ? route('people.show', $submission->requester) : null"
                    link-label="pessoa">
                    @if ($submission->requester)<span class="text-sm text-ink">{{ $submission->requester->name }}</span>@endif
                </x-ui.inline-edit>
            </dd>
        </div>

        <div class="flex min-w-0 items-baseline gap-2">
            <dt class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-muted">Comitê</dt>
            <dd class="min-w-0">
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="committee_date"
                    type="date"
                    label="Data do comitê"
                    :value="$submission->committee_date?->format('Y-m-d')">
                    @if ($submission->committee_date)<span class="text-sm tabular-nums text-ink">{{ $submission->committee_date->format('d/m/Y') }}</span>@endif
                </x-ui.inline-edit>
            </dd>
        </div>

        <div class="flex min-w-0 items-baseline gap-2">
            <dt class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-muted">Chamado</dt>
            <dd class="min-w-0">
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="ticket_reference"
                    label="Chamado"
                    :value="$submission->ticket_reference">
                    @if (filled($submission->ticket_reference))<span class="text-sm text-ink">{{ $submission->ticket_reference }}</span>@endif
                </x-ui.inline-edit>
            </dd>
        </div>
    </dl>
</div>
