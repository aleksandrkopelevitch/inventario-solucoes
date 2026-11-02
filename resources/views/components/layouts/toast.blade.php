{{-- The Toast shell (`#toast-container` + the template toast.js clones) and the
     flash messages that go through it. One copy, included by every layout:
     the main app, auth, the public documentation and the public spreadsheet
     used to carry four identical copies of the markup, and two of the flash
     script. toast.js finds both ids by name, so they must stay exactly these. --}}
<div id="toast-container" class="fixed right-4 top-4 z-50 flex w-80 flex-col gap-2">
    <div id="toast-template" class="hidden rounded-card border border-line bg-surface p-4 opacity-0 shadow-lg transition-all duration-200">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 shrink-0">
                <span data-icon-success class="hidden text-base text-lime-ink">✓</span>
                <span data-icon-warning class="hidden text-base text-hot">⚠</span>
                <span data-icon-error class="hidden text-base text-crit">✕</span>
                <span data-icon-info class="hidden text-base text-accent">ℹ</span>
            </div>
            <div class="min-w-0 flex-1">
                <p data-slot="title" class="text-sm font-semibold text-ink"></p>
                <p data-slot="content" class="mt-0.5 text-sm text-muted"></p>
            </div>
            <x-forms.button type="button" variant="ghost" class="!rounded-none !p-0 !text-lg !leading-none !font-normal shrink-0 !text-faint hover:!bg-transparent hover:!text-body">×</x-forms.button>
        </div>
        <div class="mt-3 h-0.5 overflow-hidden rounded-full bg-raised">
            <div data-timer class="h-full rounded-full bg-accent" style="width:100%"></div>
        </div>
    </div>
</div>

{{-- Flash messages, surfaced through the app's own Toast rather than a new
     banner component — this app has exactly one convention for "tell the user
     something happened" and it's `#toast-container` above.

     There was no renderer for flash at all until now: `bootstrap/app.php`'s
     throttle (429) and expired-session (419) HTML paths both `back()->with(
     'error', …)`, and nothing anywhere read `session('error')`, so those
     messages were written and silently dropped. Any redirect in the app can
     use `->with('error'|'status', …)` from here on — the LOGIN screen
     included: every Entra refusal (`Auth\EntraController`) lands there with
     one, and was dropped the same way until the auth layout carried this too.

     Wrapped in `DOMContentLoaded` because `@vite` loads app.js as a MODULE
     (deferred): an inline classic script runs while the document is still
     parsing, so `Toast` doesn't exist yet at this point in the body. --}}
@if (session('error') || session('status'))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            @if (session('error'))
                Toast.show(@json(session('error')), 'warning')
            @endif
            @if (session('status'))
                Toast.show(@json(session('status')))
            @endif
        })
    </script>
@endif
