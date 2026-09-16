@props(['label' => 'Sair'])

{{-- Signing OUT, as a form rather than a link: `login.destroy` is a DELETE
     route, so the CSRF token and the method override are what make it reachable
     at all — an <a href="/logout"> hits the route table and 405s.

     It lived nowhere in the UI until Entra SSO shipped. While every account was
     created by an admin and signed in with a password, "trocar de conta" was a
     thing nobody did; SSO turns an account into something anybody with a Leo
     mailbox gets on their first visit, and the person it strands is precisely
     the one who already had an account here under a different address — they
     land as a `Reader` with no way back out to the login screen.

     The button is styled by the CALLER (`$attributes`), because the two shells
     this sits in are opposites: a dark sidebar dropdown and the white docs top
     bar. --}}
<form method="POST" action="{{ route('login.destroy') }}" class="contents">
    @csrf
    @method('DELETE')
    <x-forms.button variant="ghost" {{ $attributes }}>
        <x-heroicon-o-arrow-right-on-rectangle class="size-4 text-muted" /> {{ $label }}
    </x-forms.button>
</form>
