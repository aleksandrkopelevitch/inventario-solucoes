<?php

use App\Mcp\Tools\GetCodeDocumentationGuide;
use App\Support\Documentation\UmlDiagram;
use App\Support\GitbookRenderer;

function umlBlock(string $type, array $body, string $title = 'Pedido'): string
{
    return "{% uml type=\"{$type}\" title=\"{$title}\" %}\n"
        . json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        . "\n{% enduml %}";
}

function sequenceBody(): array
{
    return [
        'participants' => [
            ['id' => 'cli', 'label' => 'Cliente', 'kind' => 'actor'],
            ['id' => 'api', 'label' => 'API de Pedidos', 'kind' => 'boundary'],
            ['id' => 'sap', 'label' => 'SAP S/4HANA', 'kind' => 'database'],
        ],
        'messages' => [
            ['from' => 'cli', 'to' => 'api', 'label' => 'POST /pedidos'],
            ['fragment' => 'alt', 'label' => '[estoque ok]', 'messages' => [
                ['from' => 'api', 'to' => 'sap', 'label' => 'BAPI_SALESORDER_CREATE'],
                ['from' => 'sap', 'to' => 'api', 'label' => 'ordem', 'kind' => 'return'],
            ], 'else' => [
                ['label' => '[sem estoque]', 'messages' => [['from' => 'api', 'to' => 'cli', 'label' => '409', 'kind' => 'return']]],
            ]],
            ['note' => 'Timeout de 30 s', 'over' => ['api']],
        ],
    ];
}

function classBody(): array
{
    return [
        'nodes' => [
            ['id' => 'pedido', 'kind' => 'class', 'name' => 'Pedido', 'attributes' => ['- id: int'], 'operations' => ['+ total(): Money'], 'at' => [0, 0]],
            ['id' => 'item', 'kind' => 'class', 'name' => 'Item', 'at' => [1, 0]],
            ['id' => 'repo', 'kind' => 'interface', 'name' => 'Repositorio', 'at' => [0, 1]],
        ],
        'edges' => [
            ['from' => 'pedido', 'to' => 'item', 'kind' => 'composition', 'from_label' => '1', 'to_label' => '0..*'],
        ],
        'groups' => [['label' => 'Domínio', 'kind' => 'package', 'nodes' => ['pedido', 'item']]],
    ];
}

it('renders a sequence diagram as a figure carrying its data and its text', function () {
    $html = (new GitbookRenderer)->render(umlBlock('sequence', sequenceBody()));

    expect($html)->toContain('<figure class="ak-uml" data-ak-uml=')
        ->toContain('data-ak-uml-stage')
        ->toContain('Diagrama de sequência — Pedido')
        ->toContain('<strong>Cliente</strong> → <strong>API de Pedidos</strong>: POST /pedidos')
        ->toContain('<em>senão</em> [sem estoque]')
        ->and($html)->not->toContain('{% uml');
});

it('renders a structural diagram as its elements, groups and relations', function () {
    $html = (new GitbookRenderer)->render(umlBlock('class', classBody()));

    expect($html)->toContain('Diagrama de classes')
        ->toContain('<strong>Pedido</strong> <em>(classe)</em> — atributos: - id: int — operações: + total(): Money')
        ->toContain('Pedido → Item <em>(composição)</em> [1 → 0..*]')
        ->toContain('<strong>Domínio</strong> <em>(pacote)</em>: Pedido, Item');
});

it('shows an invalid diagram as an error box with every reason', function () {
    $body = sequenceBody();
    $body['messages'][] = ['from' => 'cli', 'to' => 'ghost'];

    $html = (new GitbookRenderer)->render(umlBlock('sequence', $body));

    expect($html)->toContain('ak-uml-error')->toContain('&quot;ghost&quot;')->not->toContain('data-ak-uml=');
});

