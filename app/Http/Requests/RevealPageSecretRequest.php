<?php

namespace App\Http\Requests;

use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One attempt at revealing one protected value, from any of the THREE surfaces
 * that render a page — which is why `authorize()` branches on which route is
 * being served (same shape as `SaveDocumentationRequest`). Each of them
 * authorizes a different way, and none of the three is the other's rule:
 *
 * - the **magic link** has no user at all: the TOKEN is the authorization, and
 *   the controller checks it against the caderno the page belongs to. Returning
 *   true here would be a hole if it did not — it does, exactly as
 *   `PublicDocumentationController::file()` does for embedded media.
 * - the **knowledge base** (`/docs`) authorizes by PUBLICATION. Deliberately
 *   not by `NotebookPolicy::view`, which answers about the caderno as an object
 *   of editing and says no to a `Reader` — the very tier this surface exists
 *   for. Using it here would have made every lock on `/docs` refuse the
 *   audience it was published to.
 * - the **editor** is the caderno's own policy, unchanged.
 *
 * What none of them decides is whether the VALUE comes back: that is
 * `RevealPageSecret`, identically on all three — an admin outright, everybody
 * else with the caderno's secret code, five attempts per reader per twelve
 * hours.
 */
class RevealPageSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        if (! $notebook instanceof Notebook) {
            return true;
        }

        if ($this->routeIs('docs.*')) {
            return $notebook->isPublished();
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
