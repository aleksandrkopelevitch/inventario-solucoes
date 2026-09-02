<?php

namespace App\Support\Digibee;

use App\Actions\Digibee\BuildConnectorCards;

/**
 * The read side of the connector cards: what a prompt asks for when it knows
 * which components are in play.
 *
 * Deliberately a lookup by NAME and nothing else. The retrieval question the
 * flowSpec generator has is "what does `object-store-connector` take", and the
 * name is known exactly on both sides — from the catalog, from a pasted
 * pipeline's steps, from the tags a request derived. An approximate index would
 * be strictly worse at that, and would cost the reproducibility the generator
 * is built on.
 */
class ConnectorReference
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cards = null;

    public function has(string $connector): bool
    {
        return isset(self::cards()[$connector]);
    }

    public function card(string $connector): ?ConnectorCard
    {
        $data = self::cards()[$connector] ?? null;

        return $data === null ? null : ConnectorCard::fromArray($data);
    }

    /**
     * Cards for the connectors in play, in the order they were asked for and
     * capped — the cap is about attention, not tokens (six cards is ~9k of a
     * 500k limit), the same reasoning behind `max_examples`.
     *
     * @param  list<string>  $connectors
     * @return list<ConnectorCard>
     */
    public function cardsFor(array $connectors, ?int $limit = null): array
    {
        $limit ??= (int) config('services.digibee.max_connector_cards');
        $cards = [];

        foreach (array_unique($connectors) as $connector) {
            $card = $this->card($connector);

            if ($card !== null) {
                $cards[] = $card;
            }

            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    public function isEmpty(): bool
    {
        return self::cards() === [];
    }

    /** @return array<string, array<string, mixed>> */
    private static function cards(): array
    {
        if (self::$cards !== null) {
            return self::$cards;
        }

        $path = BuildConnectorCards::path();

        // An app checked out before the first sync has no cards, and that is a
        // working state: the prompt simply carries no reference section, which
        // is exactly how the generator behaved before this existed.
        if (! is_file($path)) {
            return self::$cards = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$cards = is_array($decoded) ? $decoded : [];
    }

    public static function flush(): void
    {
        self::$cards = null;
    }
}
