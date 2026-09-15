{{-- The internal knowledge base's reading screen — the SAME shell and the same
     body as the magic link, plus the one affordance that surface cannot have:
     a caderno switcher, passed through as `:notebooks`. --}}
<x-layouts.public-docs :title="$title" :heading="$notebook->name" :nav="$nav"
    :search-url="$searchUrl" :search-results="$searchResults"
    :notebooks="$published" :current="$notebook" :home-url="route('docs.index')">

    <x-documentation.reader-body
        :title="$title"
        :show-title="$showTitle"
        :rendered-html="$renderedHtml"
        :markdown="$markdown"
        :secret-reveal-url="$secretRevealUrl"
        :secret-scope="$secretScope"
        :child-pages="$childPages" />

</x-layouts.public-docs>
