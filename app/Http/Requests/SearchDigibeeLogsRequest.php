<?php

namespace App\Http\Requests;

use App\Models\DigibeeLogReport;
use App\Support\Digibee\Monitor\LogQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A question for the Digibee logs from the "Logs Digibee" screen — the same
 * fields as the `/mcp` tools and `digibee:logs`, read into one `LogQuery`.
 * Used by both "Buscar" and "Gerar relatório".
 */
class SearchDigibeeLogsRequest extends FormRequest
{
    private ?LogQuery $logQuery = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', DigibeeLogReport::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'term'        => ['nullable', 'string', 'max:200'],
            'pipeline'    => ['nullable', 'string', 'max:200'],
            'where'       => ['required', Rule::in(LogQuery::WHERE)],
            'environment' => ['required', Rule::in(LogQuery::ENVIRONMENTS)],
            'since'       => ['nullable', 'string', 'max:40'],
            'until'       => ['nullable', 'string', 'max:40'],
        ];
    }

    /** The window is read here, so an unreadable one is a field error, not a 500. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $this->logQuery = LogQuery::make(
                    $this->validated('environment'),
                    $this->validated('since'),
                    $this->validated('until'),
                    $this->validated('pipeline'),
                    $this->validated('term'),
                    $this->validated('where'),
                );
            } catch (\InvalidArgumentException) {
                $validator->errors()->add('since', 'Não entendi o período. Use quanto tempo atrás (“6h”, “2d”) ou uma data (“03/11 14:00”), com o início antes do fim.');

                return;
            }

            if (! $this->logQuery->narrowed()) {
                $validator->errors()->add('term', 'Informe uma pipeline ou um termo: sem nenhum dos dois, a busca seria o realm inteiro.');
            }
        }];
    }

    public function logQuery(): LogQuery
    {
        return $this->logQuery;
    }
}
