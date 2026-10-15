<?php

namespace App\Support\Documentation;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * One "who and when" about a page — its creation or its last save — in the
 * shape both places that print one need (the cadernos catalog and the page's
 * right rail), so the two cannot word or date it differently.
 *
 * `when` is RELATIVE ("há 2 horas") because the app stores and runs in UTC: a
 * relative time is right in every timezone, while a printed wall-clock time
 * needs converting first. The exact one goes in `exact`, converted to
 * `app.display_timezone`, for a tooltip.
 *
 * `who` is null when nobody was recorded — every page older than the
 * authorship columns, and anything a console command saved.
 */
final class Authorship
{
    public function __construct(
        public readonly ?string $who,
        public readonly string $when,
        public readonly string $exact,
    ) {}

    public static function of(?User $user, ?CarbonInterface $at): ?self
    {
        if ($at === null) {
            return null;
        }

        return new self(
            who: $user?->name,
            // JUST_NOW: a save from this very second reads "agora", not
            // "há 0 segundo" — which is exactly what the editor's own save shows.
            when: $at->diffForHumans(['options' => CarbonInterface::JUST_NOW]),
            exact: $at->copy()->timezone((string) config('app.display_timezone'))->translatedFormat('d/m/Y \à\s H:i'),
        );
    }
}
