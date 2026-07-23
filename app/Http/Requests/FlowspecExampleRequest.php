<?php

namespace App\Http\Requests;

use App\Enums\FlowspecTag;
use App\Services\Flowspec\CredentialScrubber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for creating/editing a corpus example (F8). The reference
 * base is admin-curated, so the flowSpec is entered as raw JSON: it must be a
 * well-formed {meta, flowSpec} document and — like the old "promote" flow —
 * must never carry a literal credential (CredentialScrubber). The full
 * platform validator is deliberately NOT run here, so a legitimate real
 * pipeline that doesn't match every Digibee rule can still be curated in.
 */
abstract class FlowspecExampleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'tags'        => ['required', 'array', 'min:1'],
            'tags.*'      => [Rule::in(FlowspecTag::values())],
            'flow_spec'   => ['required', 'string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // A basic-rule failure (missing name, non-string JSON, bad tag)
            // already fails the request — no point decoding/scrubbing yet.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $decoded = json_decode((string) $this->input('flow_spec'), true);

            if (! is_array($decoded) || ! (array_key_exists('meta', $decoded) || array_key_exists('flowSpec', $decoded))) {
                $validator->errors()->add('flow_spec', 'O flowSpec precisa ser um JSON válido no formato {"meta": ..., "flowSpec": ...}.');

                return;
            }

            $violations = app(CredentialScrubber::class)->violations($decoded);

            if ($violations !== []) {
                $validator->errors()->add('flow_spec', 'Remova as credenciais literais antes de salvar: ' . implode(' | ', $violations));
            }
        }];
    }

    /**
     * The validated flowSpec, decoded — only meaningful after validation passes.
     *
     * @return array<string, mixed>
     */
    public function flowSpec(): array
    {
        return json_decode((string) $this->input('flow_spec'), true);
    }
}
