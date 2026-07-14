<x-layouts.layout title="Mapa de integrações">
    <div class="mb-6">
        <h1 class="font-display text-[32px] font-semibold leading-tight text-ink">Mapa de integrações</h1>
        <p class="mt-1 text-sm text-muted">Ecossistema completo: soluções como nós, integrações como arestas. O grafo é derivado da tabela de integrações.</p>
    </div>

    {{-- Grid (não flex): x-forms.select se envolve num wrapper w-full, que
         numa linha flex forçaria cada item a ocupar 100% e quebrar linha. --}}
    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4 sm:items-center">
        <x-forms.select data-ak-graph-filter="status">
            <option value="all">Todos os status</option>
            <option value="active">Ativas</option>
            <option value="in_development">Em desenvolvimento</option>
            <option value="planned">Planejadas</option>
            <option value="deprecated">Descontinuadas</option>
        </x-forms.select>

        <x-forms.select data-ak-graph-filter="category">
            <option value="">Todas as categorias</option>
            @foreach ($categories as $option)
                <option value="{{ $option->value }}">{{ $option->label }}</option>
            @endforeach
        </x-forms.select>

        <x-forms.select data-ak-graph-filter="directorate">
            <option value="">Todas as diretorias</option>
            @foreach ($directorates as $option)
                <option value="{{ $option->value }}">{{ $option->label }}</option>
            @endforeach
        </x-forms.select>

    </div>

    <x-ecosystem-map id="global-map" :source-url="route('solutions.map.data')" height="620px" />

    {{-- Glue da página: reconstrói a query string a partir dos filtros acima e
         manda o mapa recarregar — não é o motor do mapa, só a integração
         com os controles desta tela. --}}
    <script>
        (function () {
            const shell = document.getElementById('global-map');
            const filters = document.querySelectorAll('[data-ak-graph-filter]');
            const baseUrl = @js(route('solutions.map.data'));

            function reload() {
                const params = new URLSearchParams();
                filters.forEach((el) => {
                    const key = el.dataset.akGraphFilter;
                    if (el.type === 'checkbox') {
                        if (el.checked) params.set(key, '1');
                    } else if (el.value) {
                        params.set(key, el.value);
                    }
                });
                const qs = params.toString();
                shell.__ecosystemMapReload?.(qs ? `${baseUrl}?${qs}` : baseUrl);
            }

            filters.forEach((el) => el.addEventListener('change', reload));
        })();
    </script>
</x-layouts.layout>
