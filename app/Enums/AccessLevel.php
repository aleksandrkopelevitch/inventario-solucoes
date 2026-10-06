<?php

namespace App\Enums;

/**
 * What an account may do inside one `AccessModule`.
 *
 * - `None` — the module does not exist for this account: no screen, no menu
 *   entry, no endpoint, no MCP tool. Somebody who reads the documentation
 *   need not see the catalog at all.
 * - `Reader` — reads, filters and exports everything in the module.
 * - `Editor` — adds creating and changing.
 *
 * None of them deletes: that is the admin's (`UserRole`).
 *
 * What a module nobody set answers is the MODULE's call —
 * `AccessModule::defaultLevel()`.
 */
enum AccessLevel: string
{
    case None = 'none';
    case Reader = 'reader';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::None   => 'Nenhum',
            self::Reader => 'Leitor',
            self::Editor => 'Editor',
        };
    }

    public function canView(): bool
    {
        return $this !== self::None;
    }
}
