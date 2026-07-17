<?php

namespace App\Http\Controllers;

use App\Enums\AttributeGroup;
use App\Http\Requests\StoreAttributeOptionRequest;
use App\Http\Requests\UpdateAttributeOptionRequest;
use App\Models\AttributeOption;
use App\Models\Integration;
use App\Models\Solution;
use App\View\Components\AttributeOptions\GroupList;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * "Manage attributes" area — CRUD for Category, Status, Directorate,
 * Hosting, Cloud, Contract, Support and Criticality option values. Only
 * exists inside `#main-modal` (never its own page), triggered from within
 * the Solution create/edit form — see `solutions/form.blade.php`.
 */
class AttributeOptionController extends Controller
{
    /** Modal content: all 8 groups, one per tab (data-ak-tabs). */
    public function index(): JsonResponse
    {
        $this->authorize('manage', AttributeOption::class);

        return response()->json([
            'content' => view('attribute-options.manage', [
                'groups' => AttributeGroup::cases(),
            ])->render(),
        ]);
    }

    /** Options for a group, for the live refresh of the Solution form's <select>s (see modal.js). */
    public function options(AttributeGroup $group): JsonResponse
    {
        $this->authorize('manage', AttributeOption::class);

        return response()->json(
            AttributeOption::options($group->value)
                ->map(fn (AttributeOption $option) => ['value' => $option->value, 'label' => $option->label])
                ->values()
        );
    }

    public function store(StoreAttributeOptionRequest $request, AttributeGroup $group): JsonResponse
    {
        $label = $request->validated('label');

        $option = AttributeOption::create([
            'group' => $group->value,
            'value' => $this->uniqueValue($group, $label),
            'label' => $label,
            'icon'  => $group->supportsIcon() ? $request->validated('icon') : null,
        ]);

        return $this->saved("\"{$option->label}\" adicionado.", $group);
    }

    public function update(UpdateAttributeOptionRequest $request, AttributeOption $option): JsonResponse
    {
        $group = AttributeGroup::from($option->group);

        $option->update([
            'label' => $request->validated('label'),
            'icon'  => $group->supportsIcon() ? $request->validated('icon') : null,
        ]);

        return $this->saved("\"{$option->label}\" atualizado.", $group);
    }

    public function destroy(AttributeOption $option): JsonResponse
    {
        $this->authorize('manage', AttributeOption::class);

        $inUse = Solution::where($option->group, $option->value)->exists()
            || ($option->group === 'criticality' && Integration::where('criticality', $option->value)->exists());

        if ($inUse) {
            return response()->json([
                'type'    => 'warning',
                'message' => "Não é possível excluir \"{$option->label}\": ainda está em uso.",
            ], 422);
        }

        $group = AttributeGroup::from($option->group);
        $option->delete();

        return $this->saved("\"{$option->label}\" removido.", $group);
    }

    /**
     * Unique slug within the group, generated from the label — the user only
     * types the (Portuguese) text, never sees/edits the internal key.
     * Directorate is the exception: `solutions.directorate` already stores
     * the raw text (no slug) for the ~40 existing values, so new values
     * follow the same format to avoid mixing conventions within the group.
     */
    private function uniqueValue(AttributeGroup $group, string $label): string
    {
        $base = $group === AttributeGroup::Directorate ? trim($label) : Str::slug($label);
        $value = $base;
        $suffix = 1;

        while (AttributeOption::where('group', $group->value)->where('value', $value)->exists()) {
            $value = $base . '-' . (++$suffix);
        }

        return $value;
    }

    private function saved(string $message, AttributeGroup $group): JsonResponse
    {
        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [GroupList::slot($group)],
        ]);
    }
}
