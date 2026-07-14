<?php

namespace App\View\Components\Layouts;

use Illuminate\View\Component;
use Illuminate\View\View;

class Layout extends Component
{
    public function __construct(public ?string $title = null) {}

    public function render(): View
    {
        return view('components.layouts.layout');
    }
}
