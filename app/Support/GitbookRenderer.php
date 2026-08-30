<?php

namespace App\Support;

use App\Models\Diagram;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableRenderer;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Renderer\HtmlDecorator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Renders documentation (Markdown + GitBook-style extended notation,
 * authored in the Editor.js block editor) into read-only HTML for
 * `.html-content` (see resources/css/app.css).
 *
 * Standard Markdown (headings, lists, task lists, quote, code fence, pipe
 * tables, `***`) goes through league/commonmark (GFM). The constructs GitBook
 * invented — that have no native Markdown — are handled here, recursively,
 * before/between the Markdown chunks:
 *
 *   {% hint style="info|warning|danger|success" %} … {% endhint %}
 *       → <aside data-callout="note|warning|danger|tip"> (.html-content callouts)
 *   {% tabs %}{% tab title="…" %} … {% endtab %} … {% endtabs %}
 *       → tabs using the data-ak-tabs contract (resources/js/modules/tabs.js)
 *   {% file src="/files/{id}" %}
 *       → download card pointing to the authenticated files.show route
 *
 * Images come as plain HTML (<figure><img src="/files/{id}">…), which
 * commonmark passes through (html_input=allow) — /files/{id} resolves via
 * the files.show route.
 */
class GitbookRenderer
{
    /** Set by `render()` on every call — see the note there. */
    private bool $linkDiagrams = true;

    private ?MarkdownConverter $converter = null;

    /**
     * An active tab must READ as the open mouth of the panel below it, which
     * means exactly one thing structurally: no line of any kind between the
     * two. Getting there takes three cooperating pieces, and it broke because
     * only two of them were in place —
     *
     *  - the tablist draws the rail (`border-b`), so inactive tabs sit ON a line;
     *  - the active tab has `-mb-px` (plus `relative`, to pin the paint order)
     *    so its own background covers that rail underneath it;
     *  - the panel has `border-t-0`, because its top border is that same rail.
     *
     * Without the third, the panel drew a SECOND 1px line right under the
     * rail — one the active tab's single pixel of overlap could never reach —
     * and every open tab kept a closed tab's underline. `border-b-surface` in
     * the old active set was trying to paint a border the tab does not have
     * (`border-b-0`); it is gone.
     */
    private const TAB_ACTIVE_CLASSES = ['bg-surface', 'text-ink', 'border-line'];

    private const TAB_INACTIVE_CLASSES = ['text-muted', 'border-transparent', 'hover:text-ink', 'hover:bg-canvas'];

    /** Counter for unique tab-block ids within the same render. */
    private int $uid = 0;

