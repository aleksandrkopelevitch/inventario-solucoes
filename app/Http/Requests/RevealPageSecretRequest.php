<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One attempt at revealing one protected value — from the authenticated reader
 * or from a magic link, which is why `authorize()` branches on which route is
 * being served (same shape as `SaveDocumentationRequest`).
 *
 * On the public surface there is no user to authorize: the TOKEN is the
 * authorization, and it is checked in the controller against the caderno the
 * page belongs to. Returning true here would be a hole if the controller did
 * not do that — it does, exactly as `PublicDocumentationController::file()`
 * does for embedded media.
 */
class RevealPageSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        if (! $notebook instanceof Notebook) {
            return true;
        }

        return $this->user()?->can('view', $notebook) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Nullable because an admin sends none — `RevealPageSecret` is what
            // decides whether an absent code is allowed, since that answer
            // depends on WHO is asking rather than on the payload's shape.
            // `max` is generous on purpose: refusing an over-long code here
            // would answer "not a code of ours" without spending an attempt,
            // which is a free oracle on the code's length.
            'code' => ['nullable', 'string', 'max:255'],
        ];
    }
}
