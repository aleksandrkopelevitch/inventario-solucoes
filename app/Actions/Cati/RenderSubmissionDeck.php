<?php

namespace App\Actions\Cati;

use App\Models\Submission;
use App\Support\Cati\DeckSpecValidator;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Renders a submission's deck: spec in PHP, `.pptx` in Python.
 *
 * The split is the same discipline the flowSpec generator follows — whatever
 * decides CONTENT (here BuildDeckSpec, later a model) produces JSON, it is
 * validated, and only then does something else write the file. Nothing
 * downstream of the validator can invent a slide.
 *
 * python-pptx is used because it opens the real corporate template and places
 * into its layouts. The fidelity lives in `resources/cati/cati-template.pptx`,
 * not in the script.
 */
class RenderSubmissionDeck
{
    public function __construct(
        private readonly BuildDeckSpec $builder,
        private readonly DeckSpecValidator $validator,
    ) {}

    /**
     * @return string absolute path to the generated file (caller owns cleanup)
     *
     * @throws RuntimeException when the spec is invalid or the renderer fails
     */
    public function handle(Submission $submission): string
    {
        $spec = $this->builder->handle($submission);

        $problems = $this->validator->validate($spec);

        if ($problems !== []) {
            // A spec that can't be placed is a bug in the builder, not something
            // to paper over by rendering a broken deck.
            throw new RuntimeException('Deck spec inválido: ' . implode(' ', $problems));
        }

        $directory = storage_path('app/cati-decks');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $specPath = tempnam($directory, 'spec-');
        $outPath = tempnam($directory, 'deck-') . '.pptx';

        file_put_contents($specPath, json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $this->run($specPath, $outPath);
        } finally {
            @unlink($specPath);
        }

        return $outPath;
    }

    private function run(string $specPath, string $outPath): void
    {
        $process = new Process([
            (string) config('services.cati.python'),
            (string) config('services.cati.deck_script'),
            '--template', (string) config('services.cati.deck_template'),
            '--spec', $specPath,
            '--out', $outPath,
        ], base_path());

        $process->setTimeout((float) config('services.cati.deck_timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            // The renderer's stderr names the layout or the block it choked on
            // — worth keeping server-side, but not worth showing a user who
            // can do nothing with it.
            Log::error('CATI: deck rendering failed', [
                'exit'   => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException('Não foi possível gerar o deck.');
        }
    }
}
