<?php

namespace App\Support\Cati;

use App\Enums\SubmissionSectionKey;
use App\Models\Submission;
use Illuminate\Support\Str;

/**
 * The questions the committee actually asks, written as rules over data the
 * app already holds.
 *
 * Almost every CATI question is about a DEVIATION — from the target cloud,
 * from the integration standard, from what a system of this criticality is
 * expected to carry. Those are checkable, so the model never has to discover
 * them: it only has to phrase them in the context of this case, which is a
 * job it does well and cheaply. Adding a rule here is adding committee
 * knowledge without adding a token.
 *
 * **A rule fires only when it is specifically interesting.** A keyword rule
 * requires the section to have content already: on a brand-new submission
 * every section is blank, and firing all of them would bury the two that
 * matter under seven that just repeat "nothing is filled in" — which the
 * section checklist (SubmissionRequirements) already says. The rules that DO
 * fire on a blank section are the ones where the blank is surprising given
 * something the catalog knows.
 *
 * `severity` is `high` | `medium` | `low` — plain strings, like
 * DocumentationRequirements' `source`, since nothing outside this file
 * branches on them.
 */
class DeviationRules
{
    /** The corporate target cloud (programa M2C). Anything else needs an argument. */
    private const TARGET_CLOUD = 'gcp';

    private const SENSITIVE_DATA_TERMS = ['dado sensível', 'dados sensíveis', 'dado pessoal', 'dados pessoais', 'lgpd', 'pii', 'anonimiz'];

    private const OBSERVABILITY_TERMS = ['observabilidade', 'logs', 'logging', 'métrica', 'metrica', 'tracing', 'monitora', 'alerta'];

    private const SECURITY_TERMS = ['mtls', 'iam', 'rbac', 'lgpd', 'criptograf', 'autenticação', 'autenticacao', 'certificado', 'firewall'];

    private const CONTINGENCY_TERMS = ['rollback', 'contingência', 'contingencia', 'reversão', 'reversao', 'retry', 'recuperação', 'recuperacao', 'backup'];

    private const PLATFORM_TERMS = ['digibee', 'barramento', 'ipaas', 'api gateway', 'mensageria', 'kafka'];

    private const HIGH_CRITICALITY = ['critical', 'high'];

