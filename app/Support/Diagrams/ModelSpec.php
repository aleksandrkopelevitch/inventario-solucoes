<?php

namespace App\Support\Diagrams;

use App\Enums\DiagramModel;

/**
 * What the model is asked for, and nothing else: the SEMANTICS of one of the
 * four diagram models.
 *
 * Every field here is a fact about the flow — who takes part, in what order,
 * in which lane, at which stage. There is not one coordinate in it, and that
 * is the whole contract: geometry belongs to `ModelLayout`, which cannot get
 * an off-by-one wrong the way a model asked for pixels reliably does. It is
 * the same reason `ChainDraft` addresses nodes by a string id instead of the
 * chain's own integer index.
 *
 * Validation lives here rather than in a FormRequest because the payload comes
 * from a language model, not from a form: the problems have to be sentences
 * that can be handed back for a repair round, naming the offending id.
 */
final class ModelSpec
{
    public const MAX_NODES = 24;

    public const MAX_EDGES = 60;

    public const MAX_LABEL = 70;

    /**
     * @param  array<mixed>  $payload  already validated
     */
    private function __construct(
        public readonly DiagramModel $model,
        public readonly string $name,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<mixed>  $payload
     * @return list<string> empty when the payload is usable
     */
    public static function validate(DiagramModel $model, array $payload): array
    {
        $problems = [];

        $name = $payload['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $problems[] = 'O campo "name" é obrigatório.';
        }

        [$itemsKey, $linksKey] = self::keys($model);

        // Two of the models place every item inside a declared container, so a
        // missing `lanes` is one problem rather than one per item.
        if (in_array($model, [DiagramModel::Workflow, DiagramModel::Dataflow], true)) {
            $lanes = array_filter((array) ($payload['lanes'] ?? []), 'is_array');

            if ($lanes === []) {
                $problems[] = 'O campo "lanes" é obrigatório neste modelo e precisa de pelo menos uma raia com "id" e "label".';
            }

            foreach (array_values($lanes) as $i => $lane) {
                if (! is_string($lane['id'] ?? null) || ! is_string($lane['label'] ?? null)) {
                    $problems[] = 'lanes[' . $i . '] precisa de "id" e "label".';
                }
            }
        }

        $items = $payload[$itemsKey] ?? null;

        if (! is_array($items) || $items === []) {
            return [...$problems, 'O campo "' . $itemsKey . '" é obrigatório e precisa de pelo menos um item.'];
        }

        if (count($items) > self::MAX_NODES) {
            $problems[] = 'São ' . count($items) . ' itens em "' . $itemsKey . '"; o máximo é ' . self::MAX_NODES . '.';
        }

        $ids = [];

        foreach (array_values($items) as $i => $item) {
            $where = $itemsKey . '[' . $i . ']';

            if (! is_array($item)) {
                $problems[] = $where . ' não é um objeto.';

                continue;
            }

            $id = $item['id'] ?? null;

            if (! is_string($id) || trim($id) === '') {
                $problems[] = $where . ' não tem "id".';
            } elseif (isset($ids[$id])) {
                $problems[] = 'O id "' . $id . '" aparece mais de uma vez.';
            } else {
                $ids[$id] = true;
            }

            $label = $item['label'] ?? null;

            if (! is_string($label) || trim($label) === '') {
                $problems[] = $where . ' não tem "label".';
            } elseif (mb_strlen($label) > self::MAX_LABEL) {
                $problems[] = $where . ' tem "label" com mais de ' . self::MAX_LABEL . ' caracteres.';
            }

            $problems = [...$problems, ...self::validateItem($model, $item, $where, $payload)];
        }

        $links = $payload[$linksKey] ?? [];

        if (! is_array($links)) {
            return [...$problems, 'O campo "' . $linksKey . '" precisa ser uma lista.'];
        }

        if (count($links) > self::MAX_EDGES) {
            $problems[] = 'São ' . count($links) . ' ligações; o máximo é ' . self::MAX_EDGES . '.';
        }

        foreach (array_values($links) as $i => $link) {
            $where = $linksKey . '[' . $i . ']';

            if (! is_array($link)) {
                $problems[] = $where . ' não é um objeto.';

                continue;
            }

            foreach (['from', 'to'] as $end) {
                $ref = $link[$end] ?? null;

                if (! is_string($ref) || ! isset($ids[$ref])) {
                    $problems[] = $where . ' aponta para "' . (is_string($ref) ? $ref : '?') . '", que não existe em "' . $itemsKey . '".';
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function from(DiagramModel $model, array $payload): self
    {
        return new self($model, trim((string) $payload['name']), $payload);
    }

    /**
     * The two lists each model is made of. Naming them per model rather than
     * calling everything "nodes" keeps the prompts in the vocabulary of the
     * thing being drawn — a sequence has participants and messages, a process
     * has steps and flows — which is what the model answers best in.
     *
     * @return array{0: string, 1: string}
     */
    public static function keys(DiagramModel $model): array
    {
        return match ($model) {
            DiagramModel::Sequence  => ['participants', 'messages'],
            DiagramModel::Lifecycle => ['states', 'transitions'],
            DiagramModel::Dataflow  => ['nodes', 'flows'],
            DiagramModel::Workflow  => ['steps', 'flows'],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return array_values(array_filter($this->payload[self::keys($this->model)[0]], 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function links(): array
    {
        return array_values(array_filter((array) ($this->payload[self::keys($this->model)[1]] ?? []), 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lanes(): array
    {
        return array_values(array_filter((array) ($this->payload['lanes'] ?? []), 'is_array'));
    }

    /**
     * The per-model rules, which are all about ONE thing: whether an item
     * names a container that exists. A step in a lane nobody declared is the
     * failure that would otherwise reach the layout and be placed at the
     * origin, silently.
     *
     * @param  array<mixed>  $item
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private static function validateItem(DiagramModel $model, array $item, string $where, array $payload): array
    {
        if ($model === DiagramModel::Lifecycle) {
            $kind = $item['kind'] ?? 'active';

            return in_array($kind, ['start', 'active', 'decision', 'failure', 'success'], true)
                ? []
                : [$where . ' tem "kind" inválido (' . json_encode($kind) . '); use start, active, decision, failure ou success.'];
        }

        if ($model === DiagramModel::Sequence) {
            return [];
        }

        // Workflow and dataflow both place an item inside a declared
        // container — a lane for one, a stage for the other.
        $key = $model === DiagramModel::Workflow ? 'lane' : 'stage';
        $containers = array_column(array_filter((array) ($payload['lanes'] ?? []), 'is_array'), 'id');
        $value = $item[$key] ?? null;

        if (! is_string($value) || ! in_array($value, $containers, true)) {
            return [$where . ' está em "' . $key . '": ' . json_encode($value) . ', que não é uma das raias declaradas em "lanes".'];
        }

        return [];
    }
}
