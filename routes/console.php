<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Digibee documentation
|--------------------------------------------------------------------------
|
| Mirrors docs.digibee.com and republishes it as a caderno. Both steps are
| safe to run here because both are public HTTP plus local writes — there is
| no credential involved anywhere in this path.
|
| Its sibling, `digibee:pipelines:pull`, is deliberately ABSENT: `digibeectl`
| authenticates with a credential that can create and delete deployments in
| production, and Digibee publishes no read-only alternative (their API
| product is in beta and covers metrics only). That pull belongs on a
| workstation or ops box, which publishes the derived
| `database/data/digibee_tenant_vocabulary.json` — the artifact travels, the
| credential does not. See App\Support\Digibee\DigibeectlClient.
|
| The import runs half an hour after the sync rather than being chained to
| it: they fail for unrelated reasons (HTTP vs. hundreds of database writes),
| and an import over last week's corpus is still a correct import.
|
*/
Schedule::command('digibee:docs:sync')
    ->weeklyOn(0, '03:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('digibee:docs:import')
    ->weeklyOn(0, '03:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Documentation search
|--------------------------------------------------------------------------
|
| An index expires seven days after it was written, however often it is read,
| and a cold one is built by whichever reader searches first. Daily keeps every
| index a reader can reach inside its TTL; a run with nothing to build only
| pushes expiries back. After the Sunday Digibee import, which can rewrite a
| caderno. The deploy runs it too (Envoy.blade.php).
|
*/
Schedule::command('documentation:search-warm')
    ->dailyAt('04:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground();
