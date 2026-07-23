<?php

namespace App\Actions\Flowspec;

use App\Models\FlowspecExample;
use App\Services\Flowspec\FlowspecDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates or updates a curated corpus example (F8) from admin-entered data.
 * `connectors` is always re-derived from the flowSpec (never trusted from the
 * input), matching how the seeder and the old promotion flow built it. The
 * slug is a stable internal key: generated once on create (unique), left
 * untouched on rename so nothing that references it breaks.
 */
class SaveFlowspecExample
{
    /**
     * @param  array{name: string, description: string, tags: list<string>, flow_spec: array<string, mixed>, is_active?: bool}  $data
     */
    public function handle(array $data, ?FlowspecExample $example = null): FlowspecExample
    {
        $attributes = [
            'name'        => $data['name'],
            'description' => $data['description'],
            'tags'        => $data['tags'],
            'flow_spec'   => $data['flow_spec'],
            'connectors'  => FlowspecDocument::from($data['flow_spec'])->connectorNames(),
            'is_active'   => $data['is_active'] ?? true,
        ];

        if ($example !== null) {
            $example->update($attributes);

            return $example;
        }

        return $this->createWithUniqueSlug($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWithUniqueSlug(array $attributes): FlowspecExample
    {
        $baseSlug = Str::slug($attributes['name']);

        // Insert-and-retry on the DB unique index (same approach the promotion
        // flow used): the index is the source of truth, so on a collision
        // (base slug already taken, or a concurrent create) re-suffix and try
        // again instead of a racy exists()-then-insert.
        return retry(
            5,
            fn (int $attempt): FlowspecExample => FlowspecExample::create([
                ...$attributes,
                'slug'   => $attempt === 1 ? $baseSlug : $baseSlug . '-' . Str::lower(Str::random(4)),
                'source' => 'manual',
            ]),
            sleepMilliseconds: 0,
            when: fn (Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }
}
