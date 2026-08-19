<?php

namespace App\Http\Requests;

use App\Enums\SubmissionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording what the committee decided.
 *
 * `status` is restricted to the three outcomes: this endpoint is the
 * deliberation, not a general status edit (that stays on the header's inline
 * editor). Conditions arrive as a list of strings and are stored as
 * `{text, done}` so they can be ticked off later.
 */
class RecordSubmissionDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(SubmissionStatus::class)->only([
                SubmissionStatus::Approved,
                SubmissionStatus::ApprovedWithConditions,
                SubmissionStatus::Rejected,
            ])],
            'decision'     => ['required', 'string'],
            'conditions'   => ['array'],
            'conditions.*' => ['string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.Illuminate\Validation\Rules\Enum' => 'A deliberação só registra aprovada, aprovada com ressalvas ou reprovada.',
            'decision.required'                       => 'Registre o que o comitê decidiu.',
        ];
    }

    /**
     * The form sends one condition per LINE of a textarea, which is far less
     * fiddly than a repeater for something typed during a meeting. The API
     * still accepts a `conditions` array, so both roads meet here.
     */
    protected function prepareForValidation(): void
    {
        $conditions = $this->has('conditions_text')
            ? preg_split('/\R/', (string) $this->input('conditions_text'))
            : (array) $this->input('conditions', []);

        $this->merge([
            'conditions' => array_values(array_filter(
                array_map(fn ($condition) => trim((string) $condition), $conditions),
                fn (string $condition) => $condition !== '',
            )),
        ]);
    }
}
