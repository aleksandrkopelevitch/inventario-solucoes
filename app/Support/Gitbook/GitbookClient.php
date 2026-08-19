<?php

namespace App\Support\Gitbook;

use App\Exceptions\GitbookApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only wrapper over the endpoints the import needs, from GitBook's
 * OpenAPI spec (https://api.gitbook.com/openapi.json):
 *
 *   GET /orgs                                    → the token's organizations
 *   GET /orgs/{org}/spaces                       → paginated
 *   GET /spaces/{space}                          → space metadata (title)
 *   GET /spaces/{space}/content/pages            → the whole page tree
 *   GET /spaces/{space}/content/page/{page}?format=markdown
 *                                                → { markdown: "# …" }
 *
 * `format=markdown` is the reason this import is cheap: GitBook hands back
 * the same Markdown-plus-`{% %}`-notation dialect that App\Support\
 * GitbookRenderer already reads, so there is no document-JSON conversion to
 * write. `format.markdown.refs=stable` asks for absolute page references
 * instead of relative ones, which survive being flattened into this app's
 * single-level page list (a `../getting-started/install` link would not).
 *
 * List endpoints share one envelope: `{items: [...], next: {page: cursor}}`.
 */
class GitbookClient
{
    public function configured(): bool
    {
        return filled(config('services.gitbook.token'));
    }

    /** @return array<int, array<string, mixed>> */
    public function organizations(): array
    {
        return $this->paginate('/orgs');
    }

    /** @return array<int, array<string, mixed>> */
    public function spaces(string $organizationId): array
    {
        return $this->paginate('/orgs/' . urlencode($organizationId) . '/spaces');
    }

    /** @return array<string, mixed> */
    public function space(string $spaceId): array
    {
        return $this->get('/spaces/' . urlencode($spaceId));
    }

    /**
     * The current published revision's page tree. Nodes are `document`
     * (content, possibly with nested `pages`), `group` (no content of its
     * own), `link` (external) or `computed` (generated from an OpenAPI spec).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pageTree(string $spaceId): array
    {
        $payload = $this->get('/spaces/' . urlencode($spaceId) . '/content/pages', [
            // Git metadata we have no use for, and skipping it is documented
            // as the faster lookup.
            'metadata' => 'false',
        ]);

        return $payload['pages'] ?? [];
    }

    /** The page's body as GitBook-flavoured Markdown ('' for a page with no content). */
    public function pageMarkdown(string $spaceId, string $pageId): string
    {
        $payload = $this->get(
            '/spaces/' . urlencode($spaceId) . '/content/page/' . urlencode($pageId),
            ['format' => 'markdown', 'format.markdown.refs' => 'stable', 'metadata' => 'false'],
        );

        return (string) ($payload['markdown'] ?? '');
    }

    /**
     * Walks a list endpoint's cursor to the end.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paginate(string $path): array
    {
        $items = [];
        $cursor = null;

        do {
            $payload = $this->get($path, $cursor ? ['page' => $cursor] : []);
            $items = [...$items, ...($payload['items'] ?? [])];
            $cursor = $payload['next']['page'] ?? null;
        } while ($cursor);

        return $items;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        if (! $this->configured()) {
            throw GitbookApiException::missingToken();
        }

        /** @var Response $response */
        $response = Http::gitbook()->get($path, $query);

        if ($response->failed()) {
            throw GitbookApiException::fromResponse($path, $response);
        }

        return $response->json() ?? [];
    }
}
