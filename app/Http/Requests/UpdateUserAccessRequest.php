<?php

namespace App\Http\Requests;

use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changes one account's level in one or more modules — `{"catalog": "editor"}`.
 *
 * Admin only (`UserPolicy::manage`), like the role: who may edit what is an
 * account decision, not content. Partial on purpose: the screen edits one
 * module at a time, and a module left out of the payload keeps its level.
 *
 * The modules are TOP-LEVEL keys rather than an `access[…]` array because the
 * screen is `x-ui.inline-edit`, which posts JSON keyed by the field's name —
 * and in a JSON body `access[catalog]` is a literal key, not an array.
 */
class UpdateUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return collect(AccessModule::cases())
            ->mapWithKeys(fn (AccessModule $module) => [$module->value => ['sometimes', Rule::enum(AccessLevel::class)]])
            ->all();
    }

    public function messages(): array
    {
        return collect(AccessModule::cases())
            ->mapWithKeys(fn (AccessModule $module) => [$module->value => 'Nível de acesso inválido.'])
            ->all();
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Nothing to change — an unknown module is simply not one of
                // the validated keys, so it lands here too.
                if ($this->levels() === []) {
                    $validator->errors()->add('access', 'Informe o nível de acesso de um módulo.');

                    return;
                }

                /** @var User $target */
                $target = $this->route('user');

                // An admin is an Editor everywhere by definition; a level
                // stored on one would be a lie the screen then has to explain.
                if ($target->isAdmin()) {
                    $validator->errors()->add('access', 'Administradores já são Editores em todos os módulos.');
                }
            },
        ];
    }

    /** @return array<string, AccessLevel> keyed by module value */
    public function levels(): array
    {
        return collect(AccessModule::cases())
            ->filter(fn (AccessModule $module) => $this->filled($module->value))
            ->mapWithKeys(fn (AccessModule $module) => [$module->value => AccessLevel::from($this->input($module->value))])
            ->all();
    }
}
