<?php

namespace App\Support\Digibee\Monitor;

use App\Enums\UserRole;
use App\Mail\DigibeeSessionExpiredMail;
use App\Models\DigibeeMonitorSession;
use App\Models\User;
use App\Support\Digibee\Monitor\Mcp\SessionSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * The session an admin sent to the iSol server ("Sessão Digibee"), as the logs
 * screen and the `/mcp` tools read it.
 *
 * When there is none, whoever asked cannot fix it — only an admin can — so the
 * admins are told by e-mail, once per lapse: the flag is keyed on the last
 * session held, so a new session arms it again.
 */
final class ServerSession implements SessionSource
{
    private const ALARM_HOURS = 24;

    public function session(): ?MonitorSession
    {
        $current = DigibeeMonitorSession::current();

        if ($current === null) {
            $this->alarm();

            return null;
        }

        return $current->toMonitorSession();
    }

    public function renewal(): string
    {
        return 'A sessão da Digibee que o iSol usa é renovada por um administrador, em ' . route('digibee-session.index')
            . ' (entrando na Digibee e clicando no favorito "Enviar sessão ao iSol"). Os administradores já foram avisados por e-mail. '
            . 'Diga isso ao usuário e não repita a chamada até a sessão ser renovada.';
    }

    /** Whether the server can read Digibee right now, without raising the alarm. */
    public static function available(): bool
    {
        return DigibeeMonitorSession::current() !== null;
    }

    private function alarm(): void
    {
        $last = DigibeeMonitorSession::query()->latest('id')->first(['id', 'expires_at']);

        if (! Cache::add('digibee-session-alarm:' . ($last?->id ?? 'none'), true, now()->addHours(self::ALARM_HOURS))) {
            return;
        }

        $expiredAt = $last?->expires_at?->setTimezone(config('app.display_timezone'))->format('d/m H:i');

        User::query()->where('role', UserRole::Admin->value)->get(['id', 'name', 'email'])
            ->each(fn (User $admin) => Mail::to($admin)->queue(new DigibeeSessionExpiredMail($expiredAt)));
    }
}
