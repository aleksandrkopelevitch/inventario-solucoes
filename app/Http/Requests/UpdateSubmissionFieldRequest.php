<?php

namespace App\Http\Requests;

use App\Enums\SubmissionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates ONE of a submission's own fields in place on the detail header
 * (`Submissions\DetailHeader` + `<x-ui.inline-edit>`).
 *
 * Every rule is `sometimes` because the header sends only the field just
 * confirmed. `slug` is deliberately absent: renaming must not move the URL of
 * the page rendering the request.
 *
 * `name` and `solution_id` are the only fields creation still asks for, so for
 * those two no rule here may be STRICTER than `StoreSubmissionRequest`'s — a
 * value the creation panel accepted must stay re-savable inline. The rest
 * (`status`, `requester_person_id`, `committee_date`, `ticket_reference`) have
 * no panel at all any more: this request is their ONLY writer, so these rules
 * are not a mirror of anything, they are the contract.
 */
class UpdateSubmissionFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // NOT NULL in the schema — can never be emptied from here.
            'name'   => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(SubmissionStatus::class)],

            'solution_id'         => ['sometimes', 'nullable', 'integer', 'exists:solutions,id'],
            'requester_person_id' => ['sometimes', 'nullable', 'integer', 'exists:people,id'],
            'committee_date'      => ['sometimes', 'nullable', 'date'],
            'ticket_reference'    => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** The editor sends '' for an emptied field; normalise it so it never lands in the column. */
    protected function prepareForValidation(): void
    {
        foreach (['solution_id', 'requester_person_id', 'committee_date', 'ticket_reference'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filled($this->input($field)) ? $this->input($field) : null]);
            }
        }
    }
}
