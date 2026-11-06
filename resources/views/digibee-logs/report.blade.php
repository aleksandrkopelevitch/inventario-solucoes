<x-layouts.layout title="Relatório de logs Digibee">
    @php
        $tz = config('app.display_timezone');
    @endphp
    <x-ui.hero-panel compact class="mb-6">
        <x-ui.page-header eyebrow="Logs Digibee" :title="$report->pipeline ?? 'Todas as pipelines'"
            :description="($report->term ? '“' . $report->term . '” · ' : '') . $report->environment . ' · ' . $report->from_at->setTimezone($tz)->format('d/m/Y H:i') . ' – ' . $report->to_at->setTimezone($tz)->format('d/m/Y H:i') . ' · pedido por ' . $report->askedBy() . ', ' . $report->created_at->setTimezone($tz)->format('d/m H:i')" />
    </x-ui.hero-panel>

    <div class="mb-4">
        <a href="{{ route('digibee-logs.index') }}" class="text-sm text-accent hover:underline">← Logs Digibee</a>
    </div>

    <x-digibee.report-body :report="$report" />
</x-layouts.layout>