it('refuses overlapping cells, a frame swallowing a stranger and a half-overlap of frames', function () {
    $body = classBody();
    $body['nodes'][] = ['id' => 'x', 'kind' => 'class', 'name' => 'X', 'at' => [1, 0]];
    expect(UmlDiagram::parse('class', '', json_encode($body))['problems'][0])->toContain('mesma célula');

    $body = classBody();
    $body['nodes'][] = ['id' => 'y', 'kind' => 'class', 'name' => 'Y', 'at' => [0, 2]];
    $body['groups'][0]['nodes'] = ['pedido', 'y'];
    expect(implode(' ', UmlDiagram::parse('class', '', json_encode($body))['problems']))->toContain('cobre as células');

    $body = classBody();
    $body['groups'] = [
        ['label' => 'A', 'nodes' => ['pedido', 'item']],
        ['label' => 'B', 'nodes' => ['item', 'repo']],
    ];
    $body['nodes'][2]['at'] = [1, 1];
    expect(implode(' ', UmlDiagram::parse('class', '', json_encode($body))['problems']))->toContain('se sobrepõem em parte');
});

it('refuses an unknown type, a broken JSON and a missing position', function () {
    expect(UmlDiagram::parse('flowchart', '', '{}')['problems'][0])->toContain('não existe')
        ->and(UmlDiagram::parse('class', '', '{nodes: []}')['problems'][0])->toContain('JSON válido');

    $body = classBody();
    unset($body['nodes'][0]['at']);
    expect(UmlDiagram::parse('class', '', json_encode($body))['problems'][0])->toContain('"at"');
});

it('lists the problems of new scenes and diagrams, and spares the ones already on the page', function () {
    $renderer = new GitbookRenderer;
    $badScene = "{% scene type=\"steps\" %}\n{% step title=\"Só uma\" %}x{% endstep %}\n{% endscene %}";
    $badUml = "{% uml type=\"sequence\" %}\n{\"participants\": []}\n{% enduml %}";

    $problems = $renderer->problems("Intro\n\n{$badScene}\n\n{$badUml}");

    // The scene's count, then the diagram's three (participants, messages, no message at all).
    expect($problems)->toHaveCount(4)
        ->and($problems[0])->toStartWith('Cena "steps": A lista "steps" deve ter entre 2 e 8')
        ->and($problems[1])->toStartWith('Diagrama UML: Um diagrama de sequência precisa de 2 a 12');

    // The same blocks, already stored: an edit elsewhere is not refused over them.
    expect($renderer->problems("Outro texto\n\n{$badScene}\n\n{$badUml}", "Intro\n\n{$badScene}\n\n{$badUml}"))->toBe([]);

    expect($renderer->problems("{% uml type=\"class\" %}\n{}"))->toHaveCount(1)
        ->and($renderer->problems("{% uml type=\"class\" %}\n{}")[0])->toContain('Falta o {% enduml %}')
        ->and($renderer->problems("```\n{% scene type=\"nope\" %}\n{% endscene %}\n```"))->toBe([])
        ->and($renderer->problems("{% scene type=\"nope\" %}\n{% endscene %}")[0])->toContain('"nope" não existe')
        ->and($renderer->problems(umlBlock('class', classBody()) . "\n\n" . umlBlock('sequence', sequenceBody())))->toBe([]);
});

it('teaches only examples that pass the validator', function () {
    $guide = file_get_contents(resource_path(GetCodeDocumentationGuide::GUIDE));
    preg_match_all('/```json\n(.*?)\n```/s', $guide, $examples);

    expect($examples[1])->toHaveCount(2)
        ->and(UmlDiagram::parse('sequence', '', $examples[1][0])['problems'])->toBe([])
        ->and(UmlDiagram::parse('class', '', $examples[1][1])['problems'])->toBe([]);

    preg_match('/```\n(\{% scene.*?\{% endscene %\})\n```/s', $guide, $scene);
    expect((new GitbookRenderer)->problems($scene[1]))->toBe([]);
});
