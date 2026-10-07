<div id="{{ $domId }}" class="space-y-2">
    @forelse ($options as $option)
        <form id="attribute-option-form-{{ $option->id }}" class="space-y-1.5">
            @csrf
            @method('PATCH')
            <div class="flex items-center gap-1.5">
                <x-forms.input name="label" :value="$option->label" class="flex-1 !py-1.5 text-sm" />
                <x-forms.button data-ak-ajax="attribute-option-form-{{ $option->id }}"
                    data-ak-action="{{ route('attribute-options.update', $option) }}"
                    class="!px-2.5 !py-1.5 text-xs">
                    Salvar
                </x-forms.button>
                <x-forms.button type="button" data-ak-ajax="attribute-option-delete-{{ $option->id }}"
                    data-ak-action="{{ route('attribute-options.destroy', $option) }}"
                    class="!shrink-0 !bg-transparent !p-1.5 !text-faint !shadow-none hover:!bg-raised hover:!text-crit"
                    title="Excluir">
                    <x-heroicon-o-trash class="size-4" />
                </x-forms.button>
            </div>
            @if ($group->supportsIcon())
                <div class="flex items-center gap-1.5">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-field border border-line bg-canvas text-muted [&>svg]:size-3.5">
                        <x-ui.heroicon :name="$option->icon" />
                    </span>
                    <x-forms.input name="icon" :value="$option->icon" placeholder="ícone (heroicons outline)" title="Nome do ícone heroicons (outline), ex.: cloud"
                        class="flex-1 !py-1 text-xs" />
                </div>
            @endif
            @if ($group->isHosting())
                {{-- How this hosting looks on the map's "Por hospedagem" view:
                     the container's colour and its badge. A colour input always
                     posts a value, so an option with none shows the map's
                     neutral grey — saving keeps that until somebody picks one. --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <x-forms.input type="color" name="color" :value="$option->color ?? \App\Models\AttributeOption::DEFAULT_COLOR"
                        title="Cor do container no mapa" aria-label="Cor de {{ $option->label }} no mapa"
                        class="!h-7 !w-10 shrink-0 cursor-pointer !p-0.5" />
                    @if ($option->imageUrl())
                        <img src="{{ $option->imageUrl() }}" alt="" class="size-7 shrink-0 rounded-field border border-line bg-white object-contain p-0.5">
                    @endif
                    <x-forms.file name="image" accept="image/png,image/jpeg,image/webp"
                        aria-label="Imagem de {{ $option->label }} no mapa" class="min-w-0 flex-1 !text-xs" />
                    @if ($option->imageUrl())
                        <x-forms.label class="flex shrink-0 cursor-pointer items-center gap-1 !text-[11px] !font-normal text-muted">
                            <x-forms.checkbox name="image_action" value="remove" /> remover imagem
                        </x-forms.label>
                    @endif
                </div>
            @endif
        </form>
        <form id="attribute-option-delete-{{ $option->id }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @empty
        <p class="text-sm text-muted">Nenhum valor cadastrado ainda.</p>
    @endforelse

    <form id="attribute-option-create-{{ $group->value }}" class="space-y-1.5 border-t border-line pt-3">
        @csrf
        <div class="flex items-center gap-1.5">
            <x-forms.input name="label" placeholder="Novo valor..." class="flex-1 !py-1.5 text-sm" />
            <x-forms.button data-ak-ajax="attribute-option-create-{{ $group->value }}"
                data-ak-action="{{ route('attribute-options.store', $group) }}"
                class="!px-2.5 !py-1.5 text-xs">
                Adicionar
            </x-forms.button>
        </div>
        @if ($group->supportsIcon())
            <x-forms.input name="icon" placeholder="ícone (heroicons outline)" title="Nome do ícone heroicons (outline), ex.: cloud"
                class="!py-1 text-xs" />
        @endif
        @if ($group->isHosting())
            <div class="flex items-center gap-1.5">
                <x-forms.input type="color" name="color" :value="\App\Models\AttributeOption::DEFAULT_COLOR"
                    title="Cor do container no mapa" aria-label="Cor no mapa" class="!h-7 !w-10 shrink-0 cursor-pointer !p-0.5" />
                <x-forms.file name="image" accept="image/png,image/jpeg,image/webp" aria-label="Imagem no mapa" class="min-w-0 flex-1 !text-xs" />
            </div>
        @endif
    </form>

    @if ($group->isHosting())
        <p class="pt-1 text-[11px] text-faint">
            Cor e imagem (JPG, PNG ou WebP, até 2 MB) definem o container desta hospedagem na visão
            "Por hospedagem" do mapa do ecossistema.
        </p>
    @endif

    @if ($group->supportsIcon())
        <p class="pt-1 text-[11px] text-faint">
            Ícone opcional (nome do <a href="https://heroicons.com" target="_blank" rel="noopener" class="underline hover:text-muted">heroicons</a> outline, ex.: <code class="rounded bg-raised px-1 py-0.5">cloud</code>) — aparece junto ao rótulo no mapa de integrações.
        </p>
    @endif
</div>
