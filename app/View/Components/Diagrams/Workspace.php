<?php

namespace App\View\Components\Diagrams;

use App\Contracts\ChainCanvas;
use App\Models\Diagram;
use App\View\Components\Concerns\ChainWorkspace;

/** Diagrama tab of a diagram's unified page. */
class Workspace extends ChainWorkspace
{
    public function __construct(public Diagram $diagram) {}

    protected function canvas(): ChainCanvas
    {
        return $this->diagram;
    }

    protected function rowKey(): string
    {
        return $this->diagram->slug;
    }

    protected function name(): string
    {
        return $this->diagram->name;
    }

    protected function status(): ?string
    {
        return str_replace('_', '-', $this->diagram->status->value);
    }
}
