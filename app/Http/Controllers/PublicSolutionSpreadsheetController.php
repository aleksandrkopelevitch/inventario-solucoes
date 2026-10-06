<?php

namespace App\Http\Controllers;

use App\Actions\ExportSolutionSpreadsheet;
use App\Enums\SpreadsheetAudience;
use App\Http\Requests\ExportSolutionSpreadsheetRequest;
use App\Models\PublicLink;
use App\Services\SolutionSpreadsheetService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The solutions spreadsheet on its magic link — no auth, the token in the URL
 * is the whole authorization, exactly like `PublicDocumentationController`.
 *
 * The same screen as the inventory's (`x-solutions.spreadsheet`), built for the
 * `Shared` audience: no contacts column, and solution names that do not link
 * into an inventory a visitor cannot open. A signed-in admin who opens the link
 * is a visitor here too — what a shared link shows must not depend on who is
 * looking at it.
 */
class PublicSolutionSpreadsheetController extends Controller
{
    public function show(string $token, SolutionSpreadsheetService $sheet): View
    {
        PublicLink::resolve(PublicLink::SOLUTIONS_SPREADSHEET, $token);

        return view('public.solutions-spreadsheet', [
            'sheet' => $sheet->payload(SpreadsheetAudience::Shared),
            'token' => $token,
        ]);
    }

    public function export(ExportSolutionSpreadsheetRequest $request, string $token, ExportSolutionSpreadsheet $export): StreamedResponse
    {
        PublicLink::resolve(PublicLink::SOLUTIONS_SPREADSHEET, $token);

        return $export->handle(
            SpreadsheetAudience::Shared,
            $request->validated('format'),
            $request->columns(),
            $request->ids(),
        );
    }
}
