<?php

namespace App\Http\Requests;

use App\Enums\SpreadsheetAudience;
use App\Models\Solution;
use App\Services\SolutionSpreadsheetService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The export of the solutions spreadsheet, from either audience.
 *
 * The two routes authorize differently: the inventory one through
 * `SolutionPolicy` (here, so a refused account is told 403 before validation
 * can answer 302/422), the magic link by resolving its token in the
 * controller (a wrong token is a 404, not a 403).
 *
 * `columns` is validated against the INTERNAL column set — the widest one —
 * and narrowed to the audience by `ExportSolutionSpreadsheet`, which is the
 * place that decides what a magic link may carry.
 */
class ExportSolutionSpreadsheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('token') !== null) {
            return true;
        }

        return $this->user()?->can('viewAny', Solution::class) ?? false;
    }

    public function rules(): array
    {
        $keys = array_column(app(SolutionSpreadsheetService::class)->columns(SpreadsheetAudience::Internal), 'key');

        return [
            'format'    => ['required', Rule::in(['xlsx', 'csv'])],
            'columns'   => ['nullable', 'array'],
            'columns.*' => ['string', Rule::in($keys)],
            'ids'       => ['nullable', 'array', 'max:5000'],
            'ids.*'     => ['integer'],
        ];
    }

    /** @return list<string>|null */
    public function columns(): ?array
    {
        return $this->has('columns') ? array_values((array) $this->validated('columns')) : null;
    }

    /** @return list<int>|null */
    public function ids(): ?array
    {
        return $this->has('ids') ? array_map('intval', array_values((array) $this->validated('ids'))) : null;
    }
}
