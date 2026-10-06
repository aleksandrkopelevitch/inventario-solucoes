<?php

namespace App\Enums;

/**
 * Whether an account is an administrator. That is all this decides now.
 *
 * What an ordinary account may do is no longer one tier for the whole app: it
 * is a level PER MODULE (`AccessModule` × `AccessLevel`, stored on
 * `users.access`), because somebody who curates the catalog is not necessarily
 * somebody who should be writing committee submissions. Every account reads
 * every module; a module's `Editor` level is what adds creating and changing.
 *
 * What stays with the admin, whatever the levels say:
 *
 * - DELETING any record — solutions, people, companies, cadernos, pages,
 *   diagrams, submissions, the flowSpec corpus. Changing a record's contents
 *   (a diagram's blocks, a person's contacts, a link between two records) is
 *   editing and belongs to the module's Editor.
 * - Accounts: inviting, granting, linking a person to an account, and the
 *   role and module levels themselves (`UserPolicy::manage`).
 * - What is not content: the attribute taxonomy, publishing a caderno (magic
 *   link and `/docs`), the spreadsheet's magic link, a caderno's protected
 *   values, and the MCP token screen.
 *
 * An admin is an Editor everywhere — `User::accessLevel()` answers that, so no
 * policy has to remember to OR the two.
 */
enum UserRole: string
{
    case Member = 'member';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Usuário',
            self::Admin  => 'Administrador',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Member => 'Lê todos os módulos; edita os módulos em que for Editor.',
            self::Admin  => 'Tudo, incluindo exclusões, contas, níveis de acesso e publicação.',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
