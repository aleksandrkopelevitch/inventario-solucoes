<?php

namespace App\Support\Documentation;

/**
 * A UML diagram written INTO a page — `{% uml type="…" title="…" %}` with a
 * JSON body, closed by `{% enduml %}` — the eighth construct of the dialect.
 *
 * The author (a person in the editor, or a model through the MCP server)
 * decides WHAT is drawn and WHERE: the participants and the order of the
 * messages of a sequence, the cell of the grid each element of a structural
 * diagram sits in. `docs-uml.js` decides HOW: every shape, arrowhead, dash and
 * label follows UML notation, measured in the browser and never movable by the
 * reader. That is the scene split again (doc-scenes.md), for the same two
 * reasons — a figure that cannot come out crooked or off-notation, and a page
 * that never carries markup a model typed — with one difference: here the
 * author places things, because a class diagram's layout IS part of what it
 * says (the parent above its children, the aggregate beside its parts).
 *
 * This class is the one validator. `GitbookRenderer` calls `parse()` to emit
 * the figure (or the error box) and the MCP write tools call it to refuse a
 * body before it is saved, so a model that wrote a diagram blind is told what
 * is wrong in the same sentences a person would read on the page.
 */
final class UmlDiagram
{
    /** type → the label a reader sees in the caption. */
    public const TYPES = [
        'sequence'   => 'Diagrama de sequência',
        'class'      => 'Diagrama de classes',
        'component'  => 'Diagrama de componentes',
        'deployment' => 'Diagrama de implantação',
        'usecase'    => 'Diagrama de casos de uso',
        'activity'   => 'Diagrama de atividades',
        'state'      => 'Diagrama de estados',
        'package'    => 'Diagrama de pacotes',
    ];

    public const PARTICIPANT_KINDS = ['object', 'actor', 'boundary', 'control', 'entity', 'database', 'component'];

    public const MESSAGE_KINDS = ['sync', 'async', 'return', 'create', 'destroy'];

    public const FRAGMENT_KINDS = ['alt', 'opt', 'loop', 'par', 'break', 'critical'];

    public const NODE_KINDS = [
        'class', 'abstract', 'interface', 'enum', 'object', 'component', 'node', 'artifact',
        'package', 'actor', 'usecase', 'database', 'lollipop', 'note',
        'action', 'decision', 'initial', 'final', 'fork', 'state',
    ];

    public const EDGE_KINDS = [
        'association', 'directed', 'inheritance', 'realization', 'dependency',
        'composition', 'aggregation', 'flow', 'include', 'extend', 'note',
    ];

    public const GROUP_KINDS = ['package', 'boundary', 'node', 'frame'];

    /** What each kind is called in the diagram's TEXT (the no-JS, screen-reader and search view). */
    public const MESSAGE_LABELS = [
        'sync' => 'síncrona', 'async' => 'assíncrona', 'return' => 'retorno', 'create' => 'criação', 'destroy' => 'destruição',
    ];

    public const NODE_LABELS = [
        'class'    => 'classe', 'abstract' => 'classe abstrata', 'interface' => 'interface', 'enum' => 'enumeração',
        'object'   => 'objeto', 'component' => 'componente', 'node' => 'nó', 'artifact' => 'artefato',
        'package'  => 'pacote', 'actor' => 'ator', 'usecase' => 'caso de uso', 'database' => 'banco de dados',
        'lollipop' => 'interface fornecida', 'note' => 'nota', 'action' => 'ação', 'decision' => 'decisão',
        'initial'  => 'início', 'final' => 'fim', 'fork' => 'bifurcação', 'state' => 'estado',
    ];

    public const EDGE_LABELS = [
        'association' => 'associação', 'directed' => 'associação navegável', 'inheritance' => 'herança',
        'realization' => 'realização', 'dependency' => 'dependência', 'composition' => 'composição',
        'aggregation' => 'agregação', 'flow' => 'fluxo', 'include' => 'inclusão', 'extend' => 'extensão',
        'note'        => 'nota',
    ];

    public const GROUP_LABELS = ['package' => 'pacote', 'boundary' => 'fronteira', 'node' => 'nó', 'frame' => 'moldura'];

