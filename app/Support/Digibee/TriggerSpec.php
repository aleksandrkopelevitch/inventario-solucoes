<?php

namespace App\Support\Digibee;

use App\Enums\DigibeeTriggerAuth;
use App\Enums\DigibeeTriggerKind;

/**
 * A synthesized `triggerSpec`, plus the two lists that say how much of it is
 * actually known.
 *
 * `assumptions` are values taken from what the tenant's own pipelines do —
 * defensible, and still somebody else's decision applied to this pipeline.
 * `missing` is what synthesis REFUSED to invent, and a spec with anything in
 * it is not usable: a scheduler needs a cron expression and an event needs an
 * event name, neither of which is derivable from a flowSpec, and both of which
 * fail silently when guessed. A pipeline scheduled at the wrong hour runs — it
 * just runs wrong, at 3am, against production data.
 */
final readonly class TriggerSpec
{
    /**
     * @param  array<string, mixed>  $spec  the `triggerSpec` as the platform stores it
     * @param  list<string>  $assumptions  values taken from the tenant's convention
     * @param  list<string>  $missing  what only a person can decide
     */
    public function __construct(
        public DigibeeTriggerKind $kind,
        public DigibeeTriggerAuth $auth,
        public array $spec,
        public array $assumptions = [],
        public array $missing = [],
    ) {}

    /** Complete enough to write into a pipeline. */
    public function usable(): bool
    {
        return $this->missing === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->spec;
    }

    /**
     * What the test runner needs beyond the URL: whether it has to carry a
     * credential, and which methods the endpoint answers at all.
     *
     * @return array{callable: bool, methods: list<string>, auth: string}
     */
    public function callability(): array
    {
        return [
            'callable' => $this->kind->isWebProtocol(),
            'methods'  => array_values(array_filter(
                is_array($this->spec['methods'] ?? null) ? $this->spec['methods'] : [],
                is_string(...),
            )),
            'auth' => $this->auth->value,
        ];
    }
}
