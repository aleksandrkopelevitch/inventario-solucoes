{{-- The magic link's reading screen. The shell and the body are both shared
     with the internal knowledge base (`docs/reader.blade.php`); what this
     surface does NOT pass is `:notebooks`, because a token grants exactly one
     caderno and there is nothing to switch to. --}}
<x-layouts.public-docs :title="$title" :heading="$notebook->name" :nav="$nav"
    :search-url="$searchUrl" :search-results="$searchResults">

    <x-documentation.reader-body
        :title="$title"
        :show-title="$showTitle"
        :rendered-html="$renderedHtml"
        :markdown="$markdown"
        :secret-reveal-url="$secretRevealUrl"
        :secret-scope="$secretScope"
        :child-pages="$childPages" />

</x-layouts.public-docs>
