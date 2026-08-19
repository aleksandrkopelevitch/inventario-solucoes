<?php

use App\Actions\Cati\BuildDeckSpec;
use App\Actions\Cati\RenderSubmissionDeck;
use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Models\Integration;
use App\Models\Person;
use App\Models\Solution;
use App\Models\Submission;
use App\Support\Cati\DeckSpecValidator;
use App\Support\Cati\MarkdownToBlocks;
use App\Support\Cati\PptxTextExtractor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function blocks(string $markdown): array
{
    return (new MarkdownToBlocks)->convert($markdown);
}

function deckFor(array $sections = []): array
{
    $submission = Submission::factory()->withSections()->create(['name' => 'CATI SKBridge']);

    foreach ($sections as $key => $content) {
        $submission->section(SubmissionSectionKey::from($key))
            ->update(['content' => $content, 'state' => SubmissionSectionState::Confirmed]);
    }

    return app(BuildDeckSpec::class)->handle($submission->fresh());
}

it('turns a markdown list into bullets with their nesting', function () {
    $result = blocks("- Ponto único de integração\n- Segmentação preservada\n  - sem tráfego lateral");

    expect($result)->toBe([
        ['type' => 'bullet', 'text' => 'Ponto único de integração', 'level' => 0],
        ['type' => 'bullet', 'text' => 'Segmentação preservada', 'level' => 0],
        ['type' => 'bullet', 'text' => 'sem tráfego lateral', 'level' => 1],
    ]);
});

it('joins soft-wrapped lines into one paragraph', function () {
    // Same reading MarkdownText gives these fields everywhere else.
    expect(blocks("Estabelecer um ponto único\ne controlado de conexão."))
        ->toBe([['type' => 'paragraph', 'text' => 'Estabelecer um ponto único e controlado de conexão.', 'level' => 0]]);
});

it('turns a gfm table into a native table block', function () {
    // This is how the "Modelo de Operação" and "Plano de Implementação" slides
    // get real PowerPoint tables instead of a wall of pipes.
    $result = blocks(<<<'MD'
    | Camada | Responsável |
    | --- | --- |
    | SKBridge | SkyMob |
    | Rede | Infraestrutura |
    MD);

    expect($result)->toBe([[
        'type'    => 'table',
        'columns' => ['Camada', 'Responsável'],
        'rows'    => [['SKBridge', 'SkyMob'], ['Rede', 'Infraestrutura']],
    ]]);
});

it('squares a ragged table row against the header', function () {
    // A short row would otherwise shift every cell after it into the wrong
    // column — worse than an error, because it looks fine.
    $result = blocks("| A | B | C |\n| --- | --- | --- |\n| 1 | 2 |\n| 1 | 2 | 3 | 4 |");

    expect($result[0]['rows'])->toBe([['1', '2', ''], ['1', '2', '3']]);
});

it('strips inline marks a placeholder cannot express', function () {
    // Run-level formatting would mean losing the layout's inherited styling,
    // so the marks come off rather than showing up as literal asterisks.
    expect(blocks('- **Segurança:** dois níveis de `firewall` e [VPN](https://x.com)'))
        ->toBe([['type' => 'bullet', 'text' => 'Segurança: dois níveis de firewall e VPN', 'level' => 0]]);
});

it('demotes a heading inside a section to a bullet', function () {
    // The slide's title is already the section's name — a second title level
    // would compete with it.
    expect(blocks("### Benefícios\n- Técnicos"))
        ->toBe([
            ['type' => 'bullet', 'text' => 'Benefícios', 'level' => 0],
            ['type' => 'bullet', 'text' => 'Técnicos', 'level' => 0],
        ]);
});

it('builds a cover and a closing around the sections', function () {
    $spec = deckFor(['summary' => 'Ponto único de conexão.']);

    expect($spec['slides'][0]['layout'])->toBe('cover')
        ->and($spec['slides'][0]['title'])->toBe('CATI SKBridge')
        ->and(end($spec['slides'])['layout'])->toBe('closing');
});

it('skips an empty optional section but keeps an empty mandatory one, marked', function () {
    $spec = deckFor(['summary' => 'Resumo.']);
    $titles = array_column($spec['slides'], 'title');

    // Objetivos is deck-only and optional — an empty slide is worse than a
    // shorter deck. Arquitetura is mandatory, so its absence has to be visible.
    expect($titles)->not->toContain('Objetivos')
        ->and($titles)->toContain('Arquitetura de solução');

    $architecture = collect($spec['slides'])->firstWhere('title', 'Arquitetura de solução');

    expect($architecture['blocks'][0]['text'])->toBe('[não preenchido]');
});

