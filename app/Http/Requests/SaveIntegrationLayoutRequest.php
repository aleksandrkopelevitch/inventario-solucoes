<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Visual layout of the F3 graphical view — each block's position (by chain
 * node index), each arrow end's anchor (per segment), each block's markdown
 * comment (also by node index), and the swimlane bands drawn behind the
 * canvas. It's purely presentational: it doesn't touch the topology (the
 * `chain` is the source of truth), so nothing here turns into participants/
 * source/target/direction.
 *
 * Swimlanes (`lanes`) are a background layout aid only — a lane never
 * references a node/index; the user visually drags blocks into a lane's
 * area the same way they drag them anywhere else on the canvas. Each lane is
 * a free rectangle (`x`/`y`/`width`/`height`, all in the same world-space
 * unit as a node's position) the user moves and resizes directly on the
 * canvas — there's no shared stack/offset to persist alongside them.
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
            'nodes.*.dashed'    => ['nullable', 'boolean'],
            'edges'             => ['present', 'array'],
            'edges.*.from'      => ['required', Rule::in(self::ANCHORS)],
            'edges.*.to'        => ['required', Rule::in(self::ANCHORS)],
            'edges.*.dashed'    => ['nullable', 'boolean'],
            'comments'          => ['sometimes', 'array'],
            'comments.*'        => ['nullable', 'string', 'max:4000'],
            'lanes'             => ['sometimes', 'array'],
            'lanes.*.label'     => ['required', 'string', 'max:60'],
            'lanes.*.color'     => ['required', 'regex:/^#[0-9a-f]{6}$/i'],
            'lanes.*.x'         => ['required', 'numeric'],
            'lanes.*.y'         => ['required', 'numeric'],
            'lanes.*.width'     => ['required', 'integer', 'min:100', 'max:6000'],
            'lanes.*.height'    => ['required', 'integer', 'min:100', 'max:6000'],
        ];
    }
}
