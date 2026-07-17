<?php

namespace App\Actions;

use App\Models\FlowspecExample;
use App\Models\FlowspecMessage;
use App\Services\Flowspec\FlowspecDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Promotes an assistant message's generated flowSpec into a curated corpus
 * example (F8, Stage 6) and links the message back to it, so the promotion is
 * idempotent (FlowspecExamplePromotionController refuses a message that already
 * carries `flowspec_example_id`).
 */
class PromoteFlowspecExample
{
    /**
     * @param  list<string>  $tags
     */
    public function handle(FlowspecMessage $message, string $name, string $description, array $tags): FlowspecExample
    {
        $baseSlug = Str::slug($name);

        // Insert-and-retry on the DB unique index instead of exists()-then-
        // insert: the check-then-insert gap races two concurrent promotions of
        // the same name into a unique-constraint violation on
        // flowspec_examples.slug. The index is the source of truth — on a
        // collision (a race, or the base slug simply already taken) re-suffix
        // and try again.
        $example = retry(
            5,
            function (int $attempt) use ($baseSlug, $name, $description, $tags, $message): FlowspecExample {
                return FlowspecExample::create([
                    'name'        => $name,
                    'slug'        => $attempt === 1 ? $baseSlug : $baseSlug . '-' . Str::lower(Str::random(4)),
                    'description' => $description,
                    'tags'        => $tags,
                    'flow_spec'   => $message->flow_spec,
                    'connectors'  => FlowspecDocument::from($message->flow_spec)->connectorNames(),
                    'source'      => 'chat',
                    'is_active'   => true,
                ]);
            },
            sleepMilliseconds: 0,
            when: fn (Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );

        $message->update(['flowspec_example_id' => $example->id]);

        return $example;
    }
}
