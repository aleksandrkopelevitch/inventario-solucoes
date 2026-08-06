<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Visual layout of the F3 graphical view — each block's position (by chain
 * node index), each arrow end's anchor (per segment), each block's markdown
 * comment (also by node index), the swimlane bands drawn behind the canvas,
 * the free-floating "post-it" annotations, and the canvas's color theme
 * (`theme`, one of `THEMES`). It's purely presentational: it doesn't touch
 * the topology (the `chain` is the source of truth), so nothing here turns
 * into participants/source/target/direction.
 *
 * Swimlanes (`lanes`) are a background layout aid only — a lane never
 * references a node/index; the user visually drags blocks into a lane's
 * area the same way they drag them anywhere else on the canvas. Each lane is
 * a free rectangle (`x`/`y`/`width`/`height`, all in the same world-space
 * unit as a node's position) the user moves and resizes directly on the
 * canvas — there's no shared stack/offset to persist alongside them. Body
 * fill (`color`) and header strip (`headerColor`) are independent — a lane
 * saved before `headerColor` existed simply omits the key, and the client
 * derives its header color from `color` in that case
 * (`integration-viz.js`'s `laneHeaderColor()`), so `headerColor` is the only
 * lane field that's genuinely optional in the sense of "may be absent
 * forever", not just "missing on old data". Every other lane property
 * (`rounded`, `dashed`, `opacity`, `orientation`, `showTitle`, `fontSize`) is
 * validated as optional too — the client backfills a default for whichever
 * ones are missing on a lane saved before that property existed
 * (`integration-viz.js`'s `applyLayout()`), so the server only needs to
 * validate the ones actually present. There used to be a background
 * `pattern` (solid/diagonal/cross) — removed down to solid-only; the rule is
 * gone entirely rather than restricted to `Rule::in(['solid'])`, so an old
 * saved lane with a stale `diagonal`/`cross` value here is simply never
 * validated/re-saved with that key, and the client already ignores it on
 * read (see `applyLayout()`'s lane mapping, which no longer reads `pattern`
 * at all).
 *
 * Annotations (`notes`) are simpler still — free text (no markdown, no
 * color choice, always the post-it yellow) at a world-space `x`/`y`, same as
 * a lane never referencing a node/index. No `width`/`height`: the note has a
 * fixed width and grows in height with its own content
 * (`integration-viz.js`'s `rebuildNotes()`), so there's nothing sized to
 * validate beyond the position and the text itself.
 */
class SaveIntegrationLayoutRequest extends FormRequest
{
    /** Possible anchors: 4 main ones + 2 on top + 2 on the bottom. */
    public const ANCHORS = ['l', 'r', 't', 'b', 'tl', 'tr', 'bl', 'br'];

    /** Fonts available in each block's contextual toolbar. */
    public const FONTS = ['sans', 'serif', 'mono'];

    /** Text sizes available in each block's contextual toolbar — `sm` is today's default (13px). */
    public const FONT_SIZES = ['sm', 'md', 'lg'];

    /** A lane's long-axis direction — decides where its title strip sits and how the title reads. */
    public const LANE_ORIENTATIONS = ['horizontal', 'vertical'];

    /** Text sizes available for a lane's header label — own (smaller) scale than a block's `FONT_SIZES`. */
    public const LANE_FONT_SIZES = ['sm', 'md', 'lg'];

    /**
     * Screenshot/canvas color "look" (`integration-viz.js`'s `EXPORT_PRESETS`)
     * — CSS-only (background + edge/pill/block-border color, never font or
     * size), applied live on the canvas (not just at export time) and
     * persisted here so it's remembered per integration.
     */
    public const THEMES = ['original', 'casual', 'corporativo', 'tech'];

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
            'theme'                    => ['nullable', Rule::in(self::THEMES)],
            'nodes'                    => ['present', 'array'],
            'nodes.*.x'                => ['required', 'numeric'],
            'nodes.*.y'                => ['required', 'numeric'],
            'nodes.*.color'            => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'nodes.*.textColor'        => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'nodes.*.font'             => ['nullable', Rule::in(self::FONTS)],
            'nodes.*.fontSize'         => ['nullable', Rule::in(self::FONT_SIZES)],
            'nodes.*.dashed'           => ['nullable', 'boolean'],
            'nodes.*.imageBorderColor' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'nodes.*.logoOnly'         => ['nullable', 'boolean'],
            'edges'                    => ['present', 'array'],
            'edges.*.from'             => ['required', Rule::in(self::ANCHORS)],
            'edges.*.to'               => ['required', Rule::in(self::ANCHORS)],
            'edges.*.dashed'           => ['nullable', 'boolean'],
            'comments'                 => ['sometimes', 'array'],
            'comments.*'               => ['nullable', 'string', 'max:4000'],
            'lanes'                    => ['sometimes', 'array'],
            'lanes.*.label'            => ['required', 'string', 'max:60'],
            'lanes.*.color'            => ['required', 'regex:/^#[0-9a-f]{6}$/i'],
            'lanes.*.headerColor'      => ['nullable', 'regex:/^#[0-9a-f]{6}$/i'],
            'lanes.*.x'                => ['required', 'numeric'],
            'lanes.*.y'                => ['required', 'numeric'],
            'lanes.*.width'            => ['required', 'integer', 'min:100', 'max:6000'],
            'lanes.*.height'           => ['required', 'integer', 'min:100', 'max:6000'],
            'lanes.*.rounded'          => ['nullable', 'boolean'],
            'lanes.*.dashed'           => ['nullable', 'boolean'],
            'lanes.*.opacity'          => ['nullable', 'numeric', 'between:0.03,0.5'],
            'lanes.*.orientation'      => ['nullable', Rule::in(self::LANE_ORIENTATIONS)],
            'lanes.*.showTitle'        => ['nullable', 'boolean'],
            'lanes.*.fontSize'         => ['nullable', Rule::in(self::LANE_FONT_SIZES)],
            'notes'                    => ['sometimes', 'array'],
            'notes.*.x'                => ['required', 'numeric'],
            'notes.*.y'                => ['required', 'numeric'],
            'notes.*.text'             => ['nullable', 'string', 'max:4000'],
        ];
    }
}
