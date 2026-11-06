<?php

use App\Enums\RemoteSignInStatus;
use App\Enums\UserRole;
use App\Jobs\RunRemoteDigibeeSignIn;
use App\Models\DigibeeMonitorSession;
use App\Models\User;
use App\Support\Digibee\Monitor\RemoteSignIn;
use Database\Factories\DigibeeMonitorSessionFactory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(LazilyRefreshDatabase::class);

function digibeeSignInAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** A run whose browser is up, its bridge on port 4321. */
function digibeeWaitingSignIn(User $user): RemoteSignIn
{
    return RemoteSignIn::begin($user->id)->with(RemoteSignInStatus::Waiting, 4321);
}

function digibeeBearer(int $hours = 24): string
{
    return 'Bearer ' . DigibeeMonitorSessionFactory::jwt(now()->addHours($hours)->getTimestamp());
}

// ── the screen ──────────────────────────────────────────────────────────

it('is an admin screen, closed to every other tier', function () {
    $this->actingAs(digibeeSignInAdmin())->get(route('digibee-session.index'))
        ->assertOk()
        ->assertSee('Entrar na Digibee')
        ->assertSee('Nenhuma sessão capturada ainda.');

    foreach ([User::factory()->editor()->create(), User::factory()->create(['role' => UserRole::Member->value])] as $user) {
        $this->actingAs($user)->get(route('digibee-session.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('digibee-session.sign-in.store'))->assertForbidden();
    }
});

it('says until when the saved session is valid, and whose it is', function () {
    $admin = digibeeSignInAdmin();
    $session = DigibeeMonitorSession::factory()->create();
    $session->user()->associate($admin)->save();

    $this->actingAs($admin)->get(route('digibee-session.index'))
        ->assertSee('Válida')
        ->assertSee($session->expires_at->setTimezone(config('app.display_timezone'))->format('d/m H:i'))
        ->assertSee($admin->name);
});

it('keeps the captured headers encrypted at rest and reads only an unexpired one', function () {
    DigibeeMonitorSession::factory()->expired()->create();
    $valid = DigibeeMonitorSession::factory()->create(['apikey' => 'the-apikey']);

    expect(DB::table('digibee_monitor_sessions')->where('id', $valid->id)->value('apikey'))->not->toContain('the-apikey')
        ->and(DigibeeMonitorSession::current()->is($valid))->toBeTrue()
        ->and(DigibeeMonitorSession::current()->toMonitorSession()->headers()['apikey'])->toBe('the-apikey');
});

// ── starting, polling, driving, cancelling ──────────────────────────────

it('starts one sign-in at a time', function () {
    Queue::fake();
    $admin = digibeeSignInAdmin();

    $this->actingAs($admin)->postJson(route('digibee-session.sign-in.store'))
        ->assertOk()
        ->assertJsonPath('updatableSlots.0.id', 'digibee-sign-in-slot');

    expect(RemoteSignIn::current()->status)->toBe(RemoteSignInStatus::Starting);
    Queue::assertPushed(RunRemoteDigibeeSignIn::class, fn ($job) => $job->signInId === RemoteSignIn::current()->id);

    $this->actingAs($admin)->postJson(route('digibee-session.sign-in.store'))
        ->assertOk()
        ->assertJsonPath('type', 'warning');

    Queue::assertPushed(RunRemoteDigibeeSignIn::class, 1);
});

it('answers the poll with the panel only when the run moved on', function () {
    $admin = digibeeSignInAdmin();
    $signIn = RemoteSignIn::begin($admin->id);

    $this->actingAs($admin)
        ->getJson(route('digibee-session.sign-in.status', ['signIn' => $signIn->id, 'seen' => 'starting']))
        ->assertOk()
        ->assertJsonMissingPath('updatableSlots');

    $signIn->with(RemoteSignInStatus::Waiting, 4321);

    $this->actingAs($admin)
        ->getJson(route('digibee-session.sign-in.status', ['signIn' => $signIn->id, 'seen' => 'starting']))
        ->assertJsonPath('updatableSlots.0.id', 'digibee-sign-in-slot');
});

it('streams the server browser while the run is live', function () {
    Http::fake(['*/frame' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg'])]);
    $admin = digibeeSignInAdmin();
    $signIn = digibeeWaitingSignIn($admin);

    $this->actingAs($admin)->get(route('digibee-session.index'))
        ->assertSee('data-ak-remote-browser', false);

    $response = $this->actingAs($admin)->get(route('digibee-session.sign-in.frame', $signIn->id))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->getContent())->toBe('JPEGBYTES');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://127.0.0.1:4321/frame'
        && $request->header('x-bridge-secret') === [$signIn->secret]);

    // A tab left on an older run gets nothing from the newer one.
    $this->actingAs($admin)->get(route('digibee-session.sign-in.frame', (string) Str::uuid()))->assertNoContent();
});

it('passes typed text to the browser exactly as typed', function () {
    Http::fake(['*/input' => Http::response(['ok' => true])]);
    $admin = digibeeSignInAdmin();
    $signIn = digibeeWaitingSignIn($admin);

    $this->actingAs($admin)
        ->postJson(route('digibee-session.sign-in.input', $signIn->id), ['type' => 'type', 'text' => ' senha com espaço '])
        ->assertOk();

    // Neither trimmed nor nulled: a password's edge whitespace is part of it.
    $this->actingAs($admin)
        ->postJson(route('digibee-session.sign-in.input', $signIn->id), ['type' => 'type', 'text' => ' '])
        ->assertOk();

    Http::assertSent(fn (HttpRequest $request) => $request['text'] === ' senha com espaço ');
    Http::assertSent(fn (HttpRequest $request) => $request['text'] === ' ');
});

it('refuses input the bridge would not accept, and input to a run that ended', function () {
    Http::fake();
    $admin = digibeeSignInAdmin();
    $signIn = digibeeWaitingSignIn($admin);
    $url = route('digibee-session.sign-in.input', $signIn->id);

    $this->actingAs($admin)->postJson($url, ['type' => 'click', 'x' => 5000, 'y' => 10])->assertStatus(422);
    $this->actingAs($admin)->postJson($url, ['type' => 'press', 'key' => 'F12'])->assertStatus(422);
    $this->actingAs($admin)->postJson($url, ['type' => 'drag'])->assertStatus(422);
    Http::assertNothingSent();

    $signIn->with(RemoteSignInStatus::Captured);
    $this->actingAs($admin)->postJson($url, ['type' => 'click', 'x' => 10, 'y' => 10])->assertStatus(409);

    $member = User::factory()->editor()->create();
    $this->actingAs($member)->postJson($url, ['type' => 'click', 'x' => 10, 'y' => 10])->assertForbidden();
});

it('cancels a live run and closes its browser', function () {
    Http::fake(['*/quit' => Http::response(['ok' => true])]);
    $admin = digibeeSignInAdmin();
    $signIn = digibeeWaitingSignIn($admin);

    $this->actingAs($admin)->deleteJson(route('digibee-session.sign-in.destroy', $signIn->id))
        ->assertOk()
        ->assertJsonPath('type', 'success');

    expect(RemoteSignIn::current()->status)->toBe(RemoteSignInStatus::Cancelled);
    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://127.0.0.1:4321/quit');
});

// ── the job ─────────────────────────────────────────────────────────────

it('captures the session the browser saw and closes it', function () {
    Sleep::fake();
    Process::fake(['*' => Process::describe()->output('{"port":4321}')->iterations(50)]);
    $bearer = digibeeBearer();
    Http::fake([
        '*/state'   => Http::sequence()->push(['status' => 'waiting'])->push(['status' => 'captured']),
        '*/session' => ['authorization' => $bearer, 'apikey' => 'the-apikey'],
        '*/quit'    => ['ok' => true],
    ]);
    $admin = digibeeSignInAdmin();
    $signIn = RemoteSignIn::begin($admin->id);

    (new RunRemoteDigibeeSignIn($signIn->id))->handle();

    $saved = DigibeeMonitorSession::current();
    expect(RemoteSignIn::current()->status)->toBe(RemoteSignInStatus::Captured)
        ->and($saved->user_id)->toBe($admin->id)
        ->and($saved->toMonitorSession()->headers())->toBe(['authorization' => $bearer, 'apikey' => 'the-apikey'])
        ->and($saved->expires_at->getTimestamp())->toBe(now()->addHours(24)->getTimestamp());

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://127.0.0.1:4321/quit');

    // The bridge secret travels in the environment, never in argv (`ps` shows argv).
    Process::assertRan(fn (PendingProcess $process) => str_contains(implode(' ', $process->command), 'digibee-remote-sign-in.mjs')
        && $process->environment['DIGIBEE_BRIDGE_SECRET'] === $signIn->secret
        && ! str_contains(implode(' ', $process->command), $signIn->secret));
})->freezeTime();

it('reports a browser that never came up', function () {
    Sleep::fake();
    Process::fake(['*' => Process::describe()->errorOutput('chromium: missing libnss3')->iterations(0)]);
    $signIn = RemoteSignIn::begin(digibeeSignInAdmin()->id);

    (new RunRemoteDigibeeSignIn($signIn->id))->handle();

    expect(RemoteSignIn::current()->status)->toBe(RemoteSignInStatus::Failed)
        ->and(RemoteSignIn::current()->message)->toContain('não abriu')
        ->and(DigibeeMonitorSession::query()->count())->toBe(0);
});

it('ends the run when the browser gave up waiting', function () {
    Sleep::fake();
    Process::fake(['*' => Process::describe()->output('{"port":4321}')->iterations(50)]);
    Http::fake(['*/state' => ['status' => 'failed', 'message' => 'timeout'], '*/quit' => ['ok' => true]]);
    $signIn = RemoteSignIn::begin(digibeeSignInAdmin()->id);

    (new RunRemoteDigibeeSignIn($signIn->id))->handle();

    expect(RemoteSignIn::current()->status)->toBe(RemoteSignInStatus::Failed)
        ->and(RemoteSignIn::current()->message)->toContain('tempo');
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/quit'));
});

it('stops and closes the browser when the run is cancelled from the screen', function () {
    Sleep::fake();
    Process::fake(['*' => Process::describe()->output('{"port":4321}')->iterations(50)]);
    Http::fake([
        // The admin presses "Cancelar login" while the job is watching.
        '*/state' => function () {
            RemoteSignIn::current()->with(RemoteSignInStatus::Cancelled);

            return Http::response(['status' => 'waiting']);
        },
        '*/quit' => ['ok' => true],
    ]);
    $signIn = RemoteSignIn::begin(digibeeSignInAdmin()->id);

    (new RunRemoteDigibeeSignIn($signIn->id))->handle();

    expect(RemoteSignIn::current()->status)->toBe(RemoteSignInStatus::Cancelled)
        ->and(DigibeeMonitorSession::query()->count())->toBe(0);
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/quit'));
});

it('does nothing for a run cancelled while it was still queued', function () {
    Process::fake();
    $signIn = RemoteSignIn::begin(digibeeSignInAdmin()->id);
    $signIn->with(RemoteSignInStatus::Cancelled);

    (new RunRemoteDigibeeSignIn($signIn->id))->handle();

    Process::assertNothingRan();
});
