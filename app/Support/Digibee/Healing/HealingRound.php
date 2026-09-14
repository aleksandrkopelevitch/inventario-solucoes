<?php

namespace App\Support\Digibee\Healing;

use App\Enums\HealingVerdict;
use App\Support\Digibee\DeploymentReport;
use App\Support\Digibee\IngestionReport;
use App\Support\Digibee\Testing\SuiteRun;

/**
 * One turn of the healing loop: write, deploy, test, judge.
 *
 * Each of the three artifacts is nullable because a round legitimately stops
 * early — a document that will not ingest is never deployed, and a deploy that
 * is refused is never tested. Reading a null as "that step passed" is the
 * mistake this shape exists to make impossible: `reached()` says how far the
 * round actually got, and the report prints that rather than a tally.
 *
 * `$evidence` is the lines that were handed to the model, and it is kept even
 * on the last round — where nothing was re-prompted — because it is the
 * answer to "what did it know when it gave up".
 */
final readonly class HealingRound
{
    /** @param list<string> $evidence */
    public function __construct(
        public int $round,
        public HealingVerdict $verdict,
        public ?IngestionReport $ingestion = null,
        public ?DeploymentReport $deployment = null,
        public ?SuiteRun $run = null,
        public array $evidence = [],
    ) {}

    /** How far this round got, as a word a person can read in a report. */
    public function reached(): string
    {
        return match (true) {
            $this->run !== null        => 'bateria',
            $this->deployment !== null => 'deploy',
            $this->ingestion !== null  => 'escrita',
            default                    => 'nada',
        };
    }

    /** Whether this round produced something worth re-prompting the model with. */
    public function correctable(): bool
    {
        return $this->evidence !== []
            && in_array($this->verdict, [HealingVerdict::StillFailing, HealingVerdict::NotIngested], true);
    }
}
