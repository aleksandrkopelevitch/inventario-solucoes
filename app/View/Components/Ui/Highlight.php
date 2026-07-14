<?php

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Destaca em `<mark>` o trecho de `$text` que casou com `$term` (busca do
 * catálogo F1). Some abaixo de 3 caracteres — mesma regra mínima da busca.
 */
class Highlight extends Component
{
    public function __construct(
        public string $text,
        public ?string $term = null,
    ) {}

    public function render(): View
    {
        return view('components.ui.highlight', ['html' => $this->highlighted()]);
    }

    private function highlighted(): string
    {
        $term = trim((string) $this->term);

        if (mb_strlen($term) < 3) {
            return e($this->text);
        }

        $position = mb_stripos($this->text, $term);

        if ($position === false) {
            return e($this->text);
        }

        $before = mb_substr($this->text, 0, $position);
        $match = mb_substr($this->text, $position, mb_strlen($term));
        $after = mb_substr($this->text, $position + mb_strlen($term));

        return e($before) . '<mark class="rounded-sm bg-yellow-300/60 px-0.5 text-inherit">' . e($match) . '</mark>' . e($after);
    }
}
