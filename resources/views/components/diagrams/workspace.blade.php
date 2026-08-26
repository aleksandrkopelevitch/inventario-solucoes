{{-- Diagrama tab of the diagram's unified page. `data-ak-solutions` /
     `-protocols` / `-node-kinds` are the same read-once-and-cache JSON
     payloads `chain-viz.js` used to read off the old rail
     (the old per-solution integrations rail) — kept here since this is now where the
     canvas actually lives. The status list left with the canvas's own
     name/status panel (2026-08-17): the status is edited in the page's top
     bar now (`Diagrams\Meta`), which builds its own option list
     server-side. --}}
<div data-ak-solutions="{{ json_encode($solutionsList) }}"
    data-ak-protocols="{{ json_encode($protocolsList) }}"
    data-ak-node-kinds="{{ json_encode($kindsList) }}"
    class="flex min-h-0 flex-1 flex-col">

    {{-- The only "row" on this page — hidden, never clicked. There's nothing
         else to select, so `chain-select.js::init()` auto-selects it on
         load instead of waiting for a click; the canvas draws exactly as it
         would from a real click on the old rail. Mutations from the canvas
         (rename/status, node/edge edits) keep patching this same row's
         attributes afterwards, same as before — harmless since nothing reads
         them again after the initial auto-select on THIS single-diagram
         page. --}}
    <div data-ak-chain-select="{{ $diagram->slug }}"
        data-diagram-name="{{ $diagram->name }}"
        data-status="{{ str_replace('_', '-', $diagram->status->value) }}"
        data-ak-chain-graph="{{ json_encode($graph) }}"
        aria-pressed="false" hidden aria-hidden="true"></div>

    <x-chain.viz />
</div>