it('keeps the sections in the deck\'s narrative order', function () {
    $spec = deckFor([
        'summary'       => 'Resumo.',
        'current_state' => 'Hoje é assim.',
        'objectives'    => 'Objetivos.',
    ]);

    $titles = array_slice(array_column($spec['slides'], 'title'), 0, 4);

    // Document order, not the ticket's numbering.
    expect($titles)->toBe(['CATI SKBridge', 'Resumo da proposta', 'Cenário atual', 'Objetivos']);
});

it('names the solution and the requester on the cover and closing', function () {
    $solution = Solution::factory()->create(['name' => 'SkyMob']);
    $person = Person::factory()->create(['name' => 'Fabio Caldart']);
    $submission = Submission::factory()->withSections()->create([
        'solution_id'         => $solution->id,
        'requester_person_id' => $person->id,
        'committee_date'      => '2026-09-01',
    ]);

    $spec = app(BuildDeckSpec::class)->handle($submission);

    expect($spec['slides'][0]['subtitle'])->toContain('SkyMob')
        ->and($spec['slides'][0]['footnote'])->toBe('09/2026')
        ->and(end($spec['slides'])['subtitle'])->toBe('Fabio Caldart');
});

it('passes its own validator for a real submission', function () {
    $spec = deckFor([
        'summary'      => 'Ponto único de conexão.',
        'architecture' => "- VM na Google Cloud\n- VPN site-to-site",
        'plan_costs'   => "| Fase | Duração |\n| --- | --- |\n| Provisionamento | 2 dias |",
    ]);

    expect((new DeckSpecValidator)->validate($spec))->toBe([]);
});

it('rejects a spec the renderer could not place', function () {
    $validator = new DeckSpecValidator;

    expect($validator->validate([]))->toBe(['O deck não tem slide nenhum.'])
        ->and($validator->validate(['slides' => [['layout' => 'diagrama', 'title' => 'X']]]))
        ->toContain('slide 1: layout desconhecido ("diagrama").')
        ->and($validator->validate(['slides' => [['layout' => 'content', 'title' => '']]]))
        ->toContain('slide 1: sem título.');
});

it('rejects a table too big to read from the back of the room', function () {
    $validator = new DeckSpecValidator;

    $spec = ['slides' => [[
        'layout' => 'content',
        'title'  => 'Plano',
        'blocks' => [[
            'type'    => 'table',
            'columns' => ['a', 'b'],
            'rows'    => array_fill(0, 13, ['1', '2']),
        ]],
    ]]];

    expect($validator->validate($spec)[0])->toContain('13 linhas');
});

it('rejects a ragged table before it silently misaligns a column', function () {
    $spec = ['slides' => [[
        'layout' => 'content',
        'title'  => 'Plano',
        'blocks' => [['type' => 'table', 'columns' => ['a', 'b'], 'rows' => [['1']]]],
    ]]];

    expect((new DeckSpecValidator)->validate($spec)[0])->toContain('não tem 2 células');
});

it('renders a real .pptx through the python renderer', function () {
    // Skipped rather than failed where the venv isn't provisioned: the deck is
    // the one part of this module with a runtime outside PHP, and a red suite
    // on a machine that simply hasn't run the install teaches people to ignore
    // red. docs/cati-fase-2.md has the two commands.
    if (! is_executable((string) config('services.cati.python'))) {
        test()->markTestSkipped('python-pptx venv não provisionado (ver docs/cati-fase-2.md).');
    }

    $submission = Submission::factory()->withSections()->create(['name' => 'CATI SKBridge']);
    $submission->section(SubmissionSectionKey::Summary)->update([
        'content' => "Ponto único de conexão.\n\n- Sem exposição das redes internas",
        'state'   => SubmissionSectionState::Confirmed,
    ]);
    $submission->section(SubmissionSectionKey::PlanCosts)->update([
        'content' => "| Fase | Duração |\n| --- | --- |\n| Provisionamento | 2 dias |",
        'state'   => SubmissionSectionState::Confirmed,
    ]);

    $path = app(RenderSubmissionDeck::class)->handle($submission->fresh());

    try {
        expect(file_exists($path))->toBeTrue()
            ->and(filesize($path))->toBeGreaterThan(20000);

        // Read it back with the app's own extractor — a deck PowerPoint can
        // open is one whose slide parts and presentation order actually parse.
        $slides = (new PptxTextExtractor)->extract($path);

        expect($slides)->not->toBeEmpty()
            ->and($slides[0]['text'])->toContain('CATI SKBridge')
            ->and(collect($slides)->pluck('text')->implode(' '))
            ->toContain('Ponto único de conexão.')
            ->toContain('Provisionamento');

        // Real list formatting, not a bullet typed into the text. Element order
        // inside <a:pPr> is fixed by the schema, and a bullet in the wrong place
        // is what makes PowerPoint offer to repair a file — so this asserts
        // against the XML the renderer actually emitted.
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'ppt/slides/slide')) {
                $xml .= $zip->getFromName($name);
            }
        }
        $zip->close();

        expect($xml)->toContain('<a:buChar char="•"/>')
            ->toContain('marL="285750"')
            // A paragraph that is NOT a bullet has to say so, or it inherits a
            // glyph from the master's list style.
            ->toContain('<a:buNone/>');
    } finally {
        @unlink($path);
    }
});

