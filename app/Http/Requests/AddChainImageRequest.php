<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesChainOwner;
use App\Models\Diagram;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Appends a new IMAGE block to the chain (data-viz F3) — pasting a picture
 * directly onto the canvas (Ctrl+V), the only way an `App\Enums\ChainNodeKind::Image`
 * block is ever created. Unlike `AddChainNodeRequest`, this request
 * carries the picture itself rather than a kind/Solution/label triple: the
 * controller stores it in `Diagram`'s `docs` media collection (the same
 * collection used for documentation-embedded images, served by
 * `MediaController`/`files.show`) and appends a node referencing it in one
 * request — no separate upload-then-attach round trip, so there's never a
 * moment with an uploaded file not yet attached to any node.
 */
class AddChainImageRequest extends FormRequest
{
    use AuthorizesChainOwner;

    /**
     * What a picture on the canvas may be — shared with
     * `SetChainNodeImageRequest`, which puts one INSIDE a block instead. No
     * SVG: Laravel's `image` rule refuses it unless told `allow_svg`, and an
     * uploaded SVG served back from `/files/{id}` is a document that can carry
     * script, not a picture.
     */
    public const IMAGE_RULES = ['required', 'image', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => self::IMAGE_RULES,
        ];
    }
}
