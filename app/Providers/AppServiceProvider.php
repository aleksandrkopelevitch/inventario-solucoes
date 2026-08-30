<?php

namespace App\Providers;

use App\Support\Fold;
use App\Support\Gitbook\TransientHttpFailure;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
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
    }

    /**
     * Everything that makes a search box forgiving about case and accents.
     *
     * `whereFolded` / `orWhereFolded` are the ONE way this app asks "does this
     * column contain what the person typed". They fold both sides to lowercase
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
    }
}
