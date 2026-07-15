<?php

use App\Http\Controllers\AttributeOptionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DocumentationGroupController;
use App\Http\Controllers\DocumentationGroupPageController;
use App\Http\Controllers\DocumentationHubController;
use App\Http\Controllers\FlowspecAttachmentController;
use App\Http\Controllers\FlowspecChatController;
use App\Http\Controllers\FlowspecExamplePromotionController;
use App\Http\Controllers\FlowspecMessageController;
use App\Http\Controllers\HeroiconController;
use App\Http\Controllers\IntegrationDocumentationController;
use App\Http\Controllers\Inventory\CompanyController;
use App\Http\Controllers\Inventory\PersonController;
use App\Http\Controllers\Inventory\SolutionController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicDocumentationController;
use App\Http\Controllers\SolutionDocumentationController;
use App\Http\Controllers\SolutionIntegrationController;
use App\Http\Controllers\SolutionMapController;
use App\Http\Middleware\BlockAgentFromWeb;
use Illuminate\Support\Facades\Route;

// Guest routes
Route::middleware('guest')->group(function () {
    Route::get('cadastro', [RegisterController::class, 'create'])->name('register.create');
    Route::post('cadastro', [RegisterController::class, 'store'])->name('register.store');

    Route::get('login', [LoginController::class, 'create'])->name('login.create');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('esqueci-minha-senha', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('esqueci-minha-senha', [ForgotPasswordController::class, 'store'])->name('password.email');

    Route::get('redefinir-senha/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('redefinir-senha', [ResetPasswordController::class, 'store'])->name('password.update');
});

// Authenticated routes (o papel `agent` é bloqueado da web — seção 15)
Route::middleware(['auth', BlockAgentFromWeb::class])->group(function () {
    Route::get('painel', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('painel/editar', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('painel', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('painel/personalizar', [ProfileController::class, 'customizePanel'])->name('profile.preferences.panel');
    Route::patch('painel/preferencias', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');

    Route::delete('logout', [LoginController::class, 'destroy'])->name('login.destroy');

    // F1 — catálogo de soluções (Etapa 2). Rotas estáticas antes das wildcard.
    Route::get('solucoes', [SolutionController::class, 'index'])->name('solutions.index');
    Route::get('solucoes/novo', [SolutionController::class, 'create'])->name('solutions.create');
    Route::post('solucoes', [SolutionController::class, 'store'])->name('solutions.store');
    // Antigo painel de cobertura (F7, por flags) — aposentado; a visão gerencial
    // agora é o hub de documentação (content-based). Redireciona bookmarks.
    Route::redirect('solucoes/cobertura', '/documentacao');
    // Busca por nome (autocomplete dos chips de "Sistemas" no form de Pessoa). Estática, antes de solutions.{solution:slug}.
    Route::get('solucoes/busca', [SolutionController::class, 'search'])->name('solutions.search');
    Route::get('solucoes/{solution}/editar', [SolutionController::class, 'edit'])->name('solutions.edit');

    // Integrações sempre a partir da solução — não há mais catálogo/módulo
    // /integracoes avulso. `scopeBindings` garante que {integration} pertence
    // à {solution} da URL (via Solution::integrations()). Estáticas antes de
    // {integration}.
    Route::scopeBindings()->group(function () {
        // Integrações do detalhe da solução (F3) — o data-viz é quem autora a
        // cadeia (participants/source/target/direction derivados via
        // SyncIntegrationFromChain; protocolo por passo). `store`/`update`
        // aqui só cobrem o que o data-viz não faz sozinho: criar uma
        // Integration nova e renomear/mudar status de uma existente.
        Route::post('solucoes/{solution}/integracoes', [SolutionIntegrationController::class, 'store'])->name('solutions.integrations.store');
        Route::patch('solucoes/{solution}/integracoes/{integration}', [SolutionIntegrationController::class, 'update'])->name('solutions.integrations.update');
        Route::patch('solucoes/{solution}/integracoes/{integration}/layout', [SolutionIntegrationController::class, 'saveLayout'])->name('solutions.integrations.layout.save');
        // Documentação rica da integração (editor de blocos Editor.js).
        Route::get('solucoes/{solution}/integracoes/{integration}/documentacao', [IntegrationDocumentationController::class, 'edit'])->name('solutions.integrations.docs.edit');
        Route::patch('solucoes/{solution}/integracoes/{integration}/documentacao', [IntegrationDocumentationController::class, 'update'])->name('solutions.integrations.docs.update');
        Route::post('solucoes/{solution}/integracoes/{integration}/documentacao/midia', [IntegrationDocumentationController::class, 'media'])->name('solutions.integrations.docs.media');
        // Título de um nó pontual (data-viz F3) — {node} é o índice na chain, não um model.
        Route::patch('solucoes/{solution}/integracoes/{integration}/chain/nos/{node}', [SolutionIntegrationController::class, 'updateNode'])
            ->whereNumber('node')
            ->name('solutions.integrations.chain.node.update');
        // Protocolo de uma ligação pontual (data-viz F3) — {edge} é o índice em chain.edges.
        Route::patch('solucoes/{solution}/integracoes/{integration}/chain/protocolo/{edge}', [SolutionIntegrationController::class, 'updateProtocol'])
            ->whereNumber('edge')
            ->name('solutions.integrations.chain.protocol.update');
        // Novo bloco ao final da cadeia (data-viz F3, painel "Adicionar bloco").
        Route::post('solucoes/{solution}/integracoes/{integration}/chain/nos', [SolutionIntegrationController::class, 'addNode'])
            ->name('solutions.integrations.chain.node.add');
        // Religa uma ponta de uma ligação existente pra outro bloco qualquer
        // (arrastar o handle da seta no data-viz F3) — {edge} é o índice em chain.edges.
        Route::patch('solucoes/{solution}/integracoes/{integration}/chain/aresta/{edge}', [SolutionIntegrationController::class, 'retargetEdge'])
            ->whereNumber('edge')
            ->name('solutions.integrations.chain.edge.retarget');
        // Ligação nova entre dois blocos já existentes ("modo ligar" do data-viz F3).
        Route::post('solucoes/{solution}/integracoes/{integration}/chain/arestas', [SolutionIntegrationController::class, 'addEdge'])
            ->name('solutions.integrations.chain.edge.add');
        // Remove uma ligação existente (botão "desligar" do editor de aresta) — {edge} é o índice em chain.edges.
        Route::delete('solucoes/{solution}/integracoes/{integration}/chain/aresta/{edge}', [SolutionIntegrationController::class, 'removeEdge'])
            ->whereNumber('edge')
            ->name('solutions.integrations.chain.edge.remove');
        Route::delete('solucoes/{solution}/integracoes/{integration}', [SolutionIntegrationController::class, 'destroy'])->name('solutions.integrations.destroy');
    });

    Route::get('solucoes/{solution}', [SolutionController::class, 'show'])->name('solutions.show');
    Route::patch('solucoes/{solution}', [SolutionController::class, 'update'])->name('solutions.update');
    // Edição inline de um atributo isolado a partir do próprio cabeçalho de detalhe.
    Route::patch('solucoes/{solution}/atributos', [SolutionController::class, 'updateAttributes'])->name('solutions.attributes.update');

    // Documentação rica da solução — árvore de 1..N páginas (editor de blocos
    // Editor.js por página). `solutions.docs.edit` é o "índice": resolve (ou
    // cria) a primeira página e redireciona pra ela, então os poucos lugares
    // que já linkam pra essa rota (Solutions\Documentation, cobertura) não
    // precisam saber qual página é a atual.
    Route::get('solucoes/{solution}/documentacao', [SolutionDocumentationController::class, 'index'])->name('solutions.docs.edit');
    Route::post('solucoes/{solution}/documentacao/paginas', [SolutionDocumentationController::class, 'store'])->name('solutions.docs.pages.store');
    // Link público ("magic link") da documentação: gerar/revogar (admin).
    // Estáticas ("compartilhar"), registradas antes do scopeBindings abaixo
    // pra não colidir com o wildcard {page} (mesmo formato de segmentos).
    Route::post('solucoes/{solution}/documentacao/compartilhar', [SolutionDocumentationController::class, 'share'])->name('solutions.docs.share');
    Route::delete('solucoes/{solution}/documentacao/compartilhar', [SolutionDocumentationController::class, 'unshare'])->name('solutions.docs.unshare');

    // {page} resolve via Solution::pages() (mesmo mecanismo do {integration}
    // escopado em Solution::integrations() acima).
    Route::scopeBindings()->group(function () {
        Route::get('solucoes/{solution}/documentacao/{page}', [SolutionDocumentationController::class, 'edit'])->name('solutions.docs.page.edit');
        Route::patch('solucoes/{solution}/documentacao/{page}', [SolutionDocumentationController::class, 'update'])->name('solutions.docs.update');
        Route::patch('solucoes/{solution}/documentacao/{page}/titulo', [SolutionDocumentationController::class, 'rename'])->name('solutions.docs.pages.rename');
        Route::delete('solucoes/{solution}/documentacao/{page}', [SolutionDocumentationController::class, 'destroy'])->name('solutions.docs.pages.destroy');
        Route::patch('solucoes/{solution}/documentacao/{page}/mover', [SolutionDocumentationController::class, 'move'])->name('solutions.docs.pages.move');
        Route::post('solucoes/{solution}/documentacao/{page}/midia', [SolutionDocumentationController::class, 'media'])->name('solutions.docs.media');
    });

    // F5 — pessoas e empresas (Etapa 2).
    Route::get('pessoas', [PersonController::class, 'index'])->name('people.index');
    Route::get('pessoas/nova', [PersonController::class, 'create'])->name('people.create');
    Route::post('pessoas', [PersonController::class, 'store'])->name('people.store');
    Route::get('pessoas/{person}/editar', [PersonController::class, 'edit'])->name('people.edit');
    Route::get('pessoas/{person}', [PersonController::class, 'show'])->name('people.show');
    Route::patch('pessoas/{person}', [PersonController::class, 'update'])->name('people.update');

    Route::get('empresas', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('empresas/nova', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('empresas', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('empresas/{company}/editar', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::get('empresas/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::patch('empresas/{company}', [CompanyController::class, 'update'])->name('companies.update');

    // Área "Gerenciar atributos" — só existe dentro da #main-modal (ver solutions/form.blade.php).
    Route::get('atributos', [AttributeOptionController::class, 'index'])->name('attribute-options.index');
    Route::get('atributos/{group}/opcoes', [AttributeOptionController::class, 'options'])->name('attribute-options.options');
    Route::post('atributos/{group}', [AttributeOptionController::class, 'store'])->name('attribute-options.store');
    Route::patch('atributos/{option}', [AttributeOptionController::class, 'update'])->name('attribute-options.update');
    Route::delete('atributos/{option}', [AttributeOptionController::class, 'destroy'])->name('attribute-options.destroy');

    Route::get('mapa', [SolutionMapController::class, 'index'])->name('solutions.map');
    Route::get('mapa/dados', [SolutionMapController::class, 'data'])->name('solutions.map.data');
    Route::patch('mapa/nos/{solution}/posicao', [SolutionMapController::class, 'updatePosition'])->name('solutions.map.position.update');

    // F8 — Gerador de flowSpec Digibee (chat). A resposta é gerada em job
    // (GenerateFlowspecReply); `status` é o endpoint de polling do thread.
    // {message} resolve escopado em FlowspecChat::messages().
    Route::get('flowspec', [FlowspecChatController::class, 'index'])->name('flowspec.index');
    Route::post('flowspec', [FlowspecChatController::class, 'store'])->name('flowspec.store');
    Route::get('flowspec/{chat}', [FlowspecChatController::class, 'show'])->name('flowspec.show');
    Route::get('flowspec/{chat}/status', [FlowspecChatController::class, 'status'])->name('flowspec.status');
    Route::post('flowspec/{chat}/mensagens', [FlowspecMessageController::class, 'store'])->name('flowspec.messages.store');
    Route::scopeBindings()->group(function () {
        Route::post('flowspec/{chat}/mensagens/{message}/anexar', [FlowspecAttachmentController::class, 'store'])->name('flowspec.messages.attach');
        Route::post('flowspec/{chat}/mensagens/{message}/promover', [FlowspecExamplePromotionController::class, 'store'])->name('flowspec.messages.promote');
    });

    // Hub de Documentação — visão transversal do que está documentado (soluções
    // + integrações) e do que falta. Substitui o antigo painel de cobertura.
    Route::get('documentacao', [DocumentationHubController::class, 'index'])->name('documentation.index');

    // Grupos ("Aninhamentos") — árvore de páginas standalone, fora de
    // qualquer Solução. Mesmo padrão de solutions.docs.* acima: `show` é o
    // índice (resolve/cria a 1ª página), rotas estáticas antes do
    // scopeBindings que resolve {page} via DocumentationGroup::pages().
    Route::post('documentacao/grupos', [DocumentationGroupController::class, 'store'])->name('documentation.groups.store');
    Route::get('documentacao/grupos/{group}', [DocumentationGroupController::class, 'show'])->name('documentation.groups.show');
    Route::patch('documentacao/grupos/{group}', [DocumentationGroupController::class, 'update'])->name('documentation.groups.update');
    Route::delete('documentacao/grupos/{group}', [DocumentationGroupController::class, 'destroy'])->name('documentation.groups.destroy');
    Route::post('documentacao/grupos/{group}/paginas', [DocumentationGroupPageController::class, 'store'])->name('documentation.groups.pages.store');

    Route::scopeBindings()->group(function () {
        Route::get('documentacao/grupos/{group}/{page}', [DocumentationGroupPageController::class, 'edit'])->name('documentation.groups.pages.edit');
        Route::patch('documentacao/grupos/{group}/{page}', [DocumentationGroupPageController::class, 'update'])->name('documentation.groups.pages.update');
        Route::patch('documentacao/grupos/{group}/{page}/titulo', [DocumentationGroupPageController::class, 'rename'])->name('documentation.groups.pages.rename');
        Route::delete('documentacao/grupos/{group}/{page}', [DocumentationGroupPageController::class, 'destroy'])->name('documentation.groups.pages.destroy');
        Route::patch('documentacao/grupos/{group}/{page}/mover', [DocumentationGroupPageController::class, 'move'])->name('documentation.groups.pages.move');
        Route::post('documentacao/grupos/{group}/{page}/midia', [DocumentationGroupPageController::class, 'media'])->name('documentation.groups.pages.media');
    });

    // Mídia embutida na documentação (imagens/arquivos da coleção `docs`),
    // referenciada por /files/{id} dentro do Markdown. Só autenticados.
    Route::get('files/{media}', [MediaController::class, 'show'])->name('files.show');

    // Ícones outline do heroicons para o picker dos callouts da documentação
    // (só o editor consome; as views read-only já vêm com o SVG renderizado).
    Route::get('heroicons/outline', [HeroiconController::class, 'outline'])->name('heroicons.outline');

    // Demonstração dos form components (Etapa 0 — DoD: renderizam isoladamente)
    Route::view('componentes', 'showcase')->name('showcase');
});

// Documentação pública ("magic link") — SEM auth. Acesso por token opaco na
// URL (Solution::public_token); a mídia embutida é servida por uma rota
// dedicada validada contra a própria solução/integrações (PublicDocumentationController).
Route::get('doc-publica/{token}', [PublicDocumentationController::class, 'solution'])->name('public.docs.solution');
// {slug} não é model-bound: slug de página só é único dentro do seu
// container, nunca globalmente (ver PublicDocumentationController::page()).
Route::get('doc-publica/{token}/pagina/{slug}', [PublicDocumentationController::class, 'page'])->name('public.docs.page');
Route::get('doc-publica/{token}/integracao/{integration:slug}', [PublicDocumentationController::class, 'integration'])->name('public.docs.integration');
Route::get('doc-publica/{token}/arquivo/{media}', [PublicDocumentationController::class, 'file'])->name('public.docs.file');

Route::get('/', fn () => auth()->check()
    ? redirect()->route('profile.show')
    : redirect()->route('login.create')
);
