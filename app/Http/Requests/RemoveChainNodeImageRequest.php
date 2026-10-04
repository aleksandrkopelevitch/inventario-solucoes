<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesChainOwner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Takes the picture back out of an action block — no body, just the index into
 * `chain.nodes` in the route. The block itself stays, as plain text.
 */
class RemoveChainNodeImageRequest extends FormRequest
{
    use AuthorizesChainOwner;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
