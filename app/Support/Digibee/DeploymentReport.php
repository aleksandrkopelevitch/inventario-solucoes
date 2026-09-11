<?php

namespace App\Support\Digibee;

use App\Enums\DeploymentStatus;

/**
 * What one deploy attempt did — the artifact block F reads to decide whether
 * the pipeline is worth testing, worth correcting, or not deployed at all.
 *
 * It separates three failures that look alike from the outside and need
 * different answers: the platform refused the request, the deploy ran and the
 * service came up broken, and the deploy is still settling when we stopped
 * waiting. Only the middle one is evidence about the flowSpec.
 */
final readonly class DeploymentReport
{
    /**
     * @param  array{replicas: string|null, errors: int, oom: int, lastError: string|null}  $engine
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $pipelineName,
        public string $environment,
        public ?string $pipelineId = null,
        public ?string $deploymentId = null,
        public DeploymentStatus $status = DeploymentStatus::Unknown,
        public ?string $endpoint = null,
        public array $engine = ['replicas' => null, 'errors' => 0, 'oom' => 0, 'lastError' => null],
        public bool $deployed = false,
        public int $waitedSeconds = 0,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** Deployed AND reported healthy by the platform. */
    public function live(): bool
    {
        return $this->deployed && $this->status->healthy();
    }

    /** Ran, came up, and can be called — which is what the test runner needs. */
    public function testable(): bool
    {
        return $this->live() && $this->endpoint !== null;
    }
}
