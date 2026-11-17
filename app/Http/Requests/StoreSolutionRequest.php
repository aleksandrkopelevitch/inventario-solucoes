<?php

namespace App\Http\Requests;

use App\Models\Solution;
use App\Support\Catalog\SolutionRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreSolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Solution::class) ?? false;
    }

    /**
     * Shared with the MCP write tools — see `SolutionRules`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SolutionRules::for();
    }
}
