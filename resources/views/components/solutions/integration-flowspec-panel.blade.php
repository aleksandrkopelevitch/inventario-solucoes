@php
    $preId = 'integration-flowspec-' . $integration->id;
@endphp

<div>
    <h3 class="font-display text-base font-semibold text-ink">flowSpec anexado</h3>

    <dl class="mt-2 flex flex-col gap-1 text-xs text-muted">
        <div class="flex items-center gap-1.5">
            <dt class="font-medium">Status:</dt>
            <dd class="text-ink">{{ $integration->flowspec_status_label ?? 'Nenhum' }}</dd>
        </div>
        @if ($integration->flowspec_generated_at)
            <div class="flex items-center gap-1.5">
                <dt class="font-medium">Gerado em:</dt>
                <dd class="text-ink">{{ $integration->flowspec_generated_at->format('d/m/Y H:i') }}</dd>
            </div>
        @endif
    </dl>

    <div class="relative mt-3">
        <pre id="{{ $preId }}"
             class="max-h-96 overflow-auto rounded-field border border-line bg-canvas p-3 font-mono text-[11.5px] leading-relaxed text-body">{{ json_encode($integration->generated_flowspec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        <x-forms.button type="button" variant="glass" class="absolute right-2 top-2 !px-2.5 !py-1 !text-xs"
            data-ak-flowspec-copy="{{ $preId }}">
            <x-heroicon-o-clipboard-document class="size-3.5" /> Copiar JSON
        </x-forms.button>
    </div>
</div>
