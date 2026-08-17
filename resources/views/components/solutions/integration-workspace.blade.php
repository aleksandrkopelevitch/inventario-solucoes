{{-- Diagrama tab of the integration's unified page. `data-ak-solutions` /
     `-protocols` / `-node-kinds` are the same read-once-and-cache JSON
     payloads `integration-viz.js` used to read off the old rail
     (`integrations-map.blade.php`) — kept here since this is now where the
     canvas actually lives. The status list left with the canvas's own
     name/status panel (2026-08-17): the status is edited in the page's top
     bar now (`Solutions\IntegrationMeta`), which builds its own option list
     server-side. --}}
<div data-ak-solutions="{{ json_encode($solutionsList) }}"
    data-ak-protocols="{{ json_encode($protocolsList) }}"
    data-ak-node-kinds="{{ json_encode($kindsList) }}"
    class="flex min-h-0 flex-1 flex-col">

    {{-- The only "row" on this page — hidden, never clicked. There's nothing
         else to select, so `integration-select.js::init()` auto-selects it on
         load instead of waiting for a click; the canvas draws exactly as it
         would from a real click on the old rail. Mutations from the canvas
         (rename/status, node/edge edits) keep patching this same row's
         attributes afterwards, same as before — harmless since nothing reads
         them again after the initial auto-select on THIS single-integration
         page. --}}
    <div data-ak-integration-select="{{ $integration->slug }}"
        data-integration-name="{{ $integration->name }}"
        data-status="{{ str_replace('_', '-', $integration->status->value) }}"
        data-integration-graph="{{ json_encode($graph) }}"
        aria-pressed="false" hidden aria-hidden="true"></div>

    <x-solutions.integration-viz />
</div>
