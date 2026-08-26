<x-layouts.layout title="Mapa de integrações">
    {{-- Same gradient "glow" hero as Soluções/Detalhe — see [[radiant-protocol-redesign]].
         The interactive map canvas below (<x-ecosystem-map>, its --viz-*
         tokens) is intentionally NOT touched by this redesign pass. --}}
    <x-ui.hero-panel class="mb-6 animate-ak-rise">
        <span class="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-[color:var(--color-glow-ink)]/70">
            <span class="size-2 rounded-full" style="background: linear-gradient(115deg, var(--color-glow-a), var(--color-lime))"></span>
            Ecossistema completo
        </span>
        <h1 class="mt-3 font-display text-[40px] font-bold leading-[0.98] tracking-tight text-[color:var(--color-glow-ink)]">Mapa de integrações</h1>
        <p class="mt-3 max-w-lg text-[15px] leading-relaxed text-[color:var(--color-glow-ink)]/70">Soluções como nós, integrações como arestas — o grafo é derivado diretamente da tabela de integrações.</p>
    </x-ui.hero-panel>

    {{-- Grid (not flex): x-forms.select wraps itself in a w-full wrapper, which
         in a flex row would force each item to take 100% width and wrap. --}}
    <div class="mb-4 grid animate-ak-rise grid-cols-2 gap-2 sm:grid-cols-4 sm:items-center" style="animation-delay: 70ms">
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

    <div class="animate-ak-rise" style="animation-delay: 140ms">
        <x-ecosystem-map id="global-map" :source-url="route('solutions.map.data')" height="620px" />
    </div>

    {{-- Page glue: rebuilds the query string from the filters above and
         tells the map to reload — not the map engine itself, just the
         diagram with this screen's controls. --}}
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
