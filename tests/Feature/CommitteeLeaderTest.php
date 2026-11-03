<?php

use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
| Recording the committee's deliberation is an admin's or the committee
| leader's (SubmissionPolicy::decide) — never the author's for being the
| author. Decided 2026-11-03.
*/

function committeeAuthor(): User
{
    return User::factory()->editor(AccessModule::Committee, AccessModule::Documentation)->create(['role' => UserRole::Member]);
}

function committeeLeader(): User
{
    $leader = User::factory()->editor(AccessModule::Committee, AccessModule::Documentation)->create(['role' => UserRole::Member]);
    $leader->forceFill(['committee_leader' => true])->save();

    return $leader;
}

function proposalBy(User $author, array $attributes = []): Submission
{
    return Submission::factory()->withSections()->create(['created_by_id' => $author->id, ...$attributes]);
}

it('refuses the author the deliberation of their own proposal', function () {
    $author = committeeAuthor();
    $submission = proposalBy($author);

    $this->actingAs($author)
        ->postJson(route('submissions.decision.store', $submission), ['status' => 'rejected', 'decision' => 'Reprovada.'])
        ->assertForbidden();

    expect($submission->fresh()->decided_at)->toBeNull();

    // …and the form is not on their screen.
    $this->actingAs($author)->get(route('submissions.show', $submission))->assertOk()->assertDontSee('Registrar deliberação');
});

it('lets the committee leader and an admin record it', function () {
    $submission = proposalBy(committeeAuthor());

    $this->actingAs(committeeLeader())->get(route('submissions.show', $submission))->assertSee('Registrar deliberação');
    $this->actingAs(committeeLeader())
        ->postJson(route('submissions.decision.store', $submission), ['status' => 'rejected', 'decision' => 'Reprovada.'])
        ->assertOk();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('submissions.decision.store', $submission), ['status' => 'rejected', 'decision' => 'Reprovada de novo.'])
        ->assertOk();

    expect($submission->fresh()->decision)->toBe('Reprovada de novo.');
});

it('does not let a leader whose committee level is None decide', function () {
    $leader = committeeLeader();
    $leader->setAccessLevel(AccessModule::Committee, AccessLevel::None);
    $leader->save();

    expect($leader->fresh()->can('decide', proposalBy(committeeAuthor())))->toBeFalse();
});

it('never takes a verdict from the header, and keeps a decided status for whoever may decide', function () {
    $author = committeeAuthor();
    $submission = proposalBy($author);

    // The bypass: approving one's own proposal from the header's status select.
    $this->actingAs($author)
        ->patchJson(route('submissions.field.update', $submission), ['status' => SubmissionStatus::Approved->value])
        ->assertStatus(422);

    // Not even an admin records a verdict there — that is "Registrar deliberação".
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->actingAs($admin)
        ->patchJson(route('submissions.field.update', $submission), ['status' => SubmissionStatus::Rejected->value])
        ->assertStatus(422);

    // A decided proposal: its author cannot reopen it, an admin can.
    $submission->update(['status' => SubmissionStatus::Rejected, 'decided_at' => now()]);

    $this->actingAs($author)
        ->patchJson(route('submissions.field.update', $submission), ['status' => SubmissionStatus::Draft->value])
        ->assertStatus(422);

    $this->actingAs($admin)
        ->patchJson(route('submissions.field.update', $submission), ['status' => SubmissionStatus::Draft->value])
        ->assertOk();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Draft);
});

it('leaves the ressalvas to whoever may decide', function () {
    $author = committeeAuthor();
    $submission = proposalBy($author, ['conditions' => [['text' => 'Homologar o dimensionamento', 'done' => false]]]);

    $this->actingAs($author)->postJson(route('submissions.conditions.toggle', [$submission, 0]))->assertForbidden();
    $this->actingAs(committeeLeader())->postJson(route('submissions.conditions.toggle', [$submission, 0]))->assertOk();

    expect($submission->fresh()->conditions[0]['done'])->toBeTrue();
});

it('lets an admin make an account the committee leader, and nobody else', function () {
    $member = committeeAuthor();
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($member)->patchJson(route('users.access.update', $member), ['committee_leader' => '1'])->assertForbidden();
    $this->actingAs($admin)->patchJson(route('users.access.update', $member), ['committee_leader' => '1'])->assertOk();

    expect($member->fresh()->committee_leader)->toBeTrue();

    // An admin decides anyway; the flag on one would mean nothing.
    $this->actingAs($admin)
        ->patchJson(route('users.access.update', User::factory()->create(['role' => UserRole::Admin->value])), ['committee_leader' => '1'])
        ->assertStatus(422);
});
