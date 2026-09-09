<?php

namespace App\Support\Digibee;

/**
 * What one ingestion did, said in a way that can be printed in a terminal or
 * pasted into a ticket.
 *
 * It reports the SHAPE and never the content, for the same reason
 * ProbeDigibeeDesignApi does: a pipeline document carries internal hostnames,
 * and eight of the 201 exported ones carry a literal credential. "9 steps
 * across 3 branches" is what a person needs to see; the steps themselves are
 * not.
 */
final readonly class IngestionReport
{
    /**
     * @param  list<string>  $changes  what this write actually changed
     * @param  list<string>  $warnings  what it left standing, and why that may matter
     * @param  list<string>  $errors  why nothing was written
     */
    public function __construct(
        public string $pipelineName,
        public ?string $pipelineId = null,
        public bool $created = false,
        public bool $wrote = false,
        public bool $verified = false,
        public int $stepCount = 0,
        public int $branchCount = 0,
        public int $versionMajor = 0,
        public int $versionMinor = 0,
        public ?TriggerSpec $trigger = null,
        public bool $triggerApplied = false,
        public array $changes = [],
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /**
     * A flowSpec that was written and read back identical. Anything else —
     * refused, dry run, written but not confirmed — is not this.
     */
    public function confirmed(): bool
    {
        return $this->wrote && $this->verified;
    }
}
