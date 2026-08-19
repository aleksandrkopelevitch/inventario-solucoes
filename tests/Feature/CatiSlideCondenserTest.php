<?php

use App\Actions\Cati\BuildDeckSpec;
use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Enums\UserRole;
use App\Jobs\CondenseSubmissionForSlides;
use App\Models\Submission;
use App\Models\SubmissionSection;
use App\Models\User;
use App\Services\Cati\SlideCondenser;
use App\Services\Cati\SlideCondenserPromptBuilder;
use App\Support\Cati\MarkdownToBlocks;
use App\Support\Cati\SlideTextValidator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/** Returns scripted replies in order, so a correction loop can be exercised. */
function fakeCondenser(array $replies): SlideCondenser
{
    return new class($replies) extends SlideCondenser
    {
        public array $prompts = [];

        public function __construct(private array $replies)
        {
            parent::__construct(
                app(SlideCondenserPromptBuilder::class),
                app(MarkdownToBlocks::class),
                app(SlideTextValidator::class),
            );
        }

        protected function prompt(string $prompt): AgentResponse
        {
            $this->prompts[] = $prompt;
            $reply = array_shift($this->replies) ?? '';

            return new AgentResponse('fake', $reply, new Usage(10, 20), new Meta('gemini', 'gemini-3.6-flash'));
        }
    };
}

function submissionWithProse(array $sections): Submission
{
    $submission = Submission::factory()->withSections()->create(['name' => 'CATI SKBridge']);

    foreach ($sections as $key => $content) {
        $submission->section(SubmissionSectionKey::from($key))
            ->update(['content' => $content, 'state' => SubmissionSectionState::Confirmed]);
    }

    return $submission->fresh();
}

it('replaces prose with slide lines and pins them to the text they came from', function () {
    $submission = submissionWithProse([
        'summary' => 'Estabelecer um ponto único e controlado de conexão entre a Plataforma SkyMob e a operação local das Centrais, viabilizando a distribuição dos programas de produção sem expor as redes internas à Internet.',
    ]);

    $condenser = fakeCondenser(["````slide:summary\n- Conexão única e controlada entre SkyMob e as Centrais\n- Redes internas sem exposição à Internet\n````"]);

    $result = $condenser->handle($submission);
    $section = $submission->section(SubmissionSectionKey::Summary)->fresh();

    expect($result['condensed'])->toBe(['summary'])
        ->and($section->slide_content)->toContain('Conexão única e controlada')
        ->and($section->slideContentIsFresh())->toBeTrue()
        // The full text is untouched: the document still carries the argument.
        ->and($section->content)->toContain('viabilizando a distribuição');
});

it('asks again when a section comes back too long, naming what was wrong', function () {
    $submission = submissionWithProse(['summary' => 'Texto original.']);

    $tooLong = collect(range(1, 9))->map(fn ($i) => "- Linha número {$i}")->implode("\n");

    $condenser = fakeCondenser([
        "````slide:summary\n{$tooLong}\n````",
        "````slide:summary\n- Conexão única e controlada\n- Sem exposição à Internet\n````",
    ]);

    $result = $condenser->handle($submission);

    expect($result['attempts'])->toBe(2)
        ->and($result['condensed'])->toBe(['summary'])
        // The retry has to say what was wrong, or it is just a re-roll.
        ->and($condenser->prompts[1])->toContain('9 linhas')
        ->and($submission->section(SubmissionSectionKey::Summary)->fresh()->slide_content)
        ->toBe("- Conexão única e controlada\n- Sem exposição à Internet");
});

it('leaves a section uncondensed rather than storing a version that does not fit', function () {
    $submission = submissionWithProse(['summary' => 'Texto original que continua valendo.']);

    $tooLong = collect(range(1, 9))->map(fn ($i) => "- Linha número {$i}")->implode("\n");
    $condenser = fakeCondenser(array_fill(0, 3, "````slide:summary\n{$tooLong}\n````"));

    $result = $condenser->handle($submission);
    $section = $submission->section(SubmissionSectionKey::Summary)->fresh();

    expect($result['failed'])->toHaveKey('summary')
        ->and($section->slide_content)->toBeNull()
        // Verbose but true beats a mangled summary.
        ->and($section->slideText())->toBe('Texto original que continua valendo.');
});

