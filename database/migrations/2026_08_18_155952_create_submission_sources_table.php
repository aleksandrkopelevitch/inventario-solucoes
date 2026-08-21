<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gathered material behind a submission: an uploaded file (media in the
 * `submission_sources` collection), a link, or a reference to something already
 * in the inventory (Solution / Integration / DocumentationPage).
 *
 * `extracted_text` holds the server-side extraction for the formats that are
 * really zipped XML (`.pptx`, `.docx`) or plain text — a previous CATI deck
 * becomes usable context without any model call. PDFs and images are NOT
 * extracted here: they go to the model as native attachments, so they are
 * stored with `extraction_state = skipped` and a note, never as a failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // upload | link | inventory
            $table->string('label');
            $table->string('url')->nullable();
            // Spatie's media row for an upload. nullOnDelete so deleting the
            // file leaves the source row (and its extracted text) auditable
            // instead of silently vanishing from the submission's trail.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->nullableMorphs('reference');
            $table->longText('extracted_text')->nullable();
            $table->string('extraction_state')->default('pending');
            $table->string('extraction_note')->nullable();
            // Likely credentials spotted in the extracted text. Flagged, never
            // removed — see App\Support\Context\SensitiveTextScanner.
            $table->json('sensitive_findings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_sources');
    }
};
