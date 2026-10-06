<?php

namespace App\Actions;

use App\Enums\SpreadsheetAudience;
use App\Services\SolutionSpreadsheetService;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The solutions spreadsheet as a file — `.xlsx` or `.csv`.
 *
 * Exports what the screen is SHOWING: `$columns` are the visible columns and
 * `$ids` the rows that survived the filters, in display order. Neither is
 * trusted beyond that — a column the audience may not see is dropped however it
 * was asked for (the magic link never exports contacts), and an id is only ever
 * a row of the catalog.
 */
class ExportSolutionSpreadsheet
{
    /** Separator for a cell holding several values (owners, cadernos). */
    private const LIST_GLUE = ', ';

    public function __construct(private readonly SolutionSpreadsheetService $sheet) {}

    /**
     * @param  list<string>|null  $columns  null exports the columns visible by default
     * @param  list<int>|null  $ids  null exports every row
     */
    public function handle(SpreadsheetAudience $audience, string $format, ?array $columns, ?array $ids, bool $documentation = true): StreamedResponse
    {
        $available = collect($this->sheet->columns($audience, $documentation))->keyBy('key');

        $selected = $columns === null
            ? $available->reject(fn (array $column) => $column['hidden'])->values()
            : collect($columns)->unique()->filter(fn (string $key) => $available->has($key))
                ->map(fn (string $key) => $available->get($key))->values();

        $header = $selected->pluck('label')->all();
        $lines = collect($this->sheet->rows($audience, $ids, $documentation))
            ->map(fn (array $row) => $selected->map(fn (array $column) => $this->value($row['cells'][$column['key']] ?? null, $column['kind']))->all())
            ->all();

        $filename = 'solucoes-' . now()->format('Y-m-d') . '.' . $format;

        return $format === 'csv'
            ? $this->csv($filename, $header, $lines)
            : $this->xlsx($filename, $header, $lines);
    }

    private function value(mixed $cell, string $kind): string|int|null
    {
        return match (true) {
            is_array($cell)                    => implode(self::LIST_GLUE, $cell),
            $kind === 'date' && $cell !== null => CarbonImmutable::parse($cell)->format('d/m/Y'),
            default                            => $cell,
        };
    }

    /**
     * Semicolon-separated with a UTF-8 BOM: what Excel in PT-BR opens straight
     * into columns, accents intact. A comma is the decimal separator there, so
     * a comma-separated file lands as one column of text.
     */
    private function csv(string $filename, array $header, array $lines): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $lines): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ([$header, ...$lines] as $line) {
                fputcsv($out, $line, ';', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(string $filename, array $header, array $lines): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $lines): void {
            $writer = new Writer;
            $writer->openToFile('php://output');

            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Soluções');
            // Header row and the solution's name stay in view while scrolling,
            // like on screen, and the header carries Excel's own filters.
            $sheet->setSheetView(new SheetView(topLeftCell: 'B2', freezeRow: 2, freezeColumn: 'B'));

            if ($header !== []) {
                $sheet->setAutoFilter(new AutoFilter(0, 1, count($header) - 1, count($lines) + 1));
                $sheet->setColumnWidthForRange(24, 1, count($header));
            }

            $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)->withBackgroundColor('E3E9E4')));

            foreach ($lines as $line) {
                $writer->addRow(Row::fromValues($line));
            }

            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
