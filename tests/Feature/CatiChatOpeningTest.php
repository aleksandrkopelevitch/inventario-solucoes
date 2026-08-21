<?php

use App\Actions\Cati\SeedSubmissionChatOpening;
use App\Enums\SubmissionSectionKey;
use App\Enums\UserRole;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Models\User;
use App\Support\Cati\DeviationRules;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function chatFor(Submission $submission): SubmissionChat
{
    return SubmissionChat::create([
        'user_id'       => User::factory()->create(['role' => UserRole::Admin])->id,
        'submission_id' => $submission->id,
    ]);
}

it('gives an empty chat a first message instead of leaving it blank', function () {
    $chat = chatFor(Submission::factory()->withSections()->create());

    app(SeedSubmissionChatOpening::class)->handle($chat);

    $messages = $chat->messages()->get();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->role)->toBe('assistant');
});

it('never seeds twice, and never on top of a real conversation', function () {
    $chat = chatFor(Submission::factory()->withSections()->create());
    $chat->messages()->create(['role' => 'user', 'content' => 'Já comecei a falar.']);

    app(SeedSubmissionChatOpening::class)->handle($chat);

    expect($chat->messages()->count())->toBe(1);
});

it('invites linking a solution when the submission has none yet', function () {
    $chat = chatFor(Submission::factory()->withSections()->create(['solution_id' => null]));

    app(SeedSubmissionChatOpening::class)->handle($chat);

    expect($chat->messages()->first()->content)
        ->toContain('ainda não está ligada a uma solução')
        // Falls back to the seed question of the first missing mandatory
        // section — the summary, on a submission with nothing filled in.
        ->toContain(SubmissionSectionKey::Summary->question());
});

it('states known facts and asks the highest-severity thing first', function () {
    $solution = Solution::factory()->create(['name' => 'SkyMob', 'cloud' => 'aws']);
    $chat = chatFor(Submission::factory()->withSections()->create(['solution_id' => $solution->id]));

    app(SeedSubmissionChatOpening::class)->handle($chat);
    $content = $chat->messages()->first()->content;

    expect($content)->toContain('SkyMob')
        // Não preciso perguntar isso — the fact is stated, not asked.
        ->toContain('Não preciso perguntar isso')
        // Off the M2C target cloud is a HIGH-severity deviation: it must win
        // over the generic "missing mandatory section" question.
        ->toContain('nuvem alvo do programa M2C');
});

it('falls back to the first missing mandatory section when nothing is high severity', function () {
    // On GCP (on target) with no other content, the only open items are the
    // low/medium completeness questions — the missing mandatory section wins.
    $solution = Solution::factory()->create(['cloud' => 'gcp']);
    $chat = chatFor(Submission::factory()->withSections()->create(['solution_id' => $solution->id]));

    app(SeedSubmissionChatOpening::class)->handle($chat);

    expect($chat->messages()->first()->content)->toContain(SubmissionSectionKey::Summary->question());
});

it('has something to say even when every mandatory section is already filled', function () {
    // Neutralizes every conformance/completeness check, not just the six
    // mandatory sections: on-target cloud (no violation), on-premise (skips
    // the sensitive-data question), low criticality (skips contingency), no
    // integrations (skips the platform question), not contracted (skips
    // vendor_missing) — and Standards names all three keyword groups the
    // mention-checks look for, with Alternatives filled too.
    $solution = Solution::factory()->create([
        'cloud' => 'gcp', 'environment' => 'on_premise', 'criticality' => 'low', 'contract_status' => 'not_contracted',
    ]);
    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    foreach (SubmissionSectionKey::mandatoryCases() as $key) {
        $submission->section($key)->update(['content' => "Texto de {$key->value}."]);
    }
    $submission->section(SubmissionSectionKey::Standards)->update([
        'content' => 'Esteira de CI/CD com code review. Logs e tracing no Cloud Logging. Autenticação via IAM e mTLS.',
    ]);
    $submission->section(SubmissionSectionKey::Alternatives)->update(['content' => 'Avaliamos construir internamente.']);

    $chat = chatFor($submission->fresh());
    app(SeedSubmissionChatOpening::class)->handle($chat);

    expect(DeviationRules::for($submission->fresh()))->toBe([])
        ->and($chat->messages()->first()->content)->toContain('quer ajustar alguma antes de resumir');
});

it('reads the submission the caller already loaded instead of fetching it again', function () {
    // The controller hands the chat a submission it has just loaded with
    // everything the opening message reads. Re-fetching it here would walk
    // sections, sources, solution, vendor and integrations one relation at a
    // time — and strict mode would not say a word, this being a single row.
    $submission = Submission::factory()->withSections()->create([
        'solution_id' => Solution::factory()->create()->id,
    ]);

    $chat = chatFor($submission);
    $chat->setRelation('submission', $submission->fresh(['solution', 'sections', 'sources']));

    DB::enableQueryLog();
    app(SeedSubmissionChatOpening::class)->handle($chat);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $query) => str_contains($query, 'from "submissions"'))->all())->toBe([])
        ->and($chat->messages()->count())->toBe(1);
});
