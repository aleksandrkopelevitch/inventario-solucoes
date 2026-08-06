<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
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
    private ?MarkdownConverter $converter = null;

    /** Counter for unique tab-block ids within the same render. */
    private int $uid = 0;

    public function render(?string $markdown): string
    {
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
                'activeClasses'     => ['bg-surface', 'text-ink', 'border-line', 'border-b-surface'],
                'inactiveClasses'   => ['text-muted', 'border-transparent', 'hover:text-ink'],
                'selectedOnInit'    => $selected,
            ]);

            $tablist .= '<button type="button" role="tab"'
                . ' data-ak-tabs="' . htmlspecialchars($config, ENT_QUOTES) . '"'
                . ' aria-selected="' . ($selected ? 'true' : 'false') . '"'
                . ' tabindex="' . ($selected ? '0' : '-1') . '"'
                . ' class="-mb-px shrink-0 rounded-t-md border border-b-0 px-3.5 py-1.5 text-sm font-medium transition-colors ' . ($selected ? 'bg-surface text-ink border-line border-b-surface' : 'text-muted border-transparent hover:text-ink') . '">'
                . e($tab['title'])
                . '</button>';

            $panels .= '<div id="' . $targetId . '"'
                . ' role="tabpanel"'
                . ' class="' . ($selected ? '' : 'hidden ') . 'rounded-b-md rounded-tr-md border border-line bg-surface px-4 py-3">'
                . $this->renderLines($tab['lines'])
                . '</div>';
        }

        return '<div class="ak-doc-tabs my-6">'
            . '<div role="tablist" class="flex flex-wrap gap-1 border-b border-line">' . $tablist . '</div>'
            . '<div id="' . $containerId . '">' . $panels . '</div>'
            . '</div>';
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
