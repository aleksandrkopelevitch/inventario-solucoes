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
     * @param  bool  $documentRejected  whether those errors are about the DOCUMENT
     *                                  rather than about the realm
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
        public bool $documentRejected = false,
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

    /**
     * Whether this refusal is something a rewrite could fix.
     *
     * The healing loop needs the distinction and nothing else here did, which
     * is why it did not exist: only OUR validation rejecting the flowSpec says
     * anything about the document. "No pipeline called X exists in the realm"
     * and "the triggerSpec you asked me to synthesize is missing a cron" are
     * facts about the request and the realm — handing either to a model as
     * evidence spends an attempt asking it to fix a document that is fine, and
     * ends the run confused.
     */
    public function correctable(): bool
    {
        return ! $this->ok() && $this->documentRejected;
    }
}
