<x-layouts.layout title="Alterações via MCP">
    <x-ui.hero-panel compact class="mb-6">
        <x-ui.page-header eyebrow="Integrações" title="Alterações via MCP"
            description="O que cada chat criou ou alterou em nome de alguém, com o antes e o depois. Reverter devolve o valor anterior, a menos que o registro tenha sido mudado depois." />
    </x-ui.hero-panel>

    <div class="space-y-6">
        {{-- Filtros por GET comum: a lista é a página, e o endereço com os
             filtros é o que alguém cola para mostrar "foi esta conversa". --}}
        <form method="GET" action="{{ route('mcp-writes.index') }}"
              class="flex flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-4 shadow-card">
            <x-forms.field label="Pessoa" for="filter-user" class="min-w-44">
                <x-forms.select id="filter-user" name="filter[user]">
                    <option value="">Todas</option>
                    @foreach ($people as $id => $name)
                        <option value="{{ $id }}" @selected(($filters['user'] ?? '') == $id)>{{ $name }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>

            <x-forms.field label="Conector" for="filter-client" class="min-w-44">
                <x-forms.select id="filter-client" name="filter[client]">
                    <option value="">Todos</option>
                    @foreach ($clients as $id => $name)
                        <option value="{{ $id }}" @selected(($filters['client'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </x-forms.select>
            </x-forms.field>

            <x-forms.field label="De" for="filter-from">
                <x-forms.input id="filter-from" type="date" name="filter[from]" :value="$filters['from'] ?? null" />
            </x-forms.field>

            <x-forms.field label="Até" for="filter-to">
                <x-forms.input id="filter-to" type="date" name="filter[to]" :value="$filters['to'] ?? null" />
            </x-forms.field>

            <x-forms.button>Filtrar</x-forms.button>
            @if ($filters !== [])
                <a href="{{ route('mcp-writes.index') }}" class="pb-2 text-xs text-accent hover:underline">Limpar filtros</a>
            @endif
        </form>

        <x-mcp.write-list :filters="$filters" />
    </div>
</x-layouts.layout>
