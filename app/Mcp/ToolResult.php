<?php

namespace App\Mcp;

/**
 * What a tool hands back — one or more content blocks, plus whether the call
 * failed.
 *
 * **A failure is a RESULT, not a JSON-RPC error.** That distinction is the
 * spec's and it matters in practice: a protocol error is invisible to the model
 * (the client handles it and the turn dies), while an `isError` result is
 * handed to the model as text it can read and act on. "Nenhuma solução com esse
 * slug" is something the model should recover from by listing solutions — so it
 * has to arrive where the model can see it. A JSON-RPC error is reserved for
 * things the model cannot fix: an unknown method, a malformed envelope.
 *
 * Most tools answer with `json()`, because a uniform shape is what lets a model
 * chain calls without re-learning each tool's prose. The exception is a
 * documentation page: its body is Markdown, and Markdown escaped into a JSON
 * string is both bigger and harder to read than Markdown in a block of its own,
 * so `page()` sends the metadata as JSON and the text as text.
 */
final class ToolResult
{
    /** @param list<array<string, mixed>> $content */
    private function __construct(
        public readonly array $content,
        public readonly bool $isError = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function json(array $data): self
    {
        return new self([self::textBlock(self::encode($data))]);
    }

    public static function text(string $text): self
    {
        return new self([self::textBlock($text)]);
    }

    /**
     * Metadata as JSON, then the body as itself.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function document(array $meta, string $body): self
    {
        return new self([
            self::textBlock(self::encode($meta)),
            self::textBlock($body),
        ]);
    }

    public static function failure(string $message): self
    {
        return new self([self::textBlock($message)], isError: true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['content' => $this->content, 'isError' => $this->isError];
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        // Unescaped unicode and slashes: the catalog is full of accented names
        // and of URLs, and `ç` / `\/` cost tokens while reading worse.
        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, mixed> */
    private static function textBlock(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }
}
