<?php

namespace App\Support\Context;

use RuntimeException;
use ZipArchive;

/**
 * Reads a `.docx` as plain text. Same idea as PptxTextExtractor and much
 * simpler: a Word document has one body part and no ordering problem.
 */
class DocxTextExtractor
{
    use OoxmlText;

    private const WORDPROCESSINGML = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @throws RuntimeException when the file isn't a readable zip */
    public function extract(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Arquivo .docx ilegível (não é um zip válido).');
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            return $xml === false ? '' : $this->textOf($xml, self::WORDPROCESSINGML, 'w');
        } finally {
            $zip->close();
        }
    }
}
