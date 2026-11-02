<?php

namespace App\View\Components\Submissions;

use App\Contracts\ChainCanvas;
use App\Models\SubmissionDiagram;
use App\View\Components\Concerns\ChainWorkspace;

/** The canvas mounted for one of a submission's drawings. */
class DiagramWorkspace extends ChainWorkspace
{
    public function __construct(public SubmissionDiagram $diagram) {}

    protected function canvas(): ChainCanvas
    {
        return $this->diagram;
    }

    /** Keyed by id rather than a slug — it only has to be unique on the page. */
    protected function rowKey(): string
    {
        return 'submission-diagram-' . $this->diagram->id;
    }

    protected function name(): string
    {
        return $this->diagram->kind->label();
    }
}
