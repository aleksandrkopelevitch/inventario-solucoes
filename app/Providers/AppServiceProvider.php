<?php

namespace App\Providers;

use App\Support\Gitbook\TransientHttpFailure;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
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
}
