{{-- "Gerando…": three bouncing dots, the one busy indicator every chat and the
     committee pre-review show while a queued job runs. A bounce is one of the
     busy animations the reduced-motion kill-switch deliberately spares. --}}
<span {{ $attributes->class('flex gap-1') }} aria-hidden="true">
    <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:0ms]"></span>
    <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:150ms]"></span>
    <span class="size-1.5 animate-bounce rounded-full bg-accent [animation-delay:300ms]"></span>
</span>
