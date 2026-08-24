<?php

namespace App\Http\Requests;

use App\Models\ApprovedTopology;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Choosing where an approved TO BE lands.
 *
 * `integration_id` empty means "create a new one" — the common case for a
 * proposal, which usually describes something the catalog does not have yet.
 *
 * When it names an existing integration, that integration must be one the
 * SOLUTION participates in. Without that check, an approval on one solution
 * could overwrite the topology of an integration belonging to another — a write
 * nobody would ever look for, on a record nobody involved was editing.
 */
class ApplyApprovedTopologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'integration_id' => ['nullable', 'integer', 'exists:integrations,id'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $integration = $this->targetIntegration();

            if ($integration === null) {
                return;
            }

            $topology = $this->route('topology');

            if (! $topology instanceof ApprovedTopology) {
                return;
            }

            if (! $integration->participants()->whereKey($topology->solution_id)->exists()) {
                $validator->errors()->add(
                    'integration_id',
                    'Essa integração não é da solução desta submissão.',
                );
            }
        });
    }

    /** The chosen target, or null for "create a new integration". */
    public function targetIntegration(): ?Integration
    {
        $id = $this->input('integration_id');

        return filled($id) ? Integration::find($id) : null;
    }
}
