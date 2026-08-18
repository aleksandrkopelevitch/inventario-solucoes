<?php

use App\Actions\Cati\IngestSubmissionSource;
use App\Enums\SubmissionSourceExtraction;
use App\Enums\SubmissionSourceKind;
use App\Models\Submission;
use App\Support\Cati\PptxTextExtractor;
use App\Support\Cati\SensitiveTextScanner;
use App\Support\Cati\SourceTextExtractor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/**
 * Builds a real `.pptx` — a zip with the parts the extractor actually reads.
 *
 * Written by hand instead of committing a binary fixture so the test states
 * the format it depends on: presentation order comes from `p:sldIdLst`, notes
 * are attached by relationship, and notes text lives in the `body`
 * placeholder. A committed deck would hide all three behind an opaque blob.
 *
 * @param  list<array{name: string, text: list<string>, notes?: string, hasChrome?: bool}>  $slides
 * @param  list<string>|null  $order  part names in presentation order (defaults to the given order)
 */
function fakePptx(array $slides, ?array $order = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'cati') . '.pptx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    $R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $paragraphs = fn (array $lines) => implode('', array_map(
        fn (string $line) => '<a:p><a:r><a:t>' . htmlspecialchars($line, ENT_XML1) . '</a:t></a:r></a:p>',
        $lines,
    ));

    $notesIndex = 0;
    $slideIds = [];
    $presentationRels = [];

    foreach ($slides as $i => $slide) {
        $part = "ppt/slides/{$slide['name']}.xml";
        $rId = 'rId' . ($i + 100);

        $zip->addFromString($part, <<<XML
        <?xml version="1.0"?>
        <p:sld xmlns:p="{$P}" xmlns:a="{$A}">
          <p:cSld><p:spTree><p:sp><p:txBody>{$paragraphs($slide['text'])}</p:txBody></p:sp></p:spTree></p:cSld>
        </p:sld>
        XML);

        $slideRels = '';

        if (isset($slide['notes'])) {
            $notesIndex++;
            $notesPart = "ppt/notesSlides/notesSlide{$notesIndex}.xml";
            $slideRels = '<Relationship Id="rIdN" Type="' . $R . '/notesSlide" Target="../notesSlides/notesSlide' . $notesIndex . '.xml"/>';

            // A real notes slide also carries the notes master's header, date,
            // footer and slide-number placeholders — the whole reason the
            // extractor has to scope to `body`.
            $chrome = ($slide['hasChrome'] ?? true)
                ? '<p:sp><p:nvSpPr><p:nvPr><p:ph type="hdr" sz="quarter"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>New Style Office</a:t></a:r></a:p></p:txBody></p:sp>'
                . '<p:sp><p:nvSpPr><p:nvPr><p:ph type="dt" idx="1"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>8/11/2026</a:t></a:r></a:p></p:txBody></p:sp>'
                . '<p:sp><p:nvSpPr><p:nvPr><p:ph type="sldNum" sz="quarter" idx="5"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>3</a:t></a:r></a:p></p:txBody></p:sp>'
                : '';

            $body = $slide['notes'] === ''
                ? ''
                : '<a:p><a:r><a:t>' . htmlspecialchars($slide['notes'], ENT_XML1) . '</a:t></a:r></a:p>';

            $zip->addFromString($notesPart, <<<XML
            <?xml version="1.0"?>
            <p:notes xmlns:p="{$P}" xmlns:a="{$A}">
              <p:cSld><p:spTree>
                {$chrome}
                <p:sp><p:nvSpPr><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:txBody>{$body}</p:txBody></p:sp>
              </p:spTree></p:cSld>
            </p:notes>
            XML);
        }

        $zip->addFromString("ppt/slides/_rels/{$slide['name']}.xml.rels", <<<XML
        <?xml version="1.0"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">{$slideRels}</Relationships>
        XML);

        $presentationRels[$slide['name']] = '<Relationship Id="' . $rId . '" Type="' . $R . '/slide" Target="slides/' . $slide['name'] . '.xml"/>';
        $slideIds[$slide['name']] = '<p:sldId id="' . (256 + $i) . '" r:id="' . $rId . '"/>';
    }

    $order ??= array_column($slides, 'name');
    $orderedIds = implode('', array_map(fn (string $name) => $slideIds[$name], $order));

    $zip->addFromString('ppt/presentation.xml', <<<XML
    <?xml version="1.0"?>
    <p:presentation xmlns:p="{$P}" xmlns:r="{$R}"><p:sldIdLst>{$orderedIds}</p:sldIdLst></p:presentation>
    XML);

    $zip->addFromString('ppt/_rels/presentation.xml.rels', <<<'XML'
    <?xml version="1.0"?>
    <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    XML . implode('', $presentationRels) . '</Relationships>');

    $zip->close();

    return $path;
}

