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
 * Área "Gerenciar atributos" — CRUD dos valores de Categoria, Status,
 * Diretoria, Hospedagem, Cloud, Contrato, Suporte e Criticidade. Só existe
 * dentro da `#main-modal` (nunca uma página própria), acionada de dentro do
 * form de criação/edição de Solução — ver `solutions/form.blade.php`.
 */
class AttributeOptionController extends Controller
{
    /** Conteúdo da Modal: todos os 8 grupos, um por aba (data-ak-tabs). */
    public function index(): JsonResponse
    {
        $this->authorize('manage', AttributeOption::class);

        return response()->json([
            'content' => view('attribute-options.manage', [
                'groups' => AttributeGroup::cases(),
            ])->render(),
        ]);
    }

    /** Opções de um grupo, para o refresh ao vivo dos <select> do form de Solução (ver modal.js). */
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
     * Slug único dentro do grupo, gerado a partir do rótulo — o usuário só
     * digita o texto em português, nunca vê/edita a chave interna. Diretoria
     * é exceção: `solutions.directorate` já guarda o texto cru (sem slug)
     * para os ~40 valores existentes, então novos valores seguem o mesmo
     * formato para não misturar convenções dentro do mesmo grupo.
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
