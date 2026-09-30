{{-- The systems a drawing concerns, as chips — the one line that tells two
     drawings apart before either is opened. Shared by the `/diagrams` rows,
     the caderno's "Diagramas" modal and the "Desenhar esta página" dialog, so
     the three read the same.

     `solutions` are Solution models (`participants`, eager-loaded with
     `id,name,slug` by every caller); `linked` = false draws them as plain
     chips, for a place where the chip must not navigate (inside a <label>). --}}
@props(['solutions', 'linked' => true])

@if (collect($solutions)->isNotEmpty())
    <div {{ $attributes->class('flex flex-wrap gap-1.5') }}>
        @foreach ($solutions as $solution)
            @if ($linked)
                <a href="{{ route('solutions.show', $solution) }}"
                    class="inline-flex max-w-full items-center rounded-full bg-accent-soft px-2.5 py-1 text-xs font-medium text-ink no-underline ring-1 ring-accent-line transition-colors hover:bg-accent-line">
                    <span class="truncate">{{ $solution->name }}</span>
                </a>
            @else
                <span class="inline-flex max-w-full items-center rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-medium text-ink ring-1 ring-accent-line">
                    <span class="truncate">{{ $solution->name }}</span>
                </span>
            @endif
        @endforeach
    </div>
@else
    <p {{ $attributes->class('text-xs text-faint') }}>Não cita nenhuma solução do catálogo.</p>
@endif
