{{-- Header --}}
<div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 shrink-0">
    <div>
        <h2 class="text-[15px] font-semibold text-slate-900">Personalizar</h2>
        <p class="mt-0.5 text-[12px] text-slate-500">Escolha o fundo do painel</p>
    </div>
    <button type="button"
        data-ak-panel-close
        class="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
        <x-heroicon-o-x-mark class="h-4 w-4" />
    </button>
</div>

{{-- Scrollable content --}}
<div class="flex-1 overflow-y-auto px-5 py-5 space-y-6"
    data-customize-panel
    data-action="{{ route('profile.preferences.update') }}">

    {{-- Colors --}}
    <div>
        <p class="mb-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Cores</p>
        <div class="grid grid-cols-3 gap-2">
            @foreach ($themes as $theme)
                @php $isActive = $currentType === 'gradient' && $currentValue === $theme->value; @endphp
                <button type="button"
                    data-ak-customize="gradient:{{ $theme->value }}"
                    title="{{ $theme->label() }}"
                    class="group relative h-16 w-full overflow-hidden rounded-xl transition hover:scale-105 hover:shadow-lg focus:outline-none"
                    style="background: {{ $theme->gradient() }};">
                    <span class="active-ring absolute inset-0 rounded-xl ring-2 ring-white ring-offset-2 ring-offset-white pointer-events-none {{ $isActive ? '' : 'hidden' }}"></span>
                    <span class="active-ring absolute bottom-1.5 right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-white/90 pointer-events-none {{ $isActive ? '' : 'hidden' }}">
                        <x-heroicon-s-check class="h-2.5 w-2.5 text-slate-800" />
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Photos --}}
    <div>
        <p class="mb-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Fotos</p>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($photos as $photo)
                @php $isActive = $currentType === 'photo' && $currentValue === $photo['photo_id']; @endphp
                <button type="button"
                    data-ak-customize="photo:{{ $photo['photo_id'] }}"
                    title="{{ $photo['label'] }}"
                    class="group relative h-20 w-full overflow-hidden rounded-xl transition hover:scale-[1.03] hover:shadow-lg focus:outline-none">
                    <img src="{{ \App\Support\BackgroundPhoto::thumbUrl($photo['photo_id']) }}"
                        alt="{{ $photo['label'] }}"
                        class="h-full w-full object-cover"
                        loading="lazy">
                    <span class="active-ring absolute inset-0 rounded-xl ring-2 ring-white ring-offset-1 ring-offset-white/50 pointer-events-none {{ $isActive ? '' : 'hidden' }}"></span>
                    <span class="active-ring absolute bottom-1.5 right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-white/90 pointer-events-none {{ $isActive ? '' : 'hidden' }}">
                        <x-heroicon-s-check class="h-2.5 w-2.5 text-slate-800" />
                    </span>
                    <span class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/50 px-2 pb-1.5 pt-4 text-[10px] font-medium text-white opacity-0 transition group-hover:opacity-100 pointer-events-none">
                        {{ $photo['label'] }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>

</div>
