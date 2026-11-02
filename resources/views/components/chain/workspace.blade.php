{{-- The canvas for one drawing (App\View\Components\Concerns\ChainWorkspace):
     the three read-once JSON payloads `chain-viz.js` caches for its editors,
     and the single hidden row it reads the drawing from on init
     (`selectTheDiagram()`). Mutations from the canvas keep patching that row's
     attributes afterwards; nothing reads them again on a one-drawing page. --}}
<div data-ak-solutions="{{ json_encode($solutionsList) }}"
    data-ak-protocols="{{ json_encode($protocolsList) }}"
    data-ak-node-kinds="{{ json_encode($kindsList) }}"
    class="flex min-h-0 flex-1 flex-col">

    <div data-ak-chain-select="{{ $rowKey }}"
        data-diagram-name="{{ $name }}"
        @if ($status !== null) data-status="{{ $status }}" @endif
        data-ak-chain-graph="{{ json_encode($graph) }}"
        aria-pressed="false" hidden aria-hidden="true"></div>

    <x-chain.viz />
</div>
