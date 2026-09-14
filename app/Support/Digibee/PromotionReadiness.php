<?php

namespace App\Support\Digibee;

/**
 * Whether a pipeline is fit for a PERSON to promote in the Digibee panel.
 *
 * It is a verdict and not an action, and that is the whole topology decision
 * behind this block: the agent's reach stops at `test`, and promotion — the one
 * operation that touches real traffic — stays a human clicking in the panel. A
 * credential on this droplet (shared with two other apps) that could deploy to
 * production would reverse the boundary the rest of this feature is built on,
 * to save that one click.
 *
 * So what this carries is what somebody needs in front of them at the moment
 * they click: whether the evidence was real, whether the pipeline still is what
 * was tested, and WHICH VERSION to promote — because the panel lists rows and
 * the answer is worthless if it does not name one.
 */
final readonly class PromotionReadiness
{
    /**
     * @param  list<string>  $blockers  every reason it is not ready, not just the first
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $pipelineName,
        public string $testedIn,
        public bool $ready = false,
        public array $blockers = [],
        public ?string $version = null,
        public ?string $endpoint = null,
        public array $notes = [],
    ) {}
}
