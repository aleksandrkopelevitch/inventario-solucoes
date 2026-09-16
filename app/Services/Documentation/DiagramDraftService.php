<?php

namespace App\Services\Documentation;

use App\Exceptions\DiagramDraftFailed;
use App\Models\DocumentationPage;
use App\Support\Documentation\ChainDraft;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Reads a documentation page and proposes the topology it describes.
 *
 * One model call, then at most ONE repair round — bounded on purpose, and
 * narrower than flowSpec's loop because the two are correcting different
 * things. A flowSpec is validated against a platform whose rules the model
 * only half knows, so iterating buys real ground; this IR has six fields and
 * a fixed vocabulary, so a payload still broken after being told exactly
 * what is wrong with it is not going to converge — it is a page the model
 * couldn't read, and saying so beats spending three more calls to say it later.
 *
 * Synchronous, unlike every other model call in this app. The three that are
 * queued are conversations: they carry history, they can run for minutes, and
 * a chat has somewhere to show "gerando…" while it waits. This is one button,
 * one call, one small object, and the person is looking at it — so it answers
 * in the request, with the button's own spinner as the progress indicator and
 * the model timeout as the ceiling. If it ever grows a bigger prompt or a real
 * loop, the thing to move behind a job is this service, unchanged: nothing
 * above it assumes the call is fast, only that it is one call.
 */
class DiagramDraftService
{
    public function __construct(private readonly DiagramDraftPromptBuilder $prompts) {}

    public function draft(DocumentationPage $page): ChainDraft
    {
        if (trim((string) $page->documentation) === '') {
            throw DiagramDraftFailed::emptyPage();
        }

        $raw = $this->prompt($this->prompts->userPrompt($page))->text;
        $payload = $this->extractJson($raw);

        if ($payload === null) {
            throw DiagramDraftFailed::noJson();
        }

        $problems = ChainDraft::validate($payload);

        if ($problems !== []) {
            $retry = $this->prompt($this->prompts->repairPrompt(
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $problems,
            ))->text;

            $repaired = $this->extractJson($retry);

            if ($repaired === null) {
                throw DiagramDraftFailed::invalidDraft($problems);
            }

            $payload = $repaired;
            $problems = ChainDraft::validate($payload);
        }

        if ($problems !== []) {
            throw DiagramDraftFailed::invalidDraft($problems);
        }

        return ChainDraft::fromArray($payload);
    }

    /**
     * The fenced block first, then the widest `{…}` in the text.
     *
     * The second pass is not the heuristic `FlowspecJson` needs — that one has
     * to tell a flowSpec from a reply that merely mentions `{{ }}` syntax,
     * because its prompt teaches that syntax. This prompt teaches none, and
     * asks for JSON and nothing else, so anything brace-shaped in the answer
     * IS the attempt. Decoding to a non-array still rules it out.
     *
     * @return array<mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $candidate = preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match) === 1
            ? $match[1]
            : $this->widestObject($text);

        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function widestObject(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start !== false && $end !== false && $end > $start
            ? substr($text, $start, $end - $start + 1)
            : null;
    }

    /** Protected so tests can substitute the real API call with a test double. */
    protected function prompt(string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            provider: config('services.documentation_ai.provider'),
            model: config('services.documentation_ai.model'),
            timeout: (int) config('services.documentation_ai.diagram_timeout'),
        );
    }
}
