<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveEntraUser;
use App\Exceptions\EntraSignInRejected;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\EntraSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Signing in through Microsoft Entra ID (OIDC authorization-code flow).
 *
 * The behaviour being copied is GitBook's, and it has two halves that are easy
 * to conflate:
 *
 * - **Silent**: somebody already signed into Windows or holding a live browser
 *   session with Microsoft opens `/docs` and is simply let in, with no screen
 *   in between. That is `prompt=none` — Entra either answers instantly with a
 *   code or refuses with `login_required`, and it never shows the person
 *   anything either way.
 * - **Interactive**: they are not signed in, so `prompt=none` refuses, and they
 *   get a login screen carrying a "Entrar com Microsoft" button.
 *
 * **The silent attempt must be made at most once per session**, and that is the
 * single most important rule in this file. `prompt=none` fails by REDIRECTING
 * back here, so a failed attempt that leads to another attempt is an infinite
 * loop between two hosts — the browser spins, and the app looks down. The guard
 * is `SILENT_ATTEMPTED` in the session, written BEFORE the redirect leaves
 * (never after the answer comes back, which is the version that loops when the
 * answer never comes back).
 *
 * State is Socialite's, not ours: `redirect()` writes it to the session and
 * `user()` verifies it, which is the CSRF protection for the whole flow.
 * `stateless()` is therefore never called here — it would turn a login route
 * into one anybody can make somebody else's browser complete.
 */
class EntraController extends Controller
{
    /** Session key: a silent attempt has already been spent this session. */
    public const SILENT_ATTEMPTED = 'entra.silent_attempted';

    /** Session key: the attempt in flight is the silent one, so failures stay quiet. */
    private const SILENT_IN_FLIGHT = 'entra.silent_in_flight';

    /**
     * The button. Always interactive: the person asked for this, so Entra may
     * show them an account picker.
     */
    public function redirect(Request $request): SymfonyRedirect|RedirectResponse
    {
        abort_unless(EntraSso::configured(), 404);

        // A person who pressed the button has decided; a silent attempt after
        // this would be spent on a session that no longer needs one.
        $request->session()->put(self::SILENT_ATTEMPTED, true);
        $request->session()->forget(self::SILENT_IN_FLIGHT);

        return $this->driver()->redirect();
    }

    /**
     * The silent attempt, started by `AttemptEntraSilentSignOn`.
     *
     * `prompt=none` is the whole difference. It tells Entra to answer from the
     * session it already has or not at all — so the person either lands where
     * they were going, or comes back through `callback()` with an error they
     * never saw.
     */
    public function silent(Request $request): SymfonyRedirect|RedirectResponse
    {
        abort_unless(EntraSso::configured(), 404);

        $request->session()->put(self::SILENT_ATTEMPTED, true);
        $request->session()->put(self::SILENT_IN_FLIGHT, true);

        return $this->driver()->with(['prompt' => 'none'])->redirect();
    }

    /**
     * Back from Microsoft, for both flavours.
     *
     * The `error` branch comes first because it is the one a silent attempt
     * takes: there is no `code` in the query at all, so letting Socialite run
     * would fail on a missing parameter instead of on the thing that actually
     * happened.
     */
    public function callback(Request $request, ResolveEntraUser $resolve): RedirectResponse
    {
        abort_unless(EntraSso::configured(), 404);

        $wasSilent = (bool) $request->session()->pull(self::SILENT_IN_FLIGHT, false);

        if ($request->filled('error')) {
            return $this->refused($request, $wasSilent);
        }

        try {
            $user = $resolve->handle($this->driver()->user());
        } catch (EntraSignInRejected $e) {
            // A REFUSAL, not a failure: Microsoft vouched for them and this app
            // said no. It is shown even when the attempt was silent, because
            // the person is going to try the button next and would otherwise
            // get the same silence twice.
            return redirect()->route('login.create')->with('error', $e->getMessage());
        } catch (InvalidStateException) {
            // The session lost its state — a stale tab, a session that expired
            // mid-flow, or somebody else's callback. Starting again is the
            // honest answer and the only safe one.
            return redirect()->route('login.create')
                ->with('error', 'A sessão expirou antes de concluir o login. Tente novamente.');
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login.create')
                ->with('error', 'Não foi possível entrar com a Microsoft. Tente novamente ou use e-mail e senha.');
        }

        Auth::login($user, remember: true);

        // Session fixation: the id that carried the OAuth state must not be the
        // id that carries the authenticated session.
        $request->session()->regenerate();
        $request->session()->put(self::SILENT_ATTEMPTED, true);

        return redirect()->intended($this->home($user));
    }

    /**
     * Entra declined. What that MEANS depends on which attempt it was.
     *
     * After a silent one it means "not signed in with Microsoft right now",
     * which is not an error and must not read as one — they get the login
     * screen, with the button, and no red message about something they never
     * asked for. After the interactive one it is a real denial worth saying out
     * loud (a blocked account, a refused consent, a cancelled picker).
     */
    private function refused(Request $request, bool $wasSilent): RedirectResponse
    {
        if ($wasSilent) {
            return redirect()->route('login.create');
        }

        report(new \RuntimeException(sprintf(
            'Entra sign-in refused: %s (%s)',
            $request->string('error'),
            $request->string('error_description'),
        )));

        return redirect()->route('login.create')
            ->with('error', 'A Microsoft não autorizou esse login. Fale com um administrador.');
    }

    /** Where a freshly signed-in account belongs. */
    private function home(User $user): string
    {
        // A `Reader` has no inventory to land on, and `profile.show` is inside
        // it — sending them there would bounce them straight back out through
        // `EnsureInventoryAccess`, which is a redirect a person can see.
        return $user->role->canReadInventory()
            ? route('profile.show')
            : route('docs.index');
    }

    private function driver(): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('azure');

        return $driver;
    }
}
