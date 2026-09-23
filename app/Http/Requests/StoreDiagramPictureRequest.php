<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesChainOwner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The F3 canvas posting its own rendered picture after a layout save.
 *
 * PNG only, and deliberately not the app's shared image rule: this is not a
 * user-chosen upload but a file the canvas produced itself
 * (`captureDiagramCanvas()`, long side 1600px), so accepting jpg/webp/svg here
 * would only widen what a hand-rolled request could put in the collection.
 *
 * Shared by BOTH canvases, like the nine chain requests beside it: the
 * submission's AS IS / TO BE is the same canvas capturing itself, so the
 * payload is the same file under the same rule and only the owner differs.
 * Its twin used to validate this inline, which is how a second idea of what
 * may enter a diagram collection starts — the reasoning above lives in one
 * place, and `AuthorizesChainOwner` resolves the owner by TYPE (see that
 * trait for why a name-based lookup fails open here).
 */
class StoreDiagramPictureRequest extends FormRequest
{
    use AuthorizesChainOwner;

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
