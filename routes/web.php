<?php

use App\Http\Controllers\AttributeOptionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DocumentationGroupController;
use App\Http\Controllers\DocumentationGroupPageController;
use App\Http\Controllers\DocumentationHubController;
use App\Http\Controllers\FlowspecChatController;
use App\Http\Controllers\FlowspecExampleController;
use App\Http\Controllers\FlowspecMessageController;
use App\Http\Controllers\HeroiconController;
use App\Http\Controllers\IntegrationDocumentationController;
use App\Http\Controllers\Inventory\CompanyController;
use App\Http\Controllers\Inventory\PersonController;
use App\Http\Controllers\Inventory\SolutionController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicDocumentationController;
use App\Http\Controllers\SolutionContextDocumentController;
use App\Http\Controllers\SolutionDocumentationController;
use App\Http\Controllers\SolutionIntegrationController;
use App\Http\Controllers\SolutionMapController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Guest routes
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login.create');
    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('login.store');

    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.update');
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/customize', [ProfileController::class, 'customizePanel'])->name('profile.preferences.panel');
    Route::patch('profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');

    Route::delete('logout', [LoginController::class, 'destroy'])->name('login.destroy');

    // F1 — solutions catalog (Stage 2). Static routes before the wildcard.
    Route::get('solutions', [SolutionController::class, 'index'])->name('solutions.index');
    Route::get('solutions/new', [SolutionController::class, 'create'])->name('solutions.create');
    Route::post('solutions', [SolutionController::class, 'store'])->name('solutions.store');
    // Old flag-based coverage panel (F7) — retired; the management view is now
    // the content-based documentation hub. Redirects existing bookmarks.
    Route::redirect('solutions/coverage', '/documentation');
    // Name search (autocomplete for the "Systems" chips in the Person form). Static, before solutions.{solution:slug}.
    Route::get('solutions/search', [SolutionController::class, 'search'])->name('solutions.search');
    Route::get('solutions/{solution}/edit', [SolutionController::class, 'edit'])->name('solutions.edit');

    // Integrations are always scoped under a solution — there's no standalone
    // /integrations catalog/module anymore. `scopeBindings` guarantees
    // {integration} belongs to the {solution} in the URL (via
    // Solution::integrations()). Static routes before {integration}.
    Route::scopeBindings()->group(function () {
        // Integrations of the solution detail page (F3) — the data-viz is what
        // authors the chain (participants/source/target/direction derived via
        // SyncIntegrationFromChain; protocol per step). `store`/`update` here
        // only cover what the data-viz doesn't do on its own: creating a new
        // Integration and renaming/changing the status of an existing one.
        Route::post('solutions/{solution}/integrations', [SolutionIntegrationController::class, 'store'])->name('solutions.integrations.store');
        Route::patch('solutions/{solution}/integrations/{integration}', [SolutionIntegrationController::class, 'update'])->name('solutions.integrations.update');
        Route::patch('solutions/{solution}/integrations/{integration}/layout', [SolutionIntegrationController::class, 'saveLayout'])->name('solutions.integrations.layout.save');
        // Rich integration documentation (Editor.js block editor).
        Route::get('solutions/{solution}/integrations/{integration}/documentation', [IntegrationDocumentationController::class, 'edit'])->name('solutions.integrations.docs.edit');
        Route::patch('solutions/{solution}/integrations/{integration}/documentation', [IntegrationDocumentationController::class, 'update'])->name('solutions.integrations.docs.update');
        Route::post('solutions/{solution}/integrations/{integration}/documentation/media', [IntegrationDocumentationController::class, 'media'])->name('solutions.integrations.docs.media');
        // AI Assist — populates the integration doc via LLM (job + polling). Context
        // documents belong to the Solution (solutions.docs.context.* routes).
        Route::get('solutions/{solution}/integrations/{integration}/documentation/assistant', [IntegrationDocumentationController::class, 'assistantPanel'])->name('solutions.integrations.docs.assist.panel');
        Route::post('solutions/{solution}/integrations/{integration}/documentation/assistant', [IntegrationDocumentationController::class, 'generateDraft'])->name('solutions.integrations.docs.assist.generate');
        // Kind/title of a single node (data-viz F3) — {node} is the index in the chain, not a model.
        Route::patch('solutions/{solution}/integrations/{integration}/chain/nodes/{node}', [SolutionIntegrationController::class, 'updateNode'])
            ->whereNumber('node')
            ->name('solutions.integrations.chain.node.update');
        // Removes a block AND every link touching it (indices shift — see removeNode()).
        Route::delete('solutions/{solution}/integrations/{integration}/chain/nodes/{node}', [SolutionIntegrationController::class, 'removeNode'])
            ->whereNumber('node')
            ->name('solutions.integrations.chain.node.remove');
        // Protocol of a single edge (data-viz F3) — {edge} is the index in chain.edges.
        Route::patch('solutions/{solution}/integrations/{integration}/chain/protocol/{edge}', [SolutionIntegrationController::class, 'updateProtocol'])
            ->whereNumber('edge')
            ->name('solutions.integrations.chain.protocol.update');
        // New block at the end of the chain (data-viz F3, "Adicionar bloco" panel).
        Route::post('solutions/{solution}/integrations/{integration}/chain/nodes', [SolutionIntegrationController::class, 'addNode'])
            ->name('solutions.integrations.chain.node.add');
        // Retargets one end of an existing edge to a different block (dragging
        // the arrow handle in data-viz F3) — {edge} is the index in chain.edges.
        Route::patch('solutions/{solution}/integrations/{integration}/chain/edge/{edge}', [SolutionIntegrationController::class, 'retargetEdge'])
            ->whereNumber('edge')
            ->name('solutions.integrations.chain.edge.retarget');
        // New edge between two already-existing blocks ("link mode" in data-viz F3).
        Route::post('solutions/{solution}/integrations/{integration}/chain/edges', [SolutionIntegrationController::class, 'addEdge'])
            ->name('solutions.integrations.chain.edge.add');
        // Removes an existing edge (the "unlink" button in the edge editor) — {edge} is the index in chain.edges.
        Route::delete('solutions/{solution}/integrations/{integration}/chain/edge/{edge}', [SolutionIntegrationController::class, 'removeEdge'])
            ->whereNumber('edge')
            ->name('solutions.integrations.chain.edge.remove');
        Route::delete('solutions/{solution}/integrations/{integration}', [SolutionIntegrationController::class, 'destroy'])->name('solutions.integrations.destroy');
    });

    Route::get('solutions/{solution}', [SolutionController::class, 'show'])->name('solutions.show');
    Route::patch('solutions/{solution}', [SolutionController::class, 'update'])->name('solutions.update');
    // Inline editing of a single attribute from the detail header itself.
    Route::patch('solutions/{solution}/attributes', [SolutionController::class, 'updateAttributes'])->name('solutions.attributes.update');

    // Rich solution documentation — a tree of 1..N pages (Editor.js block
    // editor per page). `solutions.docs.edit` is the "index": it resolves (or
    // creates) the first page and redirects to it, so the few places that
    // already link to this route (Solutions\Documentation, coverage) don't
    // need to know which page is current.
    Route::get('solutions/{solution}/documentation', [SolutionDocumentationController::class, 'index'])->name('solutions.docs.edit');
    Route::post('solutions/{solution}/documentation/pages', [SolutionDocumentationController::class, 'store'])->name('solutions.docs.pages.store');
    // Public documentation link ("magic link"): generate/revoke (admin).
    // Static routes ("share"), registered before the scopeBindings below so
    // they don't collide with the {page} wildcard (same segment shape).
    Route::post('solutions/{solution}/documentation/share', [SolutionDocumentationController::class, 'share'])->name('solutions.docs.share');
    Route::delete('solutions/{solution}/documentation/share', [SolutionDocumentationController::class, 'unshare'])->name('solutions.docs.unshare');

    // "AI Assist" context documents (`context_documents` collection), per
    // Solution — shared between its pages and its integrations' docs. {media}
    // is a global binding of the Spatie model (checked against the Solution
    // in the controller), so it stays outside the {page} scopeBindings.
    Route::post('solutions/{solution}/documentation/context', [SolutionContextDocumentController::class, 'store'])->name('solutions.docs.context.store');
    Route::get('solutions/{solution}/documentation/context/{media}', [SolutionContextDocumentController::class, 'show'])->name('solutions.docs.context.show');
    Route::delete('solutions/{solution}/documentation/context/{media}', [SolutionContextDocumentController::class, 'destroy'])->name('solutions.docs.context.destroy');

    // "AI Assist" polling — a single endpoint for both pages AND integrations:
    // the generation record carries its own target/solution, so it doesn't
    // need {page}/{integration} in the URL (and avoids scopeBindings'
    // auto-scope trying to resolve {generation} as their child).
    Route::get('solutions/{solution}/documentation/assistant/{generation}/status', [SolutionDocumentationController::class, 'draftStatus'])->name('solutions.docs.assist.status');
    // Marks a finished generation as resolved (applied/discarded/acknowledged),
    // so the editor stops resuming it on reload. Shared by pages and integrations.
    Route::post('solutions/{solution}/documentation/assistant/{generation}/consume', [SolutionDocumentationController::class, 'consumeDraft'])->name('solutions.docs.assist.consume');

    // {page} resolves via Solution::pages() (same mechanism as the
    // {integration} scoped in Solution::integrations() above).
    Route::scopeBindings()->group(function () {
        Route::get('solutions/{solution}/documentation/{page}', [SolutionDocumentationController::class, 'edit'])->name('solutions.docs.page.edit');
        Route::patch('solutions/{solution}/documentation/{page}', [SolutionDocumentationController::class, 'update'])->name('solutions.docs.update');
        Route::patch('solutions/{solution}/documentation/{page}/title', [SolutionDocumentationController::class, 'rename'])->name('solutions.docs.pages.rename');
        Route::delete('solutions/{solution}/documentation/{page}', [SolutionDocumentationController::class, 'destroy'])->name('solutions.docs.pages.destroy');
        Route::patch('solutions/{solution}/documentation/{page}/move', [SolutionDocumentationController::class, 'move'])->name('solutions.docs.pages.move');
        Route::post('solutions/{solution}/documentation/{page}/media', [SolutionDocumentationController::class, 'media'])->name('solutions.docs.media');
        // AI Assist — populates the page via LLM (job + polling).
        Route::get('solutions/{solution}/documentation/{page}/assistant', [SolutionDocumentationController::class, 'assistantPanel'])->name('solutions.docs.assist.panel');
        Route::post('solutions/{solution}/documentation/{page}/assistant', [SolutionDocumentationController::class, 'generateDraft'])->name('solutions.docs.assist.generate');
    });

    // F5 — people and companies (Stage 2).
    Route::get('people', [PersonController::class, 'index'])->name('people.index');
    Route::get('people/new', [PersonController::class, 'create'])->name('people.create');
    Route::post('people', [PersonController::class, 'store'])->name('people.store');
    Route::get('people/{person}/edit', [PersonController::class, 'edit'])->name('people.edit');
    Route::get('people/{person}', [PersonController::class, 'show'])->name('people.show');
    Route::patch('people/{person}', [PersonController::class, 'update'])->name('people.update');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('companies/new', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');

    // "Manage attributes" area — only exists inside #main-modal (see solutions/form.blade.php).
    Route::get('attributes', [AttributeOptionController::class, 'index'])->name('attribute-options.index');
    Route::get('attributes/{group}/options', [AttributeOptionController::class, 'options'])->name('attribute-options.options');
    Route::post('attributes/{group}', [AttributeOptionController::class, 'store'])->name('attribute-options.store');
    Route::patch('attributes/{option}', [AttributeOptionController::class, 'update'])->name('attribute-options.update');
    Route::delete('attributes/{option}', [AttributeOptionController::class, 'destroy'])->name('attribute-options.destroy');

    // "Usuários" area (admin-only) — only exists inside #main-modal (see
    // user-menu.blade.php). Accounts are admin-created by invite, never
    // self-registered; the invited user sets their own password through the
    // existing password-reset flow (UserController::store).
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');

    Route::get('map', [SolutionMapController::class, 'index'])->name('solutions.map');
    Route::get('map/data', [SolutionMapController::class, 'data'])->name('solutions.map.data');
    Route::patch('map/nodes/{solution}/position', [SolutionMapController::class, 'updatePosition'])->name('solutions.map.position.update');

    // F8 — Digibee flowSpec generator (chat). The reply is generated in a job
    // (GenerateFlowspecReply); `status` is the thread's polling endpoint.
    // {message} resolves scoped to FlowspecChat::messages().
    Route::get('flowspec', [FlowspecChatController::class, 'index'])->name('flowspec.index');
    Route::post('flowspec', [FlowspecChatController::class, 'store'])->name('flowspec.store');
    Route::get('flowspec/documents/search', [FlowspecChatController::class, 'searchDocuments'])->name('flowspec.documents.search');

    // Corpus curation (admin) — manage the flowSpec reference base directly,
    // in a modal (FlowspecExampleController). Static paths must precede the
    // `flowspec/{chat}` catch-all below, like documents/search above.
    Route::get('flowspec/examples', [FlowspecExampleController::class, 'index'])->name('flowspec.examples.index');
    Route::post('flowspec/examples', [FlowspecExampleController::class, 'store'])->name('flowspec.examples.store');
    Route::patch('flowspec/examples/{example}', [FlowspecExampleController::class, 'update'])->name('flowspec.examples.update');
    Route::delete('flowspec/examples/{example}', [FlowspecExampleController::class, 'destroy'])->name('flowspec.examples.destroy');

    Route::get('flowspec/{chat}', [FlowspecChatController::class, 'show'])->name('flowspec.show');
    Route::get('flowspec/{chat}/status', [FlowspecChatController::class, 'status'])->name('flowspec.status');
    Route::post('flowspec/{chat}/messages', [FlowspecMessageController::class, 'store'])->name('flowspec.messages.store');

    // Documentation Hub — cross-cutting view of what's documented (solutions +
    // integrations) and what's missing. Replaces the old coverage panel.
    Route::get('documentation', [DocumentationHubController::class, 'index'])->name('documentation.index');

    // Groups ("Nestings") — a tree of standalone pages, outside any Solution.
    // Same pattern as solutions.docs.* above: `show` is the index (resolves/
    // creates the 1st page), static routes before the scopeBindings that
    // resolves {page} via DocumentationGroup::pages().
    Route::post('documentation/groups', [DocumentationGroupController::class, 'store'])->name('documentation.groups.store');
    Route::get('documentation/groups/{group}', [DocumentationGroupController::class, 'show'])->name('documentation.groups.show');
    Route::patch('documentation/groups/{group}', [DocumentationGroupController::class, 'update'])->name('documentation.groups.update');
    Route::delete('documentation/groups/{group}', [DocumentationGroupController::class, 'destroy'])->name('documentation.groups.destroy');
    Route::post('documentation/groups/{group}/pages', [DocumentationGroupPageController::class, 'store'])->name('documentation.groups.pages.store');

    Route::scopeBindings()->group(function () {
        Route::get('documentation/groups/{group}/{page}', [DocumentationGroupPageController::class, 'edit'])->name('documentation.groups.pages.edit');
        Route::patch('documentation/groups/{group}/{page}', [DocumentationGroupPageController::class, 'update'])->name('documentation.groups.pages.update');
        Route::patch('documentation/groups/{group}/{page}/title', [DocumentationGroupPageController::class, 'rename'])->name('documentation.groups.pages.rename');
        Route::delete('documentation/groups/{group}/{page}', [DocumentationGroupPageController::class, 'destroy'])->name('documentation.groups.pages.destroy');
        Route::patch('documentation/groups/{group}/{page}/move', [DocumentationGroupPageController::class, 'move'])->name('documentation.groups.pages.move');
        Route::post('documentation/groups/{group}/{page}/media', [DocumentationGroupPageController::class, 'media'])->name('documentation.groups.pages.media');
    });

    // Media embedded in documentation (images/files from the `docs`
    // collection), referenced via /files/{id} inside the Markdown. Authenticated only.
    Route::get('files/{media}', [MediaController::class, 'show'])->name('files.show');

    // Heroicons outline icons for the documentation callout picker (only the
    // editor consumes this; read-only views already ship with the rendered SVG).
    Route::get('heroicons/outline', [HeroiconController::class, 'outline'])->name('heroicons.outline');

    // Form components demo (Stage 0 — DoD: renders in isolation)
    Route::view('components', 'showcase')->name('showcase');
});

// Public documentation ("magic link") — NO auth. Access via an opaque token in
// the URL (Solution::public_token); embedded media is served by a dedicated
// route validated against the solution/its integrations itself (PublicDocumentationController).
Route::get('public-docs/{token}', [PublicDocumentationController::class, 'solution'])->name('public.docs.solution');
// {slug} is not model-bound: a page's slug is only unique within its
// container, never globally (see PublicDocumentationController::page()).
Route::get('public-docs/{token}/page/{slug}', [PublicDocumentationController::class, 'page'])->name('public.docs.page');
Route::get('public-docs/{token}/integration/{integration:slug}', [PublicDocumentationController::class, 'integration'])->name('public.docs.integration');
Route::get('public-docs/{token}/file/{media}', [PublicDocumentationController::class, 'file'])->name('public.docs.file');

Route::get('/', fn () => auth()->check()
    ? redirect()->route('profile.show')
    : redirect()->route('login.create')
);
