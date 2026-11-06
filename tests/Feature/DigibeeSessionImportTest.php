<?php

use App\Enums\UserRole;
use App\Models\DigibeeMonitorSession;
use App\Models\User;
use App\Support\Digibee\Monitor\SessionBookmarklet;
use Database\Factories\DigibeeMonitorSessionFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['services.digibee.monitor.endpoint' => 'https://core.example.test']);
    Http::preventStrayRequests();
});

function digibeeImportAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** What the bookmarklet posts: a bare JWT (no "Bearer") and the app's apikey. */
function digibeeImportPayload(int $hours = 20, ?string $realm = null): array
{
    return ['token' => DigibeeMonitorSessionFactory::jwt(now()->addHours($hours)->getTimestamp(), $realm), 'apikey' => 'public-apikey'];
}

// ── the screens ─────────────────────────────────────────────────────────

it('is an admin screen, closed to every other tier', function () {
    $this->actingAs(digibeeImportAdmin())->get(route('digibee-session.index'))
        ->assertOk()
        ->assertSee('Enviar sessão ao iSol')
        ->assertSee('Nenhuma sessão guardada ainda.');

    foreach ([User::factory()->editor()->create(), User::factory()->create(['role' => UserRole::Member->value])] as $user) {
        $this->actingAs($user)->get(route('digibee-session.index'))->assertForbidden();
        $this->actingAs($user)->get(route('digibee-session.import'))->assertForbidden();
        $this->actingAs($user)->postJson(route('digibee-session.store'), digibeeImportPayload())->assertForbidden();
    }

    expect(DigibeeMonitorSession::query()->count())->toBe(0);
});

it('offers the bookmarklet as a draggable link', function () {
    $response = $this->actingAs(digibeeImportAdmin())->get(route('digibee-session.index'));

    $response->assertSee('href="javascript:', false)->assertSee('draggable="true"', false);
});

it('opens the import window ready to hear from the Digibee origin only', function () {
    $this->actingAs(digibeeImportAdmin())->get(route('digibee-session.import'))
        ->assertOk()
        ->assertSee('data-ak-digibee-import', false)
        ->assertSee(e(json_encode(['storeUrl' => route('digibee-session.store'), 'digibeeOrigin' => 'https://www.godigibee.io'])), false);
});

it('says until when the saved session is valid, and who sent it', function () {
    $admin = digibeeImportAdmin();
    $session = DigibeeMonitorSession::factory()->create();
    $session->user()->associate($admin)->save();

    $this->actingAs($admin)->get(route('digibee-session.index'))
        ->assertSee('Válida')
        ->assertSee($session->expires_at->setTimezone(config('app.display_timezone'))->format('d/m H:i'))
        ->assertSee($admin->name);
});

// ── importing ───────────────────────────────────────────────────────────

it('keeps a session that reads the Monitor, encrypted, with who sent it', function () {
    Http::fake(['core.example.test/monitor/api/v1/executions*' => Http::response(['data' => [], 'total' => 0])]);
    $admin = digibeeImportAdmin();
    $payload = digibeeImportPayload();

    $this->actingAs($admin)->postJson(route('digibee-session.store'), $payload)
        ->assertOk()
        ->assertJsonPath('type', 'success')
        ->assertJsonPath('updatableSlots.0.id', 'digibee-session-card');

    $saved = DigibeeMonitorSession::current();
    expect($saved->user_id)->toBe($admin->id)
        ->and($saved->toMonitorSession()->headers())->toBe(['authorization' => 'Bearer ' . $payload['token'], 'apikey' => 'public-apikey'])
        ->and(DB::table('digibee_monitor_sessions')->value('authorization'))->not->toContain($payload['token']);

    // The proof was a production read with exactly those headers.
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'environment=prod')
        && $request->header('authorization') === ['Bearer ' . $payload['token']]
        && $request->header('apikey') === ['public-apikey']);
})->freezeTime();

it('refuses a session from another realm without calling Digibee', function () {
    $this->actingAs(digibeeImportAdmin())->postJson(route('digibee-session.store'), digibeeImportPayload(realm: 'outro-realm'))
        ->assertStatus(422)
        ->assertJsonPath('type', 'warning');

    Http::assertNothingSent();
    expect(DigibeeMonitorSession::query()->count())->toBe(0);
});

it('refuses a session that has expired or is about to', function () {
    $this->actingAs(digibeeImportAdmin())->postJson(route('digibee-session.store'), digibeeImportPayload(hours: 0))
        ->assertStatus(422);

    Http::assertNothingSent();
    expect(DigibeeMonitorSession::query()->count())->toBe(0);
});

it('refuses a session the Monitor will not answer, and keeps the one already held', function () {
    Http::fake(['core.example.test/*' => Http::response(['message' => 'INSUFFICIENT_PERMISSIONS'], 403)]);
    $held = DigibeeMonitorSession::factory()->create();

    $response = $this->actingAs(digibeeImportAdmin())->postJson(route('digibee-session.store'), digibeeImportPayload())
        ->assertStatus(422);

    expect($response->json('message'))->toContain('recusou')
        ->and(DigibeeMonitorSession::current()->is($held))->toBeTrue();
});

it('refuses something that is not a token', function () {
    $this->actingAs(digibeeImportAdmin())->postJson(route('digibee-session.store'), ['token' => 'not a jwt', 'apikey' => 'k'])
        ->assertStatus(422);

    Http::assertNothingSent();
});

// ── the bookmarklet ─────────────────────────────────────────────────────

it('builds a bookmarklet that only talks to the two origins it names', function () {
    $href = SessionBookmarklet::href('https://isol.example.test/digibee-session/import');

    expect($href)->toStartWith('javascript:')
        // A `javascript:` URL decodes `%` and cuts at `#`.
        ->and($href)->not->toContain('%')->not->toContain('#')->not->toContain("\n")
        ->and($href)->toContain('const D="https://www.godigibee.io"')
        ->and($href)->toContain('O="https://isol.example.test"')
        ->and($href)->toContain('postMessage({type:\'isol-digibee-session\',token:t,apikey:k},O)');
});
