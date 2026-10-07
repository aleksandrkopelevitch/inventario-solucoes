<?php

namespace App\Http\Requests;

use App\Models\AttributeOption;
use App\Rules\Heroicon;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttributeOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', AttributeOption::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'icon'  => ['nullable', 'string', 'max:64', new Heroicon],
            // Hosting groups only (Hospedagem/Cloud) — the map's container
            // colour and badge. Same image rule as every logo in the app.
            'color'        => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'image'        => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'image_action' => ['nullable', 'in:remove'],
        ];
    }

    public function messages(): array
    {
        return [
            'color.regex' => 'Cor inválida — use o seletor de cor.',
            'image.image' => 'A imagem precisa ser JPG, PNG ou WebP.',
            'image.mimes' => 'A imagem precisa ser JPG, PNG ou WebP.',
            'image.max'   => 'A imagem pode ter no máximo 2 MB.',
        ];
    }
}
