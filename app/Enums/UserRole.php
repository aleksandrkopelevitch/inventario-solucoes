<?php

namespace App\Enums;

/**
 * What an account may do. Four tiers, and the line between them is drawn by the
 * predicates below rather than by comparing the case everywhere: before
 * `Writer` existed every policy read `$user->role === UserRole::Admin`, so
 * adding a tier meant editing thirteen files and hoping none was missed.
 *
 * - `Reader` reads the knowledge base (`/docs`) and NOTHING else. It is the
 *   tier an account provisioned by Entra SSO lands in.
 * - `Viewer` reads the catalog and the documentation. Nothing else.
 * - `Writer` writes CONTENT — solutions, people, companies, cadernos, pages,
 *   diagrams, the flowSpec corpus. Everything this app exists to catalog.
 * - `Admin` additionally does the things that are not content: inviting
 *   accounts, editing the attribute taxonomy, DELETING records, publishing a
 *   caderno (to the magic link or to `/docs`), and reading the protected values
 *   inside a page (see App\Support\Documentation\SecretText).
 *
 * Deletion sits with the admin on purpose: a caderno delete takes its whole
 * page tree with it and a diagram delete is cited from prose that survives it.
 * `canDelete()` is the one seam to move if that call should change.
 *
 * `Reader` is the tier that changed what "logged in" means in this app. Until
 * it existed, holding an account and reading the whole inventory were the same
 * thing — every `viewAny` answered `true`, and the only gate on
 * `/solutions`, `/people` or `/flowspec` was the `auth` middleware. SSO turns
 * "has an account here" from something an admin decided one person at a time
 * into something anybody with a Leo mailbox gets on their first visit, so the
 * two had to come apart: `canReadInventory()` is that seam, read by every
 * `viewAny`/`view` and enforced once more at the route group
 * (App\Http\Middleware\EnsureInventoryAccess).
 */
enum UserRole: string
{
    case Reader = 'reader';
    case Viewer = 'viewer';
    case Writer = 'writer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Reader => 'Leitor (base de conhecimento)',
            self::Viewer => 'Visualizador',
            self::Writer => 'Editor',
            self::Admin  => 'Administrador',
        };
    }

    /**
     * What this tier is for, in one sentence — the admin has to choose between
     * four of them now, and "Leitor" against "Visualizador" is not a difference
     * two words can carry.
     */
    public function description(): string
    {
        return match ($this) {
            self::Reader => 'Só a base de conhecimento (/docs). Não vê o inventário.',
            self::Viewer => 'Lê o inventário inteiro. Não altera nada.',
            self::Writer => 'Cria e edita conteúdo: soluções, pessoas, cadernos, diagramas.',
            self::Admin  => 'Tudo, incluindo contas, exclusões e publicação de cadernos.',
        };
    }

    /**
     * Reading the INVENTORY — the catalog, the diagrams, the flowSpec corpus,
     * the cadernos as objects to be edited. Everything `/docs` is not.
     *
     * This is the predicate that stops an account provisioned by SSO from
     * reading a catalog that names every vendor contact and every internal
     * system. It is deliberately the opposite shape of the other three: they
     * ADD a power to the tier above, this one is the floor that `Reader` sits
     * below.
     */
    public function canReadInventory(): bool
    {
        return $this !== self::Reader;
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