    /**
     * @param  bool  $linkDiagrams  Whether a cited drawing gets a link to its
     *                              canvas. FALSE for anything a guest can read:
     *                              `/diagrams/{slug}` is behind auth, so the
     *                              link is both a dead end (it lands on the
     *                              login screen) and a disclosure — it tells an
     *                              anonymous reader the drawing exists and what
     *                              its slug is. The card itself still renders:
     *                              the picture and the name are documentation,
     *                              the link is an editing affordance.
     */
    public function render(?string $markdown, bool $linkDiagrams = true): string
    {
        // A property rather than a parameter threaded through `renderLines()`:
        // that walk recurses (tabs and hints nest), so the flag would have to
        // ride every recursive call and every helper between here and the one
        // place that reads it. Nothing binds this class as a singleton and a
        // render is not re-entrant, so the value cannot be observed by another
        // call — but it is set on EVERY entry, never only when false, so a
        // previous call can't leave it behind either.
        $this->linkDiagrams = $linkDiagrams;

        if (blank($markdown)) {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown);

        return $this->renderLines($lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function renderLines(array $lines): string
    {
        $html = '';
        $buffer = [];
        $i = 0;
        $n = count($lines);

        $flush = function () use (&$buffer, &$html): void {
            if ($buffer === []) {
                return;
            }
            $md = implode("\n", $buffer);
            if (trim($md) !== '') {
                $html .= $this->commonmark($md);
            }
            $buffer = [];
        };

        while ($i < $n) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Code fence: consume verbatim (don't interpret {% %} inside it).
            if (preg_match('/^(```|~~~)/', $trimmed, $m)) {
                $fence = $m[1];
                $buffer[] = $line;
                $i++;
                while ($i < $n && ! str_starts_with(trim($lines[$i]), $fence)) {
                    $buffer[] = $lines[$i];
                    $i++;
                }
                if ($i < $n) {
                    $buffer[] = $lines[$i];
                    $i++;
                }

                continue;
            }

            if (preg_match('/^\{%\s*hint\s+(.*?)\s*%\}$/', $trimmed, $m)) {
                $flush();
                [$inner, $i] = $this->consumeUntil($lines, $i + 1, 'hint');
                $attrs = $this->parseAttrs($m[1]);
                $html .= $this->renderHint($attrs['style'] ?? 'info', $attrs['icon'] ?? '', $inner);

                continue;
            }

            if (preg_match('/^\{%\s*tabs\s*%\}$/', $trimmed)) {
                $flush();
                [$inner, $i] = $this->consumeUntil($lines, $i + 1, 'tabs');
                $html .= $this->renderTabs($inner);

                continue;
            }

            if (preg_match('/^\{%\s*file\s+src="([^"]*)"\s*%\}$/', $trimmed, $m)) {
                $flush();
                $html .= $this->renderFile($m[1]);
                $i++;

                continue;
            }

            if (preg_match('/^\{%\s*diagram\s+slug="([^"]*)"\s*%\}$/', $trimmed, $m)) {
                $flush();
                $html .= $this->renderDiagram($m[1]);
                $i++;

                continue;
            }

            if (preg_match('/^\{%\s*embed\s+url="([^"]*)"\s*%\}$/', $trimmed, $m)) {
                $flush();
                $html .= $this->renderEmbed(html_entity_decode($m[1], ENT_QUOTES));
                $i++;

                continue;
            }

            $buffer[] = $line;
            $i++;
        }

        $flush();

        return $html;
    }

    /**
     * Collects the lines up to the matching `{% end{$type} %}`, respecting
     * nesting of the same type. Returns [innerLines, newIndex].
     *
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private function consumeUntil(array $lines, int $i, string $type): array
    {
        $open = '/^\{%\s*' . $type . '(\s|%)/';
        $close = '/^\{%\s*end' . $type . '\s*%\}$/';
        $depth = 1;
        $inner = [];
        $n = count($lines);

        while ($i < $n) {
            $t = trim($lines[$i]);
            if (preg_match($open, $t)) {
                $depth++;
            } elseif (preg_match($close, $t)) {
                $depth--;
                if ($depth === 0) {
                    $i++;
                    break;
                }
            }
            $inner[] = $lines[$i];
            $i++;
        }

        return [$inner, $i];
    }

    /** Default outline heroicon for each callout style (used when the author didn't pick one). */
    private const DEFAULT_HINT_ICON = [
        'info'    => 'information-circle',
        'warning' => 'exclamation-triangle',
        'danger'  => 'exclamation-circle',
        'success' => 'light-bulb',
    ];

    /**
     * @param  array<int, string>  $inner
     */
    private function renderHint(string $style, string $icon, array $inner): string
    {
        $callout = match ($style) {
            'warning' => 'warning',
            'danger'  => 'danger',
            'success' => 'tip',
            default   => 'note',
        };

        $default = self::DEFAULT_HINT_ICON[$style] ?? self::DEFAULT_HINT_ICON['info'];
        // Icon chosen by the author, with a defensive fallback to the style's
        // default if the name doesn't exist in the set (no external SVG).
        $svg = ($icon !== '' ? Heroicons::outlineSvg($icon) : null) ?? Heroicons::outlineSvg($default);

        return '<aside data-callout="' . $callout . '">'
            . '<span class="callout-icon" aria-hidden="true">' . $svg . '</span>'
            . '<div class="callout-body">' . $this->renderLines($inner) . '</div>'
            . '</aside>';
    }

    /**
     * Extracts `key="value"` pairs from a GitBook-notation attribute string
     * (free order). E.g.: `style="info" icon="light-bulb"` → `['style'=>…, 'icon'=>…]`.
     *
     * @return array<string, string>
     */
    private function parseAttrs(string $raw): array
    {
        preg_match_all('/(\w+)="([^"]*)"/', $raw, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn (array $pair): array => [$pair[1] => $pair[2]])->all();
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function renderTabs(array $lines): string
    {
        $uid = ++$this->uid;
        $containerId = 'ak-doc-tabs-' . $uid;

        $tabs = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $t = trim($lines[$i]);
            if (preg_match('/^\{%\s*tab\s+title="([^"]*)"\s*%\}$/', $t, $m)) {
                [$inner, $i] = $this->consumeUntil($lines, $i + 1, 'tab');
                $tabs[] = ['title' => $m[1], 'lines' => $inner];

                continue;
            }
            $i++;
        }

        if ($tabs === []) {
            return '';
        }

        $tablist = '';
        $panels = '';
        foreach ($tabs as $idx => $tab) {
            $targetId = 'ak-doc-tab-' . $uid . '-' . $idx;
            $selected = $idx === 0;
            $config = json_encode([
                'targetId'          => $targetId,
                'targetContainerId' => $containerId,
                'activeClasses'     => self::TAB_ACTIVE_CLASSES,
                'inactiveClasses'   => self::TAB_INACTIVE_CLASSES,
                'selectedOnInit'    => $selected,
            ]);

            $state = implode(' ', $selected ? self::TAB_ACTIVE_CLASSES : self::TAB_INACTIVE_CLASSES);

            $tablist .= '<button type="button" role="tab"'
                . ' data-ak-tabs="' . htmlspecialchars($config, ENT_QUOTES) . '"'
                . ' aria-selected="' . ($selected ? 'true' : 'false') . '"'
                . ' tabindex="' . ($selected ? '0' : '-1') . '"'
                . ' class="relative -mb-px shrink-0 rounded-t-md border border-b-0 px-3.5 py-1.5 text-sm font-medium transition-colors ' . $state . '">'
                . e($tab['title'])
                . '</button>';

            $panels .= '<div id="' . $targetId . '"'
                . ' role="tabpanel"'
                . ' class="' . ($selected ? '' : 'hidden ') . 'rounded-b-md border border-t-0 border-line bg-surface px-4 py-3">'
                . $this->renderLines($tab['lines'])
                . '</div>';
        }

        return '<div class="ak-doc-tabs my-6">'
            . '<div role="tablist" class="flex flex-wrap gap-1 border-b border-line">' . $tablist . '</div>'
            . '<div id="' . $containerId . '">' . $panels . '</div>'
            . '</div>';
    }

    /**
     * A CITED drawing: its current picture, its name, and a way to open the
     * canvas in a new tab.
     *
     * Addressed by SLUG rather than id, deliberately. It is what the author
     * picked and what the URL shows, it survives a database reload between
     * environments, and a citation that outlives its diagram then still says
     * something legible instead of `diagram id="41"`.
     *
     * Three states, and all three have to read as deliberate:
     *
     * - **Picture present** — the PNG the canvas posts after every layout save
     *   (`Diagram::DIAGRAM_COLLECTION`), shown inline.
     * - **No picture yet** — the drawing exists but nobody has opened its canvas
     *   since the snapshot feature landed, so there is nothing to show. The card
     *   still renders, with the chain's own summary in place of the image: the
     *   link is the point, and a citation that vanished because a derived file
     *   is missing would read as a broken document.
     * - **Diagram gone** — deleting a drawing must never damage the prose around
     *   the citation, so this degrades to a plain "removido" card.
     */
    private function renderDiagram(string $slug): string
    {
        $diagram = Diagram::where('slug', $slug)->first();

        if (! $diagram) {
            return '<div class="ak-doc-diagram my-4 flex items-center gap-3 rounded-card border border-dashed border-line px-4 py-3 text-sm text-muted">'
                . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757" /></svg>'
                . '<span>Diagrama removido (<code>' . e($slug) . '</code>).</span>'
                . '</div>';
        }

        $picture = $diagram->picture();

        $figure = $picture
            ? '<img src="' . e(route('diagrams.picture.show', $diagram)) . '" alt="' . e($diagram->name) . '"'
                . ' class="w-full rounded-t-card border-b border-line bg-white object-contain">'
            : '<div class="flex items-center gap-2 border-b border-dashed border-line px-4 py-6 text-xs text-muted">'
                . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 15h.008" /></svg>'
                . '<span>Sem imagem ainda — abra o diagrama e salve o layout para gerar uma.</span>'
                . '</div>';

        return '<figure class="ak-doc-diagram my-5 overflow-hidden rounded-card border border-line bg-surface">'
            . $figure
            . '<figcaption class="flex items-center justify-between gap-3 px-4 py-3">'
            . '<span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">' . e($diagram->name) . '</span>'
            // New tab, and `rel` with it: the canvas is a full-screen editor,
            // and losing the page you were reading to reach it is the one thing
            // a citation must not do.
            . ($this->linkDiagrams
                ? '<a href="' . e(route('diagrams.show', $diagram)) . '" target="_blank" rel="noopener"'
                    . ' class="inline-flex shrink-0 items-center gap-1.5 rounded-field border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">'
                    . 'Abrir diagrama'
                    . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>'
                    . '</a>'
                : '')
            . '</figcaption>'
            . '</figure>';
    }

    private function renderFile(string $src): string
    {
        $media = null;
        if (preg_match('#/files/(\d+)#', $src, $m)) {
            $media = Media::find((int) $m[1]);
        }

        $name = $media?->file_name ?? 'Baixar arquivo';
        $size = $media ? $this->humanSize((int) $media->size) : '';

        return '<a href="' . e($src) . '" download'
            . ' class="ak-doc-file my-4 flex items-center gap-3 rounded-field border border-line bg-surface px-4 py-3 no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40">'
            . '<span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent">'
            . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>'
            . '</span>'
            . '<span class="min-w-0"><span class="block truncate text-sm font-medium text-ink">' . e($name) . '</span>'
            . ($size !== '' ? '<span class="block text-xs text-muted">' . e($size) . '</span>' : '')
            . '</span></a>';
    }

    /**
     * Responsive embed (YouTube/Vimeo/Figma). Keep in sync with
     * `embedData()` from resources/js/modules/docs-markdown.js (editor).
     */
    private function renderEmbed(string $url): string
    {
        $data = $this->embedData($url);

        if ($data === null) {
            return '<p><a href="' . e($url) . '" target="_blank" rel="noopener">' . e($url) . '</a></p>';
        }

        return '<div class="ak-embed ak-embed--' . $data['service'] . '">'
            . '<iframe src="' . e($data['embed']) . '" loading="lazy" frameborder="0"'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
            . ' allowfullscreen></iframe></div>';
    }

    /**
     * @return array{service: string, embed: string}|null
     */
    private function embedData(string $url): ?array
    {
        if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([\w-]+)#', $url, $m)) {
            return ['service' => 'youtube', 'embed' => "https://www.youtube.com/embed/{$m[1]}"];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
            return ['service' => 'vimeo', 'embed' => "https://player.vimeo.com/video/{$m[1]}"];
        }
        if (preg_match('#figma\.com/(?:file|proto|design|board)/#', $url)) {
            return ['service' => 'figma', 'embed' => 'https://www.figma.com/embed?embed_host=share&url=' . rawurlencode($url)];
        }

        return null;
    }

    private function commonmark(string $md): string
    {
        if ($this->converter === null) {
            $environment = new Environment([
                'html_input'         => 'allow',
                'allow_unsafe_links' => true,
                // Clickable anchors on headings (H1–H3) — the id becomes the
                // target of /docs#slug; docs-anchors.js copies the link on click.
                'heading_permalink' => [
                    'html_class'        => 'heading-permalink',
                    'id_prefix'         => '',
                    'fragment_prefix'   => '',
                    'insert'            => 'after',
                    'min_heading_level' => 1,
                    'max_heading_level' => 3,
                    'title'             => 'Copiar link para esta seção',
                    'symbol'            => '#',
                    'aria_hidden'       => true,
                ],
            ]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new GithubFlavoredMarkdownExtension);
            $environment->addExtension(new HeadingPermalinkExtension);

            // Every table goes out inside its own horizontal scroller. A table
            // is the one block whose MIN-content width can exceed the width it
            // was given — `width: 100%` does not bind it, because a cell that
            // won't wrap sets a floor the table has to honour — so a wide one
            // painted straight over the "Nesta página" navigator beside it
            // (measured on the SVL/SAP page: 917px of table in a 768px column,
            // 87px of it on top of the rail). `<pre>` never did this: it has
            // carried `overflow-x: auto` all along, and this gives a table the
            // same treatment.
            //
            // Registered at a HIGHER priority than the one
            // GithubFlavoredMarkdownExtension just added (default 0), which is
            // how commonmark is meant to be overridden — decorating the
            // upstream renderer rather than reimplementing table HTML.
            //
            // `tabindex` because a scrollable box that only answers to a mouse
            // is unreachable for anyone who navigates by keyboard, and Chrome
            // (unlike Firefox) does not make one focusable on its own.
            $environment->addRenderer(
                Table::class,
                new HtmlDecorator(new TableRenderer, 'div', ['class' => 'ak-table-scroll', 'tabindex' => '0']),
                10,
            );

            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter->convert($md)->getContent();
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
