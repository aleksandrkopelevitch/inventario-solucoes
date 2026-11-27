<?php

namespace App\Enums;

/**
 * The four pictures a CATI submission is expected to carry: the committee's
 * checklist asks for "desenho da solução e C4 com mínimo C1/C2". All four are
 * images the person uploads, made in whatever tool they draw in.
 *
 * AS IS / TO BE used to be drawn on an in-app canvas; that canvas was removed
 * on 2026-11-27 (docs-and-specs/solution-relationships-from-uml.md, phase 4b).
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
}
