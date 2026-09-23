{{-- The ecosystem map: a thin bar with the filters, and the canvas filling
     everything below it.

     Fluid and full height, like `diagrams/show` and the documentation editor —
     the canvas IS the content here, so it gets the viewport instead of a
     reading column with a hero above it. It used to open with the standard
     gradient hero, which cost the map its top 400px and pushed a graph of a
     hundred systems below the fold on a laptop. The sentence that hero carried
     is the one thing worth keeping from it, and it now sits where somebody
     needs it: beside the filters, as the instruction for a screen you have to
     click to read. --}}
<x-layouts.layout title="Mapa de integrações" :fluid="true">
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-white px-3 py-2">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="shrink-0 font-display text-[17px] font-bold tracking-tight text-ink">Mapa de integrações</h1>
                <p class="hidden min-w-0 truncate text-[12px] text-muted lg:block">
                    Clique em um sistema — ou na linha entre dois — para abrir os diagramas por trás; clique em um diagrama para desenhar o fluxo inteiro.
                </p>
            </div>

            {{-- Grid (not flex): x-forms.select wraps itself in a w-full wrapper, which
                 in a flex row would force each item to take 100% width and wrap.

                 The four controls are one row on purpose: "Ver por" is a
                 filter like the others as far as this page is concerned — the
                 glue below rebuilds the query string from every
                 `data-ak-graph-filter` it finds, so it needed no wiring of its
                 own. --}}
            <div class="grid shrink-0 grid-cols-2 gap-2 lg:grid-cols-4">
                {{-- The reading, not a filter on the data: empty keeps the
                     topology map (who talks to whom), a value regroups the
                     same solutions around a hub. --}}
                <x-forms.select data-ak-graph-filter="group" class="!py-1.5 !text-[13px]">
                    <option value="">Ver por ligações</option>
                    @foreach ($axes as $value => $label)
                        <option value="{{ $value }}">Ver por {{ mb_strtolower($label) }}</option>
                    @endforeach
                </x-forms.select>

                {{-- Dois vocabulários, um lugar só na barra. Na leitura por
                     ligações o status filtra os DIAGRAMAS (é deles que as
                     setas são feitas); na leitura por agrupamento não existe
                     diagrama nenhum, e o status filtra as SOLUÇÕES. As listas
                     se parecem, mas não coincidem: solução nunca é "em
                     desenvolvimento" e diagrama nunca é "em avaliação".
                     Oferecer uma só para as duas fazia o mesmo controle
                     mentir — "Em desenvolvimento" agrupado devolvia zero e
                     apagava o mapa sem dizer nada. O desabilitado sai da query
                     string (a cola abaixo pula `disabled`). --}}
                {{-- O `hidden` vai no WRAPPER, não no `<select>`: o componente
                     se embrulha numa div própria, então esconder só o controle
                     deixaria a célula do grid ocupada por uma seta solta. --}}
                <div data-ak-status-for="links">
                    <x-forms.select data-ak-graph-filter="status" class="!py-1.5 !text-[13px]">
                        <option value="all">Todos os status</option>
                        <option value="active">Ativas</option>
                        <option value="in_development">Em desenvolvimento</option>
                        <option value="planned">Planejadas</option>
                        <option value="deprecated">Descontinuadas</option>
                    </x-forms.select>
                </div>

                <div data-ak-status-for="groups" hidden>
                    <x-forms.select data-ak-graph-filter="status" class="!py-1.5 !text-[13px]" disabled>
                        <option value="all">Todos os status</option>
                        @foreach ($statuses as $option)
                            <option value="{{ $option->value }}">{{ $option->label }}</option>
                        @endforeach
                    </x-forms.select>
                </div>

                <x-forms.select data-ak-graph-filter="category" class="!py-1.5 !text-[13px]">
                    <option value="">Todas as categorias</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>

                <x-forms.select data-ak-graph-filter="directorate" class="!py-1.5 !text-[13px]">
                    <option value="">Todas as diretorias</option>
                    @foreach ($directorates as $option)
                        <option value="{{ $option->value }}">{{ $option->label }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        </div>

        <x-ecosystem-map id="global-map" :source-url="route('solutions.map.data')"
            height="100%" class="min-h-0 flex-1 !rounded-none !shadow-none !ring-0" />
    </div>

    {{-- Page glue: rebuilds the query string from the filters above and
         tells the map to reload — not the map engine itself, just the
         diagram with this screen's controls. --}}
    <script>
        (function () {
            const shell = document.getElementById('global-map');
            const filters = document.querySelectorAll('[data-ak-graph-filter]');
            const baseUrl = @js(route('solutions.map.data'));

            const axis = document.querySelector('[data-ak-graph-filter="group"]');

            // Qual dos dois selects de status vale agora. O que sobra fica
            // `disabled`, e o laço abaixo pula desabilitado — senão os dois
            // escreveriam a mesma chave e o último ganharia, mandando um
            // status de diagrama para a leitura que filtra soluções.
            function syncStatusVocabulary() {
                const wanted = axis?.value ? 'groups' : 'links';
                document.querySelectorAll('[data-ak-status-for]').forEach((box) => {
                    const active = box.dataset.akStatusFor === wanted;
                    box.hidden = ! active;
                    box.querySelectorAll('select').forEach((el) => { el.disabled = ! active; });
                });
            }

            function reload() {
                const params = new URLSearchParams();
                filters.forEach((el) => {
                    if (el.disabled) return;
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

            filters.forEach((el) => el.addEventListener('change', () => {
                syncStatusVocabulary();
                reload();
            }));

            syncStatusVocabulary();
        })();
    </script>
</x-layouts.layout>
