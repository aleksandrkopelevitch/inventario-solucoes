<?php

namespace App\View\Components\Digibee;

use App\Models\DigibeeMonitorSession;
use App\Models\User;
use App\Support\Digibee\Monitor\RemoteSignIn;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The "Sessão Digibee" screen's moving part: the session the server holds and
 * the sign-in that renews it — the button, the browser streamed while a run is
 * live, and how the last run ended. One slot, swapped by every action and by
 * the status poll whenever the run changes state.
 */
class SignInPanel extends Component
{
    use Renderable;

    public const DOM_ID = 'digibee-sign-in-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $signIn = RemoteSignIn::current();

        return view('components.digibee.sign-in-panel', [
            'domId'   => self::DOM_ID,
            'capture' => DigibeeMonitorSession::latestCapture(),
            'signIn'  => $signIn,
            'starter' => $signIn?->userId ? User::query()->find($signIn->userId, ['id', 'name']) : null,
        ]);
    }
}
