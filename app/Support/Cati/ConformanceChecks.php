<?php

namespace App\Support\Cati;

use App\Enums\ConformanceVerdict;
use App\Enums\SubmissionSectionKey;
use App\Models\Submission;
use Illuminate\Support\Str;

/**
 * Grades a submission against the corporate standards the CATI form asks about
 * — SDLC, diagrams, observability, security, plus the M2C target cloud.
 *
 * This is the "fitness function" idea from Building Evolutionary Architectures,
 * scaled to what this app actually knows: a standard the record can answer for
 * itself is answered before the meeting, so the committee argues only about the
 * exceptions. It is deterministic, free, and correct on every render.
 *
 * **This class is also the single source of truth for the standards signals.**
 * `DeviationRules` used to carry its own copy of these keyword sets and its own
 * idea of what counts as covered; it now derives those questions from the
 * verdicts here, so a question fires exactly when a check is not `Ok` and the
 * two can no longer drift apart.
 *
 * What it deliberately does NOT do is judge quality: it reports whether the
 * submission SAYS something about a standard, which is honest, rather than
 * whether what it says is any good, which it cannot know.
 */
class ConformanceChecks
{
    /** The corporate target cloud (programa M2C). */
    private const TARGET_CLOUD = 'gcp';

    private const OBSERVABILITY_TERMS = ['observabilidade', 'logs', 'logging', 'métrica', 'metrica', 'tracing', 'monitora', 'alerta'];

    private const SECURITY_TERMS = ['mtls', 'iam', 'rbac', 'lgpd', 'criptograf', 'autenticação', 'autenticacao', 'certificado', 'firewall'];

    private const SDLC_TERMS = ['sdlc', 'pipeline', 'ci/cd', 'esteira', 'code review', 'versionamento', 'git'];

    private const PLATFORM_TERMS = ['digibee', 'barramento', 'ipaas', 'api gateway', 'mensageria', 'kafka'];

    private const SENSITIVE_DATA_TERMS = ['dado sensível', 'dados sensíveis', 'dado pessoal', 'dados pessoais', 'lgpd', 'pii', 'anonimiz'];

    private const CONTINGENCY_TERMS = ['rollback', 'contingência', 'contingencia', 'reversão', 'reversao', 'retry', 'recuperação', 'recuperacao', 'backup'];

    private const HIGH_CRITICALITY = ['critical', 'high'];