it('reads slides in presentation order, not file-name order', function () {
    // The deck was reordered after authoring: slide2.xml is shown last. Part
    // numbering reflects creation order, so anything keyed off the file name
    // reports the wrong slide number — and provenance stops being checkable.
    $path = fakePptx(
        slides: [
            ['name' => 'slide1', 'text' => ['Capa']],
            ['name' => 'slide2', 'text' => ['Encerramento']],
            ['name' => 'slide3', 'text' => ['Objetivos']],
        ],
        order: ['slide1', 'slide3', 'slide2'],
    );

    $slides = (new PptxTextExtractor)->extract($path);

    expect(array_column($slides, 'text'))->toBe(['Capa', 'Objetivos', 'Encerramento'])
        ->and(array_column($slides, 'slide'))->toBe([1, 2, 3]);
});

it('sorts slide10 after slide9 when it falls back to file names', function () {
    // No presentation.xml: a plain string sort puts slide10 second.
    $path = tempnam(sys_get_temp_dir(), 'cati') . '.pptx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $ns = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';

    foreach ([1, 2, 9, 10] as $n) {
        $zip->addFromString("ppt/slides/slide{$n}.xml", '<?xml version="1.0"?><p:sld ' . $ns . '><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>Slide ' . $n . '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>');
    }

    $zip->close();

    expect(array_column((new PptxTextExtractor)->extract($path), 'text'))
        ->toBe(['Slide 1', 'Slide 2', 'Slide 9', 'Slide 10']);
});

it('attaches notes by relationship, not by matching numbers', function () {
    // slide3 is the only one with notes, so they live in notesSlide1 — the
    // exact shape of the reference deck. Pairing by number would hand slide1
    // notes that belong to slide3.
    $path = fakePptx([
        ['name' => 'slide1', 'text' => ['Capa']],
        ['name' => 'slide2', 'text' => ['Meio']],
        ['name' => 'slide3', 'text' => ['Objetivos'], 'notes' => 'Falar do M2C aqui.'],
    ]);

    $slides = (new PptxTextExtractor)->extract($path);

    expect($slides[0]['notes'])->toBeNull()
        ->and($slides[1]['notes'])->toBeNull()
        ->and($slides[2]['notes'])->toBe('Falar do M2C aqui.');
});

it('ignores the notes master chrome when the slide has no real notes', function () {
    // Measured on the reference deck: six notes slides, not one real note.
    // Reading the whole part gives every slide "New Style Office / 8/11/2026 / 3".
    $path = fakePptx([
        ['name' => 'slide1', 'text' => ['Capa'], 'notes' => ''],
    ]);

    expect((new PptxTextExtractor)->extract($path)[0]['notes'])->toBeNull();
});

it('keeps runs of one paragraph together and separates paragraphs', function () {
    // PowerPoint splits a sentence into runs whenever formatting changes, so
    // joining runs with a space would break words apart.
    $path = fakePptx([
        ['name' => 'slide1', 'text' => ['Ponto único de integração', 'Preservação da segmentação']],
    ]);

    expect((new PptxTextExtractor)->extract($path)[0]['text'])
        ->toBe("Ponto único de integração\nPreservação da segmentação");
});

it('marks a deck as read with slide markers for provenance', function () {
    $path = fakePptx([
        ['name' => 'slide1', 'text' => ['Capa']],
        ['name' => 'slide2', 'text' => ['Custo'], 'notes' => 'Valores a confirmar.'],
    ]);

    $extracted = app(SourceTextExtractor::class)->extract($path, 'pptx');

    expect($extracted->state)->toBe(SubmissionSourceExtraction::Done)
        ->and($extracted->text)->toContain('## Slide 1')
        ->and($extracted->text)->toContain('## Slide 2')
        ->and($extracted->text)->toContain('Notas do apresentador:')
        ->and($extracted->text)->toContain('Valores a confirmar.')
        ->and($extracted->note)->toBe('2 slides lidos.');
});

