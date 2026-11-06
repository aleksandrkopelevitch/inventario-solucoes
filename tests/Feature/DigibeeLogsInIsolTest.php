<?php

use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\DigibeeLogReportStatus;
use App\Enums\UserRole;
use App\Jobs\CollectDigibeeLogReport;
use App\Mail\DigibeeSessionExpiredMail;
use App\Mcp\Actor;
use App\Mcp\ToolRegistry;
use App\Models\DigibeeLogLookup;
use App\Models\DigibeeLogReport;
use App\Models\DigibeeMonitorSession;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['services.digibee.monitor.endpoint' => 'https://core.example.test']);
    Http::preventStrayRequests();
    Storage::fake('local');
});

/** An account that reads the Integrations module (Leitor). */
function digibeeLogsReader(): User
{
    return User::factory()->reader(AccessModule::Integrations)->create();
}

function digibeeLogsNoAccess(): User
{
    $user = User::factory()->create(['role' => UserRole::Member->value]);
    $user->setAccessLevel(AccessModule::Integrations, AccessLevel::None);
    $user->save();

    return $user;
}

/** Two executions of one pipeline: the payload search finds both, the line search one. */
function digibeeLogsFakePlatform(): void
{
    $execution = fn (string $key) => ['timestamp' => '2026-11-06T13:00:00Z', 'attributes' => [
        'pipeline.name'             => 'evt-pedido-vtex-sap', 'pipeline.version' => '2.0', 'pipeline.key' => $key,
        'execution.elapsed_time_ms' => 1500, 'payload.request' => '{"orderId":"1234567890-01","cpf":"123.456.789-09"}',
        'payload.response'          => '{"code":500}',
    ]];
    $csv = "timestamp;logLevel;logMessage\n"
        . "2026-11-06T13:00:02Z;ERROR;SAP timeout on order 1234567890-01 for CPF 123.456.789-09\n"
        . "2026-11-06T13:00:01Z;INFO;Order 1234567890-01 received\n";

    Http::fake([
        'core.example.test/monitor/api/v1/pipeline-logs*' => Http::response(['data' => [['timestamp' => '2026-11-06T13:00:02Z', 'attributes' => ['pipeline.name' => 'evt-pedido-vtex-sap', 'pipeline.version' => '2.0', 'pipeline.key' => 'k-1'], 'body' => 'SAP timeout on order 1234567890-01']], 'pagination' => ['hasNext' => false], 'total' => 1]),
        'core.example.test/monitor/api/v1/executions*'    => Http::response(['data' => [$execution('k-1'), $execution('k-2')], 'pagination' => ['hasNext' => false], 'total' => 2]),
        'core.example.test/runtime/*'                     => Http::response($csv),
    ]);
}

function digibeeLogsForm(array $overrides = []): array
{
    return $overrides + ['term' => '1234567890-01', 'where' => 'both', 'environment' => 'prod', 'since' => '1d'];
}

// ── the screen ──────────────────────────────────────────────────────────

it('is the Integrations module\'s, for readers too', function () {
    DigibeeMonitorSession::factory()->create();

    $this->actingAs(digibeeLogsReader())->get(route('digibee-logs.index'))->assertOk()->assertSee('Gerar relatório com todas');

    $none = digibeeLogsNoAccess();
    $this->actingAs($none)->get(route('digibee-logs.index'))->assertForbidden();
    $this->actingAs($none)->postJson(route('digibee-logs.search'), digibeeLogsForm())->assertForbidden();
});

it('lists the matches without opening them, redacted, and records who asked', function () {
    DigibeeMonitorSession::factory()->create();
    digibeeLogsFakePlatform();
    $reader = digibeeLogsReader();

    $response = $this->actingAs($reader)->postJson(route('digibee-logs.search'), digibeeLogsForm())->assertOk();
    $html = $response->json('updatableSlots.0.content');

    expect($html)->toContain('evt-pedido-vtex-sap 2.0')
        ->toContain('2 execuções')
        ->not->toContain('123.456.789-09');

    // Found, not opened: no export was asked for.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/runtime/'));

    expect(DigibeeLogLookup::query()->sole())->toMatchArray([
        'user_id' => $reader->id, 'source' => 'screen', 'action' => 'list', 'term' => '1234567890-01',
    ]);
});

