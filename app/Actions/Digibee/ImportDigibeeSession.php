<?php

namespace App\Actions\Digibee;

use App\Exceptions\DigibeeApiException;
use App\Models\DigibeeMonitorSession;
use App\Models\User;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\MonitorSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a Digibee session an admin brought from their own browser — the
 * "Enviar sessão ao iSol" bookmarklet hands over the token the Digibee web app
 * holds after a normal sign-in, plus the app's public `apikey`.
 *
 * Nothing about where it came from is trusted: the token has to name this
 * realm, has to have time left, and has to actually read the Monitor before it
 * is stored. A token that fails any of the three is refused with the reason,
 * and the one already stored stays.
 */
class ImportDigibeeSession
{
    /** Less than this left is not worth storing — it would expire before anybody used it. */
    private const MIN_MINUTES_LEFT = 10;

    public function handle(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $apikey, User $user): DigibeeMonitorSession
    {
        $session = new MonitorSession('Bearer ' . $token, $apikey);

        if ($session->realm() !== config('services.digibee.monitor.realm')) {
            throw $this->refused('Esta sessão não é do realm ' . config('services.digibee.monitor.realm') . ' da Digibee.');
        }

        $expiresAt = $session->expiresAt();

        if ($expiresAt === null || $expiresAt->subMinutes(self::MIN_MINUTES_LEFT)->isPast()) {
            throw $this->refused('Esta sessão da Digibee já expirou ou está para expirar. Entre na Digibee de novo e clique no favorito.');
        }

        $this->assertReadsTheMonitor($session);

        return DigibeeMonitorSession::capture($session, $user);
    }

    /** One execution from the last minute: the cheapest call that proves LOG:READ in production. */
    private function assertReadsTheMonitor(MonitorSession $session): void
    {
        try {
            (new DigibeeMonitorClient($session))->executions('prod', now()->subMinute(), now(), limit: 1);
        } catch (DigibeeApiException) {
            throw $this->refused('A Digibee recusou esta sessão ao ler o Monitor de produção. Confira se a sua conta vê os logs e tente de novo.');
        } catch (ConnectionException) {
            throw $this->refused('Não foi possível falar com a Digibee para conferir a sessão. Tente de novo em instantes.');
        }
    }

    private function refused(string $message): ValidationException
    {
        return ValidationException::withMessages(['token' => $message]);
    }
}
