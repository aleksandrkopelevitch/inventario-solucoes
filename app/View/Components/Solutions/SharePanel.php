<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Painel "Compartilhar" da toolbar da documentação (só na doc da própria
 * Solution, só admin). Gera/revoga o link público ("magic link") da
 * documentação — token opaco em `solutions.public_token`. Gerar e revogar
 * devolvem este slot atualizado (`docs-share-slot`) via `docs-share.js`.
 */
class SharePanel extends Component
{
    use Renderable;

    public const DOM_ID = 'docs-share-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.share-panel', [
            'domId'     => self::DOM_ID,
            'publicUrl' => $this->solution->publicDocsUrl(),
        ]);
    }
}