it('refuses a search that is the whole realm, or a window it cannot read', function () {
    DigibeeMonitorSession::factory()->create();
    $reader = digibeeLogsReader();

    $this->actingAs($reader)->postJson(route('digibee-logs.search'), digibeeLogsForm(['term' => '']))->assertStatus(422);

    $response = $this->actingAs($reader)->postJson(route('digibee-logs.search'), digibeeLogsForm(['since' => 'ontem à tarde']))->assertStatus(422);
    expect($response->json('message'))->toContain('Não entendi o período');

    Http::assertNothingSent();
});

it('tells the reader the session expired and e-mails the admins once', function () {
    Mail::fake();
    DigibeeMonitorSession::factory()->expired()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $reader = digibeeLogsReader();

    $this->actingAs($reader)->postJson(route('digibee-logs.search'), digibeeLogsForm())->assertStatus(409);
    $this->actingAs($reader)->postJson(route('digibee-logs.search'), digibeeLogsForm())->assertStatus(409);

    Mail::assertQueued(DigibeeSessionExpiredMail::class, 1);
    Mail::assertQueued(DigibeeSessionExpiredMail::class, fn ($mail) => $mail->hasTo($admin->email));
    Http::assertNothingSent();
});

it('opens one execution\'s log in the side panel, redacted', function () {
    DigibeeMonitorSession::factory()->create();
    digibeeLogsFakePlatform();

    $content = $this->actingAs(digibeeLogsReader())
        ->getJson(route('digibee-logs.executions.show', ['key' => 'k-0000001', 'environment' => 'prod']))
        ->assertOk()
        ->json('content');

    expect($content)->toContain('SAP timeout on order 1234567890-01')->not->toContain('123.456.789-09');
});

it('rate-limits a burst of searches', function () {
    DigibeeMonitorSession::factory()->create();
    digibeeLogsFakePlatform();
    $reader = digibeeLogsReader();

    foreach (range(1, 20) as $ignored) {
        $this->actingAs($reader)->postJson(route('digibee-logs.search'), digibeeLogsForm())->assertOk();
    }

    $this->actingAs($reader)->postJson(route('digibee-logs.search'), digibeeLogsForm())->assertStatus(429);
})->freezeTime();

// ── reports ─────────────────────────────────────────────────────────────

it('queues one report at a time', function () {
    Queue::fake();
    DigibeeMonitorSession::factory()->create();
    $reader = digibeeLogsReader();

    $response = $this->actingAs($reader)->postJson(route('digibee-logs.reports.store'), digibeeLogsForm())->assertOk();
    $report = DigibeeLogReport::query()->sole();

    expect($response->json('redirect'))->toBe(route('digibee-logs.reports.show', $report))
        ->and($report->status)->toBe(DigibeeLogReportStatus::Pending)
        ->and($report->user_id)->toBe($reader->id);
    Queue::assertPushed(CollectDigibeeLogReport::class, fn ($job) => $job->report->is($report));

    $this->actingAs($reader)->postJson(route('digibee-logs.reports.store'), digibeeLogsForm())->assertJsonPath('type', 'warning');
    expect(DigibeeLogReport::query()->count())->toBe(1);
});

it('collects every match, stores it redacted, and shows the digest', function () {
    DigibeeMonitorSession::factory()->create();
    digibeeLogsFakePlatform();
    $reader = digibeeLogsReader();
    $report = DigibeeLogReport::factory()->create(['pipeline' => null, 'term' => '1234567890-01']);

    app()->call([new CollectDigibeeLogReport($report), 'handle']);

    $report->refresh();
    expect($report->status)->toBe(DigibeeLogReportStatus::Done)
        ->and($report->executions_found)->toBe(2)
        ->and($report->executions_opened)->toBe(2)
        ->and(Storage::disk('local')->get($report->file()))->not->toContain('123.456.789-09');

    $this->actingAs($reader)->get(route('digibee-logs.reports.show', $report))
        ->assertOk()
        ->assertSee('Erros e avisos')
        ->assertSee('evt-pedido-vtex-sap')
        ->assertDontSee('123.456.789-09');

    $this->actingAs($reader)->getJson(route('digibee-logs.reports.status', $report))
        ->assertJsonPath('updatableSlots.0.id', 'digibee-report-body');

    $this->actingAs($reader)->get(route('digibee-logs.reports.download', $report))->assertOk()->assertDownload();
});

