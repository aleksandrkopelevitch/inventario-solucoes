<?php

namespace App\View\Components\Layouts;

use Illuminate\View\Component;
use Illuminate\View\View;

class Layout extends Component
{
    /** @param  array<int, array{label: string, url: ?string}>|null  $breadcrumbs */
    public function __construct(
        public ?string $title = null,
        public ?array $breadcrumbs = null,
    ) {}

    public function render(): View
    {
        return view('components.layouts.layout');
    }
}
