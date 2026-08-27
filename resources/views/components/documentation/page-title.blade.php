{{-- The page's name, edited where it is read. `min-w-0` all the way down so a
     long title truncates instead of pushing the toolbar's actions off the bar. --}}
<span id="{{ $domId }}" class="flex min-w-0 flex-1 items-center">
    <x-ui.inline-edit :action="$action" name="title" :value="$title"
                      label="Título da página" :editable="$canEdit" class="min-w-0 flex-1">
        <span class="truncate text-sm font-bold text-ink">{{ $title }}</span>
    </x-ui.inline-edit>
</span>
