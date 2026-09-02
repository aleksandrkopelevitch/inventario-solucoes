<?php

namespace App\Actions\Digibee;

use App\Support\Digibee\ConnectorCardBuilder;
use App\Support\Digibee\ConnectorDocMap;
use App\Support\Digibee\DigibeeDocsCorpus;

/**
 * Distils the synced corpus into `database/data/digibee_connector_cards.json` —
 * one card per catalog connector, which is what a flowSpec prompt actually
 * carries.
 *
 * **The artifact is committed, the corpus is not.** The corpus lives under
 * `storage/app/private` and is re-syncable; the cards are an INPUT to
 * generation, so they belong beside `digibee_component_catalog.json` for the
 * same reason it does — a generated flowSpec has to be reproducible from a
 * checkout, and a card that changed has to show up in a diff somebody reads.
 *
 * Runs at the end of every sync: a card is a pure function of its page, so
 * leaving the two to drift would mean a corpus that says one thing and a prompt
 * that says another, with nothing to notice.
 */
class BuildConnectorCards
{
    /** Where the artifact lives — config-driven so a test can redirect it. */
    public static function path(): string
    {
        return (string) config('services.digibee.cards_path');
    }

    public function __construct(
        private readonly DigibeeDocsCorpus $corpus,
        private readonly ConnectorCardBuilder $builder,
    ) {}

    /**
     * @return array{built: int, missing: list<string>, noParameters: list<string>, bytes: int}
     */
    public function handle(): array
    {
        $cards = [];
        $missing = [];
        $noParameters = [];

        foreach (ConnectorDocMap::map()['connectors'] as $connector => $key) {
            $page = $this->corpus->page($key);
            $markdown = $this->corpus->markdown($key);

            // A page named by the map that the corpus does not hold is a real
            // signal, not a shrug: either the sync stopped short or Digibee
            // moved the page and the map needs the new key. Reported, never
            // silently skipped.
            if ($page === null || $markdown === null) {
                $missing[] = "{$connector} ({$key})";

                continue;
            }

            $card = $this->builder->build($connector, $page, $markdown);

            // A card with no parameters is KEPT, and reported. Some connectors
            // genuinely take none ("Block Execution doesn't have any specific
            // configuration to function"), and for those the summary alone is
            // worth carrying. But an empty card is also exactly what a parsing
            // regression looks like, and the two are indistinguishable from
            // here — so it goes in the report every time rather than being
            // decided silently either way. The card itself claims nothing: with
            // no groups, `toPrompt()` prints the summary and stops.
            if ($card->parameterCount() === 0) {
                $noParameters[] = $connector;
            }

            $cards[$connector] = $card->toArray();
        }

        ksort($cards);

        $json = json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents(self::path(), $json);

        return [
            'built'        => count($cards),
            'missing'      => $missing,
            'noParameters' => $noParameters,
            'bytes'        => strlen($json),
        ];
    }
}
