@php
    // $requirements: App\Support\Documentation\DocumentationRequirements::for().
    // Two visual groups when attribute-backed items are present (a Solution
    // page): "Do cadastro da Solução" (facts, never gaps) vs "Cobertura do
    // conteúdo" (best-effort keyword checks). An Diagram has no
    // `attribute` items, so it renders as one flat list.
    $attributeItems = collect($requirements)->where('source', 'attribute')->values();
    $otherItems = collect($requirements)->reject(fn ($item) => $item['source'] === 'attribute')->values();
@endphp

@if (! empty($requirements))
    <div class="mb-5 rounded-field border border-line bg-canvas p-3.5">
        <p class="mb-2.5 text-xs font-semibold uppercase tracking-wide text-muted">Requisitos mínimos</p>

        @if ($attributeItems->isNotEmpty())
            <p class="mb-1.5 text-[11px] font-medium text-muted">Do cadastro da Solução</p>
            <ul class="mb-3 flex flex-col gap-1">
                @foreach ($attributeItems as $item)
                    <li class="flex items-center gap-1.5 text-xs text-body">
                        @if ($item['satisfied'])
                            <x-heroicon-o-check-circle class="size-3.5 shrink-0 text-accent" />
                            <span>{{ $item['label'] }}: <span class="font-medium text-ink">{{ $item['value'] }}</span></span>
                        @else
                            <x-heroicon-o-minus-circle class="size-3.5 shrink-0 text-muted" />
                            <span>{{ $item['label'] }}: <span class="text-muted">não preenchido no cadastro</span></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($otherItems->isNotEmpty())
            @if ($attributeItems->isNotEmpty())
                <p class="mb-1.5 text-[11px] font-medium text-muted">Cobertura do conteúdo</p>
            @endif
            <ul class="flex flex-col gap-1">
                @foreach ($otherItems as $item)
                    <li class="flex items-center gap-1.5 text-xs">
                        @if ($item['satisfied'])
                            <x-heroicon-o-check-circle class="size-3.5 shrink-0 text-accent" />
                            <span class="text-body">{{ $item['label'] }}</span>
                        @else
                            <x-heroicon-o-exclamation-triangle class="size-3.5 shrink-0 text-hot" />
                            <span class="text-hot">{{ $item['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-[11px] text-muted">Cobertura de conteúdo é uma checagem simples por palavras-chave — confira manualmente.</p>
        @endif
    </div>
@endif
