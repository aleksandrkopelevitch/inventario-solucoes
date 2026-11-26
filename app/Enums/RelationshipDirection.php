<?php

namespace App\Enums;

/**
 * Which way a relationship runs, seen from ONE of its two solutions — the
 * solution whose page (or `get_solution`) is asking. "Outgoing" means that
 * solution calls the other one.
 */
enum RelationshipDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
    case Both = 'both';
    case Undirected = 'undirected';

    public static function fromFlags(bool $out, bool $in): self
    {
        return match (true) {
            $out && $in => self::Both,
            $out        => self::Outgoing,
            $in         => self::Incoming,
            default     => self::Undirected,
        };
    }

    /** The arrow as a sentence, for a tooltip and a screen reader. */
    public function sentence(string $self, string $other): string
    {
        return match ($this) {
            self::Outgoing   => "{$self} chama {$other}",
            self::Incoming   => "{$other} chama {$self}",
            self::Both       => 'Chamam uma à outra',
            self::Undirected => 'Relacionadas, sem sentido definido',
        };
    }

    /** The word the MCP payload prints, read from the asking solution's side. */
    public function label(): string
    {
        return match ($this) {
            self::Outgoing   => 'chama',
            self::Incoming   => 'é chamada por',
            self::Both       => 'mútua',
            self::Undirected => 'sem sentido definido',
        };
    }
}
