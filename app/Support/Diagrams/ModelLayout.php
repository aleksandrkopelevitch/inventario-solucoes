<?php

namespace App\Support\Diagrams;

use App\Enums\ChainNodeKind;
use App\Enums\DiagramModel;
use App\Models\Solution;
use App\Support\Fold;
use Illuminate\Support\Collection;

/**
 * Semantics in, positions out.
 *
 * This is the half of the generator that the model is never asked for. A
 * language model given a canvas produces coordinates that look plausible and
 * overlap; given an ORDER it produces an order. So `ModelSpec` carries who,
 * what and in which lane, and everything below decides where — deterministic,
 * testable without a browser, and the same every time.
 *
 * What comes out is an ordinary `chain` + `viz_layout`: blocks, arrows, lanes
 * and (for a sequence) lifelines. Nothing here is a new kind of record, which
 * is the point — the drawing lands on the same canvas as every other, and the
 * first thing anybody does with it is drag something.
 */
final class ModelLayout
{
    /** Distance between two lifelines, and between two columns of a data flow. */
    private const COLUMN_PITCH = 300;

    /** Vertical distance between two messages on a lifeline. */
    private const MESSAGE_STEP = 52;

    /** Where the first message sits, measured from the top of the lifeline. */
    private const MESSAGE_TOP = 86;

    /** A lifeline's tail below the last message, so the line doesn't stop on it. */
    private const LIFELINE_TAIL = 60;

    /** Distance between two steps along a flow, and between two lanes. */
    private const STEP_PITCH = 240;

    private const LANE_PITCH = 190;

    private const LANE_PAD = 28;

    private const ORIGIN_X = 60;

    private const ORIGIN_Y = 60;

    /**
     * @param  Collection<int, Solution>  $solutions  keyed by folded name
     * @return array{chain: array<string, mixed>, viz_layout: array<string, mixed>}
     */
    public static function build(ModelSpec $spec, Collection $solutions): array
    {
        return match ($spec->model) {
            DiagramModel::Sequence  => self::sequence($spec, $solutions),
            DiagramModel::Dataflow  => self::dataflow($spec, $solutions),
            DiagramModel::Workflow  => self::workflow($spec, $solutions),
            DiagramModel::Lifecycle => self::lifecycle($spec, $solutions),
        };
    }

    /**
     * A sequence: one lifeline per participant, side by side, and one message
     * per arrow attached at the height of its own step.
     *
     * The lifelines are all the same height on purpose — a column that stopped
     * at its own last message would read as "this participant left", which is
     * a statement the page never made.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{chain: array<string, mixed>, viz_layout: array<string, mixed>}
     */
    private static function sequence(ModelSpec $spec, Collection $solutions): array
    {
        $items = $spec->items();
        $links = $spec->links();
        $index = self::indexById($items);

        $height = self::MESSAGE_TOP + max(1, count($links)) * self::MESSAGE_STEP + self::LIFELINE_TAIL;

        $nodes = [];
        $positions = [];

        foreach ($items as $i => $item) {
            $nodes[] = self::node($item, ChainNodeKind::Lifeline, $solutions);
            $positions[] = [
                'x'      => self::ORIGIN_X + $i * self::COLUMN_PITCH,
                'y'      => self::ORIGIN_Y,
                'height' => $height,
            ];
        }

        $edges = [];
        $anchors = [];

        foreach ($links as $step => $link) {
            $from = $index[$link['from']];
            $to = $index[$link['to']];
            // The fraction is measured against the SAME height every lifeline
            // has, so two messages of the same step line up across the canvas.
            $t = round((self::MESSAGE_TOP + $step * self::MESSAGE_STEP) / $height, 4);
            $rightwards = $from < $to;

            $edges[] = [
                'from'     => $from,
                'to'       => $to,
                'arrow'    => '->',
                'protocol' => self::text($link['label'] ?? null),
            ];
            $anchors[] = [
                'from'   => $rightwards ? 'r' : 'l',
                'to'     => $rightwards ? 'l' : 'r',
                'dashed' => (bool) ($link['async'] ?? false),
                'fromT'  => $t,
                'toT'    => $t,
            ];
        }

        return self::assemble($nodes, $edges, $positions, $anchors, []);
    }

