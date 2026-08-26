<?php

namespace App\Http\Requests;

use App\Enums\SubmissionSectionKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Applying a reply's drafts into their sections — all of them as drafts, or
 * ONE of them signed off in the same gesture.
 *
 * `confirm` is only ever sent together with `key`, and that pairing is the
 * point rather than an implementation detail. Confirming means a human read
 * the text and took responsibility for it: `RenderTicketText` ticks the
 * committee's checklist from `Confirmed` alone, and the "Rascunho da IA" badge
 * exists to mark the difference. A flag that confirmed every draft in a reply
 * at once would let somebody sign six sections without opening any of them,
 * which turns the one honest signal in this module into a formality — so the
 * button that sends this lives INSIDE each draft's own `<details>`, where it
 * cannot be reached without expanding that section's text.
 */
class ApplySubmissionDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // One section of the reply, or absent for "all of them, as drafts".
            'key'     => ['nullable', Rule::enum(SubmissionSectionKey::class)],
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    /** The single section being applied, or null for every draft in the reply. */
    public function sectionKey(): ?SubmissionSectionKey
    {
        return SubmissionSectionKey::tryFrom((string) $this->input('key'));
    }

    /**
     * Whether to sign it off as well.
     *
     * Refused without a `key`: see the class docblock — a whole-reply confirm
     * is the thing this shape exists to prevent, so it fails closed rather
     * than quietly confirming everything.
     */
    public function shouldConfirm(): bool
    {
        return $this->boolean('confirm') && $this->sectionKey() !== null;
    }
}
