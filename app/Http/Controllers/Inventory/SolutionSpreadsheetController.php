<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\ExportSolutionSpreadsheet;
use App\Enums\SpreadsheetAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportSolutionSpreadsheetRequest;
use App\Models\Notebook;
use App\Models\PublicLink;
use App\Models\Solution;
use App\Services\SolutionSpreadsheetService;
use App\View\Components\Solutions\SpreadsheetSharePanel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The catalog as a read-only spreadsheet, for the inventory's own readers —
 * plus its magic link, generated and revoked here like a caderno's.
 *
 * The magic link's side lives in `PublicSolutionSpreadsheetController`; the
 * screen and the file are the same for both (`SolutionSpreadsheetService`).
 */
class SolutionSpreadsheetController extends Controller
{
    public function index(SolutionSpreadsheetService $sheet): View
    {
        $this->authorize('viewAny', Solution::class);

        return view('solutions.spreadsheet', [
            'sheet' => $sheet->payload(SpreadsheetAudience::Internal, $this->seesDocumentation()),
        ]);
    }

    public function export(ExportSolutionSpreadsheetRequest $request, ExportSolutionSpreadsheet $export): StreamedResponse
    {
        $this->authorize('viewAny', Solution::class);

        return $export->handle(
            SpreadsheetAudience::Internal,
            $request->validated('format'),
            $request->columns(),
            $request->ids(),
            $this->seesDocumentation(),
        );
    }

    /**
     * Whether the cadernos/diagrams columns belong on this reader's sheet —
     * they are the documentation module's (SolutionSpreadsheetService).
     */
    private function seesDocumentation(): bool
    {
        return auth()->user()->can('viewAny', Notebook::class);
    }

    /** Generates the magic link (or keeps the one that exists) — see `PublicLink`. */
    public function share(): JsonResponse
    {
        $this->authorize('share', Solution::class);

        PublicLink::generate(PublicLink::SOLUTIONS_SPREADSHEET, auth()->user());

        return response()->json([
            'type'           => 'success',
            'message'        => 'Link público gerado.',
            'updatableSlots' => [SpreadsheetSharePanel::slot()],
        ]);
    }

    /** Revokes the magic link — the old URL stops resolving at once. */
    public function unshare(): JsonResponse
    {
        $this->authorize('share', Solution::class);

        PublicLink::revoke(PublicLink::SOLUTIONS_SPREADSHEET);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Acesso público revogado.',
            'updatableSlots' => [SpreadsheetSharePanel::slot()],
        ]);
    }
}
