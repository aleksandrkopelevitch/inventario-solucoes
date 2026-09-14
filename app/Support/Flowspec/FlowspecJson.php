<?php

namespace App\Support\Flowspec;

/**
 * Finding a `{meta, flowSpec}` document inside whatever the model answered.
 *
 * It lives here rather than inside one of the two correction loops because
 * both need it and they must agree: `FlowspecGenerationService` corrects a
 * document against STATIC validation, `PipelineHealingService` corrects it
 * against RUNTIME evidence, and a document one of them can read and the other
 * cannot is a loop that silently stops looping. Every rule below was paid for
 * by the first loop; the second one inherits them instead of re-deriving them.
 */
final class FlowspecJson
{
    /**
     * JSON block inside a code fence (```json ... ```) — the model fenced the
     * JSON deliberately, so a `json_decode` failure here is a real model
     * error, not a misread on our end.
     */
    public static function fencedBlock(string $text): ?string
    {
        return preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match) === 1
            ? $match[1]
            : null;
    }

    /**
     * With no code fence, scans from the first `{` to the last `}` — but only
     * treats it as a flowSpec ATTEMPT if that decodes to an array carrying a
     * `meta` or `flowSpec` key. The system prompt teaches a double-braces
     * syntax (`{{ step.alias.field }}`), so a purely conversational response
     * citing that syntax also contains `{`/`}` — without this filter it would
     * get extracted, fail `json_decode` and burn a correction attempt on a
     * meaningless "fix the JSON".
     *
     * @return array<string, mixed>|null
     */
    public static function heuristicCandidate(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($candidate) && self::isFlowspecShape($candidate)
            ? $candidate
            : null;
    }

    /**
     * A decoded value is a flowSpec document (as opposed to some other JSON
     * fragment the model cited in a conversational answer) only if it carries
     * a `meta` or `flowSpec` key.
     *
     * @param  array<string, mixed>  $decoded
     */
    public static function isFlowspecShape(array $decoded): bool
    {
        return array_key_exists('meta', $decoded) || array_key_exists('flowSpec', $decoded);
    }

    /**
     * Cheap textual check that a malformed fenced block was MEANT to be a
     * flowSpec — used to decide whether a JSON that failed to parse is a
     * broken generation attempt (correct it) or just an illustrative snippet
     * in a conversational answer (ignore it).
     */
    public static function mentionsFlowspecKeys(string $json): bool
    {
        return str_contains($json, '"meta"') || str_contains($json, '"flowSpec"');
    }

    /**
     * The document in an answer, or null — fence first, heuristic after.
     *
     * The healing loop uses this one because its situation is narrower than
     * the generation loop's: it is correcting a document that already exists
     * and is already deployed, so a reply carrying no document is a reply that
     * did not do the job, never a conversational turn to be preserved. Telling
     * a broken fence from an illustrative snippet is the generation loop's
     * problem, and it keeps doing that with the parts above.
     *
     * @return array<string, mixed>|null
     */
    public static function documentIn(string $text): ?array
    {
        $fenced = self::fencedBlock($text);

        if ($fenced !== null) {
            $decoded = json_decode($fenced, true);

            if (is_array($decoded) && self::isFlowspecShape($decoded)) {
                return $decoded;
            }
        }

        return self::heuristicCandidate($text);
    }
}
