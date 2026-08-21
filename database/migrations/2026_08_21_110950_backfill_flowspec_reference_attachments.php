<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carries the pasted pipelines of existing conversations over to the new
 * chat-scoped context.
 *
 * Context used to be per-MESSAGE (`flowspec_messages.meta.reference_flowspec`,
 * plus `solution_ids`/`document_refs`) and is now per-CONVERSATION
 * (`flowspec_attachments`). Without this, every conversation already in the
 * database would silently lose the pipeline its whole thread is about — the
 * user would see their chat suddenly answering as if nothing had been pasted.
 *
 * Two deliberate limits:
 *
 * - Only the MOST RECENT paste per conversation is carried over. A thread where
 *   someone pasted the pipeline five times is one pipeline being iterated on,
 *   not five; attaching all of them would both multiply the token cost of every
 *   future turn and leave the model guessing which one is current.
 * - `meta.solution_ids` is NOT carried over. It never held documentation — it
 *   was the seed for the name-based inference that has been removed on purpose,
 *   so converting it would resurrect exactly the invisible context this change
 *   set out to eliminate. Those conversations get a suggestion instead, the
 *   first time they mention the system by name.
 *
 * `meta.document_refs` is also skipped: it is convertible in principle, but no
 * row in this database ever used it (the chips picker went unused), so a
 * conversion path for it would be untested code guarding nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $latest = [];

        DB::table('flowspec_messages')
            ->where('role', 'user')
            ->whereNotNull('meta')
            ->orderBy('id')
            ->select('id', 'flowspec_chat_id', 'meta')
            ->each(function (object $message) use (&$latest) {
                $meta = json_decode((string) $message->meta, true);
                $reference = is_array($meta) ? ($meta['reference_flowspec'] ?? null) : null;

                // Later rows overwrite earlier ones, so what survives the walk is
                // each conversation's most recent paste.
                if (is_string($reference) && trim($reference) !== '') {
                    $latest[$message->flowspec_chat_id] = trim($reference);
                }
            });

        foreach ($latest as $chatId => $reference) {
            DB::table('flowspec_attachments')->insert([
                'flowspec_chat_id'      => $chatId,
                'kind'                  => 'text',
                'label'                 => 'flowSpec de referência',
                'content'               => $reference,
                'extraction_state'      => 'done',
                'is_flowspec_reference' => true,
                // chars / 3.5, matching App\Support\Context\TokenEstimator.
                // Inlined rather than called: a migration that has already run
                // everywhere must not start failing if that class is moved.
                'token_estimate' => (int) ceil(mb_strlen($reference) / 3.5),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only what this migration could have created. The messages it read from
        // are untouched, so re-running `up()` reproduces the same rows.
        DB::table('flowspec_attachments')
            ->where('kind', 'text')
            ->where('is_flowspec_reference', true)
            ->where('label', 'flowSpec de referência')
            ->delete();
    }
};