    /**
     * A data flow: one vertical lane per stage, blocks stacked inside it.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{chain: array<string, mixed>, viz_layout: array<string, mixed>}
     */
    private static function dataflow(ModelSpec $spec, Collection $solutions): array
    {
        return self::laned($spec, $solutions, vertical: true);
    }

    /**
     * A process: one horizontal lane per actor, steps advancing left to right.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{chain: array<string, mixed>, viz_layout: array<string, mixed>}
     */
    private static function workflow(ModelSpec $spec, Collection $solutions): array
    {
        return self::laned($spec, $solutions, vertical: false);
    }

    /**
     * The two laned models are the same layout seen from two sides: one puts
     * the container across the canvas and the flow down it, the other the
     * reverse. Writing it once is what keeps a stage and an actor behaving
     * identically when somebody drags one.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{chain: array<string, mixed>, viz_layout: array<string, mixed>}
     */
    private static function laned(ModelSpec $spec, Collection $solutions, bool $vertical): array
    {
        $items = $spec->items();
        $links = $spec->links();
        $index = self::indexById($items);
        $laneKey = $vertical ? 'stage' : 'lane';

        $laneOrder = [];

        foreach ($spec->lanes() as $i => $lane) {
            $laneOrder[$lane['id']] = $i;
        }

        // The position of an item INSIDE its lane is its order of appearance
        // there: the model already expressed the sequence by listing them, and
        // asking for a row number as well would be asking it to agree with
        // itself twice.
        $seen = [];
        $nodes = [];
        $positions = [];

        foreach ($items as $item) {
            $lane = $laneOrder[$item[$laneKey]] ?? 0;
            $slot = $seen[$lane] = ($seen[$lane] ?? -1) + 1;

            $nodes[] = self::node($item, self::kindFor($item), $solutions);
            $positions[] = $vertical
                ? ['x' => self::ORIGIN_X + $lane * self::COLUMN_PITCH + self::LANE_PAD, 'y' => self::ORIGIN_Y + 70 + $slot * 120]
                : ['x' => self::ORIGIN_X + 40 + $slot * self::STEP_PITCH, 'y' => self::ORIGIN_Y + $lane * self::LANE_PITCH + 60];
        }

        $edges = [];
        $anchors = [];

        foreach ($links as $link) {
            $from = $index[$link['from']];
            $to = $index[$link['to']];

            $edges[] = [
                'from'     => $from,
                'to'       => $to,
                'arrow'    => '->',
                'protocol' => self::text($link['label'] ?? null),
            ];
            // The arrow leaves on the axis the flow travels: down a column for
            // a data flow, along a row for a process. Where the two ends sit in
            // different lanes the canvas's own routing takes the corner.
            $anchors[] = $vertical
                ? ['from' => 'b', 'to' => 't', 'dashed' => false]
                : ['from' => 'r', 'to' => 'l', 'dashed' => false];
        }

        $span = max(1, count($seen) ? max($seen) + 1 : 1);
        $lanes = [];

        foreach ($spec->lanes() as $i => $lane) {
            $lanes[] = $vertical
                ? [
                    'label'       => self::text($lane['label']) ?? 'Etapa',
                    'color'       => '#e8eefc',
                    'x'           => self::ORIGIN_X + $i * self::COLUMN_PITCH,
                    'y'           => self::ORIGIN_Y,
                    'width'       => self::COLUMN_PITCH - 40,
                    'height'      => max(220, 130 + $span * 120),
                    'orientation' => 'vertical',
                ]
                : [
                    'label'       => self::text($lane['label']) ?? 'Raia',
                    'color'       => '#e8eefc',
                    'x'           => self::ORIGIN_X,
                    'y'           => self::ORIGIN_Y + $i * self::LANE_PITCH,
                    'width'       => max(400, 120 + $span * self::STEP_PITCH),
                    'height'      => self::LANE_PITCH - 24,
                    'orientation' => 'horizontal',
                ];
        }

        return self::assemble($nodes, $edges, $positions, $anchors, $lanes);
    }

