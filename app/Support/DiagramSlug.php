<?php

namespace App\Support;

use App\Models\Diagram;
use Illuminate\Support\Str;

/**
 * The address of a drawing, derived from its name and never taken from a
 * client (see `.claude/rules/slugs-and-routing.md`: `Str::slug()` transliterates,
 * so what comes out of here is already lowercase ASCII).
 *
 * It lives here rather than beside the one controller that used to own it
 * because there are two callers now — a diagram someone creates by hand, and
 * one built from a page's prose — and two copies of "append a number until it
 * is free" is how two diagrams end up fighting over the same address.
 */
final class DiagramSlug
{
    public static function unique(string $name): string
    {
        $base = Str::slug($name) ?: 'diagrama';
        $slug = $base;
        $suffix = 1;

        while (Diagram::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
