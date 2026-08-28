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
 * Populates the flowSpec examples corpus (F8) from
 * `database/data/digibee_flowspec_examples/`: each manifest entry
 * (`flowspec_examples_manifest.json`) points to a `{meta, flowSpec}` file and
 * carries name/description/tags — the corpus grows by adding a file + manifest
 * entry, without touching code. `connectors` is derived from the flowSpec, and
 * CredentialScrubber blocks any example with a literal secret.
 *
 * It also SHRINKS by removing one: an example this seeder owns (`seeded_at`)
 * whose slug the manifest no longer names is deactivated — see
 * deactivateExamplesDroppedFromManifest(). Runs on every deploy
 * (Envoy.blade.php's `artisan` task), so the table follows the git tree in
 * both directions.
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
                "Tags outside the FlowspecTag vocabulary in {$entry['file']}: " . implode(', ', $unknownTags),
            ));

            $violations = $scrubber->violations($flowSpec);

            throw_if($violations !== [], new RuntimeException(
                "Literal credential in {$entry['file']}: " . implode(' | ', $violations),
            ));

            $example = FlowspecExample::updateOrCreate(
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

            // force-filled because `seeded_at` is deliberately not fillable —
            // it is this seeder's claim of ownership, not anybody's input.
            $example->forceFill(['seeded_at' => now()])->save();
        }

        $this->deactivateExamplesDroppedFromManifest(array_column($manifest, 'slug'));
    }

    /**
     * Retires the examples this seeder used to own and no longer does.
     *
     * `updateOrCreate` alone only ever adds and updates, so an example deleted
     * from the manifest survived in every environment it had ever been seeded
     * into — still `is_active`, still eligible for selection into a prompt.
     * That mattered more once the deploy started seeding on every run: nothing
     * would ever contradict the stale row again.
     *
     * Scoped by `seeded_at`, so only rows this seeder created are ever touched;
     * an example an admin wrote in the app has no marker and is left alone even
     * though its slug is not in the manifest either. Deactivated rather than
     * deleted — a curated flowSpec is worth keeping, and returning a slug to
     * the manifest revives it, since the upsert above always writes
     * `is_active => true`.
     *
     * @param  list<string>  $manifestSlugs
     */
    private function deactivateExamplesDroppedFromManifest(array $manifestSlugs): void
    {
        $retired = FlowspecExample::query()
            ->whereNotNull('seeded_at')
            ->whereNotIn('slug', $manifestSlugs)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        if ($retired > 0) {
            $this->command?->warn("Deactivated {$retired} example(s) no longer in the manifest.");
        }
    }
}
