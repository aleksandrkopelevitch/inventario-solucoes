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
    /**
     * The canvas's block sizes (`components/chain/viz.blade.php`): a 160×76
     * card, 208px wide with a picture beside its text, and a 160×90 decision
     * diamond. Every distance below is derived from these, so a lane is sized
     * by what goes in it — change one there and change it here.
     */
    private const BLOCK_WIDTH = 160;

    private const WIDEST_BLOCK = 208;

    private const BLOCK_HEIGHT = 76;

    private const DIAMOND_HEIGHT = 90;

    /** Distance between two lifelines. */
    private const COLUMN_PITCH = 340;

    /** Vertical distance between two messages on a lifeline. */
    private const MESSAGE_STEP = 52;

    /** Where the first message sits, measured from the top of the lifeline. */
    private const MESSAGE_TOP = 86;

    /** A lifeline's tail below the last message, so the line doesn't stop on it. */
    private const LIFELINE_TAIL = 60;

    /** Distance between two steps along a flow — a card's width plus room for the arrow and its label. */
    private const STEP_PITCH = 320;

    /**
     * A lane's thickness across the flow — its height in a process, its width
     * in a data flow — and the gap between two of them.
     *
     * Generous on purpose (2026-10-10): at 196px around a 96–112px block the
     * lanes read as a tight sleeve, the blocks touching the band they belong
     * to. A block now sits centred in roughly two and a half times its own
     * height, with room left for an arrow's label to pass above or below it.
     */
    private const LANE_THICKNESS = 260;

    private const STAGE_THICKNESS = 340;

    private const LANE_GAP = 40;

    /** Distance between two steps down a vertical (data flow) lane. */
    private const ROW_PITCH = 170;

    /** The title strip a lane draws along its leading edge (`.ak-viz-lane-label`). */
    private const LANE_HEADER = 26;

    /** Empty room between a lane's edge (past its title strip) and the first and last block in it. */
    private const LANE_INSET = 64;

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

        // Where each item landed, so the arrow routing just below can tell
        // whether a link runs along the lane or jumps from one to another.
        $cells = [];

        foreach ($items as $item) {
            $lane = $laneOrder[$item[$laneKey]] ?? 0;
            $slot = $seen[$lane] = ($seen[$lane] ?? -1) + 1;
            $cells[] = ['lane' => $lane, 'slot' => $slot];
            $kind = self::kindFor($item);

            // Centred ACROSS the lane by its own size, so a card and a diamond
            // in the same lane share a centre line and the arrow between them
            // runs straight instead of stepping by the difference in height.
            $nodes[] = self::node($item, $kind, $solutions);
            $positions[] = $vertical
                ? [
                    'x' => self::ORIGIN_X + $lane * (self::STAGE_THICKNESS + self::LANE_GAP) + intdiv(self::STAGE_THICKNESS - self::BLOCK_WIDTH, 2),
                    'y' => self::ORIGIN_Y + self::LANE_HEADER + self::LANE_INSET + $slot * self::ROW_PITCH,
                ]
                : [
                    'x' => self::ORIGIN_X + self::LANE_HEADER + self::LANE_INSET + $slot * self::STEP_PITCH,
                    'y' => self::ORIGIN_Y + $lane * (self::LANE_THICKNESS + self::LANE_GAP) + intdiv(self::LANE_THICKNESS - self::heightOf($kind), 2),
                ];
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
            // The arrow leaves on the axis it actually TRAVELS, which is not the
            // same for every link in the drawing. In a data flow the stages
            // advance sideways, so a link between two of them goes right-to-left
            // and only a link INSIDE one stage goes down; a process is the same
            // sentence with the axes swapped. Routing every link down a column
            // was what piled five lines into one horizontal band and painted
            // them across the labels.
            $anchors[] = ['dashed' => false] + self::route(
                $cells[$from],
                $cells[$to],
                alongLane: $vertical ? 'vertical' : 'horizontal',
            );
        }

        $span = max(1, count($seen) ? max($seen) + 1 : 1);
        $lanes = [];

        // Along the flow, a lane reaches past its last block by the same inset
        // it leaves before the first.
        $alongHorizontal = self::LANE_HEADER + 2 * self::LANE_INSET + ($span - 1) * self::STEP_PITCH + self::WIDEST_BLOCK;
        $alongVertical = self::LANE_HEADER + 2 * self::LANE_INSET + ($span - 1) * self::ROW_PITCH + self::DIAMOND_HEIGHT;

        foreach ($spec->lanes() as $i => $lane) {
            $lanes[] = $vertical
                ? [
                    'label'       => self::text($lane['label']) ?? 'Etapa',
                    'color'       => '#e8eefc',
                    'x'           => self::ORIGIN_X + $i * (self::STAGE_THICKNESS + self::LANE_GAP),
                    'y'           => self::ORIGIN_Y,
                    'width'       => self::STAGE_THICKNESS,
                    'height'      => max(self::LANE_THICKNESS, $alongVertical),
                    'orientation' => 'vertical',
                ]
                : [
                    'label'       => self::text($lane['label']) ?? 'Raia',
                    'color'       => '#e8eefc',
                    'x'           => self::ORIGIN_X,
                    'y'           => self::ORIGIN_Y + $i * (self::LANE_THICKNESS + self::LANE_GAP),
                    'width'       => max(480, $alongHorizontal),
                    'height'      => self::LANE_THICKNESS,
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
     * Which sides two blocks should be joined by.
     *
     * Same container: the flow runs ALONG it — down a stage, across a lane.
     * Different containers: it crosses, so it leaves on the crossing axis. In
     * both cases the side is chosen by direction, so a link that goes back up
     * the drawing does not leave through the same face as one going forward,
     * and the canvas's own orthogonal routing gets a corner it can turn.
     *
     * @param  array{lane: int, slot: int}  $from
     * @param  array{lane: int, slot: int}  $to
     * @return array{from: string, to: string}
     */
    private static function route(array $from, array $to, string $alongLane): array
    {
        $sameLane = $from['lane'] === $to['lane'];
        $downwards = $alongLane === 'vertical' ? $to['slot'] >= $from['slot'] : $to['lane'] >= $from['lane'];
        $rightwards = $alongLane === 'vertical' ? $to['lane'] >= $from['lane'] : $to['slot'] >= $from['slot'];

        $vertical = ['from' => $downwards ? 'b' : 't', 'to' => $downwards ? 't' : 'b'];
        $horizontal = ['from' => $rightwards ? 'r' : 'l', 'to' => $rightwards ? 'l' : 'r'];

        if ($alongLane === 'vertical') {
            return $sameLane ? $vertical : $horizontal;
        }

        return $sameLane ? $horizontal : $vertical;
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

    /** How tall a block of this kind is drawn — a decision's diamond is the one taller than a card. */
    private static function heightOf(ChainNodeKind $kind): int
    {
        return $kind === ChainNodeKind::Decision ? self::DIAMOND_HEIGHT : self::BLOCK_HEIGHT;
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
