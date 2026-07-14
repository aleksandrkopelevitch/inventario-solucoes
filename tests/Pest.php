<?php

use App\Models\Integration;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full application (via Tests\TestCase, which resolves
| the app from bootstrap/app.php). Unit tests stay framework-free unless they
| opt into the base case explicitly.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Anexa participantes a uma integração: [[Solution, position], ...]. Espelha
 * o que `SyncIntegrationFromChain` garante em produção — participants nunca
 * existem sem uma `chain` condizente — montando uma chain linear (na ordem
 * das posições dadas) quando o teste não configurou uma explicitamente.
 * Testes que precisam de uma integração genuinamente sem `chain` (para
 * exercitar esse caso-limite) devem anexar via `$integration->participants()`
 * diretamente, sem passar por este helper.
 */
function attachParticipants(Integration $integration, array $participants): void
{
    foreach ($participants as [$solution, $position]) {
        $integration->participants()->attach($solution->id, ['position' => $position]);
    }

    if ($integration->chain) {
        return;
    }

    $sorted = collect($participants)->sortBy(fn ($p) => $p[1])->values();

    $integration->chain = [
        'nodes' => $sorted->map(fn ($p) => ['solution_id' => $p[0]->id, 'label' => null])->all(),
        'edges' => $sorted->count() > 1
            ? collect(range(0, $sorted->count() - 2))
                ->map(fn ($i) => ['from' => $i, 'to' => $i + 1, 'arrow' => '->', 'protocol' => null])
                ->all()
            : [],
    ];
    $integration->save();
}
