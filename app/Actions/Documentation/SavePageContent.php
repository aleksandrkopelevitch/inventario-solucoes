<?php

namespace App\Actions\Documentation;

use App\Exceptions\StalePageVersion;
use App\Models\DocumentationPage;
use App\Support\Documentation\SecretText;
use Illuminate\Support\Facades\DB;

/**
 * Saves a page's Markdown — the ONE door both writers of a page body go
 * through: the browser editor (`EditsDocumentation::persistDocumentation`) and
 * the MCP's `update_page` / `create_page`.
 *
 * Two things happen on every save, and both must be shared or the two doors
 * drift:
 *
 * - **The version is checked** when the writer says which one it read. A save
 *   based on text that is no longer stored is refused (`StalePageVersion`)
 *   instead of overwriting the newer text — in BOTH directions: a chat must not
 *   erase what a person just typed, and a person's next autosave must not
 *   erase what the chat just wrote. The row is locked for the comparison, so
 *   two saves cannot both read the old version and both win.
 * - **Protected values are put back** (`SecretText::restoreWithIds()`), since
 *   every writer is handed masked text.
 */
class SavePageContent
{
    /**
     * @return array{secretIds: list<string>, version: string}
     */
    public function handle(DocumentationPage $page, ?string $markdown, ?string $baseVersion = null): array
    {
        return DB::transaction(function () use ($page, $markdown, $baseVersion) {
            $stored = DocumentationPage::query()->lockForUpdate()->findOrFail($page->getKey(), ['id', 'documentation']);

            if ($baseVersion !== null && $baseVersion !== $stored->version()) {
                throw new StalePageVersion($stored->version());
            }

            [$documentation, $secretIds] = SecretText::restoreWithIds($markdown, $stored->documentation);

            $page->update(['documentation' => $documentation]);

            return ['secretIds' => $secretIds, 'version' => $page->refresh()->version()];
        });
    }
}
