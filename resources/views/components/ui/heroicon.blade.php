@props([
    'name' => null,
])

{{-- Renderiza o outline heroicon de `name` (slug salvo em `AttributeOption.icon`)
     fora do fluxo `<x-heroicon-o-*>` — esse tag exige o nome do ícone estático
     em tempo de compilação; aqui `name` é um valor dinâmico vindo do banco, e
     pode ter ficado inválido (ícone renomeado/removido do pacote). Renderiza
     vazio nesse caso em vez de quebrar a view. --}}
@php($svg = \App\Support\Heroicons::outlineSvg($name, (string) $attributes->get('class')))
@if ($svg)
    {!! $svg !!}
@endif
