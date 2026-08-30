<?php

use App\Http\Middleware\PreventJsonResponseCaching;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Override the default redirect so route('login') is never called
        $middleware->redirectGuestsTo(fn () => route('login.create'));

        $middleware->appendToGroup('web', PreventJsonResponseCaching::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Model not found => 404
        //
        // Returns the response instead of calling `abort(404)`:
        // `Handler::renderViaCallbacks()` does NOT catch what a callback
        // throws, so aborting from inside the 404 renderer threw a fresh
        // NotFoundHttpException straight out of the exception handler — with
        // `app.debug` off (i.e. in production) every HTML 404 escaped instead
        // of rendering any 404 page at all. Returning `null` still hands over
        // to Laravel's default rendering, which is what we want while
        // debugging locally.
        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Resource not found'], 404);
            }

            if (config('app.debug')) {
                return null;
            }

            return response()->view('errors.404', status: 404);
        });

        // Authorization (policy/gate) => 403 is NOT handled here — see the
        // generic HttpExceptionInterface renderer at the bottom.
        //
        // A renderer typed to `AuthorizationException` looks like the obvious
        // place, and this file had one for a long time, but it could never
        // fire: `Handler::render()` runs `prepareException()` BEFORE the render
        // callbacks, and that turns an AuthorizationException with no explicit
        // status into an `AccessDeniedHttpException`. So every `authorize()`
        // failure fell through to the generic renderer and answered with
        // Laravel's raw English `This action is unauthorized.` — and, because
        // `ajax-post.js` reads `errorBody.message ?? messages[status]`, that
        // English string also beat the PT-BR 403 fallback that module already
        // had, making it dead too. Same trap as the 419/CSRF case.

        // Authentication (not logged in) => 401 or redirect to login
        //
        // Unlike the AuthorizationException note directly above, this renderer
        // really does fire. `AuthenticationException` is absent from
        // `prepareException()`'s match, so it reaches the callbacks as itself,
        // and `renderViaCallbacks()` runs BEFORE the `AuthenticationException`
        // branch of `Handler::render()`. Don't "fix" it by folding it into the
        // generic HttpExceptionInterface renderer below — it never becomes an
        // HttpException, so it would never arrive there.
        //
        // The JSON body follows the Toast convention (`{message, title, type}`)
        // because of WHEN it is read: a session that lapsed while the page sat
        // open, and an AJAX mutation that is now the first thing to find out.
        // The answer is the only notice the person gets that their edit did not
        // land, so it has to name the cause and the way out. It used to be a
        // bare English `Unauthenticated`, which `ajax-post.js` showed verbatim.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Sua sessão expirou. Faça login novamente para continuar.',
                    'title'   => 'Sessão encerrada',
                    'type'    => 'warning',
                ], 401);
            }

            return redirect()->guest(route('login.create'));
        });

        // Validation => 422
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first(),
                    'title'   => 'Atenção',
                    'type'    => 'warning',
                ], 422);
            }
        });

        // Throttle => 429
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Too Many Requests'], 429);
            }

            return back()->with('error', 'Muitas requisições. Tente novamente em instantes.');
        });

        // Generic HTTP exceptions — and the ONLY place several non-HttpException
        // throwables can be caught.
        //
        // Laravel 13's `Handler::render()` runs `prepareException()` BEFORE the
        // render callbacks, so by the time any callback sees them, a
        // `TokenMismatchException` is already a 419 HttpException and an
        // `AuthorizationException` is already an `AccessDeniedHttpException`
        // (403). Renderers typed to those classes are therefore dead code (see
        // the note where the AuthorizationException one used to be) — this is
        // where both actually arrive.
        //
        // Both conversions carry Laravel's raw ENGLISH message (`CSRF token
        // mismatch.`, `This action is unauthorized.`), which this renderer used
        // to pass straight through to the user. `USER_FACING_MESSAGES` replaces
        // it for the statuses we have something better to say about; every
        // other status keeps the previous `{message, status}` shape untouched.
        $userFacing = [
            403 => ['title' => 'Sem permissão', 'message' => 'Você não tem permissão para executar esta ação.'],
            419 => ['title' => 'Sessão expirada', 'message' => 'Sua sessão expirou. Atualize a página e tente novamente.'],
        ];

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($userFacing) {
            $authored = $userFacing[$e->getStatusCode()] ?? null;

            if ($request->expectsJson()) {
                return response()->json(array_filter([
                    'message' => $authored['message'] ?? ($e->getMessage() ?: 'HTTP error'),
                    // Toast/Modal convention, same as the ValidationException
                    // renderer above — only set when we authored the message.
                    'title'  => $authored['title'] ?? null,
                    'type'   => $authored ? 'warning' : null,
                    'status' => $e->getStatusCode(),
                ], fn ($value) => $value !== null), $e->getStatusCode());
            }

            // An expired session is the one case where there's a page worth
            // going back to (the token rotated; the content is still there).
            // 403 falls through to Laravel's own `renderHttpException()`, which
            // picks up `resources/views/errors/403.blade.php` — a dead end
            // deserves a page that says so, not a silent bounce.
            if ($e->getStatusCode() === 419) {
                return back()->with('error', $authored['message']);
            }
        });

    })->create();
