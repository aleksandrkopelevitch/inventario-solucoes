<?php

namespace App\Http\Requests;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Renames / changes the status of an existing Integration — the two metadata
 * that don't live in the chain (the topology itself is entirely edited from
 * the canvas: node title, protocol, new block, retargeting).
 *
 * Both rules are `sometimes` because the editor's top bar
 * (`Solutions\IntegrationMeta`) sends only the field just confirmed — the same
 * shape as the three detail pages' `Update{Person,Company,Solution}FieldRequest`.
 * They stay `required` on top of that, so a field that IS sent can never be
 * blanked: an integration with no name is unreachable in every list that
 * points at it, and `status` is a non-null column.
 */
class UpdateIntegrationMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $integration = $this->route('integration');

        return $integration instanceof Integration
            && ($this->user()?->can('update', $integration) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'   => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', new Enum(IntegrationStatus::class)],
        ];
    }
}
