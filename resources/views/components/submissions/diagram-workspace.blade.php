{{-- The F3 canvas, mounted for one of a submission's drawings.

     Same three read-once JSON payloads and the same single hidden row as
     `solutions/diagrams/workspace.blade.php`: `chain-select.js`
     auto-selects a lone row on load, which is what draws the canvas. The row
     is keyed by the diagram's id rather than a diagram slug — nothing
     reads it back, it only has to be unique on the page. --}}
<div data-ak-solutions="{{ json_encode($solutionsList) }}"
    data-ak-protocols="{{ json_encode($protocolsList) }}"
    data-ak-node-kinds="{{ json_encode($kindsList) }}"
    class="flex min-h-0 flex-1 flex-col">

    <div data-ak-chain-select="submission-diagram-{{ $diagram->id }}"
        data-diagram-name="{{ $diagram->kind->label() }}"
        data-ak-chain-graph="{{ json_encode($graph) }}"
        aria-pressed="false" hidden aria-hidden="true"></div>

    <x-chain.viz />
</div>
