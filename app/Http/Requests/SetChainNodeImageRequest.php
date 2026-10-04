<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesChainOwner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Puts a picture inside an existing action block (data-viz F3) — pasted with
 * the block selected, or picked through the toolbar's "Imagem" button. Same
 * file rules as `AddChainImageRequest` (its `IMAGE_RULES`, not a copy), which
 * creates an image block of its own instead: the two differ in where the
 * picture goes, never in what a picture may be.
 */
class SetChainNodeImageRequest extends FormRequest
{
    use AuthorizesChainOwner;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => AddChainImageRequest::IMAGE_RULES,
        ];
    }
}
