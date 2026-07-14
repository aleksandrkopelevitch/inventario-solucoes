<?php

use App\Http\Middleware\PreventJsonResponseCaching;
use Illuminate\Auth\Access\AuthorizationException;
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
        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Resource not found'], 404);
            }

            if (config('app.debug')) {
                return null;
            }

            abort(404);
        });

        // Authorization (policy/gate) => 403
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return back()->withErrors(['auth' => 'Você não tem permissão para executar esta ação.']);
        });

        // Authentication (not logged in) => 401 or redirect to login
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated'], 401);
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

        // Generic HTTP exceptions
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'HTTP error',
                    'status'  => $e->getStatusCode(),
                ], $e->getStatusCode());
            }
        });

    })->create();
