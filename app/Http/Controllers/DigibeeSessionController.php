<?php

namespace App\Http\Controllers;

use App\Enums\RemoteSignInStatus;
use App\Http\Requests\CancelDigibeeSignInRequest;
use App\Http\Requests\SendDigibeeSignInInputRequest;
use App\Http\Requests\StartDigibeeSignInRequest;
use App\Jobs\RunRemoteDigibeeSignIn;
use App\Models\DigibeeMonitorSession;
use App\Support\Digibee\Monitor\RemoteBrowser;
use App\Support\Digibee\Monitor\RemoteSignIn;
use App\View\Components\Digibee\SignInPanel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Sessão Digibee": the server's Digibee session, and the sign-in that renews
 * it — a headless Chromium on the server (`RunRemoteDigibeeSignIn`) that an
 * admin drives from this screen, through the JPEG frames and the clicks and
 * keys this controller relays to the browser's loopback bridge.
 *
 * The sign-in is addressed by its id in every route, so a tab left open on an
 * old run can neither drive nor cancel the one somebody started after it.
 */
class DigibeeSessionController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', DigibeeMonitorSession::class);

        return view('digibee-session.index');
    }

    public function store(StartDigibeeSignInRequest $request): JsonResponse
    {
        $signIn = RemoteSignIn::begin($request->user()->id);

        if ($signIn === null) {
            return response()->json([
                'type'           => 'warning',
                'message'        => 'Já existe um login em andamento no servidor. Acompanhe por esta tela ou cancele-o.',
                'updatableSlots' => [SignInPanel::slot()],
            ]);
        }

        RunRemoteDigibeeSignIn::dispatch($signIn->id);

        return response()->json(['updatableSlots' => [SignInPanel::slot()]]);
    }

    /**
     * Polled by marker-poll.js with the status the screen is showing
     * (`?seen=`): the panel is rendered only when the run moved on, so a tick
     * while somebody types is one cache read.
     */
    public function status(Request $request, string $signIn): JsonResponse
    {
        $this->authorize('viewAny', DigibeeMonitorSession::class);

        $current = RemoteSignIn::current();
        $moved = $current === null
            || $current->id !== $signIn
            || $current->status->value !== $request->query('seen');

        return response()->json($moved ? ['updatableSlots' => [SignInPanel::slot()]] : []);
    }

    /** The page the server's browser shows, as a JPEG; 204 while there is none. */
    public function frame(string $signIn): Response
    {
        $this->authorize('viewAny', DigibeeMonitorSession::class);

        $bytes = $this->browserFor($signIn)?->frame();

        return $bytes === null
            ? response()->noContent()
            : response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store, private']);
    }

    public function input(SendDigibeeSignInInputRequest $request, string $signIn): JsonResponse
    {
        $browser = $this->browserFor($signIn);

        if ($browser === null) {
            return response()->json(['type' => 'warning', 'message' => 'Este login já terminou.'], 409);
        }

        $browser->input($request->validated());

        return response()->json(['ok' => true]);
    }

    public function destroy(CancelDigibeeSignInRequest $request, string $signIn): JsonResponse
    {
        $current = RemoteSignIn::current();

        if ($current?->id === $signIn && $current->status->active()) {
            $current->browser()?->quit();
            $current->with(RemoteSignInStatus::Cancelled);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => 'Login cancelado. O navegador do servidor foi fechado.',
            'updatableSlots' => [SignInPanel::slot()],
        ]);
    }

    private function browserFor(string $signIn): ?RemoteBrowser
    {
        $current = RemoteSignIn::current();

        return $current?->id === $signIn ? $current->browser() : null;
    }
}
