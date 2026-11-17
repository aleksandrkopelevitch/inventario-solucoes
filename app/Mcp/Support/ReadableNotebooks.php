<?php

namespace App\Mcp\Support;

use App\Enums\AccessModule;
use App\Mcp\Actor;
use App\Models\Notebook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The cadernos THIS caller may read through the MCP — the one door every
 * documentation tool goes through.
 *
 * **For almost everybody that is the PUBLISHED cadernos and nothing else**, the
 * `/docs` rule, including the part that looks like a mistake: an unpublished
 * caderno is invisible even to the admin who minted a token. `/docs` exists so
 * that "o que foi publicado" is answerable by looking at it, and this server
 * answers on behalf of a program in somebody else's chat window — the audience
 * furthest from that decision.
 *
 * **The one exception is a person who may WRITE the documentation here**
 * (`Actor::canWrite(Documentation)`: an Editor whose connection authorized
 * `mcp:write`). They read every caderno in the app already, and a write
 * connection that could not see what it just created — `create_notebook`
 * makes an unpublished caderno — would be a connection that writes blind. Each
 * caderno it is shown says whether it is published (`Presenter::notebookSummary`).
 */
class ReadableNotebooks
{
    /** @return Builder<Notebook> */
    public function query(Actor $actor): Builder
    {
        return Notebook::query()
            ->unless(self::seesUnpublished($actor), fn (Builder $query) => $query->published());
    }

    /** Readable cadernos, alphabetically, with the solutions they document. */
    public function all(Actor $actor): Collection
    {
        return $this->query($actor)
            ->with('solutions:id,slug,name')
            ->orderBy('name')
            ->get();
    }

    /** One readable caderno by slug, or null — including when it exists but this caller may not read it. */
    public function find(string $slug, Actor $actor): ?Notebook
    {
        return $this->query($actor)
            ->with('solutions:id,slug,name')
            ->where('slug', $slug)
            ->first();
    }

    public static function seesUnpublished(Actor $actor): bool
    {
        return $actor->canWrite(AccessModule::Documentation);
    }

    /**
     * The message for a slug this server cannot serve.
     *
     * It deliberately does NOT distinguish "não existe" from "existe mas não foi
     * publicado", for every caller. For a read-only caller that is a disclosure
     * rule: confirming that a caderno exists under a guessed name tells whoever
     * holds the credential what this company runs. An Editor could tell the two
     * apart anyway, so a second sentence for them would buy nothing but one more
     * branch to get wrong.
     */
    public function notFound(string $slug): string
    {
        return "Nenhum caderno publicado com o slug \"{$slug}\". "
            . 'Use list_notebooks para ver os cadernos disponíveis.';
    }
}
