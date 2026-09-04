<?php

namespace App\Support\Digibee;

/**
 * What one `digibee:design:probe` run learned about Digibee's platform API.
 *
 * The probe exists because every route the autonomous lifecycle needs for the
 * one operation `digibeectl` cannot perform — writing a flowSpec into a
 * pipeline — is UNDOCUMENTED. Building the ingestion, the deploy runner, the
 * test matrix and the self-healing loop on top of an unverified route means
 * discovering it was wrong four subsystems later, so the route is verified
 * first and this is the artifact that says so.
 *
 * `roundTripKeys` is the finding that decides the whole feature. A pipeline
 * read back has to carry the keys a WRITE would need to send — the 201
 * pipelines in the local export each carry 34, of which the generator today
 * produces two (`meta` and `flowSpec`, and `meta` is a clipboard construct
 * that appears in none of them). If the detail response omits `flowSpec` or
 * `triggerSpec`, there is nothing to round-trip and the closed loop is not
 * reachable by this route, however cleanly it answered 200.
 */
final readonly class DigibeeProbeReport
{
    /**
     * The keys a design-API upsert plausibly has to carry, taken from what
     * every one of the 201 exported pipelines actually holds rather than from
     * a guess about the API. Presence is reported, never assumed.
     */
    public const ROUND_TRIP_KEYS = [
        'id', 'name', 'projectId', 'flowSpec', 'triggerSpec', 'metadata',
        'canvasVersion', 'versionMajor', 'versionMinor', 'inSpec', 'outSpec',
    ];

    /**
     * @param  list<array{label: string, method: string, path: string, status: int|null, ok: bool, note: string, shape: list<string>}>  $steps
     * @param  list<string>  $roundTripKeys  ROUND_TRIP_KEYS the detail response actually returned
     */
    public function __construct(
        public DigibeeCredentials $credentials,
        public array $steps = [],
        public array $roundTripKeys = [],
        public bool $reachedDetail = false,
    ) {}

    public function ok(): bool
    {
        return $this->steps !== []
            && array_filter($this->steps, fn (array $step) => ! $step['ok']) === [];
    }

    /** @return list<string> ROUND_TRIP_KEYS the detail response did NOT return */
    public function missingRoundTripKeys(): array
    {
        return array_values(array_diff(self::ROUND_TRIP_KEYS, $this->roundTripKeys));
    }

    /**
     * The one line worth reading, and it deliberately refuses to call a
     * partial result a success: reading a pipeline proves the route exists,
     * not that a flowSpec can be written back through it.
     */
    public function verdict(): string
    {
        if (! $this->credentials->complete()) {
            return 'no credential resolved — nothing was called';
        }

        if (! $this->ok()) {
            return 'at least one route did not answer as the spec assumes — see the notes above before building on it';
        }

        if (! $this->reachedDetail) {
            return 'the routes answered, but no pipeline detail was read, so round-trip is unverified';
        }

        return $this->missingRoundTripKeys() === []
            ? 'the design routes answer and a pipeline round-trips — ingestion is reachable'
            : 'the routes answer but the detail response omits: ' . implode(', ', $this->missingRoundTripKeys());
    }
}