it('fails a report whose session lapsed while it waited', function () {
    Mail::fake();
    DigibeeMonitorSession::factory()->expired()->create();
    $report = DigibeeLogReport::factory()->create();

    app()->call([new CollectDigibeeLogReport($report), 'handle']);

    expect($report->refresh()->status)->toBe(DigibeeLogReportStatus::Failed)
        ->and($report->error)->toContain('expirou');
});

it('prunes reports and their files past the retention', function () {
    $old = DigibeeLogReport::factory()->done()->create(['created_at' => now()->subDays(11)]);
    Storage::disk('local')->put($old->file(), '{}');
    $recent = DigibeeLogReport::factory()->done()->create();

    $this->artisan('model:prune', ['--model' => [DigibeeLogReport::class]])->assertSuccessful();

    $this->assertModelMissing($old);
    $this->assertModelExists($recent);
    expect(Storage::disk('local')->exists($old->file()))->toBeFalse();
});

// ── the /mcp tools ──────────────────────────────────────────────────────

function digibeeIsolMcp(string $tool, array $arguments, string $token): array
{
    $result = test()->postJson(route('mcp.handle'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer ' . $token])->assertOk()->json('result');

    return ['isError' => $result['isError'], 'text' => $result['content'][0]['text']];
}

it('offers the four tools to whoever reads Integrations, as reading the outside world', function () {
    $registry = app(ToolRegistry::class);
    $names = fn (User $user) => array_map(fn ($tool) => $tool->name(), $registry->all(Actor::fromUser($user)));

    expect($names(digibeeLogsReader()))->toContain('search_digibee_logs', 'count_digibee_executions', 'query_digibee_report', 'get_digibee_execution')
        ->and($names(digibeeLogsNoAccess()))->not->toContain('search_digibee_logs');

    $described = collect($registry->describe(Actor::fromUser(digibeeLogsReader())))->keyBy('name');
    expect($described['search_digibee_logs']['annotations']['openWorldHint'])->toBeTrue()
        ->and($described['search_solutions']['annotations']['openWorldHint'] ?? false)->toBeFalse()
        ->and($described['search_digibee_logs']['description'])->toContain('no iSol');
});

it('searches through /mcp into a report the screen can open, and queries it by its id', function () {
    DigibeeMonitorSession::factory()->create();
    digibeeLogsFakePlatform();
    ['token' => $token, 'plain' => $plain] = McpToken::mint('Claude do time');

    $answer = digibeeIsolMcp('search_digibee_logs', ['term' => '1234567890-01', 'since' => '1d'], $plain);
    $data = json_decode($answer['text'], true);

    expect($answer['isError'])->toBeFalse()
        ->and($answer['text'])->not->toContain('123.456.789-09')
        ->and($data['coverage']['executions_found'])->toBe(2);

    $report = DigibeeLogReport::query()->where('uuid', $data['report'])->sole();
    expect($report->source)->toBe('mcp')
        ->and($report->mcp_token_id)->toBe($token->id)
        ->and($report->status)->toBe(DigibeeLogReportStatus::Done)
        ->and(DigibeeLogLookup::query()->where('action', 'report')->value('mcp_token_id'))->toBe($token->id);

    Http::fake();
    $query = json_decode(digibeeIsolMcp('query_digibee_report', ['report' => $data['report'], 'level' => 'ERROR'], $plain)['text'], true);
    expect($query['matching'])->toBe(2);
    Http::assertNothingSent();
});

it('tells the model an admin renews the session, never to open a terminal', function () {
    Mail::fake();
    $plain = McpToken::mint('Claude do time')['plain'];

    $answer = digibeeIsolMcp('count_digibee_executions', ['pipeline' => 'evt-pedido-vtex-sap'], $plain);

    expect($answer['isError'])->toBeTrue()
        ->and($answer['text'])->toContain(route('digibee-session.index'))
        ->not->toContain('digibee:monitor:login');
});
