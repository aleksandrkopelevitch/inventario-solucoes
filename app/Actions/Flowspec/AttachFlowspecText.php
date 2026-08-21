<?php

namespace App\Actions\Flowspec;

use App\Enums\ContextExtractionState;
use App\Enums\FlowspecAttachmentKind;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Support\Context\SensitiveTextScanner;
use App\Support\Context\TokenEstimator;
use Illuminate\Support\Str;

/**
 * Turns a long paste into a context attachment — the behavior the Claude client
 * has: text too big for the composer stops being a message and becomes
 * material the conversation carries.
 *
 * A pasted `{meta, flowSpec}` document is recognized here rather than being
 * offered as its own attachment type. That is the whole reason the standalone
 * "flowSpec de referência" editor could be removed: pasting a pipeline still
 * gets it minified (NormalizeReferenceFlowspec drops the canvas positions) and
 * still gets its own prompt section, but the user only ever performs one
 * gesture — paste — and never has to know which of three slots it belonged in.
 */
class AttachFlowspecText
{
    public function __construct(
        private readonly NormalizeReferenceFlowspec $normalize,
        private readonly SensitiveTextScanner $scanner,
    ) {}

    public function handle(FlowspecChat $chat, string $text, ?string $label = null): FlowspecAttachment
    {
        $text = trim($text);
        $isFlowspec = $this->looksLikeFlowspec($text);
        $content = $isFlowspec ? $this->normalize->handle($text) : $text;

        return $chat->attachments()->create([
            'kind'                  => FlowspecAttachmentKind::Text,
            'label'                 => $isFlowspec ? 'flowSpec de referência' : $this->label($label, $text),
            'content'               => $content,
            'extraction_state'      => ContextExtractionState::Done,
            'is_flowspec_reference' => $isFlowspec,
            // A pipeline JSON is machine output: scanning it for credentials is
            // the validator's job downstream (CredentialScrubber works on the
            // structured document, not on free text), and every `{{ account.* }}`
            // reference in it would otherwise read as a finding.
            'sensitive_findings' => $isFlowspec ? null : ($this->scanner->scan($content) ?: null),
            'token_estimate'     => TokenEstimator::forText($content),
        ]);
    }

    /**
     * Names the attachment after its own first line, the way a pasted snippet
     * gets a recognizable label instead of "Texto colado 3" — someone who
     * pasted four things needs to tell them apart.
     */
    private function label(?string $label, string $text): string
    {
        if (filled($label)) {
            return Str::limit(trim($label), 80);
        }

        $firstLine = trim((string) Str::before($text, "\n"));

        return $firstLine === '' ? 'Texto colado' : Str::limit($firstLine, 60);
    }

    /**
     * A Digibee pipeline document, as opposed to any other pasted JSON. Same
     * shape test FlowspecGenerationService uses on the model's own output: a
     * `meta` or `flowSpec` key at the top level.
     */
    private function looksLikeFlowspec(string $text): bool
    {
        if ($text === '' || ! in_array($text[0], ['{', '['], true)) {
            return false;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded)
            && (array_key_exists('meta', $decoded) || array_key_exists('flowSpec', $decoded));
    }
}
