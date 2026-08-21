<?php

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Enums\ContextExtractionState;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\CatiExample;
use App\Models\CatiGuideline;
use App\Models\Person;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Models\SubmissionMessage;
use App\Models\SubmissionSection;
use App\Models\SubmissionSource;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('creates the eleven section rows once, idempotently', function () {
    $submission = Submission::factory()->create();

    $submission->ensureSections();
    $submission->ensureSections();

    // pluck() on an Eloquent relation applies the model's casts, so these come
    // back as enum instances, not strings.
    expect($submission->sections()->count())->toBe(count(SubmissionSectionKey::cases()))
        ->and($submission->sections()->pluck('key')->map->value->sort()->values()->all())
        ->toEqual(collect(SubmissionSectionKey::cases())->map->value->sort()->values()->all());
});

it('back-fills a section key added to the enum after the submission existed', function () {
    $submission = Submission::factory()->withSections()->create();
    $submission->sections()->where('key', SubmissionSectionKey::Standards->value)->delete();

    // section() must produce the row rather than fail, which is what makes a
    // new enum case appear on old submissions with no data migration.
    $section = $submission->section(SubmissionSectionKey::Standards);

    expect($section->exists)->toBeTrue()
        ->and($section->key)->toBe(SubmissionSectionKey::Standards)
        ->and($section->state)->toBe(SubmissionSectionState::Empty)
        ->and($submission->sections()->count())->toBe(count(SubmissionSectionKey::cases()));
});

it('refuses two rows for the same section of one submission', function () {
    $submission = Submission::factory()->withSections()->create();

    expect(fn () => SubmissionSection::factory()->create([
        'submission_id' => $submission->id,
        'key'           => SubmissionSectionKey::Summary,
    ]))->toThrow(QueryException::class);
});

it('keeps ticket order separate from document order', function () {
    // The deck narrative starts with the summary and then sets the scene; the
    // Leo Resolve form jumps straight from the summary to the architecture.
    // One list, two orders — collapsing them would silently reorder the ticket.
    $ticket = array_map(fn (SubmissionSectionKey $k) => $k->value, SubmissionSectionKey::ticketOrdered());

    expect($ticket)->toBe([
        'summary', 'architecture', 'benefits_risks', 'legacy_impact', 'standards', 'plan_costs', 'alternatives',
    ])->and(SubmissionSectionKey::cases()[1]->value)->toBe('current_state');
});

it('marks six sections mandatory and four as deck-only', function () {
    expect(SubmissionSectionKey::mandatoryCases())->toHaveCount(6)
        ->and(SubmissionSectionKey::Alternatives->mandatory())->toBeFalse()
        ->and(SubmissionSectionKey::Alternatives->ticketHeading())->not->toBeNull()
        ->and(collect(SubmissionSectionKey::cases())->filter->deckOnly()->map->value->values()->all())
        ->toEqual(['current_state', 'objectives', 'domains_data', 'operating_model']);
});

it('gives every section a label and a seed question', function () {
    foreach (SubmissionSectionKey::cases() as $key) {
        expect($key->label())->not->toBeEmpty()
            ->and($key->question())->not->toBeEmpty();
    }
});

it('hyphenates the status token used by tailwind arbitrary variants', function () {
    // `group-data-[status=in_review]` never matches: Tailwind turns `_` into a
    // space inside an arbitrary variant's value. slug() is what the markup
    // must emit.
    expect(SubmissionStatus::InReview->slug())->toBe('in-review')
        ->and(SubmissionStatus::ApprovedWithConditions->slug())->toBe('approved-with-conditions')
        ->and(SubmissionStatus::InReview->value)->toBe('in_review');
});

it('gives every status a label and a literal badge class', function () {
    foreach (SubmissionStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty()
            ->and($status->badgeClass())->toContain('ring-1')
            ->and($status->dotClass())->toStartWith('bg-');
    }

    expect(SubmissionStatus::Approved->isDecided())->toBeTrue()
        ->and(SubmissionStatus::Draft->isDecided())->toBeFalse();
});

it('casts the record and resolves it by slug', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create();

    $submission = Submission::factory()->create([
        'solution_id'         => $solution->id,
        'requester_person_id' => $person->id,
        'status'              => SubmissionStatus::Submitted,
        'committee_date'      => '2026-09-01',
    ]);

    expect($submission->getRouteKeyName())->toBe('slug')
        ->and($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->committee_date->toDateString())->toBe('2026-09-01')
        ->and($submission->solution->is($solution))->toBeTrue()
        ->and($submission->requester->is($person))->toBeTrue();
});

