<?php

namespace App\Http\Controllers;

use App\Actions\Digibee\ImportDigibeeSession;
use App\Http\Requests\ImportDigibeeSessionRequest;
use App\Models\DigibeeMonitorSession;
use App\Support\Digibee\Monitor\SessionBookmarklet;
use App\View\Components\Digibee\SessionCard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * "Sessão Digibee": the Digibee session the server holds, and how it is
 * renewed — an admin signs in to Digibee in their OWN browser and the
 * "Enviar sessão ao iSol" bookmarklet hands the session to `import`.
 *
 * A browser on the server did this first (2026-11-06) and was removed the
 * same day: Entra's Conditional Access refused the sign-in from the droplet,
 * while the admin's own browser is a device it already trusts.
 */
class DigibeeSessionController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', DigibeeMonitorSession::class);

        return view('digibee-session.index', [
            'bookmarklet' => SessionBookmarklet::href(route('digibee-session.import')),
        ]);
    }

    /** The window the bookmarklet opens: it waits for the session and sends it to `store`. */
    public function import(): View
    {
        $this->authorize('create', DigibeeMonitorSession::class);

        return view('digibee-session.import', [
            'digibeeOrigin' => SessionBookmarklet::origin((string) config('services.digibee.monitor.app_url')),
        ]);
    }

    public function store(ImportDigibeeSessionRequest $request, ImportDigibeeSession $import): JsonResponse
    {
        $session = $import->handle($request->validated('token'), $request->validated('apikey'), $request->user());

        return response()->json([
            'type'           => 'success',
            'message'        => 'Sessão da Digibee guardada. Vale até ' . $session->expires_at->setTimezone(config('app.display_timezone'))->format('d/m H:i') . '.',
            'updatableSlots' => [SessionCard::slot()],
        ]);
    }
}
