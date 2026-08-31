<?php

namespace App\Enums;

/**
 * What an account may do. Three tiers, and the line between them is drawn by
 * the two predicates below rather than by comparing the case everywhere:
 * before `Writer` existed every policy read `$user->role === UserRole::Admin`,
 * so adding a tier meant editing thirteen files and hoping none was missed.
 *
 * - `Viewer` reads the catalog and the documentation. Nothing else.
 * - `Writer` writes CONTENT — solutions, people, companies, cadernos, pages,
 *   diagrams, the flowSpec corpus. Everything this app exists to catalog.
 * - `Admin` additionally does the things that are not content: inviting
 *   accounts, editing the attribute taxonomy, DELETING records, publishing a
 *   caderno's public link, and reading the protected values inside a page
 *   (see App\Support\Documentation\SecretText).
 *
 * Deletion sits with the admin on purpose: a caderno delete takes its whole
 * page tree with it and a diagram delete is cited from prose that survives it.
 * `canDelete()` is the one seam to move if that call should change.
 */
enum UserRole: string
{
    case Viewer = 'viewer';
    case Writer = 'writer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Visualizador',
            self::Writer => 'Editor',
            self::Admin  => 'Administrador',
        };
    }

    /** Creating and editing content — the whole of what `Writer` adds. */
    public function canWrite(): bool
    {
        return $this === self::Admin || $this === self::Writer;
    }

    /** Destroying a record. Deliberately narrower than `canWrite()`. */
    public function canDelete(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Administrative surfaces: accounts, the attribute taxonomy, a caderno's
     * public link and its protected values. Reads as intent at the call site,
     * where `=== UserRole::Admin` only read as a comparison.
     */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