    public const MAX_PARTICIPANTS = 12;

    public const MAX_ROWS = 60;

    public const MAX_DEPTH = 3;

    public const MAX_NODES = 40;

    public const MAX_EDGES = 80;

    public const MAX_GROUPS = 12;

    public const MAX_MEMBERS = 15;

    public const MAX_COLUMNS = 12;

    public const MAX_GRID_ROWS = 20;

    public const MAX_LABEL = 80;

    public const MAX_NOTE = 240;

    public const MAX_TITLE = 120;

    /** @var list<string> */
    private array $problems = [];

    /**
     * Layout mistakes the picture survives but misleads with — a relation
     * drawn straight through another element, which hides the line behind
     * the box and makes the arrow seem to start there. Drawn on READ (a page
     * written before this check must not turn into an error box) and refused
     * on WRITE (GitbookRenderer::problems()), where the author can still move
     * an element.
     *
     * @var list<string>
     */
    private array $warnings = [];

    private int $rows = 0;

    private function __construct(private readonly string $type) {}

    /**
     * The diagram as the renderer draws it, or everything wrong with it.
     *
     * @return array{diagram: ?array<string, mixed>, problems: list<string>, warnings: list<string>}
     */
    public static function parse(string $type, string $title, string $body): array
    {
        $type = trim($type);

        if (! array_key_exists($type, self::TYPES)) {
            return ['diagram' => null, 'warnings' => [], 'problems' => [
                'O tipo "' . $type . '" não existe. Use um destes: ' . implode(', ', array_keys(self::TYPES)) . '.',
            ]];
        }

        $data = json_decode(trim($body), true);

        if (! is_array($data) || array_is_list($data)) {
            return ['diagram' => null, 'warnings' => [], 'problems' => [
                'O corpo de {% uml %} deve ser um objeto JSON válido'
                . (json_last_error() !== JSON_ERROR_NONE ? ' (' . json_last_error_msg() . ')' : '') . '.',
            ]];
        }

        $parser = new self($type);
        $diagram = ['type' => $type, 'title' => $parser->text($title, 'title', self::MAX_TITLE)];

        $diagram += $type === 'sequence' ? $parser->sequence($data) : $parser->grid($data);

        return $parser->problems === []
            ? ['diagram' => $diagram, 'problems' => [], 'warnings' => $parser->warnings]
            : ['diagram' => null, 'problems' => $parser->problems, 'warnings' => $parser->warnings];
    }

    /* ------------------------------------------------------------------ */
    /*  Sequence */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $data */
    private function sequence(array $data): array
    {
        $participants = [];

        foreach ($this->list($data, 'participants') as $i => $item) {
            $where = 'participante ' . ($i + 1);

            if (! is_array($item)) {
                $this->problems[] = "O {$where} deve ser um objeto.";

                continue;
            }

            $id = $this->id($item['id'] ?? null, $where);
            $kind = $this->choice($item['kind'] ?? 'object', self::PARTICIPANT_KINDS, "\"kind\" do {$where}");

            if ($id === null) {
                continue;
            }

            if (isset($participants[$id])) {
                $this->problems[] = "Dois participantes têm o id \"{$id}\".";

                continue;
            }

            $participants[$id] = [
                'id'    => $id,
                'label' => $this->text($item['label'] ?? $id, "\"label\" do {$where}", self::MAX_LABEL) ?: $id,
                'kind'  => $kind,
            ];
        }

        $count = count($participants);

        if ($count < 2 || $count > self::MAX_PARTICIPANTS) {
            $this->problems[] = 'Um diagrama de sequência precisa de 2 a ' . self::MAX_PARTICIPANTS . " participantes (tem {$count}).";
        }

        $messages = $this->items($data['messages'] ?? null, array_keys($participants), 'messages', 1);

        if ($this->rows === 0) {
            $this->problems[] = 'O diagrama precisa de pelo menos uma mensagem em "messages".';
        } elseif ($this->rows > self::MAX_ROWS) {
            $this->problems[] = 'O diagrama tem ' . $this->rows . ' linhas; o máximo é ' . self::MAX_ROWS . '. Divida em dois diagramas.';
        }

        return [
            'participants' => array_values($participants),
            'messages'     => $messages,
            'numbered'     => ($data['numbered'] ?? false) === true,
        ];
    }

