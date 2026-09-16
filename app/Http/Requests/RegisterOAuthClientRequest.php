<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * A client registering itself (RFC 7591) — the request that lets somebody paste
 * a URL and nothing else.
 *
 * **The redirect URI is the whole security of this endpoint.** Registration is
 * open by necessity: the caller has no credential yet, which is the point. What
 * stops that from being a way to mint a client that redirects an authorization
 * code to an attacker is the allowlist in `config/mcp.php` — a code can only
 * ever land somewhere Leo decided a connector lives (claude.ai, chatgpt.com,
 * gemini), plus loopback, which is where a desktop client listens.
 *
 * The error shape is RFC 7591's `{error, error_description}` and NOT this app's
 * `ValidationException` envelope (§ Error Handling), for the same reason the MCP
 * endpoint answers JSON-RPC: the reader is a connector dialog, and the only
 * field it prints is that one. `failedValidation` is overridden rather than a
 * render callback added in `bootstrap/app.php`, because this is the only request
 * in the app that speaks it.
 */
class RegisterOAuthClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_name'     => ['nullable', 'string', 'max:255'],
            'client_uri'      => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'logo_uri'        => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'redirect_uris'   => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:2048', fn (string $attribute, mixed $value, callable $fail) => $this->assertAllowedRedirect((string) $value, $fail)],
        ];
    }

    /** The name to store, since `client_name` is optional in the RFC. */
    public function clientName(): string
    {
        $name = trim((string) $this->input('client_name', ''));

        if ($name !== '') {
            return Str::limit($name, 255, '');
        }

        // The host is a better fallback than "MCP Client": it is what an admin
        // reads in the account's connection list when deciding to revoke one.
        return parse_url((string) $this->redirectUris()[0], PHP_URL_HOST) ?: 'Cliente MCP';
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        return array_values(array_map('strval', (array) $this->input('redirect_uris', [])));
    }

    private function assertAllowedRedirect(string $value, callable $fail): void
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            $fail('URI de redirecionamento inválida.');

            return;
        }

        // A loopback address with any port — how a desktop client receives the
        // code without a public host. The port is deliberately not pinned: the
        // client picks a free one at runtime.
        if ($this->isLoopback($value)) {
            return;
        }

        /** @var list<string> $allowed */
        $allowed = config('mcp.redirect_origins', []);

        foreach ($allowed as $origin) {
            if (Str::startsWith($value, rtrim($origin, '/') . '/')) {
                return;
            }
        }

        $fail('URI de redirecionamento não permitida.');
    }

    private function isLoopback(string $value): bool
    {
        $host = parse_url($value, PHP_URL_HOST);

        return parse_url($value, PHP_URL_SCHEME) === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    protected function failedValidation(Validator $validator): never
    {
        // `invalid_redirect_uri` is for a URI that was REJECTED; a payload with
        // no `redirect_uris` at all is `invalid_client_metadata`, which is why
        // this looks at the indexed keys (`redirect_uris.0`) rather than at the
        // field. The RFC names both, and a client that reads them acts on the
        // first by fixing its callback — advice that helps nobody who simply
        // sent an empty body.
        $redirectError = $validator->errors()->first('redirect_uris.*');

        throw new HttpResponseException(response()->json([
            'error'             => $redirectError !== '' ? 'invalid_redirect_uri' : 'invalid_client_metadata',
            'error_description' => $redirectError !== '' ? $redirectError : $validator->errors()->first(),
        ], 400, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
