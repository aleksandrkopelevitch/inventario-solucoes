<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Visual layout of the F3 graphical view — each block's position (by chain
 * node index), each arrow end's anchor (per segment), and each block's
 * markdown comment (also by node index). It's purely presentational: it
 * doesn't touch the topology (the `chain` is the source of truth), so
 * nothing here turns into participants/source/target/direction.
 */
class SaveIntegrationLayoutRequest extends FormRequest
{
    /** Possible anchors: 4 main ones + 2 on top + 2 on the bottom. */
    public const ANCHORS = ['l', 'r', 't', 'b', 'tl', 'tr', 'bl', 'br'];

    /** Fonts available in each block's contextual toolbar. */
    public const FONTS = ['sans', 'serif', 'mono'];

    public function authorize(): bool
    {
        $integration = $this->route('integration');

        return $integration instanceof Integration
            && ($this->user()?->can('update', $integration) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nodes'             => ['present', 'array'],
            'nodes.*.x'         => ['required', 'numeric'],
            'nodes.*.y'         => ['required', 'numeric'],
            'nodes.*.color'     => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'nodes.*.textColor' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'nodes.*.font'      => ['nullable', Rule::in(self::FONTS)],
            'edges'             => ['present', 'array'],
            'edges.*.from'      => ['required', Rule::in(self::ANCHORS)],
            'edges.*.to'        => ['required', Rule::in(self::ANCHORS)],
            'comments'          => ['sometimes', 'array'],
            'comments.*'        => ['nullable', 'string', 'max:4000'],
        ];
    }
}
