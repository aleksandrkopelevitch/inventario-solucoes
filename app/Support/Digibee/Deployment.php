<?php

namespace App\Support\Digibee;

use App\Enums\DeploymentStatus;

/**
 * One deployment, read from `GET /runtime/realms/{realm}/deployments`.
 *
 * The field worth the class is `endpoint()`. The platform reports the URL it
 * assigned, nested inside `deploymentStatus.trigger` as a JSON STRING holding
 * a key/value list:
 *
 *     [{"key":"endpoint","value":"https://test.godigibee.io/pipeline/leomadeiras/v1/zfl-…"}]
 *
 * Using that instead of composing a URL removes the failure the whole
 * `runtime_hosts` map exists to prevent — an environment written into the
 * wrong half of the address sends a "test" call to production. Composition
 * stays as the fallback for a pipeline whose deployment has not reported one
 * yet (`PipelineTestSuite::endpointUrl()`), but when the platform has said it,
 * the platform wins.
 */
final readonly class Deployment
{
    /** @param array<string, mixed> $raw */
    private function __construct(public array $raw) {}

    /** @param array<string, mixed> $raw */
    public static function from(array $raw): self
    {
        return new self($raw);
    }

    public function id(): string
    {
        return (string) ($this->raw['id'] ?? '');
    }

    public function pipelineName(): string
    {
        return (string) ($this->raw['pipelineName'] ?? $this->raw['pipeline']['name'] ?? '');
    }

    public function versionMajor(): int
    {
        return (int) ($this->raw['pipelineMajorVersion'] ?? $this->raw['pipeline']['versionMajor'] ?? 0);
    }

    public function status(): DeploymentStatus
    {
        return DeploymentStatus::fromPlatform($this->raw['status'] ?? null);
    }

    /** The URL the platform assigned, or null when it has not reported one. */
    public function endpoint(): ?string
    {
        $trigger = $this->raw['deploymentStatus']['trigger'] ?? null;

        if (! is_string($trigger) || $trigger === '') {
            return null;
        }

        $entries = json_decode($trigger, true);

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && ($entry['key'] ?? null) === 'endpoint' && is_string($entry['value'] ?? null)) {
                return $entry['value'];
            }
        }

        return null;
    }

    /**
     * What the engine reports about the running pipeline — the diagnosis the
     * self-healing loop reads when a deploy succeeds and the pipeline is still
     * wrong. `availableReplicas` reads "1/1" when it is up and "0/0" when
     * autoscaling has parked it, which is the ordinary state for 81 of the 111
     * deployments in `test` and must never be mistaken for a failure.
     *
     * @return array{replicas: string|null, errors: int, oom: int, lastError: string|null}
     */
    public function engine(): array
    {
        $status = is_array($this->raw['deploymentStatus'] ?? null) ? $this->raw['deploymentStatus'] : [];

        return [
            'replicas'  => is_string($status['availableReplicas'] ?? null) ? $status['availableReplicas'] : null,
            'errors'    => (int) ($status['error-count'] ?? 0),
            'oom'       => (int) ($status['oom-count'] ?? 0),
            'lastError' => is_string($status['last-error-message'] ?? null) && $status['last-error-message'] !== ''
                ? $status['last-error-message']
                : null,
        ];
    }

    public function configurationName(): ?string
    {
        $name = $this->raw['activeConfiguration']['name'] ?? null;

        return is_string($name) ? $name : null;
    }
}
