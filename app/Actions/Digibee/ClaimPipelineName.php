<?php

namespace App\Actions\Digibee;

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\DigibeeDesignClient;

/**
 * The name a web-started run may write under: the one asked for when the realm
 * has no pipeline by that name, otherwise the first free `{name}-vN`.
 *
 * A run started from the Especialista never writes into a pipeline that
 * already exists (the user's call, 2026-11-03): ingesting by name used to let
 * an Integrations Editor overwrite any pipeline in the realm, released ones
 * included, and nothing in the platform undoes a write. A taken name becomes a
 * new pipeline beside it instead — `zfl-cadastro` → `zfl-cadastro-v1`, and a
 * name that already ends in `-vN` continues from N.
 *
 * Resolved ONCE per run, before the healing loop: its second round writes into
 * the pipeline its first round created, which is this run's own.
 */
class ClaimPipelineName
{
    private const MAX_SUFFIX = 99;

    public function __construct(private readonly DigibeeDesignClient $client) {}

    public function handle(string $name): string
    {
        if ($this->client->latestByName($name) === null) {
            return $name;
        }

        [$base, $from] = preg_match('/^(.*)-v(\d+)$/', $name, $m) === 1
            ? [$m[1], (int) $m[2] + 1]
            : [$name, 1];

        for ($n = $from; $n <= self::MAX_SUFFIX; $n++) {
            $candidate = "{$base}-v{$n}";

            if ($this->client->latestByName($candidate) === null) {
                return $candidate;
            }
        }

        throw new DigibeeApiException("Nenhum nome livre entre {$base}-v{$from} e {$base}-v" . self::MAX_SUFFIX . ' — escolha outro nome para o pipeline.');
    }
}
