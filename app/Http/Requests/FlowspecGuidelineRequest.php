<?php

namespace App\Http\Requests;

use App\Services\Flowspec\CredentialScrubber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared validation for creating/editing a guideline document (F8). Content
 * is free-form Markdown, but still runs through CredentialScrubber — an
 * admin pasting a real example snippet into a "best practices" note could
 * carry a literal credential just as easily as the flowSpec corpus can.
 */
abstract class FlowspecGuidelineRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title'   => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:' . config('services.flowspec.max_guideline_chars')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $violations = app(CredentialScrubber::class)->violations(['content' => (string) $this->input('content')]);

            if ($violations !== []) {
                $validator->errors()->add('content', 'Remova as credenciais literais antes de salvar: ' . implode(' | ', $violations));
            }
        }];
    }
}
