<?php

namespace Database\Seeders;

use App\Enums\FlowspecTag;
use App\Models\FlowspecExample;
use App\Services\Flowspec\CredentialScrubber;
use App\Services\Flowspec\FlowspecDocument;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Popula o corpus de exemplos de flowSpec (F8) a partir de
 * `database/data/digibee_flowspec_examples/`: cada entrada do manifesto
 * (`flowspec_examples_manifest.json`) aponta um arquivo `{meta, flowSpec}` e
 * carrega nome/descrição/tags — o corpus cresce adicionando arquivo + entrada
 * no manifesto, sem tocar em código. `connectors` é derivado do flowSpec, e o
 * CredentialScrubber barra qualquer exemplo com segredo literal.
 */
class FlowspecExampleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $directory = database_path('data/digibee_flowspec_examples');

        $manifest = json_decode(
            file_get_contents($directory . '/flowspec_examples_manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $scrubber = new CredentialScrubber;

        foreach ($manifest as $entry) {
            $flowSpec = json_decode(
                file_get_contents($directory . '/' . $entry['file']),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $unknownTags = array_diff($entry['tags'], FlowspecTag::values());

            throw_if($unknownTags !== [], new RuntimeException(
                "Tags fora do vocabulário FlowspecTag em {$entry['file']}: " . implode(', ', $unknownTags),
            ));

            $violations = $scrubber->violations($flowSpec);

            throw_if($violations !== [], new RuntimeException(
                "Credencial literal em {$entry['file']}: " . implode(' | ', $violations),
            ));

            FlowspecExample::updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name'        => $entry['name'],
                    'description' => $entry['description'],
                    'tags'        => $entry['tags'],
                    'flow_spec'   => $flowSpec,
                    'connectors'  => FlowspecDocument::from($flowSpec)->connectorNames(),
                    'source'      => $entry['source'] ?? 'manual',
                    'is_active'   => true,
                ],
            );
        }
    }
}