    /**
     * @return list<array{key: string, section: string, question: string, why: string, severity: string}>
     */
    public static function for(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'solution.vendor', 'solution.integrations']);

        $solution = $submission->solution;
        $content = $submission->sections->mapWithKeys(
            fn ($section) => [$section->key->value => (string) $section->content],
        );

        $text = fn (SubmissionSectionKey $key): string => $content->get($key->value, '');

        $rules = [];

        // ── Deviations from a corporate standard ────────────────────────────
        if ($solution?->cloud !== null && $solution->cloud !== self::TARGET_CLOUD) {
            $rules[] = [
                'key'      => 'cloud_off_target',
                'section'  => SubmissionSectionKey::Standards->value,
                'question' => 'A solução está em ' . mb_strtoupper($solution->cloud) . ', e não na nuvem alvo do programa M2C. Qual a justificativa e por quanto tempo?',
                'why'      => 'Nuvem registrada no catálogo: ' . $solution->cloud,
                'severity' => 'high',
            ];
        }

        if ($solution?->contract_status === 'contracted' && $solution->vendor === null) {
            $rules[] = [
                'key'      => 'vendor_missing',
                'section'  => SubmissionSectionKey::PlanCosts->value,
                'question' => 'O catálogo diz que a solução é contratada, mas não tem fornecedor registrado. Quem é o fornecedor?',
                'why'      => 'contract_status = contracted, sem vendor_company_id',
                'severity' => 'medium',
            ];
        }

        // ── Blanks that are surprising given what the catalog knows ─────────
        $integrations = $solution?->integrations ?? collect();

        if ($integrations->isNotEmpty() && blank($text(SubmissionSectionKey::LegacyImpact))) {
            $rules[] = [
                'key'      => 'legacy_impact_blank',
                'section'  => SubmissionSectionKey::LegacyImpact->value,
                'question' => 'A solução já tem ' . $integrations->count() . ' integração(ões) catalogada(s) (' . $integrations->pluck('name')->implode(', ') . '). Quais delas mudam, e há algo a descomissionar?',
                'why'      => 'Integrações existentes no inventário, sem impacto descrito',
                'severity' => 'high',
            ];
        }

        if (blank($text(SubmissionSectionKey::Alternatives))) {
            $rules[] = [
                'key'      => 'alternatives_blank',
                'section'  => SubmissionSectionKey::Alternatives->value,
                'question' => 'Que outras opções foram consideradas, e por que esta ganhou?',
                // Not mandatory on the form, so the section checklist stays
                // quiet about it — but the committee asks every single time.
                'why'      => 'Seção opcional no formulário, perguntada em toda deliberação',
                'severity' => 'low',
            ];
        }

        // ── Content that is there but doesn't cover what the form asks ──────
        if (filled($text(SubmissionSectionKey::Standards))) {
            $standards = $text(SubmissionSectionKey::Standards);

            if (! Str::contains($standards, self::OBSERVABILITY_TERMS, ignoreCase: true)) {
                $rules[] = [
                    'key'      => 'observability_absent',
                    'section'  => SubmissionSectionKey::Standards->value,
                    'question' => 'Como a solução será observada em produção — logs, métricas e tracing?',
                    'why'      => 'O formulário pede observabilidade em "Padrões Adotados"',
                    'severity' => 'medium',
                ];
            }

            if (! Str::contains($standards, self::SECURITY_TERMS, ignoreCase: true)) {
                $rules[] = [
                    'key'      => 'security_absent',
                    'section'  => SubmissionSectionKey::Standards->value,
                    'question' => 'Quais controles de segurança se aplicam — autenticação, autorização, criptografia em trânsito?',
                    'why'      => 'O formulário pede segurança (mTLS, IAM, RBAC, LGPD) em "Padrões Adotados"',
                    'severity' => 'medium',
                ];
            }
        }

        if ($solution?->environment === 'saas'
            && filled($text(SubmissionSectionKey::DomainsData))
            && ! Str::contains($text(SubmissionSectionKey::DomainsData) . ' ' . $text(SubmissionSectionKey::Standards), self::SENSITIVE_DATA_TERMS, ignoreCase: true)) {
            $rules[] = [
                'key'      => 'sensitive_data_unstated',
                'section'  => SubmissionSectionKey::DomainsData->value,
                'question' => 'Sendo SaaS, que dados saem do ambiente da Leo? Há dado pessoal ou sensível envolvido?',
                'why'      => 'Hospedagem SaaS sem menção a dado sensível/LGPD',
                'severity' => 'high',
            ];
        }

        if (in_array($solution?->criticality, self::HIGH_CRITICALITY, true)
            && filled($text(SubmissionSectionKey::PlanCosts))
            && ! Str::contains($text(SubmissionSectionKey::PlanCosts) . ' ' . $text(SubmissionSectionKey::BenefitsRisks), self::CONTINGENCY_TERMS, ignoreCase: true)) {
            $rules[] = [
                'key'      => 'contingency_absent',
                'section'  => SubmissionSectionKey::PlanCosts->value,
                'question' => 'Numa solução de criticidade ' . $solution->criticality . ', como se volta atrás se a implantação der errado?',
                'why'      => 'Criticidade alta sem plano de contingência/rollback descrito',
                'severity' => 'high',
            ];
        }

        if ($integrations->isNotEmpty()
            && filled($text(SubmissionSectionKey::Architecture))
            && ! Str::contains($text(SubmissionSectionKey::Architecture) . ' ' . $text(SubmissionSectionKey::Standards), self::PLATFORM_TERMS, ignoreCase: true)) {
            $rules[] = [
                'key'      => 'integration_platform_unstated',
                'section'  => SubmissionSectionKey::Architecture->value,
                'question' => 'Por onde passam as integrações — plataforma de integração corporativa, ou ponto a ponto? Se for exceção ao padrão, qual a justificativa?',
                // Deliberately a content check, not a structural one: nothing
                // in the schema records which platform mediates an integration
                // (`Integration.protocol` describes the transport — rest, sftp
                // — not the platform), so there is no field to read.
                'why'      => 'Integrações existentes, sem plataforma de integração citada',
                'severity' => 'medium',
            ];
        }

        return $rules;
    }
}
