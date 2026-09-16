<?php

namespace App\Support\Archify;

/**
 * One Archify CLI invocation, already decoded.
 *
 * `problems` is the flattened, human-readable half of the `diagnostics` array
 * the CLI emits under `--json`: one sentence per diagnostic, with the fix it
 * suggests appended. It is what the repair round is handed, and what an
 * operator sees if the repair round fails — so it deliberately keeps Archify's
 * own wording (which names the offending node and path) rather than
 * summarising it into something that cannot be acted on.
 */
final class ArchifyResult
{
    /**
     * @param  array<mixed>  $payload  the decoded `--json` envelope
     * @param  list<string>  $problems
     * @param  bool  $ran  whether the CLI actually RAN. False means the process
     *                     never produced a verdict — the binary could not be
     *                     launched, or it was killed on timeout — which is an
     *                     operator's problem and reads nothing like "your
     *                     diagram is invalid".
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $payload = [],
        public readonly array $problems = [],
        public readonly string $stderr = '',
        public readonly bool $ran = true,
    ) {}

    /** The sidecar could not be executed at all. */
    public static function couldNotRun(string $reason): self
    {
        return new self(ok: false, problems: [$reason], stderr: $reason, ran: false);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload, string $stderr = ''): self
    {
        $problems = [];

        foreach ($payload['diagnostics'] ?? [] as $diagnostic) {
            if (! is_array($diagnostic)) {
                continue;
            }

            $message = (string) ($diagnostic['message'] ?? '');
            $fixes = array_filter((array) ($diagnostic['supportedFixes'] ?? []), 'is_string');

            if ($message === '') {
                continue;
            }

            $problems[] = $fixes === [] ? $message : $message . ' — ' . implode('; ', $fixes);
        }

        // A failure with no diagnostics at all still has to say something: the
        // envelope's own `error` is the only wording left, and silence here
        // would surface as "it didn't work" with nothing to act on.
        if ($problems === [] && ! ($payload['ok'] ?? false) && filled($payload['error'] ?? null)) {
            $problems[] = (string) $payload['error'];
        }

        return new self((bool) ($payload['ok'] ?? false), $payload, $problems, $stderr);
    }
}
