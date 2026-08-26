{{-- Name + status of the diagram, in the top bar of its canvas page. Both are
     click-to-edit for whoever can update the diagram and byte-for-byte
     read-only text for everyone else — the same gesture (double click, or the
     pencil) every other datum in the app answers to.

     The status sits to the RIGHT of the name on purpose: it's the one piece of
     state a reader wants without opening anything, and the top bar is what
     stays visible while the canvas is panned/zoomed under it. --}}
<div id="{{ $domId }}" class="flex min-w-0 items-center gap-2">
    {{-- `input-class` retypes the name exactly as it reads — a 14px bold
         crumb, not the app's default body text. --}}
    <x-ui.inline-edit name="name" :value="$diagram->name" :action="$action" :editable="$canEdit"
                      label="Nome do diagrama" edit-class="min-w-56 max-w-md"
                      input-class="!text-sm !font-bold !text-ink" class="min-w-0">
        <span class="truncate text-sm font-bold text-ink">{{ $diagram->name }}</span>
    </x-ui.inline-edit>

    {{-- `nullable=false`: a diagram always has a status (it's a non-null
         column with an initial value of "planned"), so a blank option would
         offer something the endpoint rejects. --}}
    <x-ui.inline-edit name="status" type="select" :options="$statusOptions"
                      :value="$diagram->status->value" :nullable="false"
                      :action="$action" :editable="$canEdit"
                      label="Status" edit-class="min-w-44"
                      input-class="!text-xs !font-medium" class="shrink-0">
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $diagram->status->badgeClass() }}">
            {{ $diagram->status->label() }}
        </span>
    </x-ui.inline-edit>
</div>
