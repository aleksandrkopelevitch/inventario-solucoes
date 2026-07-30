<?php

namespace App\Actions\Flowspec;

use App\Models\FlowspecGuideline;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates or updates a guideline document (F8) from admin-entered data. The
 * slug is a stable internal key: generated once on create (unique), left
 * untouched on rename so nothing that references it breaks.
 */
class SaveFlowspecGuideline
{
    /**
     * @param  array{title: string, content: string, is_active?: bool}  $data
     */
    public function handle(array $data, ?FlowspecGuideline $guideline = null): FlowspecGuideline
    {
        $attributes = [
            'title'     => $data['title'],
            'content'   => $data['content'],
            'is_active' => $data['is_active'] ?? true,
        ];

        if ($guideline !== null) {
            $guideline->update($attributes);

            return $guideline;
        }

        return $this->createWithUniqueSlug($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWithUniqueSlug(array $attributes): FlowspecGuideline
    {
        $baseSlug = Str::slug($attributes['title']);

        return retry(
            5,
            fn (int $attempt): FlowspecGuideline => FlowspecGuideline::create([
                ...$attributes,
                'slug'   => $attempt === 1 ? $baseSlug : $baseSlug . '-' . Str::lower(Str::random(4)),
                'source' => 'manual',
            ]),
            sleepMilliseconds: 0,
            when: fn (Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }
}
