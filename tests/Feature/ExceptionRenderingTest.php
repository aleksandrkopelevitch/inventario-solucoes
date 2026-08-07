<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Covers the exception renderers registered in `bootstrap/app.php`. Calls the
 * handler directly rather than going through a route: Laravel skips
 * `VerifyCsrfToken` while running tests, so there's no way to provoke a real
 * token mismatch through the HTTP kernel.
 */
it('renders an expired session as an actionable PT-BR payload for AJAX, not the raw CSRF message', function () {
    $request = Request::create('/solutions', 'POST');
    $request->headers->set('Accept', 'application/json');

    $response = app(ExceptionHandler::class)
        ->render($request, new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(419);

    $payload = json_decode($response->getContent(), true);

    expect($payload['title'])->toBe('Sessão expirada')
        ->and($payload['type'])->toBe('warning')
        ->and($payload['message'])->toContain('sessão expirou')
        ->and($payload['message'])->not->toContain('CSRF');
});

it('renders a forbidden action in PT-BR instead of Laravel\'s raw English message', function () {
    // `prepareException()` converts AuthorizationException into an
    // AccessDeniedHttpException BEFORE the render callbacks, so the renderer
    // this file used to have for it never fired and every authorize() failure
    // answered `This action is unauthorized.` — which also beat the PT-BR 403
    // fallback in ajax-post.js, since that reads `errorBody.message` first.
    $request = Request::create('/solutions', 'POST');
    $request->headers->set('Accept', 'application/json');

    $response = app(ExceptionHandler::class)
        ->render($request, new AuthorizationException);

    expect($response->getStatusCode())->toBe(403);

    $payload = json_decode($response->getContent(), true);

    expect($payload['title'])->toBe('Sem permissão')
        ->and($payload['type'])->toBe('warning')
        ->and($payload['message'])->toBe('Você não tem permissão para executar esta ação.')
        ->and($payload['message'])->not->toContain('unauthorized');
});

it('serves the branded 403 page for a forbidden HTML request', function () {
    $request = Request::create('/solutions', 'GET');
    $request->headers->set('Accept', 'text/html');

    $response = app(ExceptionHandler::class)
        ->render($request, new AuthorizationException);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toContain('Sem permissão')
        ->and($response->getContent())->toContain('Voltar ao início');
});

it('leaves every other HTTP status on the previous {message, status} shape', function () {
    // Guard for the 419 special-case above: it must not start decorating
    // unrelated statuses with title/type keys that consumers don't expect.
    $request = Request::create('/solutions', 'GET');
    $request->headers->set('Accept', 'application/json');

    $response = app(ExceptionHandler::class)
        ->render($request, new HttpException(503, 'Service unavailable'));

    expect($response->getStatusCode())->toBe(503);

    $payload = json_decode($response->getContent(), true);

    expect($payload)->toBe(['message' => 'Service unavailable', 'status' => 503]);
});
