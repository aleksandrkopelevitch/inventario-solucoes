<?php

namespace App\Mcp\Support;

use App\Enums\AttributeGroup;
use App\Models\AttributeOption;
use App\Support\Fold;

/**
 * The catalog's own vocabulary, handed to the model instead of guessed at.
 *
 * `solutions.category` stores a VALUE (`iam`) while every screen shows a LABEL
 * ("Identidade e Acesso"), and both are configuration — an admin invents them on
 * the "Gerenciar atributos" screen (§ AttributeOptionPolicy). A model filtering
 * this catalog therefore knows neither half: it has never seen the values, and
 * translating the label it read in a question back into one is exactly the kind
 * of guess that answers "0 registros" and looks like an empty catalog.
 *
 * So the vocabulary goes into the JSON Schema, which is the one thing a client
 * puts in front of the model before it chooses arguments, and it goes in as
 * `enum` — a client that validates arguments then refuses a wrong value before
 * the request is made, and one that does not at least shows the model the list.
 *
 * `resolve()` is the other half, for the model that answers with the label
 * anyway: a filter matches on the value, on the label, or on either folded
 * (§ Searching — `whereFolded()`), so "identidade e acesso", "Identidade e
 * Acesso" and `iam` are one filter. Deliberately EQUALITY and not containment:
 * a filter is a choice from a closed list, and a containment match would let
 * "suporte" pick whichever of two statuses happened to sort first.
 */
class Vocabulary
{
    /**
     * A schema fragment for one attribute filter.
     *
     * @return array<string, mixed>
     */
    public static function schema(AttributeGroup $group, string $description): array
    {
        $options = AttributeOption::options($group->value);

        $legend = $options
            ->map(fn (AttributeOption $option) => "{$option->value} = {$option->label}")
            ->implode('; ');

        return array_filter([
            'type'        => 'string',
            'description' => $legend === '' ? $description : "{$description} Valores: {$legend}.",
            // Omitted when the group is empty rather than sent as `[]`, which
            // several clients read as "no value is valid" and refuse everything.
            'enum' => $options->pluck('value')->all() ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The stored value for whatever the model passed, or null when it matches
     * nothing in the group.
     *
     * Null is returned rather than the input echoed back, and that is the whole
     * point of this method: passing an unknown value straight into the `where`
     * silently answers with an empty catalog, which reads as "não existe
     * nenhuma solução assim" instead of "esse filtro não existe". The caller
     * turns null into a message that says which it was.
     */
    public static function resolve(AttributeGroup $group, ?string $input): ?string
    {
        if (blank($input)) {
            return null;
        }

        $needle = Fold::text($input);

        return AttributeOption::options($group->value)
            ->first(fn (AttributeOption $option) => $option->value === $input
                || Fold::text((string) $option->value) === $needle
                || Fold::text((string) $option->label) === $needle)
            ?->value;
    }

    /**
     * The backing value of a BackedEnum case matching whatever the model passed
     * — by value or by label, folded either way.
     *
     * The same leniency `resolve()` gives the `AttributeOption` groups, and it
     * is needed for the same reason inverted: every payload this server sends
     * carries LABELS ("Ativo", "Fornecedor", "Responsável técnico"), because a
     * raw `in_development` is not what a person asking the question said. A
     * model that reads a label in one answer and passes it back as a filter is
     * doing the obvious thing, and refusing it would make the two halves of this
     * API disagree about the same vocabulary.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    public static function enumValue(string $enum, ?string $input): ?string
    {
        if (blank($input)) {
            return null;
        }

        $needle = Fold::text($input);

        foreach ($enum::cases() as $case) {
            $label = method_exists($case, 'label') ? (string) $case->label() : '';

            if (Fold::text((string) $case->value) === $needle || ($label !== '' && Fold::text($label) === $needle)) {
                return (string) $case->value;
            }
        }

        return null;
    }

    /** Every case of an enum, for an error message that lists what WOULD work. */
    public static function enumLegend(string $enum): string
    {
        return implode(', ', array_map(
            fn (\BackedEnum $case) => method_exists($case, 'label')
                ? "\"{$case->label()}\" ({$case->value})"
                : "\"{$case->value}\"",
            $enum::cases(),
        ));
    }

    /** Every value of a group, for an error message that lists what WOULD work. */
    public static function legend(AttributeGroup $group): string
    {
        return AttributeOption::options($group->value)
            ->map(fn (AttributeOption $option) => "\"{$option->label}\" ({$option->value})")
            ->implode(', ');
    }
}
