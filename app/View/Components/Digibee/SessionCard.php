<?php

namespace App\View\Components\Digibee;

use App\Models\DigibeeMonitorSession;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The Digibee session the server holds: valid until when, and whose. One slot,
 * on the "Sessão Digibee" screen and on the import window, swapped when a new
 * session arrives.
 */
class SessionCard extends Component
{
    use Renderable;

    public const DOM_ID = 'digibee-session-card';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.digibee.session-card', [
            'domId'   => self::DOM_ID,
            'capture' => DigibeeMonitorSession::latestCapture(),
        ]);
    }
}
