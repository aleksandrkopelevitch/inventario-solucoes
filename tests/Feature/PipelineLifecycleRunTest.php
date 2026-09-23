<?php

use App\Actions\Digibee\AssessPromotion;
use App\Actions\Flowspec\SynthesizeTriggerSpec;
use App\Enums\DigibeeTriggerKind;
use App\Enums\HealingVerdict;
use App\Enums\PipelineRunStatus;
use App\Enums\UserRole;
use App\Jobs\RunPipelineLifecycle;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Models\PipelineRun;
use App\Models\User;
use App\View\Components\Flowspec\LifecyclePanel;
use App\Services\Digibee\PipelineHealingService;
use App\Support\Digibee\Healing\HealingReport;
use App\Support\Digibee\Healing\HealingRound;
use App\Support\Digibee\PromotionReadiness;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/**
 * The app's only web surface that reaches the Digibee realm.
 *
 * Nothing here lets a request actually deploy: the queue is faked, so what is
 * asserted is who may ASK, what is refused before anything is queued, and that
 * a killed worker cannot block a message forever.
 */
function runEditor(): User
{
    return User::factory()->create(['role' => UserRole::Writer->value]);
}

function runChatFor(User $user): FlowspecChat
{
    return FlowspecChat::factory()->create(['user_id' => $user->id, 'title' => 'Integração ZFL']);
}

function runMessageIn(FlowspecChat $chat, bool $withDocument = true): FlowspecMessage
{
    return $chat->messages()->create([
        'role'      => 'assistant',
        'content'   => 'pronto',
        'flow_spec' => $withDocument ? ['meta' => [], 'flowSpec' => ['disconnected-root:a' => []]] : null,
    ]);
}

function runPayload(array $overrides = []): array
{
    return array_merge(['pipeline_name' => 'zfl-teste', 'environment' => 'test'], $overrides);
}

beforeEach(function () {
    Queue::fake();
    config()->set('services.digibee.design.deployable_environments', ['test']);
});

/*
|--------------------------------------------------------------------------
| Who may ask
|--------------------------------------------------------------------------
*/

it('lets an editor start a run on their own conversation', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertOk();

    $run = PipelineRun::sole();

    expect($run->pipeline_name)->toBe('zfl-teste')
        ->and($run->status)->toBe(PipelineRunStatus::Pending)
        ->and($run->user_id)->toBe($user->id)
        ->and($run->creates)->toBeFalse();

    Queue::assertPushed(RunPipelineLifecycle::class);
});

it('refuses a viewer who owns the conversation', function () {
    // Seeing every flowSpec in your own chat is `view`; reaching the realm is a
    // write capability. A Viewer has the first and must not have the second.
    $user = User::factory()->create(['role' => UserRole::Viewer->value]);
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses an editor on somebody else conversation', function () {
    $chat = runChatFor(runEditor());
    $message = runMessageIn($chat);

    $this->actingAs(runEditor())
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertForbidden();
});

it('404s a message that belongs to another conversation', function () {
    // `scopeBindings()`: without it, a message could be run through a chat the
    // person does own, sidestepping the policy that guards the real one.
    $user = runEditor();
    $mine = runChatFor($user);
    $theirs = runChatFor(runEditor());
    $message = runMessageIn($theirs);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$mine, $message]), runPayload())
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| What is refused before anything is queued
|--------------------------------------------------------------------------
*/

it('refuses a message that never generated a flowSpec', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat, withDocument: false);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('refuses an environment that is not open for deployment', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload(['environment' => 'prod']))
        ->assertStatus(422);

    expect($response->json('message'))->toContain('não está liberado');
    Queue::assertNothingPushed();
});

it('refuses a pipeline name that is not a slug', function () {
    // The name lands in the endpoint URL, and nothing on that platform deletes
    // a pipeline.
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload(['pipeline_name' => 'Integração ZFL']))
        ->assertStatus(422);
});

