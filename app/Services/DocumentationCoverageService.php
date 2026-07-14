<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cobertura de documentação do inventário, medida por **conteúdo real** — uma
 * Solução/Integração está "documentada" quando sua coluna `documentation` não
 * está vazia. Alimenta o hub de Documentação (visão transversal soluções +
 * integrações). Nunca em controller/Blade; contadores em queries agregadas
 * (sem N+1 e sem carregar o longText `documentation`).
 */
class DocumentationCoverageService
{
    /** Expressão SQL "tem documentação" reaproveitada em contadores e na lista. */
    private const HAS_DOCS = "documentation is not null and documentation <> ''";

    /**
     * Contadores globais de cobertura (inventário inteiro, independentes de
     * filtro) para soluções e integrações.
     *
     * @return array{
     *     solutions: array{documented: int, total: int, percent: float},
     *     integrations: array{documented: int, total: int, percent: float},
     * }
     */
    public function counters(): array
    {
        return [
            'solutions'    => $this->countFor(Solution::query()),
            'integrations' => $this->countFor(Integration::query()),
        ];
    }

    /**
     * Lista agrupada por solução: cada solução (com seu status de doc) e as
     * integrações em que participa (cada uma com o seu). Aplica os filtros do
     * hub (busca por nome, tipo de item e status de documentação).
     *
     * Estrutura de cada item:
     *   ['solution' => ['name','slug','url','hasDocs','showStatus'],
     *    'integrations' => [['name','slug','url','hasDocs'], ...]]
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function groups(array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $type = in_array($filters['type'] ?? null, ['solutions', 'integrations'], true) ? $filters['type'] : 'all';
        $status = in_array($filters['status'] ?? null, ['documented', 'pending'], true) ? $filters['status'] : 'all';

        $solutions = Solution::query()
            ->select('id', 'name', 'slug')
            ->selectRaw('(' . self::HAS_DOCS . ') as has_docs')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('integrations', fn (Builder $i) => $i->where('integrations.name', 'like', "%{$search}%"))))
            ->with(['integrations' => fn ($rel) => $rel
                ->select('integrations.id', 'integrations.name', 'integrations.slug')
                ->selectRaw('(integrations.documentation is not null and integrations.documentation <> \'\') as has_docs')
                ->orderBy('integrations.name')])
            ->orderBy('name')
            ->get();

        return $solutions
            ->map(function (Solution $solution) use ($type, $status) {
                $showStatus = $type !== 'integrations';
                $showIntegrations = $type !== 'solutions';

                $integrations = $showIntegrations
                    ? $solution->integrations
                        ->filter(fn (Integration $i) => $this->matchesStatus((bool) $i->has_docs, $status))
                        ->map(fn (Integration $i) => [
                            'name'    => $i->name,
                            'slug'    => $i->slug,
                            'url'     => route('solutions.integrations.docs.edit', [$solution, $i]),
                            'hasDocs' => (bool) $i->has_docs,
                        ])
                        ->values()
                    : collect();

                return [
                    'solution' => [
                        'name'       => $solution->name,
                        'slug'       => $solution->slug,
                        'url'        => route('solutions.docs.edit', $solution),
                        'showUrl'    => route('solutions.show', $solution),
                        'hasDocs'    => (bool) $solution->has_docs,
                        'showStatus' => $showStatus,
                    ],
                    'integrations' => $integrations,
                    // Mantém o grupo visível se a própria solução casa com o
                    // filtro de status (quando aplicável) OU sobrou alguma
                    // integração após o filtro.
                    'keep' => ($showStatus && $this->matchesStatus((bool) $solution->has_docs, $status))
                        || $integrations->isNotEmpty(),
                ];
            })
            ->filter(fn (array $group) => $group['keep'])
            ->map(fn (array $group) => collect($group)->except('keep')->all())
            ->values();
    }

    /** @return array{documented: int, total: int, percent: float} */
    private function countFor(Builder $query): array
    {
        $row = $query
            ->selectRaw('count(*) as total, sum(case when ' . self::HAS_DOCS . ' then 1 else 0 end) as documented')
            ->first();

        $total = (int) $row->total;
        $documented = (int) $row->documented;

        return [
            'documented' => $documented,
            'total'      => $total,
            'percent'    => $total > 0 ? round($documented / $total * 100) : 0.0,
        ];
    }

    private function matchesStatus(bool $hasDocs, string $status): bool
    {
        return match ($status) {
            'documented' => $hasDocs,
            'pending'    => ! $hasDocs,
            default      => true,
        };
    }
}
