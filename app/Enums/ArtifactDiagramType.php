<?php

namespace App\Enums;

/**
 * The four pictures a documentation page can be turned into that the F3 canvas
 * cannot draw.
 *
 * The canvas draws exactly one thing: a free graph of blocks and arrows, which
 * answers "what talks to what". These answer the questions a Digibee pipeline
 * page actually asks and that a topology cannot — in what ORDER the calls
 * happen, where the data comes to rest, what the states of a run are, who
 * approves what. They are rendered by the vendored Archify sidecar as
 * self-contained artifacts, and they are NOT chains: nothing here is editable
 * on the canvas, derives participants, or reaches the ecosystem map.
 *
 * `architecture` is deliberately absent even though Archify renders it. That
 * question already has an answer in this app, it is editable, and two
 * architectures of the same systems — one drawn, one generated — is exactly the
 * second truth the diagrams module was collapsed to avoid.
 */
enum ArtifactDiagramType: string
{
    case Sequence = 'sequence';
    case Dataflow = 'dataflow';
    case Lifecycle = 'lifecycle';
    case Workflow = 'workflow';

    public function label(): string
    {
        return match ($this) {
            self::Sequence  => 'Sequência',
            self::Dataflow  => 'Fluxo de dados',
            self::Lifecycle => 'Ciclo de vida',
            self::Workflow  => 'Processo',
        };
    }

    /** One line, shown in the menu — what this type is for, not what it is. */
    public function hint(): string
    {
        return match ($this) {
            self::Sequence  => 'Quem chama quem, em ordem, com retornos',
            self::Dataflow  => 'De onde o dado vem, o que o transforma, onde ele para',
            self::Lifecycle => 'Os estados de uma execução, com repiques e fim',
            self::Workflow  => 'As etapas de um processo, com aprovações e exceções',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Sequence  => 'arrows-right-left',
            self::Dataflow  => 'circle-stack',
            self::Lifecycle => 'arrow-path',
            self::Workflow  => 'queue-list',
        };
    }
}
