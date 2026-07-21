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
        // Edge-to-edge mode (the flowSpec chat): drop the breadcrumb header and
        // the centered max-width canvas so the page owns the whole viewport.
        public bool $fluid = false,
    ) {}

    public function render(): View
    {
        return view('components.layouts.layout');
    }
}