    /**
     * A lifecycle: the happy path on one line, and anything that failed or
     * branched off it on a second line below.
     *
     * Two rows rather than one per kind: a run has a course and it has
     * exceptions, and putting every failure on the same lower line is what
     * makes the shape of a retry legible — it goes down, and it comes back.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array{chain: array<string, mixed>, viz_layout: array<string, mixed>}
     */
    private static function lifecycle(ModelSpec $spec, Collection $solutions): array
    {
        $items = $spec->items();
        $links = $spec->links();
        $index = self::indexById($items);

        $nodes = [];
        $positions = [];
        $column = 0;
        $offColumn = 0;

        foreach ($items as $item) {
            $kind = match ($item['kind'] ?? 'active') {
                'start'    => ChainNodeKind::Start,
                'success'  => ChainNodeKind::End,
                'decision' => ChainNodeKind::Decision,
                default    => ChainNodeKind::Step,
            };
            $exception = ($item['kind'] ?? null) === 'failure';

            $nodes[] = self::node($item, $exception ? ChainNodeKind::Step : $kind, $solutions);
            $positions[] = $exception
                ? ['x' => self::ORIGIN_X + $offColumn++ * self::STEP_PITCH + 120, 'y' => self::ORIGIN_Y + 220]
                : ['x' => self::ORIGIN_X + $column++ * self::STEP_PITCH, 'y' => self::ORIGIN_Y];
        }

        $edges = [];
        $anchors = [];

        foreach ($links as $link) {
            $from = $index[$link['from']];
            $to = $index[$link['to']];
            $down = $positions[$to]['y'] > $positions[$from]['y'];
            $up = $positions[$to]['y'] < $positions[$from]['y'];

            $edges[] = [
                'from'     => $from,
                'to'       => $to,
                'arrow'    => '->',
                'protocol' => self::text($link['label'] ?? null),
            ];
            $anchors[] = match (true) {
                $down => ['from' => 'b', 'to' => 't', 'dashed' => false],
                // Coming back up from the exception row is a retry, and it is
                // drawn dashed for the same reason the canvas dashes anything
                // that is not the main course.
                $up     => ['from' => 't', 'to' => 'b', 'dashed' => true],
                default => ['from' => 'r', 'to' => 'l', 'dashed' => false],
            };
        }

        return self::assemble($nodes, $edges, $positions, $anchors, []);
    }

    /**
     * @param  array<mixed>  $item
     * @param  Collection<int, Solution>  $solutions
     * @return array<string, mixed>
     */
    private static function node(array $item, ChainNodeKind $kind, Collection $solutions): array
    {
        $solution = $kind->referencesSolution() && filled($item['solution'] ?? null)
            ? $solutions[Fold::text((string) $item['solution'])] ?? null
            : null;

        return [
            'solution_id' => $solution?->id,
            // The name the model wrote is the fallback label, exactly as in
            // `CreateDiagramFromDraft`: a name that matched nothing becomes a
            // free-text block instead of an empty one.
            'label' => $solution !== null ? null : self::text($item['label'] ?? $item['solution'] ?? null),
            'kind'  => $kind->value,
        ];
    }

    /**
     * A decision when the model says so; a SYSTEM when it named one from the
     * catalog; and a STEP otherwise.
     *
     * That last branch is the one worth stating. A step used to come out as a
     * free-text system, which the canvas draws dashed because a system it does
     * not know is something outside Leo — true of a partner's API, nonsense
     * about "Abre o chamado". An activity is not a system at all, so it has a
     * kind of its own and a solid box.
     */
    private static function kindFor(array $item): ChainNodeKind
    {
        if (($item['kind'] ?? null) === 'decision') {
            return ChainNodeKind::Decision;
        }

        return filled($item['solution'] ?? null) ? ChainNodeKind::System : ChainNodeKind::Step;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private static function indexById(array $items): array
    {
        $index = [];

        foreach ($items as $i => $item) {
            $index[$item['id']] = $i;
        }

        return $index;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array{chain: array<string, mixed>, viz_layout: array<string, mixed>}
     */
    private static function assemble(array $nodes, array $edges, array $positions, array $anchors, array $lanes): array
    {
        return [
            'chain'      => ['nodes' => $nodes, 'edges' => $edges],
            'viz_layout' => array_filter([
                'nodes' => $positions,
                'edges' => $anchors,
                'lanes' => $lanes,
            ], fn (array $value) => $value !== []),
        ];
    }
}
