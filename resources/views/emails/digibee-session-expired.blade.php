<x-mail::message>
# A sessão da Digibee expirou

Alguém tentou pesquisar os logs da Digibee pelo {{ config('app.name') }}, mas a sessão que o servidor usa
@if ($expiredAt)
expirou em {{ $expiredAt }}.
@else
não existe.
@endif

Para renovar: entre na Digibee no seu navegador e clique no favorito **Enviar sessão ao iSol**.
Se ainda não tem o favorito, ele está na tela abaixo.

<x-mail::button :url="$renewUrl">
Abrir Sessão Digibee
</x-mail::button>

Este aviso vai no máximo uma vez por dia enquanto a sessão não for renovada.
</x-mail::message>