it('refuses a second run while one is still going', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user)->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())->assertOk();

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Já existe uma execução em andamento')
        ->and(PipelineRun::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| A killed worker must not block the message forever
|--------------------------------------------------------------------------
*/

it('reaps a run that went quiet and lets a new one start', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $dead = PipelineRun::create([
        'flowspec_message_id' => $message->id,
        'user_id'             => $user->id,
        'pipeline_name'       => 'zfl-teste',
        'environment'         => 'test',
        'status'              => PipelineRunStatus::Running,
    ]);

    // Older than the staleness window: the job that would have finished this
    // row no longer exists, so nothing else would ever move it.
    $dead->forceFill(['updated_at' => now()->subSeconds(PipelineRun::STALE_AFTER_SECONDS + 60)])->saveQuietly();

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertOk();

    expect($dead->refresh()->status)->toBe(PipelineRunStatus::Failed)
        ->and($dead->error)->toContain('parou de responder')
        ->and(PipelineRun::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Watching it
|--------------------------------------------------------------------------
*/

it('answers a poll without rendering the panel when nothing changed', function () {
    // The tick is every few seconds for minutes; re-rendering markup the client
    // throws away costs a query and a render each time.
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    PipelineRun::create([
        'flowspec_message_id' => $message->id, 'user_id' => $user->id,
        'pipeline_name' => 'zfl-teste', 'environment' => 'test',
        'status' => PipelineRunStatus::Running, 'rounds' => [['round' => 1]],
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('flowspec.lifecycle.status', [$chat, $message]) . '?seen=1')
        ->assertOk();

    expect($response->json('pending'))->toBeTrue()
        ->and($response->json('rounds'))->toBe(1)
        ->and($response->json('updatableSlots'))->toBeNull();
});

it('renders the panel when a round has landed since the client last looked', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    PipelineRun::create([
        'flowspec_message_id' => $message->id, 'user_id' => $user->id,
        'pipeline_name' => 'zfl-teste', 'environment' => 'test',
        'status' => PipelineRunStatus::Running, 'rounds' => [['round' => 1], ['round' => 2]],
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('flowspec.lifecycle.status', [$chat, $message]) . '?seen=1')
        ->assertOk();

    expect($response->json('updatableSlots'))->not->toBeNull()
        ->and($response->json('updatableSlots.0.id'))->toBe('flowspec-lifecycle-' . $message->id);
});

it('always renders the panel once the run has settled', function () {
    // The last swap is what removes the poll marker, so the client stops.
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    PipelineRun::create([
        'flowspec_message_id' => $message->id, 'user_id' => $user->id,
        'pipeline_name' => 'zfl-teste', 'environment' => 'test',
        'status' => PipelineRunStatus::Done, 'rounds' => [['round' => 1]],
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('flowspec.lifecycle.status', [$chat, $message]) . '?seen=1')
        ->assertOk();

    expect($response->json('pending'))->toBeFalse()
        ->and($response->json('updatableSlots'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The job: rounds have to land WHILE it runs
|--------------------------------------------------------------------------
*/

it('persists each round as it finishes, not all of them at the end', function () {
    // The whole point of the screen. A round is a real deployment, so a person
    // watching must see them happen rather than a spinner that resolves
    // minutes later — which means the loop reports through a callback and the
    // job writes on each call.
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $run = PipelineRun::create([
        'flowspec_message_id' => $message->id, 'user_id' => $user->id,
        'pipeline_name' => 'zfl-teste', 'environment' => 'test',
        'status' => PipelineRunStatus::Pending,
    ]);

    $seenWhileRunning = [];

    $healing = new class($run, $seenWhileRunning) extends PipelineHealingService
    {
        public function __construct(private PipelineRun $run, private array &$seen)
        {
            // No parent::__construct(): nothing below heal() is reached.
        }

        public function heal(
            array $document,
            string $pipelineName,
            string $environment = 'test',
            ?App\Support\Digibee\TriggerSpec $trigger = null,
            ?App\Support\Digibee\Testing\EndpointCredential $credential = null,
            bool $create = false,
            ?int $maxRounds = null,
            ?callable $onRound = null,
            ?int $deployTimeoutSeconds = null,
        ): HealingReport {
            foreach ([HealingVerdict::StillFailing, HealingVerdict::Green] as $index => $verdict) {
                $onRound(new HealingRound(round: $index + 1, verdict: $verdict, evidence: ['linha']));

                // What the database holds MID-RUN, which is what the browser
                // would be polling at that moment.
                $this->seen[] = count($this->run->fresh()->roundList());
            }

            return new HealingReport(
                pipelineName: $pipelineName, environment: $environment,
                verdict: HealingVerdict::Green, document: $document,
                endpoint: 'https://test.example.test/pipeline/r/v1/zfl-teste',
            );
        }
    };

    $assess = new class extends AssessPromotion
    {
        public function __construct() {}

        public function handle(HealingReport $evidence): PromotionReadiness
        {
            return new PromotionReadiness(
                pipelineName: $evidence->pipelineName, testedIn: $evidence->environment,
                ready: true, version: 'v1.0',
            );
        }
    };

    (new RunPipelineLifecycle($run))->handle($healing, $assess, app(SynthesizeTriggerSpec::class));

    expect($seenWhileRunning)->toBe([1, 2])   // written as each finished
        ->and($run->refresh()->status)->toBe(PipelineRunStatus::Done)
        ->and($run->verdict)->toBe(HealingVerdict::Green)
        ->and($run->readiness['ready'])->toBeTrue()
        ->and($run->readiness['version'])->toBe('v1.0')
        ->and($run->endpoint)->toContain('zfl-teste');
});

it('refuses to run a row somebody already settled', function () {
    // A second dispatch (a queue that resurrected the job after a hard worker
    // kill) must not deploy again: the verdict has been read.
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $run = PipelineRun::create([
        'flowspec_message_id' => $message->id, 'user_id' => $user->id,
        'pipeline_name' => 'zfl-teste', 'environment' => 'test',
        'status' => PipelineRunStatus::Done,
    ]);

    $healing = new class extends PipelineHealingService
    {
        public bool $called = false;

        public function __construct() {}

        public function heal(
            array $document,
            string $pipelineName,
            string $environment = 'test',
            ?App\Support\Digibee\TriggerSpec $trigger = null,
            ?App\Support\Digibee\Testing\EndpointCredential $credential = null,
            bool $create = false,
            ?int $maxRounds = null,
            ?callable $onRound = null,
            ?int $deployTimeoutSeconds = null,
        ): HealingReport {
            $this->called = true;

            return new HealingReport(pipelineName: $pipelineName, environment: $environment, verdict: HealingVerdict::Green);
        }
    };

    (new RunPipelineLifecycle($run))->handle($healing, app(AssessPromotion::class), app(SynthesizeTriggerSpec::class));

    expect($healing->called)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The name it suggests
|--------------------------------------------------------------------------
*/

it('suggests a pipeline name from the first words of the title, not the whole sentence', function () {
    // The first real run in production suggested the entire chat title slugged
    // — 79 characters. It validates, and nobody wants it as a pipeline name;
    // the tenant's own are `zfl-bloq-desbloq-cliente`, `get-token-cws`.
    $chat = runChatFor(runEditor());
    $chat->update(['title' => 'Crie uma integração Digibee da seção consultar status entrega pedido']);
    $message = runMessageIn($chat);

    $suggested = (new LifecyclePanel($message))->render()->getData()['suggestedName'];

    expect($suggested)->toBe('crie-uma-integracao-digibee');
});

it('never suggests a name the form would then refuse', function () {
    // Whole segments only: `AsciiSlug` forbids a trailing hyphen, so a cut
    // mid-word would offer a name and reject it on submit.
    $chat = runChatFor(runEditor());
    $chat->update(['title' => 'Integração   —   ZFL']);
    $message = runMessageIn($chat);

    $suggested = (new LifecyclePanel($message))->render()->getData()['suggestedName'];

    expect($suggested)->toBe('integracao-zfl')
        ->and(validator(['n' => $suggested], ['n' => [new App\Rules\AsciiSlug]])->passes())->toBeTrue();
});

it('falls back to a usable name when the conversation has no title', function () {
    $chat = runChatFor(runEditor());
    $chat->update(['title' => '']);
    $message = runMessageIn($chat);

    expect((new LifecyclePanel($message))->render()->getData()['suggestedName'])->toBe('pipeline');
});

/*
|--------------------------------------------------------------------------
| The trigger the run writes
|--------------------------------------------------------------------------
|
| Every automatic run against a pipeline the lifecycle had created died at the
| deploy — twelve times in production — with `DigibeeApiException: invalid
| trigger spec - missing type`, a message naming a field of a spec that did not
| exist at all. Nothing on the path from this form to the platform ever asked
| for a trigger, so the pipeline was born with `triggerSpec: []`.
*/

it('records the trigger the form asked for', function () {
    Queue::fake();
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload([
            'trigger_kind'  => 'scheduler',
            'trigger_cron'  => '0 0 3 * * *',
        ]))
        ->assertOk();

    $run = PipelineRun::sole();

    expect($run->trigger_kind)->toBe('scheduler')
        ->and($run->trigger_cron)->toBe('0 0 3 * * *')
        ->and($run->trigger_event)->toBeNull();
});

it('asks for the cron a scheduler needs and the name an event needs', function () {
    Queue::fake();
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    // Neither can be invented from a flowSpec, and neither fails loudly if it
    // is: a guessed cron runs at the wrong time, an invented event name
    // subscribes to a topic nobody publishes.
    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload(['trigger_kind' => 'scheduler']))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning'])
        ->assertJsonFragment(['message' => 'Um agendamento precisa do cron: ele não sai do flowSpec, e um cron chutado não falha — roda na hora errada.']);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload(['trigger_kind' => 'event']))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning'])
        ->assertJsonFragment(['message' => 'Um gatilho de evento precisa do nome do evento: um nome inventado escuta um tópico que ninguém publica, sem erro nenhum.']);

    expect(PipelineRun::count())->toBe(0);
});

it('drops a value typed against a kind that has no use for it', function () {
    Queue::fake();
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload([
            'trigger_kind' => 'rest',
            'trigger_cron' => '0 * * * * *',
        ]))
        ->assertOk();

    expect(PipelineRun::sole()->trigger_cron)->toBeNull();
});

it('leaves the pipeline own trigger alone when none is chosen', function () {
    Queue::fake();
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertOk();

    // `null` is what `PipelineHealingService::heal()` reads as "write nothing",
    // which is right for a run against a pipeline that already has a trigger.
    expect(PipelineRun::sole()->trigger_kind)->toBeNull();
});

it('synthesizes a complete spec from what the run recorded', function () {
    $spec = app(SynthesizeTriggerSpec::class)->handle(
        DigibeeTriggerKind::Scheduler,
        ['cron' => '0 0 3 * * *'],
    );

    expect($spec->usable())->toBeTrue()
        ->and($spec->toArray()['type'])->toBe('scheduler')
        ->and($spec->toArray()['cronExpression'])->toBe('0 0 3 * * *');
});

it('reports the two unguessable values as missing rather than inventing them', function () {
    $triggers = app(SynthesizeTriggerSpec::class);

    expect($triggers->handle(DigibeeTriggerKind::Scheduler)->usable())->toBeFalse()
        ->and($triggers->handle(DigibeeTriggerKind::Event)->usable())->toBeFalse()
        // A web-protocol trigger needs nothing beyond its kind, which is why it
        // is the one shape that could be deployed before any of this.
        ->and($triggers->handle(DigibeeTriggerKind::Rest)->usable())->toBeTrue();
});

it('offers the trigger on the panel', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    $this->actingAs($user);
    $html = (string) (new LifecyclePanel($message, $chat->title))->render()->with(
        (new LifecyclePanel($message, $chat->title))->data()
    )->render();

    expect($html)->toContain('trigger_kind')
        ->and($html)->toContain('trigger_cron')
        ->and($html)->toContain('trigger_event');
});

it('refuses to create a pipeline with no trigger, instead of creating an undeployable one', function () {
    Queue::fake();
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    // Without this the form could still reproduce the condition the trigger
    // work exists to remove: the run creates the pipeline (permanent — nothing
    // on this platform deletes one), `DeployPipeline` then refuses it for an
    // empty `triggerSpec`, and the realm keeps an undeployable name forever.
    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload(['creates' => true]))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning'])
        ->assertJsonFragment(['message' => 'Um pipeline novo precisa de gatilho: sem ele a Digibee recusa publicar, e o pipeline criado fica para sempre — nada aqui apaga um.']);

    expect(PipelineRun::count())->toBe(0);
});

it('still lets a run against an existing pipeline keep the trigger it already has', function () {
    Queue::fake();
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    // `required_if` is about CREATION only: an existing pipeline has a trigger
    // of its own, and null still means "leave it alone".
    $this->actingAs($user)
        ->postJson(route('flowspec.lifecycle.store', [$chat, $message]), runPayload())
        ->assertOk();

    expect(PipelineRun::sole()->trigger_kind)->toBeNull();
});

it('hides keeping the pipeline own trigger once creating is on the table', function () {
    $user = runEditor();
    $chat = runChatFor($user);
    $message = runMessageIn($chat);

    // The rule above is server-side; this is the form telling the same truth,
    // so a refusal is not the first the operator hears of it.
    $this->actingAs($user)->get(route('flowspec.show', $chat))
        ->assertSee('data-ak-trigger-keep', false)
        ->assertSee('data-ak-trigger-creates', false);
});
