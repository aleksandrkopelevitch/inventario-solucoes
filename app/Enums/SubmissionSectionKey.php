<?php

namespace App\Enums;

/**
 * The sections of a CATI submission — and the reason this module exists.
 *
 * The Leo Resolve form asks seven questions; the presentation template asks
 * for eleven slides' worth of content. They are the same material projected
 * twice, which is why an architect writes everything twice today and the two
 * copies diverge on the first correction. This enum is the ONE list: the six
 * mandatory form sections, plus Alternativas (asked but not mandatory), plus
 * the four the deck needs and the form never mentions.
 *
 * `cases()` order is DOCUMENT order (the deck's narrative). The ticket has its
 * own order, given by `ticketOrder()` — use `ticketOrdered()` when rendering
 * the Leo Resolve text, never `cases()`.
 */
enum SubmissionSectionKey: string
{
    case Summary = 'summary';
    case CurrentState = 'current_state';
    case Objectives = 'objectives';
    case DomainsData = 'domains_data';
    case Architecture = 'architecture';
    case OperatingModel = 'operating_model';
    case BenefitsRisks = 'benefits_risks';
    case LegacyImpact = 'legacy_impact';
    case Standards = 'standards';
    case PlanCosts = 'plan_costs';
    case Alternatives = 'alternatives';

    public function label(): string
    {
        return match ($this) {
            self::Summary        => 'Resumo da proposta',
            self::CurrentState   => 'Cenário atual',
            self::Objectives     => 'Objetivos',
            self::DomainsData    => 'Domínios e dados',
            self::Architecture   => 'Arquitetura de solução',
            self::OperatingModel => 'Modelo de operação',
            self::BenefitsRisks  => 'Benefícios e riscos',
            self::LegacyImpact   => 'Impactos em integrações e legados',
            self::Standards      => 'Padrões adotados',
            self::PlanCosts      => 'Plano de implementação e custos',
            self::Alternatives   => 'Alternativas avaliadas',
        };
    }

    /**
     * Heading this section carries in the Leo Resolve ticket, verbatim — null
     * for the four that exist only because the deck template asks for them.
     * Kept exactly as the form words it (accents and all): it is what the
     * committee reads, and `RenderTicketText` prints it unchanged.
     */
    public function ticketHeading(): ?string
    {
        return match ($this) {
            self::Summary       => 'Resumo da Proposta',
            self::Architecture  => 'Arquitetura de Solução',
            self::BenefitsRisks => 'Benefícios e Riscos',
            self::LegacyImpact  => 'Impactos em Integrações e Sistemas Legados',
            self::Standards     => 'Padrões Adotados',
            self::PlanCosts     => 'Plano de Implementação e Custos Estimados',
            self::Alternatives  => 'Alternativas Avaliadas',
            default             => null,
        };
    }

    /** Position in the Leo Resolve form (1-7); null for the deck-only sections. */
    public function ticketOrder(): ?int
    {
        return match ($this) {
            self::Summary       => 1,
            self::Architecture  => 2,
            self::BenefitsRisks => 3,
            self::LegacyImpact  => 4,
            self::Standards     => 5,
            self::PlanCosts     => 6,
            self::Alternatives  => 7,
            default             => null,
        };
    }

    /**
     * Required by the committee — the six the form marks **Obrigatório**.
     * Alternativas is on the ticket but optional, so it is NOT mandatory here;
     * `ticketHeading() !== null` and `mandatory()` are deliberately different
     * questions and the checklist reads both.
     */
    public function mandatory(): bool
    {
        return match ($this) {
            self::Summary,
            self::Architecture,
            self::BenefitsRisks,
            self::LegacyImpact,
            self::Standards,
            self::PlanCosts => true,
            default         => false,
        };
    }

    /** Only the deck asks for it — it never reaches the ticket. */
    public function deckOnly(): bool
    {
        return $this->ticketHeading() === null;
    }

    /**
     * The seed question for the interview: what to ask when nothing better is
     * available. It is a FALLBACK, not the script — the assistant is expected
     * to rewrite it around what the inventory already answered and what the
     * attached material already says. Anything derivable from the catalog
     * (App\Support\Cati\SubmissionRequirements) must never be asked at all.
     */
    public function question(): string
    {
        return match ($this) {
            self::Summary        => 'Em duas ou três frases: o que será feito, que problema resolve e qual o resultado esperado?',
            self::CurrentState   => 'Como isso funciona hoje? Se já existe, o que exatamente muda?',
            self::Objectives     => 'Quais são os objetivos principais, do ponto de vista da área e da companhia?',
            self::DomainsData    => 'Que domínios de negócio a solução envolve, quais dados ela lê e grava, de onde vêm esses dados e há dado sensível envolvido?',
            self::Architecture   => 'Como a solução é composta? Quais componentes, onde rodam e como conversam entre si?',
            self::OperatingModel => 'Quem opera cada camada depois de pronto — fornecedor, infraestrutura, segurança, operação?',
            self::BenefitsRisks  => 'Quais os benefícios e os riscos, separados em financeiro, técnico e compliance?',
            self::LegacyImpact   => 'Que sistemas e integrações são afetados? Há algo a descomissionar?',
            self::Standards      => 'A proposta segue os padrões corporativos de SDLC, integrações, observabilidade e segurança? Onde não segue, por quê?',
            self::PlanCosts      => 'Quais as fases de implementação, com prazo, e quais os custos previstos de licenciamento, infraestrutura e operação?',
            self::Alternatives   => 'Que outras soluções foram consideradas e qual foi o critério de escolha?',
        };
    }

    /**
     * The sections that go into the Leo Resolve ticket, in the form's order.
     *
     * @return array<int, self>
     */
    public static function ticketOrdered(): array
    {
        $sections = array_filter(self::cases(), fn (self $key) => $key->ticketOrder() !== null);
        usort($sections, fn (self $a, self $b) => $a->ticketOrder() <=> $b->ticketOrder());

        return array_values($sections);
    }

    /**
     * The mandatory sections — what the checklist blocks on.
     *
     * @return array<int, self>
     */
    public static function mandatoryCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $key) => $key->mandatory()));
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $key) => ['value' => $key->value, 'label' => $key->label()],
            self::cases(),
        );
    }
}
