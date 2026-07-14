<x-layouts.layout :title="$title" :breadcrumbs="$breadcrumbs ?? null">
    <div @class([
        'mx-auto flex items-stretch rounded-card border border-line bg-white',
        //'max-w-3xl' => ! isset($pagesNav),
        //'max-w-5xl' => isset($pagesNav),
    ])>
        @isset($pagesNav)
            <x-documentation.pages-nav :pages="$pagesNav" :integrations="$integrationsNav ?? []" :create-page-url="$createPageUrl" />
        @endisset

        <div class="min-w-0 flex-1">
            {{-- Barra superior: voltar + salvar (grudenta no topo ao rolar, logo abaixo do header global) --}}
            <div class="sticky top-14 z-10 flex items-center justify-between gap-3 rounded-t-card border-b border-line bg-white px-6 py-3">
                <span class="text-sm text-ink font-bold">
                    {{ $backLabel }}
                </span>

                <div class="flex items-center gap-3">
                    @if ($canEdit || trim($renderedHtml) !== '')
                        <x-forms.button type="button" variant="ghost" data-ak-docs-copy
                            class="!h-9 !w-9 !p-0" aria-label="Copiar Markdown" title="Copiar Markdown">
                            <x-heroicon-o-clipboard-document class="size-5" />
                        </x-forms.button>
                    @endif

                    @if ($canEdit)
                        <span data-ak-docs-status class="text-xs text-muted" aria-live="polite"></span>
                        <x-forms.button type="button" data-ak-docs-save data-action="{{ $saveUrl }}" class="!h-9 !px-4 !text-sm">
                            Salvar
                        </x-forms.button>
                    @endif

                    {{-- Compartilhar (link público) — só na doc da própria Solution
                         ($coverageSolution vem só de SolutionDocumentationController)
                         e só para quem pode editá-la. --}}
                    @isset($coverageSolution)
                        @can('update', $coverageSolution)
                            <div class="relative">
                                <x-forms.button type="button" variant="ghost" data-ak-toggle="docs-share-dropdown" data-ak-toggle-classes="hidden" data-ak-toggle-blur="true"
                                    class="!h-9 !w-9 !p-0" aria-label="Compartilhar documentação">
                                    <x-heroicon-o-share class="size-5" />
                                </x-forms.button>
                                <div id="docs-share-dropdown" class="hidden absolute right-0 top-full z-20 mt-1.5 w-80 rounded-field border border-line bg-surface p-4 shadow-xl">
                                    <x-solutions.share-panel :solution="$coverageSolution" />
                                </div>
                            </div>
                        @endcan
                    @endisset
                </div>
            </div>

            <div class="px-8">
                @if ($canEdit)
                    {{-- Markdown cru de origem; o editor monta os blocos a partir daqui.
                         O <textarea> preserva o conteúdo com escape seguro. --}}
                    <textarea data-ak-docs-source hidden>{{ $documentation }}</textarea>

                    {{-- Ponto de montagem do Editor.js (resources/js/modules/docs-editor.js).
                         As bordas de bloco só aparecem no hover; o menu de blocos abre com "/". --}}
                    <div class="mt-6 ak-docs-editor" data-ak-docs-editor
                        data-config="{{ json_encode(['uploadUrl' => $uploadUrl]) }}"></div>

                    <p class="mt-6 text-xs text-muted">
                        Dica: digite <kbd class="rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-[11px]">/</kbd>
                        no início de um bloco para inserir títulos, listas, tabelas, hints, abas, imagens e arquivos —
                        ou use Markdown direto (<code>## </code>, <code>- </code>, <code>> </code>, <code>```</code>).
                    </p>
                @else
                    @if (trim($renderedHtml) !== '')
                        {{-- Markdown cru para o botão "Copiar Markdown" (docs-copy.js) — não há
                             editor nesta tela read-only, então a fonte é este textarea. --}}
                        <textarea data-ak-docs-markdown hidden>{{ $documentation }}</textarea>

                        <div class="html-content mt-6">
                            {!! $renderedHtml !!}
                        </div>
                    @else
                        <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                            Nenhuma documentação cadastrada ainda.
                        </p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-layouts.layout>
