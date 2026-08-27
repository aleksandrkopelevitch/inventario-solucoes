<?php

namespace App\View\Components\Documentation;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Renders one `{text, match}` segment list from `DocumentationSearchService`
 * as text with the matched runs wrapped in `<mark>`.
 *
 * The HTML is assembled HERE, in PHP, and echoed by the shared
 * `components.ui.highlight` view (`{!! $html !!}`) — the same shape as
 * `x-ui.highlight`, which does the single-term version of this for the
 * catalog. Every segment goes through `e()`, so a page's own text can never
 * reach the palette as markup; only the `<mark>` wrapper this class writes is
 * ever raw.
 *
 * Assembling it in PHP is also what avoids a Blade trap that has no good
 * workaround in a template: a loop-plus-conditional emitting text with NO
 * whitespace between the segments would have to write the `endif` and
 * `endforeach` directives adjacently, and Blade matches directives with `\B@`
 * — an `@` preceded by a word character is not seen as one, so the second
 * directive survives into the output verbatim and the view dies with
 * "syntax error, unexpected end of file". Separating them with anything that
 * renders (a space, a newline) puts real whitespace inside the sentence, and
 * separating them with a Blade comment does not work either: comments are
 * stripped BEFORE statements are compiled, so the two directives end up
 * adjacent again by the time it matters.
 */
class SearchHighlight extends Component
{
    /** @param  array<int, array{text: string, match: bool}>  $segments */
    public function __construct(public array $segments = []) {}

    public function render(): View
    {
        return view('components.ui.highlight', ['html' => $this->highlighted()]);
    }

    private function highlighted(): string
    {
        return collect($this->segments)
            ->map(fn (array $segment): string => $segment['match']
                ? '<mark class="rounded-[3px] bg-lime-soft px-0.5 text-lime-ink">' . e($segment['text']) . '</mark>'
                : e($segment['text']))
            ->implode('');
    }
}
