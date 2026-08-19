<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

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
            ->acceptJson());
    }
}
