<?php

namespace App\Enums;

/**
 * The parts of the app an account's level is set for, one at a time
 * (`users.access`). Each policy names the module it belongs to — that mapping
 * is the whole of this enum's job, so a new screen joins a module by its
 * policy asking `User::canEdit()` about it.
 *
 * The ecosystem map belongs to none: it is a read-only view derived from the
 * drawings, and every account reads it.
 */
enum AccessModule: string
{
    /** Solutions, people and companies. */
    case Catalog = 'catalog';

    /** Cadernos, their pages and the diagrams drawn inside them. */
    case Documentation = 'documentation';

    /** The Especialista em Integrações (`/flowspec`) and its corpus. */
    case Integrations = 'integrations';

    /** The Comitê de Arquitetura (`/submissions`). */
    case Committee = 'committee';

    public function label(): string
    {
        return match ($this) {
            self::Catalog       => 'Soluções, Pessoas e Empresas',
            self::Documentation => 'Documentação e Diagramas',
            self::Integrations  => 'Especialista em Integrações',
            self::Committee     => 'Comitê de Arquitetura',
        };
    }

    /**
     * The level every NEW account gets here — invited, granted from a
     * person's page or provisioned by Entra SSO — and what a module nobody set
     * on an account answers.
     *
     * The catalog and the documentation are read by default; the Especialista
     * and the Comitê are opened by an admin, one account at a time (the user's
     * call, 2026-10-06). Changing one of these changes what every account that
     * was never set there may do — so existing accounts are written down
     * explicitly by the migration that introduced the levels, never left to
     * follow this.
     */
    public function defaultLevel(): AccessLevel
    {
        return match ($this) {
            self::Catalog, self::Documentation  => AccessLevel::Reader,
            self::Integrations, self::Committee => AccessLevel::None,
        };
    }

    /** The same, short enough for a badge. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Catalog       => 'Catálogo',
            self::Documentation => 'Documentação',
            self::Integrations  => 'Especialista',
            self::Committee     => 'Comitê',
        };
    }
}
