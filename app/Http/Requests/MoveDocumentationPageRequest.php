<?php

namespace App\Http\Requests;

use App\Models\DocumentationPage;
use App\Services\DocumentationPageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reordering/renesting a page WITHIN its container (`…pages.move`) — not to be
 * confused with MoveDocumentationPageToContainerRequest, which re-files it
 * under another Solution or group.
 *
 * `up`/`down` walk the page's sibling list and have always been silent no-ops
 * at its ends. `in`/`out` — the two gestures that change the page's LEVEL, one
 * step at a time —
 * are validated instead of silently ignored: they're offered by the rail only
 * when they're possible, so a request for an impossible one means a stale rail
 * or a forged payload, and answering "Ordem atualizada." to it would be a lie.
 */
class MoveDocumentationPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = $this->route('solution') ?? $this->route('group');

        return $model !== null && $this->user()->can('update', $model);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'direction' => [
                'required',
                Rule::in(['up', 'down', 'in', 'out']),
                function (string $attribute, mixed $value, callable $fail): void {
                    $page = $this->route('page');

                    if (! $page instanceof DocumentationPage) {
                        return;
                    }

                    $message = $this->nestingFailure($page, (string) $value);

                    if ($message !== null) {
                        $fail($message);
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'direction.required' => 'Escolha para onde mover a página.',
            'direction.in'       => 'Movimento inválido.',
        ];
    }

    /**
     * Why this level change can't happen, in the words the person clicking
     * would need — or null when it can.
     *
     * The three `in` failures are worth distinguishing: `$fits` covers
     * everything the DEPTH CAP refuses (either the page itself has no room
     * left, or its own subpages wouldn't), and once it fits, the only reason
     * left for the service to refuse is having nothing above to nest under.
     */
    private function nestingFailure(DocumentationPage $page, string $direction): ?string
    {
        if ($direction === 'out') {
            return $page->isRoot() ? 'Esta página já está no primeiro nível.' : null;
        }

        if ($direction !== 'in') {
            return null;
        }

        $fits = $page->depth() + $page->subtreeHeight() <= DocumentationPage::MAX_DEPTH - 1;

        return match (true) {
            ! $fits && $page->subtreeHeight() > 1                        => 'As subpáginas dela passariam do último nível — promova ou mova as subpáginas primeiro.',
            ! $fits                                                      => 'Esta página já está no último nível.',
            ! app(DocumentationPageService::class)->canMove($page, 'in') => 'Não há página acima para receber esta subpágina.',
            default                                                      => null,
        };
    }
}
