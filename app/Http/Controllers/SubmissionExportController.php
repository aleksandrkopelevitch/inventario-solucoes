<?php

namespace App\Http\Controllers;

use App\Actions\Cati\RenderSubmissionMarkdown;
use App\Actions\Cati\RenderTicketText;
use App\Models\Submission;
use Illuminate\Http\Response;

/**
 * The two Fase 1 outputs. Both are plain text, both are rendered from the same
 * record — the point being that nothing is retyped between the ticket and the
 * document.
 */
class SubmissionExportController extends Controller
{
    public function markdown(Submission $submission, RenderSubmissionMarkdown $render): Response
    {
        $this->authorize('view', $submission);

        return $this->download($render->handle($submission), "{$submission->slug}.md");
    }

    public function ticket(Submission $submission, RenderTicketText $render): Response
    {
        $this->authorize('view', $submission);

        return $this->download($render->handle($submission), "{$submission->slug}-chamado.md");
    }

    private function download(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type'        => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
