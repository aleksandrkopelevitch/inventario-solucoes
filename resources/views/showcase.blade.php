{{-- The design system on one page: every SHARED component (resources/views/
     components/forms and components/ui) with its tag, so a new screen starts
     from what exists instead of a copy of some other screen. Domain
     components (x-companies.*, x-flowspec.*, …) are screens' own parts and are
     not listed.

     Inert on purpose: anything that posts somewhere points at this page's own
     URL, which only answers GET — clicking "Gerar link" or a trash icon here
     shows the app's error handling and changes nothing. --}}
<x-layouts.layout title="Componentes">
    @php($demoUrl = route('showcase'))

    <div class="mx-auto max-w-4xl space-y-8">
        <x-ui.hero-panel>
            <x-ui.page-header large eyebrow="Design system" title="Componentes"
                description="Tudo o que é compartilhado entre telas, com o nome da tag. Antes de escrever marcação nova, procure aqui. Este cabeçalho é x-ui.page-header dentro de x-ui.hero-panel." />
        </x-ui.hero-panel>

        {{-- ─── Formulários ─────────────────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Formulários <span class="font-mono text-xs font-normal text-faint">x-forms.*</span></h2>

            <form id="showcase-form" class="grid gap-5 sm:grid-cols-2">
                @csrf

                <x-forms.field label="Input" for="sc-input" name="input" hint="x-forms.input dentro de x-forms.field (rótulo + dica + erro)">
                    <x-forms.input id="sc-input" name="input" placeholder="Digite algo" />
                </x-forms.field>

                <x-forms.field label="Select" for="sc-select" name="select" hint="x-forms.select">
                    <x-forms.select id="sc-select" name="select">
                        <option value="">Selecione…</option>
                        <option value="a">Opção A</option>
                        <option value="b">Opção B</option>
                    </x-forms.select>
                </x-forms.field>

                <x-forms.field label="Textarea" for="sc-textarea" name="textarea" hint="x-forms.textarea" class="sm:col-span-2">
                    <x-forms.textarea id="sc-textarea" name="textarea" rows="3" placeholder="Texto longo" />
                </x-forms.field>

                <x-forms.field label="Arquivo" for="sc-file" name="file" hint="x-forms.file">
                    <x-forms.file id="sc-file" name="file" />
                </x-forms.field>

                <x-forms.field label="Imagem" hint="x-forms.image-upload (prévia + remover)">
                    <x-forms.image-upload name="imagem" />
                </x-forms.field>

                <x-forms.field label="Checkbox" hint="x-forms.checkbox dentro de x-forms.label">
                    <x-forms.label class="!flex items-center gap-2 !font-normal">
                        <x-forms.checkbox name="checkbox" /> Aceito os termos
                    </x-forms.label>
                </x-forms.field>

                <x-forms.field label="Toggle" hint="x-forms.toggle — um booleano">
                    <x-forms.toggle name="toggle">Ativo</x-forms.toggle>
                </x-forms.field>

                <x-forms.field label="Radio" hint="x-forms.radio-group + x-forms.radio">
                    <x-forms.radio-group>
                        <x-forms.radio name="radio" value="sim">Sim</x-forms.radio>
                        <x-forms.radio name="radio" value="nao">Não</x-forms.radio>
                    </x-forms.radio-group>
                </x-forms.field>

                <x-forms.field label="Chips" hint="x-forms.chips — seleção múltipla, com papel por item; Enter adiciona" class="sm:col-span-2">
                    <x-forms.chips
                        name="responsaveis"
                        :items="[['value' => 'ana', 'label' => 'Ana', 'role' => 'owner']]"
                        :roles="[['value' => 'owner', 'label' => 'Responsável'], ['value' => 'contributor', 'label' => 'Colaborador']]"
                    />
                </x-forms.field>
            </form>
        </section>

        {{-- ─── Ações ───────────────────────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Ações e links</h2>

            <div class="flex flex-wrap items-center gap-3">
                <x-forms.button type="button">Primário</x-forms.button>
                <x-forms.button type="button" variant="glass">Secundário (glass)</x-forms.button>
                <x-forms.button type="button" variant="ghost">Ghost</x-forms.button>
                <x-forms.button type="button" variant="ghost" class="!size-9 !p-0" aria-label="Editar">
                    <x-heroicon-o-pencil-square class="size-5" />
                </x-forms.button>
                <x-forms.button :href="$demoUrl" variant="glass">Botão-link (href)</x-forms.button>
            </div>
            <p class="font-mono text-[11px] text-faint">x-forms.button — variant primary | glass | ghost; com href vira &lt;a&gt;. Nunca type="button" num botão data-ak-ajax de formulário com campos.</p>

            <div class="flex flex-wrap items-center gap-4">
                <span class="text-sm text-body">Solução X <x-ui.external-link :href="$demoUrl" label="solução" /></span>
                <x-ui.add-chip>Adicionar contato</x-ui.add-chip>
                <span class="inline-flex items-center gap-2 text-sm text-body">
                    Linha removível
                    <x-ui.row-remove id="showcase-row-remove" :action="$demoUrl" confirm="Remover este item? (exemplo — nada é apagado)" label="Remover exemplo" />
                </span>
            </div>
            <p class="font-mono text-[11px] text-faint">x-ui.external-link · x-ui.add-chip · x-ui.row-remove</p>
        </section>

        {{-- ─── Identidade ──────────────────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Identidade e ícones</h2>

            <div class="flex flex-wrap items-end gap-4">
                <x-ui.logo name="Digibee" size="sm" />
                <x-ui.logo name="Digibee" />
                <x-ui.logo name="SAP" size="lg" :tone="\App\Support\CategoryPalette::tileClass('erp')" />
                <x-ui.avatar name="Ana Souza" size="sm" />
                <x-ui.avatar name="Ana Souza" />
                <x-ui.avatar name="Bruno Lima" size="lg" />
                <x-ui.heroicon name="cloud" class="size-6 text-muted" />
                <x-ui.heroicon name="nao-existe" class="size-6 text-muted" />
                <span class="inline-flex items-center gap-2 text-sm text-muted"><x-ui.typing-dots /> gerando…</span>
                <x-ui.status-badge :status="\App\Enums\DiagramStatus::cases()[0]" />
                <x-ui.status-badge :status="\App\Enums\DiagramStatus::cases()[1]" size="md" />
            </div>
            <p class="font-mono text-[11px] text-faint">x-ui.logo (tile quadrado, tom por categoria) · x-ui.avatar (redondo) · x-ui.heroicon (nome vindo do banco; um nome inválido não quebra) · x-ui.typing-dots · x-ui.status-badge (qualquer enum com label() e badgeClass())</p>

            <x-ui.hero-panel compact>
                <p class="text-sm text-[color:var(--color-glow-ink)]">x-ui.hero-panel compact — o cabeçalho de identidade de um detalhe.</p>
            </x-ui.hero-panel>
        </section>

        {{-- ─── Conteúdo ────────────────────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Conteúdo</h2>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="space-y-2">
                    <x-ui.markdown text="Texto **curto** com *Markdown*, uma [ligação]({{ $demoUrl }}) e quebra
de linha — como nas notas de uma pessoa." />
                    <p class="font-mono text-[11px] text-faint">x-ui.markdown — campos curtos (notas, descrição); HTML cru é removido</p>
                </div>

                <div class="space-y-2">
                    <p class="text-sm text-body"><x-ui.highlight text="Integração com o Digibee via REST" term="digibee" /></p>
                    <p class="font-mono text-[11px] text-faint">x-ui.highlight — destaca o termo buscado, sem acento nem caixa</p>
                </div>

                <x-ui.empty-state illustration="docs" title="Nenhuma página ainda"
                    description="x-ui.empty-state — ilustração + o que falta + o que fazer." />
                <x-ui.empty-state illustration="diagrams" title="Nenhum diagrama" illustration-class="max-w-[180px]"
                    description="A ilustração é escolhida por nome (illustrations/empty-*)." />

                <div class="space-y-2 sm:col-span-2">
                    <x-ui.no-results title="Nenhuma empresa encontrada" form-id="showcase-filter-form" :url="$demoUrl" />
                    <p class="font-mono text-[11px] text-faint">x-ui.no-results — os filtros esconderam tudo (não é lista vazia): oferece "Limpar filtros". Lista vazia de verdade é x-ui.empty-state.</p>
                </div>
            </div>
        </section>

        {{-- ─── Listas ──────────────────────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Listas</h2>

            <x-ui.filter-bar form-id="showcase-filter-form" class="!mb-0">
                <x-slot:search>
                    <x-ui.filter-search id="showcase-search" :url="$demoUrl" placeholder="Buscar (exemplo)" />
                </x-slot:search>

                <x-forms.select auto name="filter[kind]">
                    <option value="">Tipo</option>
                    <option value="a">Opção A</option>
                </x-forms.select>
            </x-ui.filter-bar>
            <p class="font-mono text-[11px] text-faint">
                x-ui.filter-bar + x-ui.filter-search — a barra de filtros de toda lista. A linha de filtros ativos e o
                contador de resultados (ui.filter-chips, ui.results-count) são templates de uma subclasse por catálogo
                (App\View\Components\Concerns\FilterChipsSlot / ResultsCountSlot), não tags.
            </p>

            <div class="overflow-x-auto rounded-field border border-line">
                <table class="w-full text-sm">
                    <thead class="bg-raised text-left text-xs text-muted">
                        <tr>
                            <x-ui.sortable-th column="name" form-id="showcase-filter-form" :url="$demoUrl">Nome</x-ui.sortable-th>
                            <th class="px-3 py-2 font-medium">Tipo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr><td class="px-3 py-2">Exemplo A</td><td class="px-3 py-2 text-muted">Fornecedor</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="font-mono text-[11px] text-faint">x-ui.sortable-th — cabeçalho que alterna filter[sort] do formulário da lista</p>

            <x-ui.solution-chips :solutions="\App\Models\Solution::query()->orderBy('name')->limit(3)->get(['id', 'name', 'slug'])" />
            <x-ui.solution-picker form-id="showcase-solution-picker" :action="$demoUrl" :items="[]" submit="Salvar (exemplo)" />
            <p class="font-mono text-[11px] text-faint">x-ui.solution-chips — soluções como chips com link (um diagrama, um caderno) · x-ui.solution-picker — escolher um conjunto de soluções e salvar de uma vez</p>
        </section>

        {{-- ─── Copiar e compartilhar ───────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Copiar e compartilhar</h2>

            <x-ui.copy-field value="tok_exemplo_123456" mono label="Token de exemplo" message="Copiado (exemplo)." copy-label="Copiar token" />
            <p class="font-mono text-[11px] text-faint">x-ui.copy-field — valor somente leitura + botão de copiar (copy-field.js)</p>

            <x-ui.public-link :public-url="$demoUrl" :share-url="$demoUrl" :unshare-url="$demoUrl" form-id="showcase-public-link"
                shared="Com link: o campo, Copiar, Abrir e Revogar."
                unshared="Sem link: o convite e Gerar." />
            <p class="font-mono text-[11px] text-faint">x-ui.public-link — o link mágico de um caderno e da planilha</p>
        </section>

        {{-- ─── Edição no lugar ─────────────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Edição no lugar</h2>

            <div class="space-y-1">
                <x-ui.inline-edit name="job_title" label="Cargo" value="Analista de integração" :action="null">
                    Analista de integração
                </x-ui.inline-edit>
            </div>
            <p class="font-mono text-[11px] text-faint">
                x-ui.inline-edit — leitura que vira campo com duplo clique ou no lápis (text, email, tel, select, textarea,
                file; method POST vira um criador "+ Adicionar …"). Sem action, como aqui, fica só leitura.
                Peças internas: x-ui.inline-edit-field, x-ui.inline-edit-actions.
            </p>
        </section>

        {{-- ─── Retorno ─────────────────────────────────────────────── --}}
        <section class="space-y-4 rounded-card border border-line bg-surface p-6 shadow-card">
            <h2 class="font-display text-lg font-semibold text-ink">Retorno ao usuário</h2>

            <div class="flex flex-wrap gap-3">
                <x-forms.button type="button" variant="glass" onclick="Toast.show('Salvo.')">Toast sucesso</x-forms.button>
                <x-forms.button type="button" variant="glass" onclick="Toast.show('Atenção ao campo.', 'warning')">Toast aviso</x-forms.button>
                <x-forms.button type="button" variant="glass" onclick="Modal.loadAlert({title: 'Atenção', content: 'Mensagem do alerta.', type: 'warning'})">Alerta (modal)</x-forms.button>
            </div>

            <div class="overflow-hidden rounded-field border border-line">
                <x-ui.panel-header title="Nova empresa" />
                <p class="px-5 py-4 text-sm text-muted">Conteúdo do painel lateral…</p>
            </div>
            <p class="font-mono text-[11px] text-faint">x-ui.panel-header — título e × de um painel lateral (data-ak-panel-open / -url abrem; o × fecha)</p>
            <p class="font-mono text-[11px] text-faint">
                Toast.show(msg, tipo) · Toast.open({title, content, type}) · Modal.loadAlert({...}) — texto puro, nunca HTML.
                As cascas (x-layouts.toast, x-layouts.alert-modal) já estão em todo layout.
            </p>
        </section>
    </div>
</x-layouts.layout>
