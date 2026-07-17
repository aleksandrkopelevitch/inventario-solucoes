@props([
    'name' => null,
])

{{-- Renders the outline heroicon for `name` (slug stored in `AttributeOption.icon`)
     outside the `<x-heroicon-o-*>` flow — that tag requires the icon name to be
     static at compile time; here `name` is a dynamic value coming from the
     database, and it may have become invalid (icon renamed/removed from the
     package). Renders empty in that case instead of breaking the view. --}}
@php($svg = \App\Support\Heroicons::outlineSvg($name, (string) $attributes->get('class')))
@if ($svg)
    {!! $svg !!}
@endif
