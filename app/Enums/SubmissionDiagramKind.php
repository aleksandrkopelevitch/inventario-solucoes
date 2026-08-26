<?php

namespace App\Enums;

/**
 * The four drawings a CATI submission is expected to carry, and the reason
 * they are not all the same thing.
 *
 * The committee's own checklist asks for "desenho da solução e C4 com mínimo
 * C1/C2", and those two halves are answered differently on purpose:
 *
 * - **AS IS / TO BE are DRAWN here**, on the same F3 canvas the diagrams
 *   use, because they are topology — the thing this inventory already models
 *   and already renders. Accepting them as uploaded pictures would give the
 *   proposal's architecture no home but a PNG: editable nowhere, diffable
 *   against nothing, stale the moment anything moves.
 * - **C4 C1/C2 are UPLOADED**, because C4 is a modelling notation and the
 *   canvas is a free graph. Bending one into the other would mean inventing a
 *   notation and calling it C4 — worse than accepting the drawing the
 *   architect already made in a tool that speaks it.
 */
enum SubmissionDiagramKind: string
{
    case AsIs = 'as_is';
    case ToBe = 'to_be';
    case C4Context = 'c4_context';
    case C4Container = 'c4_container';

    public function label(): string
    {
        return match ($this) {
            self::AsIs        => 'Arquitetura AS IS',
            self::ToBe        => 'Arquitetura TO BE',
            self::C4Context   => 'C4 · Contexto (C1)',
            self::C4Container => 'C4 · Contêineres (C2)',
        };
    }

    /** What the committee is being asked to look at, on the slide's title. */
    public function slideTitle(): string
    {
        return $this->label();
    }

    public function hint(): string
    {
        return match ($this) {
            self::AsIs        => 'Como funciona hoje. Em branco significa que nada disso existe ainda.',
            self::ToBe        => 'O que está sendo proposto. É o desenho sobre o qual o comitê delibera.',
            self::C4Context   => 'Sistema e seus atores externos.',
            self::C4Container => 'Aplicações, serviços e bases dentro do sistema.',
        };
    }

    /**
     * Drawn on the F3 canvas (chain + viz_layout) rather than uploaded.
     *
     * The one question the rest of the module asks this enum: it decides
     * whether a row gets a canvas or a file input, whether its deck slide's
     * picture comes from a captured canvas or an upload, and whether a chain
     * mutation is even legal against it.
     */
    public function isDrawn(): bool
    {
        return $this === self::AsIs || $this === self::ToBe;
    }

    /**
     * Token safe inside a Tailwind arbitrary variant's value
     * (`group-data-[kind=as-is]:…`).
     *
     * Tailwind turns `_` into a SPACE there, so `data-[kind=as_is]` compiles
     * to a selector matching `kind="as is"` and silently never fires — the
     * same trap `SubmissionStatus::slug()` exists for.
     */
    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /** @return array<int, self> Canvas-backed, in the order the deck shows them. */
    public static function drawnCases(): array
    {
        return [self::AsIs, self::ToBe];
    }

    /** @return array<int, self> Upload-backed, in the order the deck shows them. */
    public static function uploadedCases(): array
    {
        return [self::C4Context, self::C4Container];
    }
}
