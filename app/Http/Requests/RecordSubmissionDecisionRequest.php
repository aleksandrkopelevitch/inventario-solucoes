<?php

namespace App\Http\Requests;

use App\Enums\SubmissionStatus;
use App\Models\Notebook;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * An approval PUBLISHES: `PromoteApprovedSubmission` writes the approved
     * sections into the solution's caderno (creating one when it has none).
     * That is the Documentation module's write, so whoever records an approval
     * must be able to make it there too — `update` on the submission alone let
     * a Committee Editor with no say over documentation put a page into a
     * caderno, live at once if it was published. A rejection writes nothing
     * outside the submission and stays open to its author.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->approves()) {
                    return;
                }

                if (! $this->user()->can('create', Notebook::class)) {
                    $validator->errors()->add(
                        'status',
                        'Aprovar publica a submissão na documentação da solução, e sua conta não edita a documentação. Peça a quem edita a documentação para registrar a aprovação.',
                    );
                }
            },
        ];
    }

    /** Approving, on a submission that has a solution to publish INTO. */
    private function approves(): bool
    {
        return $this->route('submission')?->solution_id !== null && in_array($this->input('status'), [
            SubmissionStatus::Approved->value,
            SubmissionStatus::ApprovedWithConditions->value,
        ], true);
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
