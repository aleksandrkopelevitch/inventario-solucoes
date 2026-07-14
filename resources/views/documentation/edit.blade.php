<x-layouts.layout :title="$title">
    <div class="mx-auto max-w-3xl">
        {{-- Barra superior: voltar + salvar (grudenta no topo ao rolar) --}}
        <div class="sticky top-0 z-10 -mx-4 mb-6 flex items-center justify-between gap-3 border-b border-line bg-canvas/85 px-4 py-3 backdrop-blur">
            <a href="{{ $backUrl }}" class="inline-flex items-center gap-1.5 text-sm text-accent hover:underline">
                <x-heroicon-o-arrow-left class="size-4" /> {{ $backLabel }}
            </a>

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

        <p class="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">{{ $eyebrow }}</p>
        <h1 class="mt-1 font-display text-3xl font-semibold text-ink">{{ $title }}</h1>

        {{-- Card branco (estilo GitBook) — o app roda sobre bg-canvas (levemente
             esverdeado); o conteúdo da documentação fica sobre fundo branco de
             verdade, como no GitBook, tanto editando quanto no resultado final. --}}
        @if ($canEdit)
            {{-- Markdown cru de origem; o editor monta os blocos a partir daqui.
                 O <textarea> preserva o conteúdo com escape seguro. --}}
            <textarea data-ak-docs-source hidden>{{ $documentation }}</textarea>

            {{-- Ponto de montagem do Editor.js (resources/js/modules/docs-editor.js).
                 As bordas de bloco só aparecem no hover; o menu de blocos abre com "/". --}}
            <div class="mt-6 rounded-card border border-line bg-white px-8 py-8 shadow-sm">
                <div data-ak-docs-editor
                    data-config="{{ json_encode(['uploadUrl' => $uploadUrl]) }}"
                    class="ak-docs-editor"></div>
            </div>

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

                <div class="html-content mt-6 rounded-card border border-line bg-white px-8 py-8 shadow-sm">
                    {!! $renderedHtml !!}
                </div>
            @else
                <p class="mt-6 rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">
                    Nenhuma documentação cadastrada ainda.
                </p>
            @endif
        @endif

        {{-- Índice das outras documentações desta solução — a doc de cada
             Integration em que ela participa (cada uma com sua própria coluna
             `documentation`; não existem múltiplos documentos por Solution).
             Só aparece na doc da própria Solution ($relatedDocs vem só de
             SolutionDocumentationController). --}}
        @isset($relatedDocs)
            @if ($relatedDocs->isNotEmpty())
                <div class="mt-8">
                    <h2 class="font-display text-lg font-semibold text-ink">Documentações relacionadas</h2>
                    <p class="mt-1 text-sm text-muted">Documentação de cada integração desta solução.</p>

                    <div class="mt-3 divide-y divide-line rounded-card border border-line bg-surface">
                        @foreach ($relatedDocs as $doc)
                            <a href="{{ $doc['url'] }}" class="flex items-center justify-between gap-3 px-4 py-3 text-sm no-underline hover:bg-raised">
                                <span class="font-medium text-ink">{{ $doc['label'] }}</span>
                                <span @class([
                                    'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-accent-soft text-accent' => $doc['hasDocs'],
                                    'bg-raised text-muted' => ! $doc['hasDocs'],
                                ])>
                                    {{ $doc['hasDocs'] ? 'Documentado' : 'Sem documentação' }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endisset
    </div>
</x-layouts.layout>
