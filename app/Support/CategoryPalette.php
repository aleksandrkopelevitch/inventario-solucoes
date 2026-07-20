<?php

namespace App\Support;

/**
 * Maps a Solution's category to one of 8 harmonic color families, and hands
 * back the literal Tailwind classes for its logo tile and its category chip.
 *
 * Why a code-side map instead of a `color` column on `attribute_options`
 * (sibling of `icon`): the palette is a governed *design* decision — the
 * families are tuned against each other and against the green brand anchor
 * and the semantic tones (hot/crit). Letting each option carry a free hex
 * would let the harmony drift. The 19 category values are stable and seeded,
 * so a central map is the single source of truth.
 *
 * The class strings below MUST stay literal: `resources/css/app.css` has an
 * explicit `@source` pointing at THIS file so Tailwind's JIT emits the
 * `bg-cat-*` / `text-cat-*-ink` / `ring-cat-*-line` utilities. Building a
 * class name by concatenation (`"bg-cat-{$family}"`) would never be scanned
 * and would silently render uncolored. Color values live in `@theme`.
 *
 * Green stays the brand anchor (sidebar, primary buttons, headings); this
 * only colors the *content* — the catalog was a wall of identical green
 * tiles + gray category text, unscannable by type across 92 cards.
 */
class CategoryPalette
{
    /** Category value → family. Grouped by meaning, not by chance. */
    private const FAMILY = [
        // Operações físicas
        'manufacturing' => 'emerald', 'tms' => 'emerald', 'infrastructure' => 'emerald',
        // Comércio e cliente
        'ecommerce' => 'teal', 'crm' => 'teal', 'customer_service' => 'teal',
        // Dados e integração
        'data_bi' => 'blue', 'ipaas' => 'blue',
        // Plataforma e ERP
        'internal_platform' => 'indigo', 'erp' => 'indigo', 'itsm' => 'indigo',
        // Marketing e gente
        'marketing' => 'fuchsia', 'hcm' => 'fuchsia',
        // Segurança, identidade e risco
        'security' => 'rose', 'iam' => 'rose', 'legal_grc' => 'rose',
        // Financeiro
        'payments' => 'amber', 'tax' => 'amber',
        // Outros
        'other' => 'slate',
    ];

    /**
     * Per-family literal classes. `tile` = solid block for the logo fallback
     * (paired with the component's `text-white`); `chip` = soft badge matching
     * the `soft/ink/line` shape of the other metadata badges; `icon` = a
     * heroicon (outline) slug so every chip reads even when the DB option has
     * no icon of its own.
     */
    private const CLASSES = [
        'emerald' => ['tile' => 'bg-cat-emerald', 'chip' => 'bg-cat-emerald-soft text-cat-emerald-ink ring-1 ring-cat-emerald-line', 'icon' => 'cog-6-tooth'],
        'teal'    => ['tile' => 'bg-cat-teal',    'chip' => 'bg-cat-teal-soft text-cat-teal-ink ring-1 ring-cat-teal-line',          'icon' => 'shopping-cart'],
        'blue'    => ['tile' => 'bg-cat-blue',    'chip' => 'bg-cat-blue-soft text-cat-blue-ink ring-1 ring-cat-blue-line',          'icon' => 'chart-bar'],
        'indigo'  => ['tile' => 'bg-cat-indigo',  'chip' => 'bg-cat-indigo-soft text-cat-indigo-ink ring-1 ring-cat-indigo-line',    'icon' => 'cube'],
        'fuchsia' => ['tile' => 'bg-cat-fuchsia', 'chip' => 'bg-cat-fuchsia-soft text-cat-fuchsia-ink ring-1 ring-cat-fuchsia-line', 'icon' => 'megaphone'],
        'rose'    => ['tile' => 'bg-cat-rose',    'chip' => 'bg-cat-rose-soft text-cat-rose-ink ring-1 ring-cat-rose-line',          'icon' => 'shield-check'],
        'amber'   => ['tile' => 'bg-cat-amber',   'chip' => 'bg-cat-amber-soft text-cat-amber-ink ring-1 ring-cat-amber-line',       'icon' => 'credit-card'],
        'slate'   => ['tile' => 'bg-cat-slate',   'chip' => 'bg-cat-slate-soft text-cat-slate-ink ring-1 ring-cat-slate-line',       'icon' => 'tag'],
    ];

    /**
     * `!`-important soft classes for the category filter `<select>` when a
     * category is active — beats `<x-forms.select>`'s own bg/text/border so the
     * active category filter wears its family color (matching its chip + cards)
     * instead of the generic green. Literal per family (scanned via @source).
     */
    private const SELECT = [
        'emerald' => '!border-0 !bg-cat-emerald-soft !text-cat-emerald-ink !ring-1 !ring-cat-emerald-line',
        'teal'    => '!border-0 !bg-cat-teal-soft !text-cat-teal-ink !ring-1 !ring-cat-teal-line',
        'blue'    => '!border-0 !bg-cat-blue-soft !text-cat-blue-ink !ring-1 !ring-cat-blue-line',
        'indigo'  => '!border-0 !bg-cat-indigo-soft !text-cat-indigo-ink !ring-1 !ring-cat-indigo-line',
        'fuchsia' => '!border-0 !bg-cat-fuchsia-soft !text-cat-fuchsia-ink !ring-1 !ring-cat-fuchsia-line',
        'rose'    => '!border-0 !bg-cat-rose-soft !text-cat-rose-ink !ring-1 !ring-cat-rose-line',
        'amber'   => '!border-0 !bg-cat-amber-soft !text-cat-amber-ink !ring-1 !ring-cat-amber-line',
        'slate'   => '!border-0 !bg-cat-slate-soft !text-cat-slate-ink !ring-1 !ring-cat-slate-line',
    ];

    public static function family(?string $value): string
    {
        return self::FAMILY[$value] ?? 'slate';
    }

    /** `!`-important classes for the active category `<select>` (see SELECT). */
    public static function selectActiveClass(?string $value): string
    {
        return self::SELECT[self::family($value)];
    }

    /** Solid tile background class (e.g. `bg-cat-blue`). */
    public static function tileClass(?string $value): string
    {
        return self::CLASSES[self::family($value)]['tile'];
    }

    /** Soft chip classes (bg + text + ring), matching the other badges. */
    public static function chipClass(?string $value): string
    {
        return self::CLASSES[self::family($value)]['chip'];
    }

    /** Heroicon (outline) slug for the family, e.g. `chart-bar`. */
    public static function icon(?string $value): string
    {
        return self::CLASSES[self::family($value)]['icon'];
    }

    /**
     * Soft chip classes for a family NAME directly (not a category value) —
     * used to color things that aren't categories but reuse the harmonic
     * palette, e.g. the active-filter chips (each filter dimension picks a
     * family). Falls back to slate for an unknown family.
     */
    public static function chipClassForFamily(string $family): string
    {
        return (self::CLASSES[$family] ?? self::CLASSES['slate'])['chip'];
    }
}
