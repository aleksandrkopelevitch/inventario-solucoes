<?php

namespace App\Providers;

use App\Models\McpToken;
use App\Support\Digibee\DigibeeAuthResolver;
use App\Support\Fold;
use App\Support\Gitbook\TransientHttpFailure;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\Provider as AzureProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->bootSearchFolding();
        $this->bootEntraSocialite();
        $this->bootMcpRateLimiter();

        // GitBook's REST API — the only external HTTP service this app talks
        // to (read-only, `php artisan gitbook:import`). Explicit timeouts, as
        // a macro client so every caller inherits them; `acceptJson()` is what
        // makes an error answer arrive as JSON we can quote back to the user.
        Http::macro('gitbook', fn () => Http::baseUrl((string) config('services.gitbook.url'))
            ->withToken((string) config('services.gitbook.token'))
            ->timeout((int) config('services.gitbook.timeout'))
            ->connectTimeout(5)
            ->acceptJson()
            ->retry(
                (int) config('services.gitbook.retries'),
                (int) config('services.gitbook.retry_sleep'),
                fn (Throwable $e) => TransientHttpFailure::matches($e),
                // `throw: false` is load-bearing, not a preference: `retry()`
                // otherwise throws a raw RequestException the moment a response
                // fails, which jumps straight over GitbookClient's own
                // `$response->failed()` check — and with it every operator-facing
                // message GitbookApiException authors ("the token has no access
                // to this space", "check the id against --list"). Returning the
                // failed response keeps that mapping the single way an API error
                // reaches the user. A ConnectionException still propagates:
                // there is no response to return, and the command catches it.
                throw: false,
            ));

        // The same transport for a GitBook CDN asset — deliberately WITHOUT the
        // token: an asset lives on a different host, and sending the bearer
        // there would hand our credential to a third party.
        Http::macro('gitbookAsset', fn () => Http::timeout((int) config('services.gitbook.timeout'))
            ->connectTimeout(5)
            ->retry(
                (int) config('services.gitbook.retries'),
                (int) config('services.gitbook.retry_sleep'),
                fn (Throwable $e) => TransientHttpFailure::matches($e),
                // Same reason as above: GitbookAssetImporter reads
                // `$response->failed()` to record WHICH asset stayed behind and
                // why, instead of one exception ending the page's import.
                throw: false,
            ));

        // Digibee's published documentation. A SECOND external service, which
        // makes the note above ("the only one") no longer true — and it is
        // deliberately a different macro rather than a parameter on the GitBook
        // one: docs.digibee.com is GitBook-hosted, but it is somebody else's
        // space and we reach it as an anonymous reader over plain HTTP. Sending
        // our organization's bearer token there would hand our credential to a
        // third party, exactly what `gitbookAsset` above exists to avoid.
        Http::macro('digibeeDocs', fn () => Http::baseUrl((string) config('services.digibee.docs_url'))
            ->timeout((int) config('services.digibee.docs_timeout'))
            ->connectTimeout(5)
            ->retry(
                (int) config('services.digibee.docs_retries'),
                (int) config('services.digibee.docs_retry_sleep'),
                fn (Throwable $e) => TransientHttpFailure::matches($e),
                // Same reason as the two above: SyncDigibeeDocs reads
                // `$response->failed()` to record WHICH page stayed behind, so
                // one 404 in a 581-page corpus is a line in the report rather
                // than the end of the sync.
                throw: false,
            ));

        // Digibee's PLATFORM api — a third external service, and the only one
        // this app authenticates against with a credential that can change
        // something. Deliberately a macro like the two above so every caller
        // inherits the timeouts and the auth headers, but the configuration
        // comes from DigibeeAuthResolver rather than straight from config():
        // on a workstation the session lives in digibeectl's own file, and the
        // resolver is what reads it.
        //
        // The closure delegates by IMPORTED CLASS NAME on purpose. A
        // Http::macro closure is rebound to the PendingRequest
        // (Macroable::__call does `bindTo($this, static::class)`), so `self::`
        // and `static::` inside it resolve to PendingRequest and die with
        // "Method PendingRequest::x does not exist" on a line that looks
        // perfectly correct — see AGENTS.md § HTTP Client. `use` statements are
        // resolved at compile time and are immune.
        Http::macro('digibeeDesign', fn () => app(DigibeeAuthResolver::class)->pendingRequest());
    }

    /**
     * Everything that makes a search box forgiving about case and accents.
     *
     * `whereFolded` / `orWhereFolded` are the ONE way this app asks "does this
     * column contain what the person typed" (and `whereFoldedIs` the one way it
     * asks "is it exactly this"). They fold both sides to lowercase
     * ASCII (see App\Support\Fold) rather than leaning on the database's
     * collation, because the answer differed by driver: the same
     * `where(..., 'like', "%$term%")` was case-insensitive while this app ran
     * on SQLite and case-sensitive the day it moved to Postgres, and nothing
     * in the suite could say so — the suite still runs on SQLite.
     *
     * They are macros on the QUERY builder, not the Eloquent one: Eloquent
     * forwards an unknown method to it, so one registration serves
     * `Solution::whereFolded(...)`, a `whereHas` closure, and a raw
     * `DB::table(...)` alike. And they are NOT named `whereLike` — Laravel has
     * its own, which handles case and not accents; a macro can never shadow a
     * real method, so that name would simply be dead code.
     */
    /**
     * The MCP endpoint's rate limit, keyed by the TOKEN rather than by the IP.
     *
     * The IP is the wrong key here and would be wrong in both directions: every
     * request from a given chat product arrives from that product's egress
     * range, so two unrelated tokens share a bucket, while one token used from a
     * laptop and from a phone gets two. The token is the thing being spent.
     *
     * The fallback to the IP covers requests that never reached
     * `AuthenticateMcpToken` — a wrong token, in other words — which is exactly
     * the traffic worth limiting by origin: without it, guessing tokens is
     * unthrottled.
     *
     * 120/minute is generous on purpose. A model exploring the catalog makes a
     * burst of calls to answer one question, and a limit tuned to a human's
     * clicking rate would break the ordinary case while doing nothing about the
     * one it is here for.
     */
    private function bootMcpRateLimiter(): void
    {
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by(
            ($request->attributes->get('mcp_token') instanceof McpToken)
                ? 'mcp-token:' . $request->attributes->get('mcp_token')->getKey()
                : 'mcp-ip:' . $request->ip(),
        ));
    }

    /**
     * Registers the `azure` Socialite driver (Microsoft Entra ID).
     *
     * SocialiteProviders' packages hook themselves up by LISTENING for
     * `SocialiteWasCalled` rather than by registering a driver — so without
     * this line `Socialite::driver('azure')` throws "Driver [azure] not
     * supported", at the moment somebody presses the login button and nowhere
     * earlier.
     *
     * Registered unconditionally, even when SSO is switched off: the listener
     * only teaches Socialite a name, and every route that could use it already
     * refuses through `EntraSso::configured()`. Making the registration
     * conditional would mean a `.env` change that only takes effect after a
     * cache clear, which is the wrong kind of surprise for a login.
     */
    private function bootEntraSocialite(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('azure', AzureProvider::class);
        });
    }

    private function bootSearchFolding(): void
    {
        // SQLite has no `translate()` and an ASCII-only `lower()`, so the
        // folding is registered on the connection as a real SQL function. On
        // every other driver this is a no-op.
        Event::listen(fn (ConnectionEstablished $event) => Fold::registerOn($event->connection));

        Builder::macro('whereFolded', function (string $column, ?string $term, string $boolean = 'and') {
            /** @var Builder $this */
            if ($term === null || trim($term) === '') {
                return $this;
            }

            return $this->whereRaw(
                Fold::expression($column, $this->getConnection()) . ' like ?' . Fold::ESCAPE_SQL,
                ['%' . Fold::term($term) . '%'],
                $boolean,
            );
        });

        Builder::macro('orWhereFolded', function (string $column, ?string $term) {
            /** @var Builder $this */
            return $this->whereFolded($column, $term, 'or');
        });

        // Folded EQUALITY, which is a different question from folded
        // containment and must not be spelled with the same macro. This one
        // asks "is this column THIS value, however it was capitalised" — the
        // shape identity matching needs: an e-mail typed `Admin@Leo…` on the
        // invite form is the same account as `admin@leo…` in the catalog, while
        // `whereFolded` would also match it inside `outro-admin@leo….com`.
        // Wildcards are the reason it can't just be `whereLike` without them
        // either: `_` is legal in an e-mail local part and is a LIKE wildcard,
        // so `a_b@x.com` would match `axb@x.com`.
        Builder::macro('whereFoldedIs', function (string $column, ?string $value, string $boolean = 'and') {
            /** @var Builder $this */
            if ($value === null || trim($value) === '') {
                return $this;
            }

            return $this->whereRaw(
                Fold::expression($column, $this->getConnection()) . ' = ?',
                [Fold::text($value)],
                $boolean,
            );
        });
    }
}
