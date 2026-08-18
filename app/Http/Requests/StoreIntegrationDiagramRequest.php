<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The F3 canvas posting its own rendered picture after a layout save.
 *
 * PNG only, and deliberately not the app's shared image rule: this is not a
 * user-chosen upload but a file the canvas produced itself
 * (`captureDiagramCanvas()`, long side 1600px), so accepting jpg/webp/svg here
 * would only widen what a hand-rolled request could put in the collection.
 */
class StoreIntegrationDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $integration = $this->route('integration');

        return $integration instanceof Integration
            && ($this->user()?->can('update', $integration) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // 1600px of canvas with embedded fonts lands well under this; the
            // ceiling is a guard, not a target.
            'image' => ['required', 'image', 'mimes:png', 'max:8192'],
        ];
    }
}
