<?php

namespace App\Support\Digibee;

/**
 * What a promotion attempt decided — and it is written to be read when it
 * REFUSES, because that is what it will do almost every time.
 *
 * A gate whose interesting output is "promoted: true" is a gate nobody checks.
 * Each refusal is a whole sentence naming the condition that was not met, so
 * the answer to "why is this not in production" never requires reading the
 * code that refused.
 */
final readonly class PromotionReport
{
    /**
     * @param  list<string>  $refusals  every condition that failed, not just the first
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $pipelineName,
        public string $fromEnvironment,
        public string $toEnvironment,
        public bool $promoted = false,
        public array $refusals = [],
        public ?DeploymentReport $deployment = null,
        public array $warnings = [],
    ) {}

    public function refused(): bool
    {
        return $this->refusals !== [];
    }
}
