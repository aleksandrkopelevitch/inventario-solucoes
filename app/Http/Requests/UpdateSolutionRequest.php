<?php

namespace App\Http\Requests;

use App\Support\Catalog\SolutionRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solution')) ?? false;
    }

    /**
     * Shared with the MCP write tools — see `SolutionRules`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SolutionRules::for($this->route('solution'));
    }
}
