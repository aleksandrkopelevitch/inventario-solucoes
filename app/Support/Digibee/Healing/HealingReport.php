<?php

namespace App\Support\Digibee\Healing;

use App\Enums\HealingVerdict;

/**
 * What a healing run did, end to end — the artifact a person reads and the one
 * the eventual promotion gate (Bloco G) asks before it will touch `prod`.
 *
 * The `document` it carries is the last one the run HELD, which is not
 * necessarily the best one: a loop ending `StillFailing` leaves the pipeline
 * holding the final attempt, because that attempt is what is deployed.
 * Reporting an earlier round's document because it "failed less" would
 * describe a pipeline that is not the one running. The one ending where it was
 * never written at all is `NotIngested`, and the verdict says so.
 */
final readonly class HealingReport
{
    /**
     * @param  list<HealingRound>  $rounds
     * @param  array<string, mixed>|null  $document  the last document written
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $pipelineName,
        public string $environment,
        public HealingVerdict $verdict,
        public array $rounds = [],
        public ?array $document = null,
        public ?string $endpoint = null,
        public array $warnings = [],
    ) {}

    public function healed(): bool
    {
        return $this->verdict->healed();
    }

    /** Rounds that actually reached the platform — what this run cost the realm. */
    public function deploys(): int
    {
        return count(array_filter($this->rounds, fn (HealingRound $r) => $r->deployment?->deployed === true));
    }

    /**
     * The evidence the run ended on, or an empty list when it never got to
     * judge the pipeline at all.
     *
     * @return list<string>
     */
    public function finalEvidence(): array
    {
        // `end()` moves the array's internal pointer, which on a readonly
        // promoted property is a write — "Cannot modify readonly property",
        // from a method that only reads.
        $last = $this->rounds[array_key_last($this->rounds)] ?? null;

        return $last instanceof HealingRound ? $last->evidence : [];
    }
}
