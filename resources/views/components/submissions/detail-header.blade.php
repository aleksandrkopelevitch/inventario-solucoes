@php
    $submission = $submission;
@endphp

<div id="{{ $domId }}" class="rounded-card border border-line bg-linear-to-br from-accent-soft to-surface p-6 shadow-card">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-muted">Comitê de Arquitetura</p>

            <x-ui.inline-edit
                :action="$canEdit ? route('submissions.field.update', $submission) : null"
                name="name"
                label="Nome da submissão"
                :value="$submission->name"
                input-class="!font-display !text-[28px]/[32px] !font-bold !text-ink">
                <span class="font-display text-[28px]/[32px] font-bold text-ink">{{ $submission->name }}</span>
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

    <dl class="mt-5 grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-muted">Solução</dt>
            <dd>
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="solution_id"
                    type="select"
                    label="Solução"
                    :options="$solutions"
                    :value="$submission->solution_id"
                    :link="$submission->solution ? route('solutions.show', $submission->solution) : null"
                    link-label="solução">
                    <span class="text-sm text-ink">{{ $submission->solution?->name }}</span>
                </x-ui.inline-edit>
            </dd>
        </div>

        <div>
            <dt class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-muted">Solicitante</dt>
            <dd>
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="requester_person_id"
                    type="select"
                    label="Solicitante"
                    :options="$people"
                    :value="$submission->requester_person_id"
                    :link="$submission->requester ? route('people.show', $submission->requester) : null"
                    link-label="pessoa">
                    <span class="text-sm text-ink">{{ $submission->requester?->name }}</span>
                </x-ui.inline-edit>
            </dd>
        </div>

        <div>
            <dt class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-muted">Data do comitê</dt>
            <dd>
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="committee_date"
                    type="date"
                    label="Data do comitê"
                    :value="$submission->committee_date?->format('Y-m-d')">
                    <span class="text-sm tabular-nums text-ink">{{ $submission->committee_date?->format('d/m/Y') }}</span>
                </x-ui.inline-edit>
            </dd>
        </div>

        <div>
            <dt class="mb-0.5 text-xs font-semibold uppercase tracking-wider text-muted">Chamado no Leo Resolve</dt>
            <dd>
                <x-ui.inline-edit
                    :action="$canEdit ? route('submissions.field.update', $submission) : null"
                    name="ticket_reference"
                    label="Chamado"
                    :value="$submission->ticket_reference">
                    <span class="text-sm text-ink">{{ $submission->ticket_reference }}</span>
                </x-ui.inline-edit>
            </dd>
        </div>
    </dl>
</div>
