<?php

namespace App\Http\Requests;

/** The "Alterações via MCP" list's filters, validated the same wherever they arrive. */
final class McpWriteFilterRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'filter'        => ['nullable', 'array'],
            'filter.user'   => ['nullable', 'integer', 'exists:users,id'],
            'filter.client' => ['nullable', 'string', 'max:64'],
            'filter.from'   => ['nullable', 'date_format:Y-m-d'],
            'filter.to'     => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The filters that carry a value — what the list and its slot are built from.
     *
     * @return array<string, string>
     */
    public static function filled(mixed $filters): array
    {
        return array_filter((array) $filters, fn ($value) => filled($value));
    }
}
