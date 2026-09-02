<?php

namespace App\Support\Digibee;

/**
 * Prepares a synced page for the caderno: the corpus keeps the file exactly as
 * Digibee served it, and this is what makes a copy of it READABLE inside this
 * app.
 *
 * Four things, and each of them is about the page landing somewhere else than
 * the site it was written for:
 *
 * - **The GitBook banner goes.** Every page opens with "For the complete
 *   documentation index, see llms.txt…", which is an instruction to an agent
 *   fetching the page, not documentation.
 * - **The "Agent Instructions" chapter goes.** Same reason, at the other end:
 *   it explains the `?ask=` protocol to whoever fetched the file. Left in, it
 *   would be indexed by our own search and answer people's queries with GitBook
 *   support text.
 * - **Relative links become absolute.** `/documentation/…/rest-v2.md` means
 *   nothing under `/notebooks/{caderno}/{page}` — it would 404 inside this app.
 *   Pointed at docs.digibee.com they keep working, and they take the reader to
 *   the authoritative version, which is where a vendor's manual should send
 *   somebody anyway.
 * - **Figures are dropped, with a reason.** The images are `/files/{id}`
 *   references that GitBook does not serve at that path (verified: 404 on both
 *   `/files/{id}` and `/documentation/files/{id}`) — the real bytes come from a
 *   CDN URL that only the rendered HTML carries. Rehosting them would mean
 *   fetching all 581 pages a second time as HTML to scrape `<img src>`, for a
 *   copy whose job is reading and searching. A broken image on every other page
 *   is the worse of the two, so the block is removed and the page carries a
 *   link to the original at the top.
 */
class DigibeeMarkdownNormalizer
{
    private const LINE_BREAK = '/\r\n|\r|\n/';

    public function normalize(string $markdown, DigibeeDocPage $page): string
    {
        $body = $this->withoutAgentInstructions($markdown);
        $body = $this->withoutBanner($body);
        $body = $this->withoutFigures($body);
        $body = $this->absoluteLinks($body);

        return trim($this->sourceNote($page) . "\n\n" . trim($body)) . "\n";
    }

    /**
     * A hint block naming where this text came from and when.
     *
     * It is the first thing on the page on purpose: this caderno is a COPY of
     * somebody else's manual, and a reader who does not know that will treat a
     * six-month-old paragraph as ours.
     */
    private function sourceNote(DigibeeDocPage $page): string
    {
        return "{% hint style=\"info\" %}\n"
            . 'Cópia da documentação oficial da Digibee, sincronizada automaticamente. '
            . "A versão que vale é a do site: [{$page->title}]({$this->humanUrl($page)})\n"
            . '{% endhint %}';
    }

    /**
     * The page a PERSON should land on — the corpus is addressed by the `.md`
     * URL, and sending a reader there hands them raw Markdown in a browser tab.
     */
    private function humanUrl(DigibeeDocPage $page): string
    {
        return (string) preg_replace('/\.md$/', '', $page->url);
    }

    private function withoutAgentInstructions(string $markdown): string
    {
        $lines = preg_split(self::LINE_BREAK, $markdown) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^#\s+Agent Instructions\s*$/i', $line) === 1) {
                break;
            }

            $kept[] = $line;
        }

        // The chapter is preceded by a `---` divider that now separates nothing.
        while ($kept !== [] && in_array(trim((string) end($kept)), ['', '---'], true)) {
            array_pop($kept);
        }

        return implode("\n", $kept);
    }

    private function withoutBanner(string $markdown): string
    {
        return (string) preg_replace('/\A>\s+For the complete documentation index.*?(?:\R|$)/u', '', $markdown);
    }

    private function withoutFigures(string $markdown): string
    {
        return (string) preg_replace('~<figure>.*?</figure>~us', '', $markdown);
    }

    /**
     * `(/documentation/x.md)` and `(/spaces/…)` become full URLs.
     *
     * Only root-relative targets are touched: an in-page `#anchor` is still
     * correct here, and this app's own `page:` construct must never be invented
     * for a corpus whose pages are not addressed that way.
     */
    private function absoluteLinks(string $markdown): string
    {
        $base = rtrim((string) config('services.digibee.docs_url'), '/');

        return (string) preg_replace_callback(
            '~\]\((/(?!/)[^)\s]*)\)~',
            fn (array $m) => '](' . $base . preg_replace('/\.md(?=$|#)/', '', $m[1]) . ')',
            $markdown,
        );
    }
}
