<?php

namespace App\View\Components\Digibee;

use App\Models\DigibeeLogReport;
use App\Support\Digibee\Monitor\ReportDigest;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A collected report on screen: while it runs, its status and the poll marker;
 * once done, the same digest a chat gets (`ReportDigest`) — by pipeline,
 * levels, ERROR/WARN messages by shape, HTTP calls, a timeline. The file it
 * reads was redacted when it was written.
 */
class ReportBody extends Component
{
    use Renderable;

    public const DOM_ID = 'digibee-report-body';

    public function __construct(public readonly DigibeeLogReport $report) {}

    public static function slot(DigibeeLogReport $report): array
    {
        return (new static($report))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $contents = $this->report->status->settled() ? $this->report->contents() : null;

        return view('components.digibee.report-body', [
            'domId'    => self::DOM_ID,
            'report'   => $this->report,
            'contents' => $contents,
            'digest'   => $contents === null ? null : ReportDigest::of($contents['executions'] ?? [], $this->report->term),
        ]);
    }
}
