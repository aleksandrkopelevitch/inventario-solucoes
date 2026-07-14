<?php

use Illuminate\Support\Facades\Blade;

/**
 * Every form component must render in isolation without throwing.
 * 8 ported + radio-group + 4 new (field, toggle, image-upload, chips).
 */
dataset('form_components', [
    'input'        => ['<x-forms.input name="nome" />', 'name="nome"'],
    'select'       => ['<x-forms.select name="uf"><option value="sp">SP</option></x-forms.select>', '<select'],
    'textarea'     => ['<x-forms.textarea name="bio" rows="3" />', '<textarea'],
    'button'       => ['<x-forms.button>Salvar</x-forms.button>', 'data-label'],
    'checkbox'     => ['<x-forms.checkbox name="ativo" />', 'type="checkbox"'],
    'radio'        => ['<x-forms.radio name="cor" value="azul">Azul</x-forms.radio>', 'type="radio"'],
    'radio-group'  => ['<x-forms.radio-group><x-forms.radio name="cor" value="azul">Azul</x-forms.radio></x-forms.radio-group>', '<fieldset'],
    'label'        => ['<x-forms.label for="nome">Nome</x-forms.label>', '<label'],
    'file'         => ['<x-forms.file name="anexo" />', 'type="file"'],
    'field'        => ['<x-forms.field label="Nome do produto" name="nome"><x-forms.input name="nome" /></x-forms.field>', 'Nome do produto'],
    'toggle'       => ['<x-forms.toggle name="ativo">Ativo</x-forms.toggle>', 'type="checkbox"'],
    'image-upload' => ['<x-forms.image-upload name="logo" />', 'data-ak-avatar-upload'],
    'chips'        => ['<x-forms.chips name="tags" />', 'data-ak-chips'],
]);

it('renders form component in isolation', function (string $markup, string $needle) {
    $html = Blade::render($markup);

    expect(trim($html))->not->toBe('')
        ->and($html)->toContain($needle);
})->with('form_components');
