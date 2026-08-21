<?php

namespace App\Support\Context;

use SimpleXMLElement;

/**
 * The bit of OOXML both extractors need: pulling readable text out of a part.
 *
 * An Office file is a zip of XML, so a previous CATI deck becomes usable
 * context with no model call at all — which is what lets the corpus of past
 * approved submissions harvest itself.
 */
trait OoxmlText
{
    /**
     * Text of one part, paragraphs separated by newlines.
     *
     * Runs inside a paragraph are concatenated with no separator: Word and
     * PowerPoint split a single sentence into several runs whenever formatting
     * changes mid-word, so joining runs with a space would insert spaces in
     * the middle of words.
     *
     * @param  string  $namespace  the part's main namespace URI
     * @param  string  $prefix  its prefix inside this method's XPath (`a` for
     *                          DrawingML, `w` for WordprocessingML)
     */
    private function textOf(string $xml, string $namespace, string $prefix): string
    {
        $root = $this->parse($xml);

        return $root === null ? '' : $this->textOfNode($root, $namespace, $prefix);
    }

    /**
     * Same, scoped to one already-parsed subtree — used to read ONLY the shape
     * that holds a notes slide's body, instead of everything on the part.
     */
    private function textOfNode(SimpleXMLElement $node, string $namespace, string $prefix): string
    {
        $node->registerXPathNamespace($prefix, $namespace);
        $paragraphs = $node->xpath(".//{$prefix}:p") ?: [];

        $lines = [];

        foreach ($paragraphs as $paragraph) {
            // SimpleXML namespace registrations don't inherit into nodes
            // returned by xpath() — re-register on each one or the inner query
            // silently returns nothing.
            $paragraph->registerXPathNamespace($prefix, $namespace);
            $runs = $paragraph->xpath(".//{$prefix}:t") ?: [];

            $line = trim(implode('', array_map(strval(...), $runs)));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * LIBXML_NONET: never let a document pull in an external DTD. External
     * entity loading itself is off by default since PHP 8.
     */
    private function parse(string $xml): ?SimpleXMLElement
    {
        $root = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        return $root === false ? null : $root;
    }

    /**
     * Relationship targets of a part, by relationship type suffix.
     *
     * Relationships are how OOXML actually links parts, and the numbering is
     * NOT a substitute: in the reference deck, `slide3` points at
     * `notesSlide1`. Anything that pairs parts by their file number is wrong on
     * any real document.
     *
     * @return array<string, string> relationship id => target, as written
     */
    private function relationships(string $relsXml, string $typeSuffix): array
    {
        $root = $this->parse($relsXml);

        if ($root === null) {
            return [];
        }

        $targets = [];

        foreach ($root->children() as $relationship) {
            $type = (string) $relationship['Type'];

            if (str_ends_with($type, $typeSuffix)) {
                $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
            }
        }

        return $targets;
    }
}
