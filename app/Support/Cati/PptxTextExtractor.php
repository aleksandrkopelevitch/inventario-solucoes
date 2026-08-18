<?php

namespace App\Support\Cati;

use RuntimeException;
use ZipArchive;

/**
 * Reads a `.pptx` slide by slide — the text of each slide plus the presenter
 * notes attached to it, which routinely carry the reasoning the slide itself
 * hides.
 *
 * Two things here are not optional details, and getting either wrong produces
 * output that looks right on a small file and is wrong on every real deck:
 *
 * - **Slide ORDER comes from `p:sldIdLst` in `ppt/presentation.xml`**, resolved
 *   through the presentation's relationships — not from the file names. Part
 *   numbering reflects creation order, so a reordered deck reports the wrong
 *   slide numbers, and provenance ("isso veio do slide 7") stops being true.
 * - **Notes are attached by RELATIONSHIP.** In the reference deck, `slide3`
 *   points at `notesSlide1`. Pairing by number attributes the wrong notes to
 *   the wrong slide.
 */
class PptxTextExtractor
{
    use OoxmlText;

    private const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const PRESENTATION_NS = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    private const RELATIONSHIPS_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return list<array{slide: int, text: string, notes: string|null}> in presentation order
     *
     * @throws RuntimeException when the file isn't a readable zip
     */
    public function extract(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Arquivo .pptx ilegível (não é um zip válido).');
        }

        try {
            $slides = [];

            foreach ($this->orderedSlideParts($zip) as $position => $part) {
                $xml = $zip->getFromName($part);

                if ($xml === false) {
                    continue;
                }

                $slides[] = [
                    'slide' => $position + 1,
                    'text'  => $this->textOf($xml, self::DRAWINGML, 'a'),
                    'notes' => $this->notesFor($zip, $part),
                ];
            }

            return $slides;
        } finally {
            $zip->close();
        }
    }

    /**
     * Slide parts in the order the presentation actually shows them.
     *
     * Falls back to a natural sort of the slide parts when `presentation.xml`
     * can't be read — natural, because a plain string sort puts `slide10`
     * before `slide2`.
     *
     * @return list<string>
     */
    private function orderedSlideParts(ZipArchive $zip): array
    {
        $presentation = $zip->getFromName('ppt/presentation.xml');
        $rels = $zip->getFromName('ppt/_rels/presentation.xml.rels');

        if ($presentation !== false && $rels !== false) {
            $ordered = $this->slidePartsFromPresentation($presentation, $rels);

            if ($ordered !== []) {
                return $ordered;
            }
        }

        return $this->slidePartsByName($zip);
    }

    /** @return list<string> */
    private function slidePartsFromPresentation(string $presentationXml, string $relsXml): array
    {
        $targets = $this->relationships($relsXml, '/slide');

        $root = @simplexml_load_string($presentationXml, \SimpleXMLElement::class, LIBXML_NONET);

        if ($root === false) {
            return [];
        }

        $root->registerXPathNamespace('p', self::PRESENTATION_NS);
        $root->registerXPathNamespace('r', self::RELATIONSHIPS_NS);

        $parts = [];

        foreach ($root->xpath('//p:sldIdLst/p:sldId') ?: [] as $slideId) {
            $id = (string) $slideId->attributes(self::RELATIONSHIPS_NS)['id'];
            $target = $targets[$id] ?? null;

            if ($target !== null) {
                $parts[] = $this->resolve('ppt/presentation.xml', $target);
            }
        }

        return $parts;
    }

    /** @return list<string> */
    private function slidePartsByName(ZipArchive $zip): array
    {
        $parts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (is_string($name) && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $matches)) {
                $parts[(int) $matches[1]] = $name;
            }
        }

        ksort($parts, SORT_NUMERIC);

        return array_values($parts);
    }

    /** Presenter notes attached to a slide part, via its relationships. */
    private function notesFor(ZipArchive $zip, string $slidePart): ?string
    {
        $relsPart = dirname($slidePart) . '/_rels/' . basename($slidePart) . '.rels';
        $rels = $zip->getFromName($relsPart);

        if ($rels === false) {
            return null;
        }

        $targets = $this->relationships($rels, '/notesSlide');

        if ($targets === []) {
            return null;
        }

        $xml = $zip->getFromName($this->resolve($slidePart, reset($targets)));

        if ($xml === false) {
            return null;
        }

        return $this->notesBody($xml);
    }

    /**
     * The text of a notes slide's BODY placeholder, and nothing else.
     *
     * Reading the whole part instead is the obvious shortcut and it is wrong:
     * a notes slide also carries the notes master's header, date, footer and
     * slide-number placeholders, so every slide comes back with notes that
     * read `New Style Office / 8/11/2026 / 3`. Measured on the reference deck,
     * which has six notes slides and not one real note — scoping to
     * `p:ph type="body"` is what tells those two situations apart.
     */
    private function notesBody(string $xml): ?string
    {
        $root = $this->parse($xml);

        if ($root === null) {
            return null;
        }

        $root->registerXPathNamespace('p', self::PRESENTATION_NS);
        $shapes = $root->xpath('//p:sp[.//p:ph[@type="body"]]') ?: [];

        if ($shapes === []) {
            return null;
        }

        $notes = trim($this->textOfNode($shapes[0], self::DRAWINGML, 'a'));

        return $notes === '' ? null : $notes;
    }

    /** Resolves a relationship target (`../notesSlides/x.xml`) against the part that declared it. */
    private function resolve(string $fromPart, string $target): string
    {
        $path = dirname($fromPart) . '/' . $target;
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