it('condenses several sections in one call', function () {
    $submission = submissionWithProse([
        'summary'      => 'Um texto.',
        'architecture' => 'Outro texto.',
    ]);

    $condenser = fakeCondenser([
        "````slide:summary\n- Resumo curto\n````\n\n````slide:architecture\n- VM na Google Cloud\n````",
    ]);

    $result = $condenser->handle($submission);

    expect($result['attempts'])->toBe(1)
        ->and($result['condensed'])->toEqual(['summary', 'architecture'])
        // Only sections with prose are sent — an empty one has nothing to cut.
        ->and($condenser->prompts[0])->not->toContain('`objectives`');
});

it('falls back to the full text once the section is edited underneath it', function () {
    $submission = submissionWithProse(['summary' => 'Texto original.']);
    fakeCondenser(["````slide:summary\n- Resumo curto\n````"])->handle($submission);

    $section = $submission->section(SubmissionSectionKey::Summary);
    expect($section->fresh()->slideText())->toBe('- Resumo curto');

    $section->update(['content' => 'Texto reescrito, agora dizendo outra coisa.']);
    $section = $section->fresh();

    // A summary of a paragraph that no longer exists is worse than the
    // paragraph, so the deck goes back to the full text.
    expect($section->slideContentIsFresh())->toBeFalse()
        ->and($section->slideText())->toBe('Texto reescrito, agora dizendo outra coisa.');
});

it('builds the deck from the slide version when it is fresh', function () {
    $submission = submissionWithProse(['summary' => 'Um parágrafo bem longo escrito para ser lido, não projetado.']);
    fakeCondenser(["````slide:summary\n- Linha de slide\n````"])->handle($submission);

    $spec = app(BuildDeckSpec::class)->handle($submission->fresh());
    $slide = collect($spec['slides'])->firstWhere('title', 'Resumo da proposta');

    expect($slide['blocks'])->toBe([['type' => 'bullet', 'text' => 'Linha de slide', 'level' => 0]]);
});

it('accepts a table as slide content but bounds it', function () {
    $validator = new SlideTextValidator;
    $markdown = new MarkdownToBlocks;

    $fits = $markdown->convert("| Fase | Duração |\n| --- | --- |\n| Piloto | 5 dias |");
    expect($validator->validate($fits))->toBe([]);

    $wide = '| ' . implode(' | ', range('a', 'h')) . " |\n| " . implode(' | ', array_fill(0, 8, '---')) . ' |';
    expect($validator->validate($markdown->convert($wide))[0])->toContain('8 colunas');
});

it('queues the condensation and refuses when nothing is written yet', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $empty = Submission::factory()->withSections()->create(['created_by_id' => $user->id]);

    $this->postJson(route('submissions.slides.condense', $empty))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    Queue::assertNothingPushed();

    $written = Submission::factory()->withSections()->create(['created_by_id' => $user->id]);
    $written->section(SubmissionSectionKey::Summary)->update(['content' => 'Texto.']);

    $this->postJson(route('submissions.slides.condense', $written))->assertOk();

    Queue::assertPushed(CondenseSubmissionForSlides::class);
});

it('shows the slide version on the page, flagged when stale', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($user);

    $submission = Submission::factory()->withSections()->create(['created_by_id' => $user->id]);
    $section = $submission->section(SubmissionSectionKey::Summary);
    $section->update([
        'content'           => 'Texto reescrito.',
        'slide_content'     => '- Linha antiga',
        'slide_source_hash' => SubmissionSection::hashFor('outro texto'),
    ]);

    $html = $this->get(route('submissions.show', $submission))->assertOk()->getContent();

    // A bad summary has to be visible to be fixed.
    expect($html)->toContain('Versão para slide')
        ->toContain('Linha antiga')
        ->toContain('desatualizada');
});
