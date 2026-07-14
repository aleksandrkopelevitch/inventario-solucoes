<?php

namespace App\View\Components\People;

use App\Models\Person;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Cabeçalho do detalhe da pessoa (briefing 9.5). Extraído como componente
 * próprio para que editar a pessoa a partir da sua própria página de
 * detalhe (via side panel) também atualize o que está na tela.
 *
 * Renderável como slot atualizável: `DetailHeader::slot($person)`.
 */
class DetailHeader extends Component
{
    use Renderable;

    public const DOM_ID = 'person-detail-header-slot';

    public function __construct(public Person $person) {}

    public static function slot(Person $person): array
    {
        return (new static($person))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->person->loadMissing(['company:id,name,slug', 'contacts']);

        return view('components.people.detail-header', ['domId' => self::DOM_ID]);
    }
}
