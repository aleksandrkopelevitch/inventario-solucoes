<?php

namespace App\Mcp\Support;

use App\Models\Notebook;
use Illuminate\Support\Collection;

/**
 * The only door the MCP server has onto documentation: the cadernos an admin
 * PUBLISHED to the internal knowledge base.
 *
 * It is one class rather than a `published()` written out in each of the four
 * documentation tools, for the reason `Notebook::scopePublished()` itself gives:
 * every one of those calls is an AUTHORISATION, not a listing preference, and
 * the ones reached by slug are where that matters most — the question they ask
 * is "may this be served at all", and the way that rule gets lost is one call
 * site forgetting it while three remember.
 *
 * The rule is `/docs`'s, deliberately, including the part that looks like a
 * mistake: an unpublished caderno is invisible here even though the token was
 * minted by an admin who can read it in the app. `/docs` exists so that "o que
 * foi publicado" is answerable by looking at it, and this server answers on
 * behalf of a program in somebody else's chat window — the audience furthest
 * from that decision, not the closest.
 */
class PublishedNotebooks
{
    /** Published cadernos, alphabetically, with the solutions they document. */
    public function all(): Collection
    {
        return Notebook::query()
            ->published()
            ->with('solutions:id,slug,name')
            ->orderBy('name')
            ->get();
    }

    /** One published caderno by slug, or null — including when it exists but is unpublished. */
    public function find(string $slug): ?Notebook
    {
        return Notebook::query()
            ->published()
            ->with('solutions:id,slug,name')
            ->where('slug', $slug)
            ->first();
    }

    /**
     * The message for a slug this server cannot serve.
     *
     * It deliberately does NOT distinguish "não existe" from "existe mas não foi
     * publicado", and this is the one place in the module where that reticence
     * costs something real — a model told the caderno is merely unpublished
     * could say something useful. It is withheld for the same reason the app's
     * dead magic link answers with one message for three causes: the reply is
     * read by whoever holds the token, and confirming that a caderno exists
     * under a guessed name is a disclosure about what this company runs, from a
     * credential that was never supposed to enumerate anything.
     */
    public function notFound(string $slug): string
    {
        return "Nenhum caderno publicado com o slug \"{$slug}\". "
            . 'Use list_notebooks para ver os cadernos disponíveis.';
    }
}
