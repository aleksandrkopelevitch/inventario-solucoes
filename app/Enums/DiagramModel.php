<?php

namespace App\Enums;

/**
 * The four shapes a page's prose can be drawn as, beyond the free graph.
 *
 * Each one is a different question about the same flow — in what ORDER the
 * calls happen, what the states of a run are, where the data comes to rest,
 * who does what — and each maps onto elements the canvas already has: a
 * lifeline, a lane, a block, an arrow. Nothing here renders anything: a model
 * decides the SEMANTICS (`App\Support\Diagrams\ModelSpec`), a layout turns it
 * into positions (`App\Support\Diagrams\ModelLayout`), and what comes out is
 * an ordinary `Diagram` somebody can drag around afterwards.
 *
 * `architecture` is absent on purpose: the free graph the canvas already draws
 * IS that, and a second way to answer the same question is the second truth
 * the diagrams module was collapsed to avoid.
 */
enum DiagramModel: string
{
    case Sequence = 'sequence';
    case Lifecycle = 'lifecycle';
    case Dataflow = 'dataflow';
    case Workflow = 'workflow';

    public function label(): string
    {
        return match ($this) {
            self::Sequence  => 'Sequência',
            self::Lifecycle => 'Ciclo de vida',
            self::Dataflow  => 'Fluxo de dados',
            self::Workflow  => 'Processo',
        };
    }

    /** One line, in the menu — what the model is FOR, not what it is. */
    public function hint(): string
    {
        return match ($this) {
            self::Sequence  => 'Quem chama quem, em ordem, com retornos',
            self::Lifecycle => 'Os estados de uma execução, com repiques e fim',
            self::Dataflow  => 'De onde o dado vem, o que o transforma, onde ele para',
            self::Workflow  => 'As etapas de um processo, com aprovações e exceções',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Sequence  => 'arrows-right-left',
            self::Lifecycle => 'arrow-path',
            self::Dataflow  => 'circle-stack',
            self::Workflow  => 'queue-list',
        };
    }
}
