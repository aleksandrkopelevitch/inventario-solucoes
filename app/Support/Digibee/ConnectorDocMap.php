<?php

namespace App\Support\Digibee;

/**
 * Which documentation page describes which catalog component.
 *
 * **Curated and committed, not matched at runtime.** 33 of the 34 connectors
 * do fall out of a slug match (`rest-connector-v2` → `web-protocols/rest-v2`),
 * but a wrong automatic match is worse than a missing one — it teaches the
 * model another component's parameters, and nothing downstream can tell. So the
 * derivation ran once, its result was reviewed, and
 * `database/data/digibee_connector_docs.json` is what ships: the same reasoning
 * that keeps `digibee_component_catalog.json` a static file rather than a live
 * lookup.
 *
 * Two decisions are baked into that file:
 *
 * - **A connector page wins over a trigger page of the same name.** `rabbitmq`
 *   and `email-v2` exist as both, and a trigger documents how a pipeline is
 *   STARTED — which is not what a step's params are.
 * - **`google-bigquery-sql-connector` is the one hand-written entry**, since
 *   its page is filed as `google-gcp/bigquery-standard-sql`.
 *
 * Six step types (`connector`, `transformer`, `capsule`, `library`, `assert`,
 * `validator`) map to nothing on purpose: they are flowSpec kinds rather than
 * components, and the platform publishes no page for them.
 */
class ConnectorDocMap
{
    private const PATH = 'data/digibee_connector_docs.json';

    /** @var array{connectors: array<string, string>, step_types: array<string, string>}|null */
    private static ?array $map = null;

    /** The doc key for a connector name, or null when the catalog has no page for it. */
    public static function forConnector(string $connector): ?string
    {
        return self::map()['connectors'][$connector] ?? null;
    }

    public static function forStepType(string $type): ?string
    {
        return self::map()['step_types'][$type] ?? null;
    }

    /** Every connector name that has a documented page, in catalog order. */
    public static function connectors(): array
    {
        return array_keys(self::map()['connectors']);
    }

    /** @return array{connectors: array<string, string>, step_types: array<string, string>} */
    public static function map(): array
    {
        return self::$map ??= json_decode(
            (string) file_get_contents(database_path(self::PATH)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** Tests that rewrite the file need the memo dropped; nothing in the app does. */
    public static function flush(): void
    {
        self::$map = null;
    }
}