    /**
     * One list of sequence items — messages, fragments, references, notes,
     * dividers — recursing into a fragment's operands.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function items(mixed $items, array $ids, string $where, int $depth): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $this->problems[] = "O campo {$where} deve ser uma lista.";

            return [];
        }

        $out = [];

        foreach ($items as $i => $item) {
            // A path the author can follow in the JSON, counted from 1:
            // `messages[3]`, `messages[3].else[1].messages[2]`.
            $path = "{$where}[" . ($i + 1) . ']';
            $here = "item {$path}";

            if (! is_array($item)) {
                $this->problems[] = "O {$here} deve ser um objeto.";

                continue;
            }

            $this->rows++;

            if (array_key_exists('fragment', $item)) {
                if ($depth > self::MAX_DEPTH) {
                    $this->problems[] = "O {$here} aninha fragmentos além de " . self::MAX_DEPTH . ' níveis.';

                    continue;
                }

                $operands = [[
                    'label'    => $this->text($item['label'] ?? '', "\"label\" do {$here}", self::MAX_LABEL),
                    'messages' => $this->items($item['messages'] ?? null, $ids, "{$path}.messages", $depth + 1),
                ]];

                // An `else` is another OPERAND, which only alt (alternatives)
                // and par (parallel branches) have: an opt or a loop with one
                // would be drawn as a compartment UML does not give it.
                if (! empty($item['else']) && ! in_array($item['fragment'], ['alt', 'par'], true)) {
                    $kind = is_string($item['fragment']) ? $item['fragment'] : '?';
                    $this->problems[] = "O {$here} é um \"{$kind}\": \"else\" só vale em alt e par.";
                }

                foreach (is_array($item['else'] ?? null) ? $item['else'] : [] as $k => $operand) {
                    $this->rows++;
                    $operands[] = [
                        'label'    => $this->text(is_array($operand) ? ($operand['label'] ?? '') : '', "\"label\" do item {$path}.else[" . ($k + 1) . ']', self::MAX_LABEL),
                        'messages' => $this->items(is_array($operand) ? ($operand['messages'] ?? null) : null, $ids, "{$path}.else[" . ($k + 1) . '].messages', $depth + 1),
                    ];
                }

                $out[] = [
                    'fragment' => $this->choice($item['fragment'], self::FRAGMENT_KINDS, "\"fragment\" do {$here}"),
                    'operands' => $operands,
                ];

                continue;
            }

            if (array_key_exists('ref', $item) || array_key_exists('note', $item)) {
                $key = array_key_exists('ref', $item) ? 'ref' : 'note';
                $over = array_values(array_filter((array) ($item['over'] ?? []), 'is_string'));

                if ($over === [] || count($over) > 2 || array_diff($over, $ids) !== []) {
                    $this->problems[] = "O {$here} precisa de \"over\" com 1 ou 2 ids de participantes existentes.";
                }

                $out[] = [
                    $key   => $this->text($item[$key], "\"{$key}\" do {$here}", $key === 'note' ? self::MAX_NOTE : self::MAX_LABEL, required: true),
                    'over' => $over,
                ];

                continue;
            }

            if (array_key_exists('divider', $item)) {
                $out[] = ['divider' => $this->text($item['divider'], "\"divider\" do {$here}", self::MAX_LABEL)];

                continue;
            }

            $from = $item['from'] ?? null;
            $to = $item['to'] ?? null;

            if ($from === null && $to === null) {
                $this->problems[] = "O {$here} não é mensagem, fragmento, ref, note nem divider: uma mensagem precisa de \"from\" e/ou \"to\".";

                continue;
            }

            foreach (['from' => $from, 'to' => $to] as $end => $value) {
                if ($value !== null && ! in_array($value, $ids, true)) {
                    $this->problems[] = "O \"{$end}\" do {$here} (\"" . (is_scalar($value) ? $value : '?') . '") não é um participante.';
                }
            }

            $kind = $this->choice($item['kind'] ?? 'sync', self::MESSAGE_KINDS, "\"kind\" do {$here}");

            if (in_array($kind, ['create', 'destroy'], true) && ($to === null || $to === $from)) {
                $this->problems[] = "Uma mensagem \"{$kind}\" ({$here}) precisa de um \"to\" diferente do \"from\".";
            }

            $out[] = array_filter([
                'from'  => $from,
                'to'    => $to,
                'label' => $this->text($item['label'] ?? '', "\"label\" do {$here}", self::MAX_LABEL),
                'kind'  => $kind,
            ], fn ($value) => $value !== null);
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Structural diagrams, on a grid */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $data */
    private function grid(array $data): array
    {
        $nodes = [];
        $cells = [];

        foreach ($this->list($data, 'nodes') as $i => $item) {
            $where = 'elemento ' . ($i + 1);

            if (! is_array($item)) {
                $this->problems[] = "O {$where} deve ser um objeto.";

                continue;
            }

            $id = $this->id($item['id'] ?? null, $where);

            if ($id === null) {
                continue;
            }

            $where = "elemento \"{$id}\"";
            $kind = $this->choice($item['kind'] ?? 'class', self::NODE_KINDS, "\"kind\" do {$where}");
            $at = $item['at'] ?? null;

            if (! is_array($at) || count($at) !== 2 || ! is_int($at[0] ?? null) || ! is_int($at[1] ?? null)
                || $at[0] < 0 || $at[0] >= self::MAX_COLUMNS || $at[1] < 0 || $at[1] >= self::MAX_GRID_ROWS) {
                $this->problems[] = "O {$where} precisa de \"at\": [coluna, linha], inteiros de 0 a " . (self::MAX_COLUMNS - 1)
                    . ' e 0 a ' . (self::MAX_GRID_ROWS - 1) . '.';
                $at = null;
            } elseif (isset($cells["{$at[0]},{$at[1]}"])) {
                $this->problems[] = "O {$where} e o \"{$cells["{$at[0]},{$at[1]}"]}\" estão na mesma célula [{$at[0]}, {$at[1]}].";
            } else {
                $cells["{$at[0]},{$at[1]}"] = $id;
            }

            if (isset($nodes[$id])) {
                $this->problems[] = "Dois elementos têm o id \"{$id}\".";

                continue;
            }

            $nameRequired = ! in_array($kind, ['initial', 'final', 'fork', 'decision', 'note'], true);

            $nodes[$id] = array_filter([
                'id'         => $id,
                'kind'       => $kind,
                'name'       => $this->text($item['name'] ?? '', "\"name\" do {$where}", self::MAX_LABEL, required: $nameRequired),
                'text'       => $kind === 'note' ? $this->text($item['text'] ?? '', "\"text\" do {$where}", self::MAX_NOTE, required: true) : null,
                'stereotype' => $this->text($item['stereotype'] ?? '', "\"stereotype\" do {$where}", 40),
                'attributes' => $this->lines($item['attributes'] ?? [], "\"attributes\" do {$where}"),
                'operations' => $this->lines($item['operations'] ?? [], "\"operations\" do {$where}"),
                'at'         => $at,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []);
        }

        $count = count($nodes);

        if ($count < 1 || $count > self::MAX_NODES) {
            $this->problems[] = 'O diagrama precisa de 1 a ' . self::MAX_NODES . " elementos em \"nodes\" (tem {$count}).";
        }

        $edges = [];

        foreach (is_array($data['edges'] ?? null) ? $data['edges'] : [] as $i => $item) {
            $where = 'relação ' . ($i + 1);

            if (! is_array($item)) {
                $this->problems[] = "A {$where} deve ser um objeto.";

                continue;
            }

            foreach (['from', 'to'] as $end) {
                if (! is_string($item[$end] ?? null) || ! isset($nodes[$item[$end]])) {
                    $this->problems[] = "O \"{$end}\" da {$where} não é o id de um elemento.";
                }
            }

            $edges[] = array_filter([
                'from'       => $item['from'] ?? null,
                'to'         => $item['to'] ?? null,
                'kind'       => $this->choice($item['kind'] ?? 'association', self::EDGE_KINDS, "\"kind\" da {$where}"),
                'label'      => $this->text($item['label'] ?? '', "\"label\" da {$where}", self::MAX_LABEL),
                'from_label' => $this->text($item['from_label'] ?? '', "\"from_label\" da {$where}", 30),
                'to_label'   => $this->text($item['to_label'] ?? '', "\"to_label\" da {$where}", 30),
            ], fn ($value) => $value !== null && $value !== '');
        }

        if (count($edges) > self::MAX_EDGES) {
            $this->problems[] = 'O diagrama tem ' . count($edges) . ' relações; o máximo é ' . self::MAX_EDGES . '.';
        }

        $this->crossings($nodes, $edges);

        return [
            'nodes'  => array_values($nodes),
            'edges'  => $edges,
            'groups' => $this->groups($data['groups'] ?? [], $nodes),
        ];
    }

    /**
     * Frames drawn around cells — a package, a system boundary, a deployment
     * node. A frame is the bounding box of its members' cells, so the two
     * things that can go wrong are both about layout, and both are refused
     * rather than drawn: a frame whose box would swallow an element that is
     * not a member, and two frames that half-overlap (a frame may hold
     * another whole, never a part of one).
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function groups(mixed $groups, array $nodes): array
    {
        if (! is_array($groups) || ! array_is_list($groups)) {
            $this->problems[] = '"groups" deve ser uma lista.';

            return [];
        }

        if (count($groups) > self::MAX_GROUPS) {
            $this->problems[] = 'O diagrama tem ' . count($groups) . ' grupos; o máximo é ' . self::MAX_GROUPS . '.';
        }

        $out = [];

        foreach ($groups as $i => $group) {
            $where = 'grupo ' . ($i + 1);

            if (! is_array($group)) {
                $this->problems[] = "O {$where} deve ser um objeto.";

                continue;
            }

            $members = array_values(array_unique(array_filter((array) ($group['nodes'] ?? []), 'is_string')));
            $unknown = array_diff($members, array_keys($nodes));

            if ($members === [] || $unknown !== []) {
                $this->problems[] = "O {$where} precisa de \"nodes\" com ids de elementos existentes"
                    . ($unknown !== [] ? ' (desconhecidos: ' . implode(', ', $unknown) . ')' : '') . '.';

                continue;
            }

            $placed = array_filter($members, fn (string $id) => isset($nodes[$id]['at']));

            if (count($placed) === count($members)) {
                $cols = array_map(fn (string $id) => $nodes[$id]['at'][0], $members);
                $rows = array_map(fn (string $id) => $nodes[$id]['at'][1], $members);

                foreach ($nodes as $id => $node) {
                    if (in_array($id, $members, true) || ! isset($node['at'])) {
                        continue;
                    }

                    [$col, $row] = $node['at'];

                    if ($col >= min($cols) && $col <= max($cols) && $row >= min($rows) && $row <= max($rows)) {
                        $label = $group['label'] ?? $where;
                        $this->problems[] = "O grupo \"{$label}\" cobre as células de [" . min($cols) . ', ' . min($rows) . '] a ['
                            . max($cols) . ', ' . max($rows) . "], onde está o elemento \"{$id}\", que não pertence a ele. "
                            . 'Mova o elemento para fora ou inclua-o no grupo.';
                    }
                }
            }

            $out[] = [
                'label' => $this->text($group['label'] ?? '', "\"label\" do {$where}", self::MAX_LABEL),
                'kind'  => $this->choice($group['kind'] ?? 'package', self::GROUP_KINDS, "\"kind\" do {$where}"),
                'nodes' => $members,
            ];
        }

        foreach ($out as $a => $one) {
            foreach ($out as $b => $other) {
                if ($a < $b) {
                    $shared = array_intersect($one['nodes'], $other['nodes']);

                    if ($shared !== [] && count($shared) !== count($one['nodes']) && count($shared) !== count($other['nodes'])) {
                        $this->problems[] = "Os grupos \"{$one['label']}\" e \"{$other['label']}\" se sobrepõem em parte: um grupo pode conter outro inteiro, nunca só uma parte dele.";
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Relations whose straight line crosses another element's cell.
     *
     * Judged in GRID units, since the real sizes are measured in the browser,
     * and so judged CONSERVATIVELY: only the core of a cell counts (0.4 of a
     * column, 0.4 of a row around its centre), because this refuses a write
     * and a false alarm costs more than a miss. That catches a line drawn
     * along a row or a column through a neighbour, and a diagonal through a
     * cell's middle. A near miss passes here; the editor's preview, which
     * knows every box's real size, flags it (docs-uml.js `crossings`). The
     * EndoFlow "Contêineres" diagram is the case that set the size: its line
     * from the SPA to Supabase clears the Worker's box by a few pixels, and a
     * 0.6-wide core called that a crossing.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function crossings(array $nodes, array $edges): void
    {
        foreach ($edges as $edge) {
            $from = $nodes[$edge['from'] ?? ''] ?? null;
            $to = $nodes[$edge['to'] ?? ''] ?? null;

            if (! isset($from['at'], $to['at']) || $edge['from'] === $edge['to']) {
                continue;
            }

            foreach ($nodes as $id => $node) {
                if ($id === $edge['from'] || $id === $edge['to'] || ! isset($node['at'])) {
                    continue;
                }

                [$c, $r] = $node['at'];

                if (self::segmentHitsBox($from['at'], $to['at'], [$c - 0.2, $r - 0.2, $c + 0.2, $r + 0.2])) {
                    $this->warnings[] = "A relação \"{$edge['from']}\" → \"{$edge['to']}\" passa por cima do elemento \"{$id}\": "
                        . 'no desenho a linha fica escondida atrás dele e parece sair dele. Mova um dos elementos para outra célula.';
                }
            }
        }
    }

    /**
     * Liang–Barsky: does the segment a→b touch the box [x1, y1, x2, y2]?
     *
     * @param  array{0: int, 1: int}  $a
     * @param  array{0: int, 1: int}  $b
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     */
    private static function segmentHitsBox(array $a, array $b, array $box): bool
    {
        [$x0, $y0] = $a;
        $dx = $b[0] - $x0;
        $dy = $b[1] - $y0;
        $low = 0.0;
        $high = 1.0;

        foreach ([[-$dx, $x0 - $box[0]], [$dx, $box[2] - $x0], [-$dy, $y0 - $box[1]], [$dy, $box[3] - $y0]] as [$p, $q]) {
            if ($p == 0) {
                if ($q < 0) {
                    return false;
                }

                continue;
            }

            $t = $q / $p;

            if ($p < 0) {
                $low = max($low, $t);
            } else {
                $high = min($high, $t);
            }

            if ($low > $high) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Field helpers */
    /* ------------------------------------------------------------------ */

    /** @return list<mixed> */
    private function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            $this->problems[] = "O campo \"{$key}\" é obrigatório e deve ser uma lista.";

            return [];
        }

        return $value;
    }

    private function id(mixed $value, string $where): ?string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $value)) {
            $this->problems[] = "O {$where} precisa de um \"id\" curto (letras, números, _ . -).";

            return null;
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed, string $where): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->problems[] = "O {$where} deve ser um destes: " . implode(', ', $allowed) . '.';

            return $allowed[0];
        }

        return $value;
    }

    private function text(mixed $value, string $where, int $max, bool $required = false): string
    {
        if ($value !== null && ! is_scalar($value)) {
            $this->problems[] = "O {$where} deve ser um texto.";

            return '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        if ($required && $text === '') {
            $this->problems[] = "O {$where} é obrigatório.";
        } elseif (mb_strlen($text) > $max) {
            $this->problems[] = "O {$where} passa de {$max} caracteres.";
        }

        return $text;
    }

    /** @return list<string> */
    private function lines(mixed $value, string $where): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->problems[] = "O {$where} deve ser uma lista de textos.";

            return [];
        }

        if (count($value) > self::MAX_MEMBERS) {
            $this->problems[] = "O {$where} tem mais de " . self::MAX_MEMBERS . ' linhas.';
        }

        return array_values(array_filter(
            array_map(fn ($line) => $this->text($line, $where, self::MAX_LABEL), $value),
            fn (string $line) => $line !== '',
        ));
    }
}
