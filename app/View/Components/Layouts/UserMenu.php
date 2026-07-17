<?php

namespace App\View\Components\Layouts;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Context menu at the bottom-left corner of the sidebar (avatar + logged-in
 * user's name). Updatable slot so that editing the profile (name/email/
 * avatar) via `profile.edit` is reflected here without a reload — see
 * `ProfileController::update()`.
 */
class UserMenu extends Component
{
    use Renderable;

    public const DOM_ID = 'sidebar-user-menu-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.layouts.user-menu', ['domId' => self::DOM_ID]);
    }
}
