<?php

namespace App\Support\Documentation;

/**
 * Pulls the JSON object out of a reply that was asked for nothing else.
 *
 * Shared by the two generators that ask a model for a typed object — the chain
 * draft and the Archify artifact. It is deliberately NOT `FlowspecJson`, which
 * has to tell a flowSpec from a reply that merely MENTIONS `{{ }}` syntax,
 * because its prompt teaches that syntax. Neither prompt here teaches any, and
 * both ask for JSON and nothing else, so anything brace-shaped in the answer is
 * the attempt; decoding to a non-array is what rules it out.
 */
final class ModelJson
{
    /**
     * The fenced block first, then the widest `{…}` in the text.
     *
     * @return array<mixed>|null
     */
    public static function extract(string $text): ?array
    {
        $candidate = preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match) === 1
            ? $match[1]
            : self::widestObject($text);

        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Pretty, for handing a payload back to the model in a repair round. */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function widestObject(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start !== false && $end !== false && $end > $start
            ? substr($text, $start, $end - $start + 1)
            : null;
    }
}
