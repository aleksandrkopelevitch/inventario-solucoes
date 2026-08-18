<?php

namespace App\Support\Cati;

use Throwable;

/**
 * Turns one uploaded file into text a prompt can carry — or decides,
 * deliberately, that it shouldn't be text at all.
 *
 * The partition mirrors what App\Services\Documentation\ContextDocumentResolver
 * already does for a Solution's context documents: text formats are inlined,
 * PDFs and images go to the model as native attachments (laravel/ai's
 * LocalDocument / LocalImage) and are marked `Skipped` — never `Failed`, or
 * users learn to re-upload files that were working fine.
 */
class SourceTextExtractor
{
    /** Read as text, as-is. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'yaml', 'yml'];

    /** Deliberately not extracted here — the model reads them natively. */
    private const ATTACHMENT_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'svg'];

    /**
     * A ceiling on what one source contributes, so a 200-page export can't
     * crowd everything else out of the prompt. The per-turn budget
     * (`services.cati.doc_budget_chars`) applies again later, across sources.
     */
    private const MAX_CHARS = 400000;

    public function __construct(
        private readonly PptxTextExtractor $pptx,
        private readonly DocxTextExtractor $docx,
    ) {}

    public function extract(string $path, string $extension): ExtractedText
    {
        $extension = mb_strtolower(ltrim($extension, '.'));

        if (in_array($extension, self::ATTACHMENT_EXTENSIONS, true)) {
            return ExtractedText::skipped('Vai como anexo nativo para o modelo, sem extração de texto.');
        }

        try {
            return match (true) {
                $extension === 'pptx'                             => $this->fromSlides($path),
                $extension === 'docx'                             => $this->cap($this->docx->extract($path)),
                in_array($extension, self::TEXT_EXTENSIONS, true) => $this->cap((string) file_get_contents($path)),
                default                                           => ExtractedText::skipped("Formato .{$extension} não é lido como texto."),
            };
        } catch (Throwable $e) {
            // Reported by the caller (which knows which source failed) — here
            // only the message the user will see next to the file.
            return ExtractedText::failed('Não foi possível ler o arquivo: ' . $e->getMessage());
        }
    }

    /**
     * A deck becomes one text with explicit slide markers, because provenance
     * on a submission is written as "veio do slide 7" and has to stay checkable.
     */
    private function fromSlides(string $path): ExtractedText
    {
        $slides = $this->pptx->extract($path);

        if ($slides === []) {
            return ExtractedText::skipped('Nenhum slide legível no arquivo.');
        }

        $blocks = [];

        foreach ($slides as $slide) {
            $block = "## Slide {$slide['slide']}";

            if ($slide['text'] !== '') {
                $block .= "\n{$slide['text']}";
            }

            if ($slide['notes'] !== null) {
                $block .= "\n\nNotas do apresentador:\n{$slide['notes']}";
            }

            $blocks[] = $block;
        }

        return $this->cap(implode("\n\n", $blocks), count($slides) . ' slides lidos.');
    }

    private function cap(string $text, ?string $note = null): ExtractedText
    {
        $text = trim($text);

        if ($text === '') {
            return ExtractedText::skipped('O arquivo não tem texto legível.');
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
            $note = trim(($note ? $note . ' ' : '') . 'Texto truncado no limite de ' . self::MAX_CHARS . ' caracteres.');
        }

        return ExtractedText::done($text, $note);
    }
}