it('drops sections, sources and chats with the submission', function () {
    $submission = Submission::factory()->withSections()->create();
    SubmissionSource::factory()->create(['submission_id' => $submission->id]);
    $chat = SubmissionChat::factory()->create(['submission_id' => $submission->id]);
    $message = SubmissionMessage::factory()->create(['submission_chat_id' => $chat->id]);

    $submission->delete();

    $this->assertModelMissing($chat);
    $this->assertModelMissing($message);
    expect(SubmissionSection::count())->toBe(0)
        ->and(SubmissionSource::count())->toBe(0);
});

it('keeps a solution deletion from taking the submission with it', function () {
    // The catalog record can be retired while the committee's history stays —
    // nullOnDelete, not cascade.
    $solution = Solution::factory()->create();
    $submission = Submission::factory()->create(['solution_id' => $solution->id]);

    $solution->delete();

    expect($submission->fresh()->solution_id)->toBeNull();
});

it('reports a skipped extraction as attachable, not as text', function () {
    $withText = SubmissionSource::factory()->create();
    $skipped = SubmissionSource::factory()->skipped()->create();

    expect($withText->hasText())->toBeTrue()
        ->and($skipped->hasText())->toBeFalse()
        ->and($skipped->extraction_state)->toBe(ContextExtractionState::Skipped);
});

it('counts a chat as awaiting a reply only inside the stall window', function () {
    $this->freezeTime();

    $chat = SubmissionChat::factory()->create();
    SubmissionMessage::factory()->create(['submission_chat_id' => $chat->id, 'role' => 'user']);

    expect($chat->isAwaitingReply())->toBeTrue();

    $this->travel(SubmissionChat::REPLY_STALL_SECONDS + 1)->seconds();

    // A worker killed mid-job must not lock the composer out forever.
    expect($chat->isAwaitingReply())->toBeFalse();
});

it('stops awaiting once the assistant answered', function () {
    $chat = SubmissionChat::factory()->create();
    SubmissionMessage::factory()->create(['submission_chat_id' => $chat->id, 'role' => 'user']);
    SubmissionMessage::factory()->assistant()->create(['submission_chat_id' => $chat->id]);

    expect($chat->isAwaitingReply())->toBeFalse();
});

it('filters the catalog by status, solution and free text', function () {
    $solution = Solution::factory()->create(['name' => 'SkyMob', 'slug' => 'skymob']);
    $wanted = Submission::factory()->forSolution($solution)->status(SubmissionStatus::Submitted)->create(['name' => 'CATI SKBridge']);
    Submission::factory()->status(SubmissionStatus::Draft)->create(['name' => 'CATI Outra coisa']);

    expect(Submission::query()->filter(['status' => 'submitted'])->pluck('id')->all())->toBe([$wanted->id])
        ->and(Submission::query()->filter(['solution' => 'skymob'])->pluck('id')->all())->toBe([$wanted->id])
        ->and(Submission::query()->filter(['search' => 'SKBridge'])->pluck('id')->all())->toBe([$wanted->id])
        ->and(Submission::query()->filter(['search' => 'SkyMob'])->pluck('id')->all())->toBe([$wanted->id])
        ->and(Submission::query()->filter([])->count())->toBe(2);
});

it('lets the author and admins edit, and nobody else', function () {
    $author = User::factory()->create(['role' => UserRole::Viewer]);
    $other = User::factory()->create(['role' => UserRole::Viewer]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $submission = Submission::factory()->create(['created_by_id' => $author->id]);

    $policy = new SubmissionPolicy;

    expect($policy->update($author, $submission))->toBeTrue()
        ->and($policy->update($admin, $submission))->toBeTrue()
        ->and($policy->update($other, $submission))->toBeFalse()
        // Reading is open — the point is that it stops being a file in someone's Downloads.
        ->and($policy->view($other, $submission))->toBeTrue();
});

it('registers the sources media collection without conversions', function () {
    $submission = Submission::factory()->create();
    $submission->registerMediaCollections();

    expect(Submission::SOURCES_COLLECTION)->toBe('submission_sources')
        ->and($submission->getMedia(Submission::SOURCES_COLLECTION))->toHaveCount(0);
});

it('keeps only active corpus rows in scope and reads an example by section', function () {
    CatiGuideline::factory()->create();
    CatiGuideline::factory()->inactive()->create();
    $example = CatiExample::factory()->create();
    CatiExample::factory()->inactive()->create();

    expect(CatiGuideline::query()->active()->count())->toBe(1)
        ->and(CatiExample::query()->active()->count())->toBe(1)
        ->and($example->section(SubmissionSectionKey::Summary))->toContain('ponto único')
        ->and($example->section(SubmissionSectionKey::PlanCosts))->toBeNull();
});
