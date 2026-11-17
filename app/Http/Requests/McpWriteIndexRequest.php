<?php

namespace App\Http\Requests;

use App\Models\McpWrite;
use Illuminate\Foundation\Http\FormRequest;

/** Opening "Alterações via MCP" (and its filters). */
class McpWriteIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', McpWrite::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return McpWriteFilterRules::rules();
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return McpWriteFilterRules::filled($this->validated('filter', []));
    }
}
