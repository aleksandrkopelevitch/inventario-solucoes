<?php

namespace App\Enums;

/**
 * Where a `{meta, flowSpec}` document is going, which is what decides how its
 * entry branch is spelled and whether canvas positions mean anything at all.
 *
 * The two are NOT stylistic variants of one format. Measured over the 201
 * pipelines exported from the tenant: all 201 root at `start`, none carries a
 * top-level `meta` key and no step carries a `position` — while the generator
 * is required to emit exactly one `disconnected-root:<uuid>` plus a `meta`
 * entry per canvas step, because its output is written to be PASTED into the
 * canvas. `meta.position` is therefore a CLIPBOARD construct, not a
 * persistence one, and a document ingested through the design API needs
 * neither.
 *
 * Both spellings have to keep working: people go on pasting (that is what the
 * generator is for today), and the API's own write path was verified with
 * `start` — a document upserted that way roots the way the tenant's own
 * pipelines do. So this is a mode key, not a migration.
 *
 * The one thing it is NOT is a third prompt contract. The model keeps
 * generating exactly one shape (the clipboard one, rule 1 of the system
 * prompt) and the ingestion path CONVERTS, because a second generation
 * contract would double what every prompt regression has to be tested
 * against, for a difference that is mechanical: rename one branch key, drop
 * `meta`.
 */
enum FlowspecTarget: string
{
    /** Pasted into the Digibee canvas: `disconnected-root:<uuid>` + `meta.position`. */
    case Clipboard = 'clipboard';

    /** Written into a pipeline through the design API: rooted at `start`, no `meta`. */
    case Platform = 'platform';

    /** The entry branch's exact name, where the target has a fixed one. */
    public function rootBranch(): ?string
    {
        return match ($this) {
            self::Platform  => 'start',
            self::Clipboard => null, // `disconnected-root:<uuid v4>` — the uuid is the document's
        };
    }

    public function isRootBranch(string $branch): bool
    {
        return match ($this) {
            self::Platform  => $branch === 'start',
            self::Clipboard => str_starts_with($branch, 'disconnected-root:'),
        };
    }

    /**
     * Whether `meta[<step id>].position` is required for canvas steps.
     *
     * False for the platform for a measured reason rather than a tolerant one:
     * a stored pipeline has no `meta` at all, so requiring positions there
     * would reject every document the tenant itself contains.
     */
    public function usesCanvasPositions(): bool
    {
        return $this === self::Clipboard;
    }

    public function label(): string
    {
        return match ($this) {
            self::Clipboard => 'colagem no canvas',
            self::Platform  => 'ingestão pela API',
        };
    }
}
