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

it('accepts else only on alt and par', function () {
    $body = sequenceBody();
    $body['messages'][1]['fragment'] = 'opt';

    expect(implode(' ', UmlDiagram::parse('sequence', '', json_encode($body))['problems']))
        ->toContain('"else" só vale em alt e par');

    $body['messages'][1]['fragment'] = 'par';
    expect(UmlDiagram::parse('sequence', '', json_encode($body))['problems'])->toBe([]);
});

it('refuses on write a relation drawn through another element, and still draws it on read', function () {
    $crossing = ['nodes' => [
        ['id' => 'a', 'kind' => 'class', 'name' => 'A', 'at' => [0, 0]],
        ['id' => 'b', 'kind' => 'class', 'name' => 'B', 'at' => [1, 0]],
        ['id' => 'c', 'kind' => 'class', 'name' => 'C', 'at' => [2, 0]],
    ], 'edges' => [['from' => 'a', 'to' => 'c', 'kind' => 'dependency']]];
    $markdown = umlBlock('class', $crossing);

    expect((new GitbookRenderer)->problems($markdown)[0])->toContain('"a" → "c" passa por cima do elemento "b"')
        ->and((new GitbookRenderer)->render($markdown))->toContain('data-ak-uml=');

    // A diagonal that slips past a cell's corner is not a crossing: the
    // check refuses a write, so it judges only a cell's core (see crossings()).
    $nearMiss = ['nodes' => [
        ['id' => 'spa', 'kind' => 'component', 'name' => 'SPA', 'at' => [1, 0]],
        ['id' => 'fn', 'kind' => 'component', 'name' => 'Worker', 'at' => [1, 1]],
        ['id' => 'db', 'kind' => 'database', 'name' => 'Banco', 'at' => [0, 3]],
    ], 'edges' => [['from' => 'spa', 'to' => 'db', 'kind' => 'flow']]];

    expect((new GitbookRenderer)->problems(umlBlock('component', $nearMiss)))->toBe([]);
});

it('draws the rest of the reference palette and says so in the text', function () {
    $sequence = [
        'participants' => [['id' => 'a', 'label' => 'Cliente', 'kind' => 'actor'], ['id' => 'b', 'label' => 'API']],
        'messages'     => [
            ['to' => 'b', 'label' => 'evento externo', 'kind' => 'async', 'gate' => true],
            ['state' => 'Aguardando pagamento', 'over' => ['b']],
            ['from'  => 'a', 'to' => 'b', 'label' => 'pagar'],
        ],
    ];
    $structure = [
        'nodes' => [
            ['id' => 'lista', 'kind' => 'class', 'name' => 'Lista', 'template' => 'T', 'at' => [0, 0]],
            ['id' => 'emp', 'kind' => 'class', 'name' => 'Empregado', 'at' => [2, 0]],
            ['id' => 'n', 'kind' => 'nary', 'at' => [1, 1]],
            ['id' => 'proj', 'kind' => 'class', 'name' => 'Projeto', 'at' => [0, 2]],
            ['id' => 'req', 'kind' => 'note', 'stereotype' => 'requirement', 'text' => 'Todo projeto tem gerente.', 'at' => [3, 0]],
            ['id' => 'txt', 'kind' => 'text', 'text' => '{ordered}', 'at' => [3, 2]],
            ['id' => 'div', 'kind' => 'divider', 'name' => 'fronteira', 'at' => [4, 1]],
            ['id' => 'p', 'kind' => 'port', 'name' => 'http', 'at' => [5, 1]],
        ],
        'edges' => [
            ['from' => 'n', 'to' => 'emp', 'kind' => 'association'],
            ['from' => 'n', 'to' => 'proj', 'kind' => 'association'],
            ['from' => 'proj', 'to' => 'lista', 'kind' => 'association', 'from_label' => '*', 'from_role' => 'projetos', 'to_label' => '1', 'to_role' => 'lista'],
            ['from' => 'req', 'to' => 'emp', 'kind' => 'callout'],
        ],
    ];

    $html = (new GitbookRenderer)->render(umlBlock('sequence', $sequence) . "\n\n" . umlBlock('class', $structure));

    expect((new GitbookRenderer)->problems(umlBlock('sequence', $sequence) . "\n\n" . umlBlock('class', $structure)))->toBe([])
        ->and($html)->toContain('<strong>(portão)</strong> → <strong>API</strong>')
        ->toContain('<em>estado</em> (API): Aguardando pagamento')
        ->toContain('Lista&lt;T&gt;')
        ->toContain('<em>(associação n-ária)</em>')
        ->toContain('[* projetos → 1 lista]')
        ->toContain('<em>(chamada)</em>');
});

it('refuses a gate with both ends and a template on what is not a class', function () {
    $sequence = sequenceBody();
    $sequence['messages'][0]['gate'] = true;
    expect(implode(' ', UmlDiagram::parse('sequence', '', json_encode($sequence))['problems']))->toContain('"gate"');

    $structure = classBody();
    $structure['nodes'][] = ['id' => 'c', 'kind' => 'component', 'name' => 'C', 'template' => 'T', 'at' => [3, 3]];
    expect(implode(' ', UmlDiagram::parse('class', '', json_encode($structure))['problems']))->toContain('"template", que só vale em class');
});

it('refuses on write a figure inside a hint, which the editor can only show as text', function () {
    $markdown = "{% hint style=\"info\" %}\nAviso.\n\n" . umlBlock('class', classBody()) . "\n{% endhint %}";

    expect((new GitbookRenderer)->problems($markdown)[0])->toContain('dentro de um {% hint %}')
        ->and((new GitbookRenderer)->render($markdown))->toContain('data-ak-uml=');
});
