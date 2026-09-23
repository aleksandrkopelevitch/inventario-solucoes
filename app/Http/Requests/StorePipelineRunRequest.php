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
    /**
     * `creates` arrives as `"1"` from the form's checkbox and as `true` from a
     * JSON client, and `required_if` compares the two STRICTLY once the other
     * value is a bool (`validateRequiredIf` passes `is_bool($other)` as
     * `in_array`'s third argument). So `required_if:creates,1` fires for the
     * form and silently does nothing for JSON — the half that is easiest to
     * test and hardest to notice. Normalising first makes one rule cover both.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('creates')) {
            $this->merge(['creates' => $this->boolean('creates')]);
        }
    }

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

            // The trigger the run will write. Optional against an EXISTING
            // pipeline, which keeps whatever it already has in the realm —
            // and required when `creates` is set, because there is no "own
            // trigger" to keep on a pipeline that does not exist yet.
            //
            // Without `required_if` the form could still reproduce the exact
            // condition this whole change exists to remove: the run would
            // create the pipeline (permanent — nothing on this platform
            // deletes one), `DeployPipeline` would then refuse it for an empty
            // `triggerSpec`, and the realm would be left with an undeployable
            // name nobody can reclaim. Refusing here costs nothing.
            'trigger_kind' => ['required_if:creates,true', 'nullable', Rule::enum(DigibeeTriggerKind::class)],
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
            'environment.in'           => 'Esse ambiente não está liberado para implantação.',
            'trigger_kind.enum'        => 'Esse tipo de gatilho não existe na plataforma.',
            'trigger_kind.required_if' => 'Um pipeline novo precisa de gatilho: sem ele a Digibee recusa publicar, e o pipeline criado fica para sempre — nada aqui apaga um.',
            'trigger_cron.required'    => 'Um agendamento precisa do cron: ele não sai do flowSpec, e um cron chutado não falha — roda na hora errada.',
            'trigger_event.required'   => 'Um gatilho de evento precisa do nome do evento: um nome inventado escuta um tópico que ninguém publica, sem erro nenhum.',
        ];
    }
}
