<?php

namespace App\Http\Requests;

use App\Rules\AsciiSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a lifecycle run from the F8 conversation.
 *
 * Both fields are guardrails rather than preferences:
 *
 * - **The environment is validated against `deployable_environments`**, at the
 *   door. The actions refuse it too, but a request that reaches a queued job
 *   before being refused leaves a row, a dispatch and a person watching a run
 *   that was never going to happen.
 * - **The pipeline name is an `AsciiSlug`.** It is what the platform addresses
 *   the pipeline by and what ends up in the endpoint URL
 *   (`…/pipeline/{realm}/v1/{name}`), and every one of the tenant's 201 names
 *   is already lowercase ASCII with single hyphens. Nothing deletes a pipeline
 *   here, so a name with a space or an accent in it is permanent.
 */
class StorePipelineRunRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'pipeline_name' => ['required', 'string', 'max:255', new AsciiSlug],
            'environment'   => [
                'required',
                'string',
                Rule::in((array) config('services.digibee.design.deployable_environments')),
            ],
            // Creating one is permanent on this platform, so it is never a
            // default: the form asks, and the row records the answer.
            'creates' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'environment.in' => 'Esse ambiente não está liberado para implantação.',
        ];
    }
}
