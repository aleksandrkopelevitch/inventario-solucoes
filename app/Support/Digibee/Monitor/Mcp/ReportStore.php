<?php

namespace App\Support\Digibee\Monitor\Mcp;

use App\Support\Digibee\Monitor\LogQuery;

/**
 * Where a collected report is kept and found again by the name the model was
 * given: a file on the workstation (`FileReportStore`), a row plus a private
 * file on the iSol server (`App\Support\Digibee\Monitor\ServerReportStore`).
 * What goes in is already redacted.
 */
interface ReportStore
{
    /** Keeps the (redacted) report and answers the name to query it by. */
    public function save(string $redactedJson, LogQuery $query, array $summary): string;

    /**
     * The report by that name. A name that cannot be one is refused with
     * InvalidToolArguments; one that names nothing, with InvalidArgumentException.
     *
     * @return array<string, mixed>
     */
    public function load(string $name): array;

    /** Where reports live, for the tool description: "nesta máquina" / "no iSol". */
    public function where(): string;
}
