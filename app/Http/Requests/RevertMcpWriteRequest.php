<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reverting ONE MCP write. No body to speak of: the row is the route
 * parameter; the filters ride along so the list can be answered as it was.
 */
class RevertMcpWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('revert', $this->route('mcpWrite')) ?? false;
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
