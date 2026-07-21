@props([
    'chats',            // Collection of the user's FlowspecChat (latest first, withCount('messages'))
    'current' => null,  // the FlowspecChat being shown, if any — highlighted in the list
])

{{-- Saved-conversations rail for the flowSpec chat. Collapses horizontally:
     the toggle button (in the section top bar, outside this aside) carries
     data-ak-toggle="fs-sidebar" and animates the width to 0. The inner
     fixed-width wrapper keeps the content from reflowing while it slides. --}}
<aside id="fs-sidebar"
       class="flex w-72 shrink-0 flex-col overflow-hidden border-r border-line bg-surface transition-[width] duration-200 max-md:hidden">
    <div class="flex w-72 flex-1 flex-col overflow-hidden">
        <div class="flex items-center justify-between gap-2 border-b border-line px-3 py-2.5">
            <span class="text-[11px] font-bold uppercase tracking-[0.12em] text-faint">Conversas</span>
            <x-forms.button :href="route('flowspec.index')" variant="ghost" class="!p-1.5" title="Nova conversa">
                <x-heroicon-o-plus class="size-4" />
            </x-forms.button>
        </div>

        <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto p-2">
            @forelse ($chats as $chat)
                @php $active = $current && $current->id === $chat->id; @endphp
                <a href="{{ route('flowspec.show', $chat) }}" @class([
                    'block rounded-field px-3 py-2 no-underline transition-colors',
                    'bg-accent-soft' => $active,
                    'hover:bg-raised' => ! $active,
                ])>
                    <span @class(['block truncate text-sm', 'font-semibold text-ink' => $active, 'text-body' => ! $active])>{{ $chat->title }}</span>
                    <span class="mt-0.5 block truncate text-[11px] text-faint">{{ $chat->updated_at->diffForHumans() }}</span>
                </a>
            @empty
                <p class="px-3 py-6 text-center text-xs text-faint">Nenhuma conversa ainda.</p>
            @endforelse
        </div>
    </div>
</aside>
