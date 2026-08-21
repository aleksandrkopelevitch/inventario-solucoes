<?php

namespace Database\Factories;

use App\Enums\ContextExtractionState;
use App\Enums\FlowspecAttachmentKind;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Support\Context\TokenEstimator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowspecAttachment>
 */
class FlowspecAttachmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $content = 'Contrato REST: POST /colaboradores com Bearer token de 30 min.';

        return [
            'flowspec_chat_id'      => FlowspecChat::factory(),
            'kind'                  => FlowspecAttachmentKind::Text,
            'label'                 => 'Texto colado',
            'content'               => $content,
            'extraction_state'      => ContextExtractionState::Done,
            'extraction_note'       => null,
            'is_flowspec_reference' => false,
            'token_estimate'        => TokenEstimator::forText($content),
        ];
    }

    /** A reference to documentation already in the inventory — read live, never copied. */
    public function document(?DocumentationPage $page = null): static
    {
        return $this->state(function () use ($page) {
            $page ??= DocumentationPage::factory()->create();

            return [
                'kind'           => FlowspecAttachmentKind::Document,
                'label'          => $page->title,
                'reference_type' => $page->getMorphClass(),
                'reference_id'   => $page->getKey(),
                'content'        => null,
                'token_estimate' => 0,
            ];
        });
    }

    /** An uploaded text file whose content was extracted at upload time. */
    public function file(string $label = 'contrato.md'): static
    {
        return $this->state(fn (array $attributes) => [
            'kind'  => FlowspecAttachmentKind::File,
            'label' => $label,
        ]);
    }

    /** A PDF/image: not a failure — it rides along as a native attachment instead. */
    public function nativeAttachment(string $label = 'arquitetura.pdf'): static
    {
        return $this->state(fn () => [
            'kind'             => FlowspecAttachmentKind::File,
            'label'            => $label,
            'content'          => null,
            'extraction_state' => ContextExtractionState::Skipped,
            'extraction_note'  => 'Vai como anexo nativo para o modelo, sem extração de texto.',
        ]);
    }

    /** A pasted `{meta, flowSpec}` document — the old reference-flowSpec editor, as a text attachment. */
    public function flowspecReference(string $json = '{"meta":{},"flowSpec":{}}'): static
    {
        return $this->state(fn () => [
            'kind'                  => FlowspecAttachmentKind::Text,
            'label'                 => 'flowSpec de referência',
            'content'               => $json,
            'is_flowspec_reference' => true,
            'token_estimate'        => TokenEstimator::forText($json),
        ]);
    }
}
