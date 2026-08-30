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
    /** Default name for a pasted pipeline — see disambiguate() for the rest of it. */
    public const FLOWSPEC_LABEL = 'flowSpec de referência';

    public function __construct(
        private readonly NormalizeReferenceFlowspec $normalize,
        private readonly SensitiveTextScanner $scanner,
    ) {}

    public function handle(FlowspecChat $chat, string $text, ?string $label = null): FlowspecAttachment
    {
        $text = trim($text);
        $isFlowspec = NormalizeReferenceFlowspec::looksLike($text);
        $content = $isFlowspec ? $this->normalize->handle($text) : $text;

        return $chat->attachments()->create([
            'kind'                  => FlowspecAttachmentKind::Text,
            'label'                 => $this->disambiguate($chat, $isFlowspec ? self::FLOWSPEC_LABEL : $this->label($label, $text)),
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
     * Keeps a name unique WITHIN the conversation by appending an ordinal.
     *
     * A pasted pipeline has no name to read: the document is `{meta, flowSpec}`
     * and neither half carries one (`meta` is the canvas position map, which
     * NormalizeReferenceFlowspec strips anyway), so every one of them defaulted
     * to the same constant and a chat with three of them showed three identical
     * pills — and, since the label is now the prompt heading, three identical
     * headings. Rather than invent a name out of a branch key, they are made
     * merely TELLABLE APART here and named properly by the person, who is the
     * only one who knows which is which (flowspec.attachments.update).
     *
     * The first keeps the bare name, so nothing is numbered until there is
     * something to distinguish it from.
     */
    private function disambiguate(FlowspecChat $chat, string $base): string
    {
        $taken = $chat->attachments()->pluck('label')->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $ordinal = 2;

        while (in_array("{$base} {$ordinal}", $taken, true)) {
            $ordinal++;
        }

        return "{$base} {$ordinal}";
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
}