it('skips a pdf instead of failing on it', function () {
    // A PDF is not a problem to report — it goes to the model as a native
    // attachment. Reporting it as an error teaches users to re-upload files
    // that are working fine.
    $extracted = app(SourceTextExtractor::class)->extract('/dev/null', 'pdf');

    expect($extracted->state)->toBe(SubmissionSourceExtraction::Skipped)
        ->and($extracted->text)->toBeNull()
        ->and($extracted->note)->toContain('anexo nativo');
});

it('fails a corrupt office file without throwing', function () {
    $path = tempnam(sys_get_temp_dir(), 'cati') . '.pptx';
    file_put_contents($path, 'isto não é um zip');

    $extracted = app(SourceTextExtractor::class)->extract($path, 'pptx');

    expect($extracted->state)->toBe(SubmissionSourceExtraction::Failed)
        ->and($extracted->note)->toContain('Não foi possível ler');
});

it('reads plain text formats as they are', function () {
    $path = tempnam(sys_get_temp_dir(), 'cati') . '.md';
    file_put_contents($path, '# Proposta' . PHP_EOL . 'Texto.');

    expect(app(SourceTextExtractor::class)->extract($path, '.MD')->text)
        ->toBe('# Proposta' . PHP_EOL . 'Texto.');
});

it('flags credentials but leaves internal addressing alone', function () {
    // Internal IPs and ports are what these documents are ABOUT — the
    // reference deck is largely VLANs, firewall rules and port 9180. Flagging
    // them makes the warning noise everyone dismisses.
    $findings = (new SensitiveTextScanner)->scan(
        'O SKBridge expõe a porta 9180 em 192.168.10.4, dentro da VLAN 10.0.0.0/8.'
    );

    expect($findings)->toBe([]);

    $findings = (new SensitiveTextScanner)->scan(
        'Autenticar com Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N e senha=SuperSecreta123'
    );

    expect(collect($findings)->pluck('type')->all())
        ->toContain('Token JWT')
        ->toContain('Credencial atribuída a um campo');
});

it('shows enough of a finding to locate it and never enough to use it', function () {
    $findings = (new SensitiveTextScanner)->scan('chave: AKIAIOSFODNN7EXAMPLE');

    expect($findings[0]['sample'])->toBe('AKIAIOSFODNN…')
        ->and($findings[0]['sample'])->not->toContain('EXAMPLE');
});

it('ingests an uploaded deck as a source with its text and provenance', function () {
    Storage::fake('public');

    $submission = Submission::factory()->create();
    $path = fakePptx([
        ['name' => 'slide1', 'text' => ['CATI SKBridge']],
        ['name' => 'slide2', 'text' => ['Propósito']],
    ]);

    $source = app(IngestSubmissionSource::class)->handle(
        $submission,
        new UploadedFile($path, 'CATI_SKBridge.pptx', null, null, true),
    );

    expect($source->kind)->toBe(SubmissionSourceKind::Upload)
        ->and($source->label)->toBe('CATI_SKBridge.pptx')
        ->and($source->extraction_state)->toBe(SubmissionSourceExtraction::Done)
        ->and($source->hasText())->toBeTrue()
        ->and($source->extracted_text)->toContain('CATI SKBridge')
        ->and($source->sensitive_findings)->toBeNull()
        ->and($source->media_id)->not->toBeNull()
        ->and($submission->getMedia(Submission::SOURCES_COLLECTION))->toHaveCount(1);
});

it('keeps the upload even when the file cannot be read', function () {
    Storage::fake('public');

    $submission = Submission::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'cati') . '.pptx';
    file_put_contents($path, 'corrompido');

    $source = app(IngestSubmissionSource::class)->handle(
        $submission,
        new UploadedFile($path, 'quebrado.pptx', null, null, true),
    );

    // The row exists so the user can see what happened, rather than the file
    // silently not being there.
    expect($source->extraction_state)->toBe(SubmissionSourceExtraction::Failed)
        ->and($source->media_id)->not->toBeNull()
        ->and($source->hasText())->toBeFalse();
});

it('records a credential found in an uploaded file', function () {
    Storage::fake('public');

    $submission = Submission::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'cati') . '.md';
    file_put_contents($path, 'Pareamento: api_key=abcdef123456789');

    $source = app(IngestSubmissionSource::class)->handle(
        $submission,
        new UploadedFile($path, 'notas.md', null, null, true),
    );

    expect($source->hasSensitiveFindings())->toBeTrue()
        ->and($source->sensitive_findings[0]['type'])->toBe('Credencial atribuída a um campo')
        // Flagged, not removed: the text is intact.
        ->and($source->extracted_text)->toContain('api_key=abcdef123456789');
});
