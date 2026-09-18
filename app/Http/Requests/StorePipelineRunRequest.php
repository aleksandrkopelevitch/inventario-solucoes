<?php

namespace App\Http\Requests;

use App\Enums\DigibeeTriggerKind;
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

            // The trigger the run will write. Optional, because a pipeline that
            // already has one in the realm needs nothing here — but a pipeline
            // being CREATED without one cannot be deployed at all, which is
            // what `DeployPipeline` now refuses instead of discovering as a
            // 500 from the platform.
            'trigger_kind' => ['nullable', Rule::enum(DigibeeTriggerKind::class)],
            // The two values a flowSpec cannot yield. Required with their kind
            // and rejected without it: a cron typed against a REST trigger is
            // a misunderstanding worth answering, not a field to ignore.
            'trigger_cron' => [
                'exclude_unless:trigger_kind,scheduler',
                'required',
                'string',
                'max:120',
            ],
            'trigger_event' => [
                'exclude_unless:trigger_kind,event',
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'environment.in'         => 'Esse ambiente não está liberado para implantação.',
            'trigger_kind.enum'      => 'Esse tipo de gatilho não existe na plataforma.',
            'trigger_cron.required'  => 'Um agendamento precisa do cron: ele não sai do flowSpec, e um cron chutado não falha — roda na hora errada.',
            'trigger_event.required' => 'Um gatilho de evento precisa do nome do evento: um nome inventado escuta um tópico que ninguém publica, sem erro nenhum.',
        ];
    }
}