    /**
     * @return list<array{key: string, label: string, verdict: ConformanceVerdict, detail: string, question: string}>
     */
    public static function for(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'solution.diagrams']);

        $solution = $submission->solution;
        $content = $submission->sections->mapWithKeys(
            fn ($section) => [$section->key->value => (string) $section->content],
        );

        $text = fn (SubmissionSectionKey ...$keys): string => collect($keys)
            ->map(fn (SubmissionSectionKey $key) => $content->get($key->value, ''))
            ->implode(' ');

        $standards = $text(SubmissionSectionKey::Standards);
        $hasDiagrams = ($solution?->diagrams->count() ?? 0) > 0;

        return [
            self::cloudTarget($solution?->cloud),

            self::mentionCheck(
                key: 'sdlc',
                label: 'SDLC',
                haystack: $standards,
                terms: self::SDLC_TERMS,
                stated: 'Processo de desenvolvimento descrito.',
                question: 'Que processo de desenvolvimento a solução segue — esteira, revisão de código, versionamento?',
            ),

            self::integrationPlatform($hasDiagrams, $text(SubmissionSectionKey::Architecture, SubmissionSectionKey::Standards)),

            self::mentionCheck(
                key: 'observability',
                label: 'Observabilidade',
                haystack: $standards,
                terms: self::OBSERVABILITY_TERMS,
                stated: 'Logs, métricas ou tracing mencionados.',
                question: 'Como a solução será observada em produção — logs, métricas e tracing?',
            ),

            self::mentionCheck(
                key: 'security',
                label: 'Segurança',
                haystack: $standards,
                terms: self::SECURITY_TERMS,
                stated: 'Controles de segurança mencionados.',
                question: 'Quais controles de segurança se aplicam — autenticação, autorização, criptografia em trânsito?',
            ),

            self::sensitiveData($solution?->environment, $text(SubmissionSectionKey::DomainsData, SubmissionSectionKey::Standards)),

            self::contingency($solution?->criticality, $text(SubmissionSectionKey::PlanCosts, SubmissionSectionKey::BenefitsRisks)),
        ];
    }

    /** Only the checks the committee has to spend time on. */
    public static function exceptions(Submission $submission): array
    {
        return array_values(array_filter(
            self::for($submission),
            fn (array $check) => $check['verdict']->needsArgument(),
        ));
    }

    /** @return array{key: string, label: string, verdict: ConformanceVerdict, detail: string, question: string} */
    private static function cloudTarget(?string $cloud): array
    {
        [$verdict, $detail] = match (true) {
            $cloud === null               => [ConformanceVerdict::Unknown, 'Nuvem não registrada no catálogo.'],
            $cloud === self::TARGET_CLOUD => [ConformanceVerdict::Ok, 'GCP, a nuvem alvo do programa M2C.'],
            default                       => [ConformanceVerdict::Violation, mb_strtoupper($cloud) . ', fora da nuvem alvo do M2C.'],
        };

        return [
            'key'      => 'cloud_target',
            'label'    => 'Nuvem alvo (M2C)',
            'verdict'  => $verdict,
            'detail'   => $detail,
            'question' => $cloud === null
                ? 'Em qual nuvem a solução roda?'
                : 'A solução está em ' . mb_strtoupper((string) $cloud) . ', e não na nuvem alvo do programa M2C. Qual a justificativa e por quanto tempo?',
        ];
    }

    /** @return array{key: string, label: string, verdict: ConformanceVerdict, detail: string, question: string} */
    private static function integrationPlatform(bool $hasDiagrams, string $haystack): array
    {
        // Nothing in the schema records WHICH platform mediates a diagram
        // (`Diagram.protocol` is the transport — rest, sftp — not the
        // platform), so this can only ever be a content check.
        $verdict = match (true) {
            ! $hasDiagrams                                               => ConformanceVerdict::Ok,
            Str::contains($haystack, self::PLATFORM_TERMS, ignoreCase: true) => ConformanceVerdict::Ok,
            default                                                          => ConformanceVerdict::Attention,
        };

        return [
            'key'     => 'integration_platform',
            'label'   => 'Padrão de integração',
            'verdict' => $verdict,
            'detail'  => match (true) {
                ! $hasDiagrams                  => 'Sem diagramas catalogados.',
                $verdict === ConformanceVerdict::Ok => 'Plataforma de integração citada.',
                default                             => 'Integrações existentes, sem plataforma citada.',
            },
            'question' => 'Por onde passam as integrações — plataforma corporativa ou ponto a ponto? Se for exceção ao padrão, qual a justificativa?',
        ];
    }

    /** @return array{key: string, label: string, verdict: ConformanceVerdict, detail: string, question: string} */
    private static function sensitiveData(?string $environment, string $haystack): array
    {
        $stated = Str::contains($haystack, self::SENSITIVE_DATA_TERMS, ignoreCase: true);

        // Only pressed for a SaaS solution: that is where data leaves the Leo
        // environment, which is the question the committee actually asks.
        $verdict = match (true) {
            $stated                 => ConformanceVerdict::Ok,
            $environment === 'saas' => ConformanceVerdict::Attention,
            $environment === null   => ConformanceVerdict::Unknown,
            default                 => ConformanceVerdict::Ok,
        };

        return [
            'key'      => 'sensitive_data',
            'label'    => 'Dados sensíveis / LGPD',
            'verdict'  => $verdict,
            'detail'   => $stated ? 'Tratamento de dado sensível declarado.' : 'Nada declarado sobre dado pessoal ou sensível.',
            'question' => 'Que dados a solução manipula? Há dado pessoal ou sensível, e onde ele reside?',
        ];
    }

    /** @return array{key: string, label: string, verdict: ConformanceVerdict, detail: string, question: string} */
    private static function contingency(?string $criticality, string $haystack): array
    {
        $stated = Str::contains($haystack, self::CONTINGENCY_TERMS, ignoreCase: true);
        $critical = in_array($criticality, self::HIGH_CRITICALITY, true);

        $verdict = match (true) {
            $stated               => ConformanceVerdict::Ok,
            $critical             => ConformanceVerdict::Attention,
            $criticality === null => ConformanceVerdict::Unknown,
            default               => ConformanceVerdict::Ok,
        };

        return [
            'key'     => 'contingency',
            'label'   => 'Contingência e rollback',
            'verdict' => $verdict,
            'detail'  => $stated
                ? 'Plano de contingência descrito.'
                : ($critical ? 'Criticidade alta, sem plano de contingência.' : 'Não descrito.'),
            'question' => 'Como se volta atrás se a implantação der errado?',
        ];
    }

    /** @return array{key: string, label: string, verdict: ConformanceVerdict, detail: string, question: string} */
    private static function mentionCheck(string $key, string $label, string $haystack, array $terms, string $stated, string $question): array
    {
        // A blank section is "not stated", never a violation: the committee
        // treats silence as a question, not as a breach.
        $found = Str::contains($haystack, $terms, ignoreCase: true);

        return [
            'key'      => $key,
            'label'    => $label,
            'verdict'  => $found ? ConformanceVerdict::Ok : ConformanceVerdict::Attention,
            'detail'   => $found ? $stated : 'Não mencionado em "Padrões Adotados".',
            'question' => $question,
        ];
    }
}
