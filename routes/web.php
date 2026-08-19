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
use App\Http\Controllers\FlowspecGuidelineController;
use App\Http\Controllers\FlowspecMessageController;
use App\Http\Controllers\HeroiconController;
use App\Http\Controllers\IntegrationDiagramController;
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
use App\Http\Controllers\SubmissionChatController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\SubmissionExportController;
use App\Http\Controllers\SubmissionSectionController;
use App\Http\Controllers\SubmissionSourceController;
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

        // The canvas's own rendered PNG, posted right after a layout save and
        // read by the CATI deck. Media only — never topology.
        Route::post('solutions/{solution}/integrations/{integration}/diagram', [IntegrationDiagramController::class, 'store'])->name('solutions.integrations.diagram.store');
        Route::get('solutions/{solution}/integrations/{integration}/diagram', [IntegrationDiagramController::class, 'show'])->name('solutions.integrations.diagram.show');
        // Rich integration documentation (Editor.js block editor).
        Route::get('solutions/{solution}/integrations/{integration}/documentation', [IntegrationDocumentationController::class, 'edit'])->name('solutions.integrations.docs.edit');
        Route::patch('solutions/{solution}/integrations/{integration}/documentation', [IntegrationDocumentationController::class, 'update'])->name('solutions.integrations.docs.update');
        Route::post('solutions/{solution}/integrations/{integration}/documentation/media', [IntegrationDocumentationController::class, 'media'])->name('solutions.integrations.docs.media');
        // Documentation Assistant — a chat that helps write the integration doc
        // (job + polling per turn). Context documents belong to the Solution
        // (solutions.docs.context.* routes).
        Route::get('solutions/{solution}/integrations/{integration}/documentation/chat', [IntegrationDocumentationController::class, 'chatPanel'])->name('solutions.integrations.docs.chat.panel');
        Route::post('solutions/{solution}/integrations/{integration}/documentation/chat/messages', [IntegrationDocumentationController::class, 'sendMessage'])->name('solutions.integrations.docs.chat.messages.store');
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
        // New IMAGE block — pasting a picture directly on the F3 canvas (Ctrl+V).
        Route::post('solutions/{solution}/integrations/{integration}/chain/images', [SolutionIntegrationController::class, 'addImageNode'])
            ->name('solutions.integrations.chain.image.add');
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
    // One-field edits straight from the detail header (<x-ui.inline-edit>) for
    // the solution's OWN columns — name, description, vendor, logo, support
    // note; the 8 attribute badges use the route above instead. The browser
    // sends the logo as POST + `_method=PATCH` (PHP only fills $_FILES on
    // POST); the router resolves that to this PATCH route.
    Route::patch('solutions/{solution}/field', [SolutionController::class, 'updateField'])->name('solutions.field.update');
    // The solution's owners (the `person_solution` pivot), linked/unlinked from
    // the header's owners grid. NOT scoped, same reasoning as
    // `people.solutions.destroy`: the person is a first-class record here, not a
    // child of the solution, and `detach` no-ops harmlessly on someone who isn't
    // linked — there's no mutation for a 404 to protect.
    Route::post('solutions/{solution}/people', [SolutionController::class, 'attachPerson'])->name('solutions.people.store');
    // Re-points one row of the owners grid at another person. Scoped, unlike
    // its two neighbours: this one READS the link it's replacing (it carries
    // the role and `is_primary` over), so the pivot row has to exist —
    // `scopeBindings` resolves {person} through `Solution::people()` and 404s
    // when it doesn't, instead of silently attaching a second link.
    Route::patch('solutions/{solution}/people/{person}', [SolutionController::class, 'updatePerson'])
        ->scopeBindings()
        ->name('solutions.people.update');
    Route::delete('solutions/{solution}/people/{person}', [SolutionController::class, 'detachPerson'])->name('solutions.people.destroy');

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

    // Documentation Assistant polling — a single endpoint for both pages AND
    // integrations: the chat carries its own target/solution, so it doesn't
    // need {page}/{integration} in the URL (and avoids scopeBindings'
    // auto-scope trying to resolve {chat} as their child).
    Route::get('solutions/{solution}/documentation/chat/{chat}/status', [SolutionDocumentationController::class, 'chatStatus'])->name('solutions.docs.chat.status');
    // Marks a message's draft as applied. Shared by pages and integrations.
    Route::post('solutions/{solution}/documentation/chat/messages/{message}/apply', [SolutionDocumentationController::class, 'applyChatMessage'])->name('solutions.docs.chat.messages.apply');

    // {page} resolves via Solution::pages() (same mechanism as the
    // {integration} scoped in Solution::integrations() above).
    Route::scopeBindings()->group(function () {
        Route::get('solutions/{solution}/documentation/{page}', [SolutionDocumentationController::class, 'edit'])->name('solutions.docs.page.edit');
        Route::patch('solutions/{solution}/documentation/{page}', [SolutionDocumentationController::class, 'update'])->name('solutions.docs.update');
        Route::patch('solutions/{solution}/documentation/{page}/title', [SolutionDocumentationController::class, 'rename'])->name('solutions.docs.pages.rename');
        Route::delete('solutions/{solution}/documentation/{page}', [SolutionDocumentationController::class, 'destroy'])->name('solutions.docs.pages.destroy');
        Route::patch('solutions/{solution}/documentation/{page}/move', [SolutionDocumentationController::class, 'move'])->name('solutions.docs.pages.move');
        Route::post('solutions/{solution}/documentation/{page}/media', [SolutionDocumentationController::class, 'media'])->name('solutions.docs.media');
        // Documentation Assistant — a chat that helps write the page (job + polling per turn).
        Route::get('solutions/{solution}/documentation/{page}/chat', [SolutionDocumentationController::class, 'chatPanel'])->name('solutions.docs.chat.panel');
        Route::post('solutions/{solution}/documentation/{page}/chat/messages', [SolutionDocumentationController::class, 'sendMessage'])->name('solutions.docs.chat.messages.store');
    });

    // F5 — people and companies (Stage 2).
    Route::get('people', [PersonController::class, 'index'])->name('people.index');
    Route::get('people/new', [PersonController::class, 'create'])->name('people.create');
    Route::post('people', [PersonController::class, 'store'])->name('people.store');
    Route::get('people/{person}/edit', [PersonController::class, 'edit'])->name('people.edit');
    Route::get('people/{person}', [PersonController::class, 'show'])->name('people.show');
    Route::patch('people/{person}', [PersonController::class, 'update'])->name('people.update');
    // One-field edits straight from the detail header (<x-ui.inline-edit>),
    // instead of opening the whole panel — same idea as
    // `solutions.attributes.update`. The browser sends the photo as POST +
    // `_method=PATCH` (PHP only fills $_FILES on POST); the router resolves
    // that to this PATCH route.
    Route::patch('people/{person}/field', [PersonController::class, 'updateField'])->name('people.field.update');
    Route::post('people/{person}/contacts', [PersonController::class, 'storeContact'])->name('people.contacts.store');
    // Scoped: a contact id belonging to someone else 404s instead of being
    // retargeted onto (or deleted from) this person.
    Route::scopeBindings()->group(function (): void {
        Route::patch('people/{person}/contacts/{contact}', [PersonController::class, 'updateContact'])->name('people.contacts.update');
        Route::delete('people/{person}/contacts/{contact}', [PersonController::class, 'destroyContact'])->name('people.contacts.destroy');
    });
    // Person↔solution links, edited from the "Sistemas" card.
    Route::post('people/{person}/solutions', [PersonController::class, 'storeSolution'])->name('people.solutions.store');
    // Scoped, unlike its siblings: this one can also RE-POINT the link at
    // another system, and that's a detach + attach — on a solution this person
    // isn't linked to it would quietly CREATE a link instead of editing one.
    // The scoped binding 404s that, and resolves `{solution}` through
    // `Person::solutions()`, which is why the controller can read the role it
    // carries off `$solution->pivot` without a second query.
    Route::patch('people/{person}/solutions/{solution}', [PersonController::class, 'updateSolution'])
        ->scopeBindings()
        ->name('people.solutions.update');
    // NOT scoped: the solution is a first-class record here, not a child of the
    // person, and `detach` no-ops harmlessly on one that isn't linked — there's
    // no mutation for a 404 to protect.
    Route::delete('people/{person}/solutions/{solution}', [PersonController::class, 'destroySolution'])->name('people.solutions.destroy');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('companies/new', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    // One-field edits straight from the detail header (<x-ui.inline-edit>),
    // instead of opening the whole panel — same idea as `people.field.update`.
    // The browser sends the logo as POST + `_method=PATCH` (PHP only fills
    // $_FILES on POST); the router resolves that to this PATCH route.
    Route::patch('companies/{company}/field', [CompanyController::class, 'updateField'])->name('companies.field.update');
    // The company's two relations, attached/detached from their cards on the
    // detail page. Both detaches are scoped: they null out a foreign key on the
    // CHILD record, so a person (or system) belonging to another company must
    // 404 instead of being quietly unlinked from it. `{providedSolution}` is
    // named for the relation the scoped binding resolves through
    // (`providedSolutions()`) — Company has no `solutions()`.
    Route::post('companies/{company}/people', [CompanyController::class, 'attachPerson'])->name('companies.people.store');
    Route::post('companies/{company}/solutions', [CompanyController::class, 'attachSolution'])->name('companies.solutions.store');
    Route::scopeBindings()->group(function (): void {
        Route::delete('companies/{company}/people/{person}', [CompanyController::class, 'detachPerson'])->name('companies.people.destroy');
        Route::delete('companies/{company}/solutions/{providedSolution}', [CompanyController::class, 'detachSolution'])->name('companies.solutions.destroy');
    });

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

    // Guideline documents (admin) — Markdown notes always folded into the
    // generator's system prompt (FlowspecGuidelineController). Same
    // static-path-before-catch-all constraint as the examples routes above.
    Route::get('flowspec/guidelines', [FlowspecGuidelineController::class, 'index'])->name('flowspec.guidelines.index');
    Route::post('flowspec/guidelines', [FlowspecGuidelineController::class, 'store'])->name('flowspec.guidelines.store');
    Route::patch('flowspec/guidelines/{guideline}', [FlowspecGuidelineController::class, 'update'])->name('flowspec.guidelines.update');
    Route::delete('flowspec/guidelines/{guideline}', [FlowspecGuidelineController::class, 'destroy'])->name('flowspec.guidelines.destroy');

    Route::get('flowspec/{chat}', [FlowspecChatController::class, 'show'])->name('flowspec.show');

    /*
    |--------------------------------------------------------------------------
    | CATI — submissions to the IT Architecture Committee
    |--------------------------------------------------------------------------
    |
    | `submissions/create` MUST stay above `submissions/{submission}`, or the
    | binding tries to resolve a submission whose slug is "create" — the same
    | trap already noted for `flowspec/{chat}` above.
    |
    */
    Route::get('submissions', [SubmissionController::class, 'index'])->name('submissions.index');
    Route::get('submissions/create', [SubmissionController::class, 'create'])->name('submissions.create');
    Route::post('submissions', [SubmissionController::class, 'store'])->name('submissions.store');

    Route::get('submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
    Route::get('submissions/{submission}/edit', [SubmissionController::class, 'edit'])->name('submissions.edit');
    Route::patch('submissions/{submission}', [SubmissionController::class, 'update'])->name('submissions.update');
    Route::patch('submissions/{submission}/field', [SubmissionController::class, 'updateField'])->name('submissions.field.update');
    Route::delete('submissions/{submission}', [SubmissionController::class, 'destroy'])->name('submissions.destroy');

    Route::get('submissions/{submission}/export/markdown', [SubmissionExportController::class, 'markdown'])->name('submissions.export.markdown');
    Route::get('submissions/{submission}/export/ticket', [SubmissionExportController::class, 'ticket'])->name('submissions.export.ticket');
    Route::get('submissions/{submission}/export/deck', [SubmissionExportController::class, 'deck'])->name('submissions.export.deck');

    Route::post('submissions/{submission}/sources', [SubmissionSourceController::class, 'store'])->name('submissions.sources.store');
    Route::post('submissions/{submission}/slides/condense', [SubmissionSectionController::class, 'condense'])->name('submissions.slides.condense');
    Route::post('submissions/{submission}/chat/messages', [SubmissionChatController::class, 'store'])->name('submissions.chat.messages.store');

    // Scoped: without it, DELETE submissions/{a}/sources/{source} would delete
    // material belonging to submission {b}, and the chat endpoints would answer
    // for a chat that isn't this submission's.
    Route::scopeBindings()->group(function () {
        Route::patch('submissions/{submission}/sections/{section}', [SubmissionSectionController::class, 'update'])->name('submissions.sections.update');
        Route::post('submissions/{submission}/sections/{section}/confirm', [SubmissionSectionController::class, 'confirm'])->name('submissions.sections.confirm');

        Route::get('submissions/{submission}/sources/{source}', [SubmissionSourceController::class, 'show'])->name('submissions.sources.show');
        Route::delete('submissions/{submission}/sources/{source}', [SubmissionSourceController::class, 'destroy'])->name('submissions.sources.destroy');

        Route::get('submissions/{submission}/chat/{chat}/status', [SubmissionChatController::class, 'status'])->name('submissions.chat.status');
    });

    // NOT scoped: a message is two hops from a submission (submission → chat →
    // message), and scopeBindings() would resolve `{message}` through a
    // `Submission::messages()` relation that doesn't and shouldn't exist.
    // SubmissionChatController::apply() checks the ownership explicitly instead.
    Route::post('submissions/{submission}/chat/messages/{message}/apply', [SubmissionChatController::class, 'apply'])->name('submissions.chat.messages.apply');

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