it('puts each integration\'s canvas picture on its own slide, after the architecture', function () {
    Storage::fake('public');

    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'SAP x SkyMob']);
    attachParticipants($integration, [[$solution, 0], [Solution::factory()->create(), 1]]);

    $integration->addMedia(UploadedFile::fake()->image('canvas.png', 1600, 900))
        ->toMediaCollection(Integration::DIAGRAM_COLLECTION);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);
    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'VM na Google Cloud.']);

    $spec = app(BuildDeckSpec::class)->handle($submission->fresh());
    $titles = array_column($spec['slides'], 'title');

    $diagramIndex = array_search('Arquitetura — SAP x SkyMob', $titles, true);

    expect($diagramIndex)->not->toBeFalse()
        // Right after the architecture section, where the committee expects it.
        ->and($titles[$diagramIndex - 1])->toBe('Arquitetura de solução');

    $block = $spec['slides'][$diagramIndex]['blocks'][0];

    expect($block['type'])->toBe('image')
        ->and(is_file($block['path']))->toBeTrue()
        // The link is what keeps the canvas the one place a diagram is edited.
        ->and($block['link'])->toBe(route('solutions.integrations.docs.edit', [$solution, $integration]))
        ->and((new DeckSpecValidator)->validate($spec))->toBe([]);
});

it('leaves out an integration whose canvas was never saved', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'Sem desenho']);
    attachParticipants($integration, [[$solution, 0], [Solution::factory()->create(), 1]]);

    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);
    $submission->section(SubmissionSectionKey::Architecture)->update(['content' => 'Texto.']);

    // A slide with a hole in it is worse than a shorter deck.
    expect(array_column(app(BuildDeckSpec::class)->handle($submission->fresh())['slides'], 'title'))
        ->not->toContain('Arquitetura — Sem desenho');
});

it('rejects an image the renderer would die on', function () {
    // python-pptx would raise a traceback the user can do nothing with; caught
    // here, where the message can name the slide.
    $spec = ['slides' => [[
        'layout' => 'content',
        'title'  => 'Arquitetura',
        'blocks' => [['type' => 'image', 'path' => '/tmp/nao-existe-123.png']],
    ]]];

    expect((new DeckSpecValidator)->validate($spec)[0])->toContain('imagem não encontrada');
});

it('gives a section with two tables two slides instead of losing one', function () {
    // The renderer places one figure per slide; before pagination the second
    // table was dropped without a word.
    $spec = deckFor(['operating_model' => <<<'MD'
    | Camada | Responsável |
    | --- | --- |
    | SKBridge | SkyMob |

    | Porta | Destino |
    | --- | --- |
    | 9180 | Rede administrativa |
    MD]);

    $titles = array_column($spec['slides'], 'title');

    expect($titles)->toContain('Modelo de operação')
        ->toContain('Modelo de operação (cont.)');

    $tables = collect($spec['slides'])
        ->flatMap(fn (array $slide) => $slide['blocks'] ?? [])
        ->where('type', 'table');

    expect($tables)->toHaveCount(2)
        // Never two figures on one slide — the renderer refuses that outright.
        ->and(collect($spec['slides'])->every(
            fn (array $slide) => collect($slide['blocks'] ?? [])->whereIn('type', ['table', 'image'])->count() <= 1
        ))->toBeTrue();
});

it('continues a long section onto another slide rather than off the bottom', function () {
    $long = collect(range(1, 14))
        ->map(fn (int $i) => "- Ponto número {$i} sobre a arquitetura proposta, com detalhe suficiente para ocupar uma linha inteira do slide")
        ->implode("\n");

    $spec = deckFor(['objectives' => $long]);
    $objectives = collect($spec['slides'])->filter(fn ($s) => str_starts_with($s['title'], 'Objetivos'));

    expect($objectives->count())->toBeGreaterThan(1)
        ->and($objectives->last()['title'])->toBe('Objetivos (cont.)')
        // Nothing lost in the split.
        ->and($objectives->flatMap(fn ($s) => $s['blocks'])->count())->toBe(14);
});

it('keeps a short section on a single slide', function () {
    $spec = deckFor(['summary' => "Ponto único de conexão.\n\n- Sem exposição das redes internas"]);

    expect(collect($spec['slides'])->filter(fn ($s) => str_starts_with($s['title'], 'Resumo'))->count())->toBe(1);
});
