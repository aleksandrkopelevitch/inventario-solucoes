<?php

use App\Http\Controllers\ApprovedTopologyController;
use App\Http\Controllers\AttributeOptionController;
use App\Http\Controllers\Auth\AccessLinkController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DiagramController;
use App\Http\Controllers\DiagramPictureController;
use App\Http\Controllers\DocumentationHubController;
use App\Http\Controllers\FlowspecAttachmentController;
use App\Http\Controllers\FlowspecChatController;
use App\Http\Controllers\FlowspecExampleController;
use App\Http\Controllers\FlowspecGuidelineController;
use App\Http\Controllers\FlowspecMessageController;
use App\Http\Controllers\HeroiconController;
use App\Http\Controllers\Inventory\CompanyController;
use App\Http\Controllers\Inventory\PersonAccessController;
use App\Http\Controllers\Inventory\PersonController;
use App\Http\Controllers\Inventory\SolutionController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotebookContextDocumentController;
use App\Http\Controllers\NotebookController;
use App\Http\Controllers\NotebookPageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicDocumentationController;
use App\Http\Controllers\SolutionMapController;
use App\Http\Controllers\SubmissionChatController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\SubmissionDecisionController;
use App\Http\Controllers\SubmissionDiagramController;
use App\Http\Controllers\SubmissionExportController;
use App\Http\Controllers\SubmissionSectionController;
use App\Http\Controllers\SubmissionSourceController;
use App\Http\Controllers\UserController;
use App\Models\Solution;
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

    // An access link handed to somebody by an admin (People > Acesso). It leads to
    // the password screen and NEVER to a session — see AccessLinkController for why
    // that distinction is the whole design.
    Route::get('access/{token}', [AccessLinkController::class, 'show'])->name('access.show');
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

    // A solution's documentation is no longer ITS OWN: it lives in cadernos
    // (see `notebooks.*` below), and a solution links to as many as describe
    // it. This shim keeps old bookmarks working — it lands on the first caderno
    // linked to the solution, or on the solution itself when none is.
    Route::get('solutions/{solution}/documentation', function (Solution $solution) {
        $notebook = $solution->notebooks()->first();

        return $notebook
            ? redirect()->route('notebooks.show', $notebook)
            : redirect()->route('solutions.show', $solution);
    })->name('solutions.docs.legacy');

    // F5 — people and companies (Stage 2).
    Route::get('people', [PersonController::class, 'index'])->name('people.index');
    Route::get('people/new', [PersonController::class, 'create'])->name('people.create');
    // "Quem tem acesso" — a VIEW of the Pessoas module, not a modal in the
    // sidebar menu. A static segment, so it has to stay ahead of
    // `people/{person}` or it resolves as a person's slug.
    Route::get('people/accounts', [PersonController::class, 'accounts'])->name('people.accounts');
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
    /*
     |------------------------------------------------------------------
     | A person's ACCESS — the account they log in with
     |------------------------------------------------------------------
     |
     | All of it is `UserPolicy::manage` (admin), never `PersonPolicy::update`
     | (admin OR editor): an editor curates this person and must not be able to
     | hand out an account. `{user}` is the account behind the person, and the
     | controller reaches it through the route so a mismatched pair cannot be
     | posted — see PersonAccessController.
     */
    Route::post('people/{person}/access', [PersonAccessController::class, 'store'])->name('people.access.store');
    Route::patch('people/{person}/access', [PersonAccessController::class, 'link'])->name('people.access.link');
    Route::delete('people/{person}/access', [PersonAccessController::class, 'destroy'])->name('people.access.destroy');
    Route::patch('people/{person}/access/{user}/role', [PersonAccessController::class, 'updateRole'])->name('people.access.role');
    Route::post('people/{person}/access/{user}/link', [PersonAccessController::class, 'refreshLink'])->name('people.access.link.refresh');
    Route::delete('people/{person}/access/{user}/link', [PersonAccessController::class, 'destroyLink'])->name('people.access.link.destroy');

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

    // Accounts as accounts, admin-only. The SCREEN moved into the Pessoas
    // module (`people/accounts` reads the roster, a person's own page grants and
    // revokes), so what is left here are the two endpoints that are about the
    // account rather than about whose it is: inviting somebody who is not in the
    // catalog, and the role — which both screens change through this one route.
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    // The role, changed in place on that same panel. It is the only
    // administrative act in the app that used to have no screen at all: a
    // promotion to editor, or taking the admin off someone who left, meant an
    // UPDATE against the production database.
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    // Switches an account off, from the roster. It has to live here rather than
    // only on a person's Acesso card: an account does not need a Person, so an
    // orphan had its role changeable and no way to be switched off at all.
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('map', [SolutionMapController::class, 'index'])->name('solutions.map');
    Route::get('map/data', [SolutionMapController::class, 'data'])->name('solutions.map.data');
    Route::patch('map/nodes/{solution}/position', [SolutionMapController::class, 'updatePosition'])->name('solutions.map.position.update');

    // F8 — Digibee flowSpec generator (chat). The reply is generated in a job
    // (GenerateFlowspecReply); `status` is the thread's polling endpoint.
    // {message} resolves scoped to FlowspecChat::messages().
    Route::get('flowspec', [FlowspecChatController::class, 'index'])->name('flowspec.index');
    Route::post('flowspec', [FlowspecChatController::class, 'store'])->name('flowspec.store');
    Route::get('flowspec/documents/search', [FlowspecChatController::class, 'searchDocuments'])->name('flowspec.documents.search');

    // Suggestions for the text being typed — the replacement for the automatic
    // documentation injection this module used to do (see
    // FlowspecChatController::suggestDocuments). Static, and NOT nested under
    // `{chat}`: the new-chat composer needs it before a chat exists, so the chat
    // arrives as an authorized `?chat=` parameter instead.
    Route::get('flowspec/documents/suggest', [FlowspecChatController::class, 'suggestDocuments'])->name('flowspec.documents.suggest');

    // The picker panel, for the same reason: one panel serves the new-chat
    // screen and an open conversation.
    Route::get('flowspec/attachments/picker', [FlowspecAttachmentController::class, 'picker'])->name('flowspec.attachments.picker');

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
    // No standalone edit page/panel: every field is edited in place on the
    // detail header (submissions.field.update) — creation only ever asks for
    // name + solution (see StoreSubmissionRequest).
    Route::patch('submissions/{submission}/field', [SubmissionController::class, 'updateField'])->name('submissions.field.update');
    Route::delete('submissions/{submission}', [SubmissionController::class, 'destroy'])->name('submissions.destroy');

    Route::get('submissions/{submission}/export/markdown', [SubmissionExportController::class, 'markdown'])->name('submissions.export.markdown');
    Route::get('submissions/{submission}/export/ticket', [SubmissionExportController::class, 'ticket'])->name('submissions.export.ticket');
    Route::get('submissions/{submission}/export/deck', [SubmissionExportController::class, 'deck'])->name('submissions.export.deck');

    Route::post('submissions/{submission}/sources', [SubmissionSourceController::class, 'store'])->name('submissions.sources.store');
    Route::post('submissions/{submission}/decision', [SubmissionDecisionController::class, 'store'])->name('submissions.decision.store');
    Route::post('submissions/{submission}/pre-review', [SubmissionDecisionController::class, 'preReview'])->name('submissions.pre-review.store');
    Route::get('submissions/{submission}/pre-review/status', [SubmissionDecisionController::class, 'preReviewStatus'])->name('submissions.pre-review.status');
    Route::post('submissions/{submission}/conditions/{index}', [SubmissionDecisionController::class, 'toggleCondition'])
        ->whereNumber('index')
        ->name('submissions.conditions.toggle');
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

        /*
         | The submission's four drawings. `{diagram}` resolves through
         | Submission::diagrams(), so a diagram belonging to another submission
         | 404s instead of being edited through the wrong parent.
         |
         | The nine chain endpoints mirror the diagrams module's ONE FOR ONE
         | — same request classes, same controller trait (Concerns\EditsChain),
         | same response shapes — because it is the same canvas over a
         | different owner. `chain-viz.js` never learns which one it is
         | drawing: every URL it calls arrives inside the graph payload
         | (`ChainCanvas::chainUrls()`).
         |
         | The index params are a plain integer into `chain.nodes`/`chain.edges`
         | (`whereNumber`), not a model — exactly as on the diagrams routes.
         */
        Route::prefix('submissions/{submission}/diagrams/{diagram}')->name('submissions.diagrams.')->group(function () {
            Route::get('/', [SubmissionDiagramController::class, 'edit'])->name('edit');
            Route::patch('layout', [SubmissionDiagramController::class, 'saveLayout'])->name('layout.save');
            Route::get('picture', [SubmissionDiagramController::class, 'showPicture'])->name('picture.show');
            Route::post('picture', [SubmissionDiagramController::class, 'storePicture'])->name('picture.store');

            Route::post('upload', [SubmissionDiagramController::class, 'storeUpload'])->name('upload.store');
            Route::delete('upload', [SubmissionDiagramController::class, 'destroyUpload'])->name('upload.destroy');

            Route::post('chain/nodes', [SubmissionDiagramController::class, 'addNode'])->name('chain.node.add');
            Route::post('chain/images', [SubmissionDiagramController::class, 'addImageNode'])->name('chain.image.add');
            Route::patch('chain/nodes/{node}', [SubmissionDiagramController::class, 'updateNode'])->whereNumber('node')->name('chain.node.update');
            Route::delete('chain/nodes/{node}', [SubmissionDiagramController::class, 'removeNode'])->whereNumber('node')->name('chain.node.remove');

            Route::post('chain/edges', [SubmissionDiagramController::class, 'addEdge'])->name('chain.edge.add');
            Route::patch('chain/protocol/{edge}', [SubmissionDiagramController::class, 'updateProtocol'])->whereNumber('edge')->name('chain.protocol.update');
            Route::patch('chain/edge/{edge}', [SubmissionDiagramController::class, 'retargetEdge'])->whereNumber('edge')->name('chain.edge.retarget');
            Route::delete('chain/edge/{edge}', [SubmissionDiagramController::class, 'removeEdge'])->whereNumber('edge')->name('chain.edge.remove');
        });
    });

    // NOT scoped: a message is two hops from a submission (submission → chat →
    // message), and scopeBindings() would resolve `{message}` through a
    // `Submission::messages()` relation that doesn't and shouldn't exist.
    // SubmissionChatController::apply() checks the ownership explicitly instead.
    Route::post('submissions/{submission}/chat/messages/{message}/apply', [SubmissionChatController::class, 'apply'])->name('submissions.chat.messages.apply');

    /*
     | Closing the loop a committee opened: the TO BE it approved either lands on
     | a real catalog Diagram or is declared already reflected.
     |
     | NOT scoped, for the same reason the message route above isn't:
     | `scopeBindings()` resolves `{topology}` through a PLURAL
     | `Submission::topologies()`, and the relation is a HasOne — a submission is
     | deliberated once. A HasMany that can only ever hold one row would be a lie
     | written to satisfy the router, so the controller checks ownership itself.
     |
     | The target diagram is checked against the SOLUTION in
     | ApplyApprovedTopologyRequest: an approval must never overwrite a diagram
     | belonging to somebody else.
     */
    Route::post('submissions/{submission}/topology/{topology}/apply', [ApprovedTopologyController::class, 'apply'])->name('submissions.topology.apply');
    Route::post('submissions/{submission}/topology/{topology}/dismiss', [ApprovedTopologyController::class, 'dismiss'])->name('submissions.topology.dismiss');

    Route::get('flowspec/{chat}/status', [FlowspecChatController::class, 'status'])->name('flowspec.status');
    Route::post('flowspec/{chat}/messages', [FlowspecMessageController::class, 'store'])->name('flowspec.messages.store');
    Route::post('flowspec/{chat}/attachments', [FlowspecAttachmentController::class, 'store'])->name('flowspec.attachments.store');

    // Scoped: without it, DELETE flowspec/{a}/attachments/{attachment} would
    // detach context belonging to conversation {b} — including another user's,
    // since the policy is checked against {a}.
    Route::scopeBindings()->group(function () {
        Route::patch('flowspec/{chat}/attachments/{attachment}', [FlowspecAttachmentController::class, 'update'])->name('flowspec.attachments.update');
        Route::delete('flowspec/{chat}/attachments/{attachment}', [FlowspecAttachmentController::class, 'destroy'])->name('flowspec.attachments.destroy');
    });

    /*
     |--------------------------------------------------------------------------
     | Diagrams — the drawings module
     |--------------------------------------------------------------------------
     |
     | Flat, not nested: a diagram is addressed by itself. It used to be an
     | `Integration` reachable only under a solution that took part in it, which
     | meant every one of these URLs carried a `{solution}` the endpoint didn't
     | need and a `scopeBindings` check to keep the two in agreement. A diagram
     | reaches a solution the other way round now — a documentation page points
     | at it — so there is nothing left to scope.
     |
     | The nine chain endpoints are the canvas's, and they mirror the
     | submission-diagram ones ONE FOR ONE: same payloads, same responses, same
     | FormRequests. `chain-viz.js` never learns which owner it is editing,
     | because every URL it calls arrives inside the graph payload
     | (`ChainCanvas::chainUrls()`).
     |
     | `{node}`/`{edge}` are plain integer INDICES into `chain.nodes`/`.edges`
     | (`whereNumber`), not models.
     */
    Route::get('diagrams', [DiagramController::class, 'index'])->name('diagrams.index');
    Route::post('diagrams', [DiagramController::class, 'store'])->name('diagrams.store');

    Route::prefix('diagrams/{diagram}')->name('diagrams.')->group(function () {
        Route::get('/', [DiagramController::class, 'show'])->name('show');
        // Name/status only — never the chain (that is the canvas's, below).
        Route::patch('/', [DiagramController::class, 'update'])->name('update');
        Route::delete('/', [DiagramController::class, 'destroy'])->name('destroy');
        // Purely visual: block positions, edge anchors, comments, lanes, notes.
        Route::patch('layout', [DiagramController::class, 'saveLayout'])->name('layout.save');

        // The canvas's own rendered PNG, posted right after a layout save and
        // read by the CATI deck. Media only — never topology.
        Route::post('picture', [DiagramPictureController::class, 'store'])->name('picture.store');
        Route::get('picture', [DiagramPictureController::class, 'show'])->name('picture.show');

        // Kind/title of a single block — {node} is the index in the chain.
        Route::patch('chain/nodes/{node}', [DiagramController::class, 'updateNode'])
            ->whereNumber('node')->name('chain.node.update');
        // Removes a block AND every link touching it (indices shift — see removeChainNode()).
        Route::delete('chain/nodes/{node}', [DiagramController::class, 'removeNode'])
            ->whereNumber('node')->name('chain.node.remove');
        // New block, born isolated — wiring is a separate gesture.
        Route::post('chain/nodes', [DiagramController::class, 'addNode'])->name('chain.node.add');
        // New IMAGE block — pasting a picture directly on the canvas (Ctrl+V).
        Route::post('chain/images', [DiagramController::class, 'addImageNode'])->name('chain.image.add');
        // Protocol and/or direction of a single link — {edge} is the index in chain.edges.
        Route::patch('chain/protocol/{edge}', [DiagramController::class, 'updateProtocol'])
            ->whereNumber('edge')->name('chain.protocol.update');
        // Retargets one end of an existing link to a different block.
        Route::patch('chain/edge/{edge}', [DiagramController::class, 'retargetEdge'])
            ->whereNumber('edge')->name('chain.edge.retarget');
        // New link between two blocks that already exist.
        Route::post('chain/edges', [DiagramController::class, 'addEdge'])->name('chain.edge.add');
        // Removes a link without removing its blocks.
        Route::delete('chain/edge/{edge}', [DiagramController::class, 'removeEdge'])
            ->whereNumber('edge')->name('chain.edge.remove');
    });

    // Documentation Hub — the cross-cutting view of what's documented and
    // what's missing. Reads cadernos; `/notebooks` is where they're edited.
    Route::get('documentation', [DocumentationHubController::class, 'index'])->name('documentation.index');
    // Bookmarks from when a standalone group had its own URL.
    Route::get('documentation/groups/{group}', fn (string $group) => redirect()->route('notebooks.show', $group));

    /*
     |--------------------------------------------------------------------------
     | Cadernos (Notebooks) — the one container of documentation
     |--------------------------------------------------------------------------
     |
     | Flat, like `diagrams/{diagram}`, and for the same reason: a caderno is
     | addressed by ITSELF. It used to be reached through the solution that
     | owned it, which meant every URL carried a `{solution}` the endpoint
     | didn't need — and, worse, made it impossible to express the thing this
     | module exists for, one body of documentation describing several systems.
     | A caderno reaches a solution the other way round now, through the
     | `notebooks.solutions` pivot.
     |
     | Static segments come BEFORE the scopeBindings group, or they'd collide
     | with the `{page}` wildcard (same segment shape). Every one of them is
     | also in `DocumentationPageService::RESERVED_SLUGS`, which is what stops a
     | page ever being slugged into that collision.
     */
    Route::get('notebooks', [NotebookController::class, 'index'])->name('notebooks.index');
    Route::post('notebooks', [NotebookController::class, 'store'])->name('notebooks.store');
    // Create/edit side panel. Two routes rather than one optional {notebook},
    // so the create one can't be reached by inventing a slug. `panel` is a
    // reserved word on BOTH levels — see NotebookController::uniqueSlug() for
    // the caderno's slug and DocumentationPageService::RESERVED_SLUGS for the
    // page's; without that a caderno named "Panel" would be unreachable.
    Route::get('notebooks/panel', [NotebookController::class, 'panel'])->name('notebooks.panel.create');
    Route::get('notebooks/{notebook}/panel', [NotebookController::class, 'panel'])->name('notebooks.panel.edit');
    Route::get('notebooks/{notebook}', [NotebookController::class, 'show'])->name('notebooks.show');
    Route::patch('notebooks/{notebook}', [NotebookController::class, 'update'])->name('notebooks.update');
    Route::delete('notebooks/{notebook}', [NotebookController::class, 'destroy'])->name('notebooks.destroy');
    // Which solutions this caderno documents — a full sync, never a toggle.
    Route::patch('notebooks/{notebook}/solutions', [NotebookController::class, 'syncSolutions'])->name('notebooks.solutions');
    // Public documentation link ("magic link"): generate/revoke (admin).
    Route::post('notebooks/{notebook}/share', [NotebookController::class, 'share'])->name('notebooks.share');
    Route::delete('notebooks/{notebook}/share', [NotebookController::class, 'unshare'])->name('notebooks.unshare');
    // Rotates the caderno's secret code — the string that unlocks the protected
    // values in its pages (admin only, NotebookPolicy::administer). A STATIC
    // segment in the same position as `{page}` below, so it must stay ahead of
    // the scopeBindings group AND be reserved in
    // DocumentationPageService::RESERVED_SLUGS, or a page slugged `secret-code`
    // would shadow it.
    Route::post('notebooks/{notebook}/secret-code', [NotebookController::class, 'rotateSecretCode'])->name('notebooks.secret-code');

    // "Assiste IA" context documents (`context_documents` collection), per
    // caderno — shared by every page in its tree. {media} is a global binding
    // of the Spatie model (checked against the notebook in the controller), so
    // it stays outside the {page} scopeBindings.
    Route::post('notebooks/{notebook}/context', [NotebookContextDocumentController::class, 'store'])->name('notebooks.context.store');
    Route::get('notebooks/{notebook}/context/{media}', [NotebookContextDocumentController::class, 'show'])->name('notebooks.context.show');
    Route::delete('notebooks/{notebook}/context/{media}', [NotebookContextDocumentController::class, 'destroy'])->name('notebooks.context.destroy');

    Route::post('notebooks/{notebook}/pages', [NotebookPageController::class, 'store'])->name('notebooks.pages.store');

    // Documentation Assistant polling — the chat carries its own target, so it
    // doesn't need {page} in the URL (and avoids scopeBindings' auto-scope
    // trying to resolve {chat} as a page's child).
    Route::get('notebooks/{notebook}/chat/{chat}/status', [NotebookPageController::class, 'chatStatus'])->name('notebooks.chat.status');
    // Marks a message's draft as applied.
    Route::post('notebooks/{notebook}/chat/messages/{message}/apply', [NotebookPageController::class, 'applyChatMessage'])->name('notebooks.chat.messages.apply');

    // {page} resolves via Notebook::pages(), so a page belonging to another
    // caderno 404s instead of being edited through the wrong owner.
    Route::scopeBindings()->group(function () {
        Route::get('notebooks/{notebook}/{page}', [NotebookPageController::class, 'edit'])->name('notebooks.pages.edit');
        Route::patch('notebooks/{notebook}/{page}', [NotebookPageController::class, 'update'])->name('notebooks.pages.update');
        Route::patch('notebooks/{notebook}/{page}/title', [NotebookPageController::class, 'rename'])->name('notebooks.pages.rename');
        Route::delete('notebooks/{notebook}/{page}', [NotebookPageController::class, 'destroy'])->name('notebooks.pages.destroy');
        Route::patch('notebooks/{notebook}/{page}/move', [NotebookPageController::class, 'move'])->name('notebooks.pages.move');
        // Re-files the page under ANOTHER caderno — `/move` above only reorders
        // it within this one. This is what empties the GitBook import's landing
        // zone.
        Route::patch('notebooks/{notebook}/{page}/notebook', [NotebookPageController::class, 'moveToNotebook'])->name('notebooks.pages.notebook');
        Route::post('notebooks/{notebook}/{page}/media', [NotebookPageController::class, 'media'])->name('notebooks.pages.media');
        // One protected value of this page, by its ORDINAL in the text — an
        // index into the `{% secret %}` constructs, validated as numeric and
        // range-checked by the action, exactly like the chain routes' node/edge
        // indices. POST rather than GET: it carries the code, and a URL that
        // ends in the code would be in every history and every access log.
        Route::post('notebooks/{notebook}/{page}/secrets/{index}', [NotebookPageController::class, 'revealSecret'])
            ->whereNumber('index')
            ->name('notebooks.pages.secrets');
        // Documentation Assistant — a chat that helps write the page (job + polling per turn).
        Route::get('notebooks/{notebook}/{page}/chat', [NotebookPageController::class, 'chatPanel'])->name('notebooks.chat.panel');
        Route::post('notebooks/{notebook}/{page}/chat/messages', [NotebookPageController::class, 'sendMessage'])->name('notebooks.chat.messages.store');
    });

    // Media embedded in documentation (images/files from the `docs`
    // collection), referenced via /files/{id} inside the Markdown. Authenticated only.
    Route::get('files/{media}', [MediaController::class, 'show'])->name('files.show');

    // The diagram catalog as a solution-grouped tree, for the documentation
    // editor's `diagram` block picker. Read-only JSON.
    Route::get('diagrams-catalog', [DiagramController::class, 'catalog'])->name('diagrams.catalog');

    // Heroicons outline icons for the documentation callout picker (only the
    // editor consumes this; read-only views already ship with the rendered SVG).
    Route::get('heroicons/outline', [HeroiconController::class, 'outline'])->name('heroicons.outline');

    // Form components demo (Stage 0 — DoD: renders in isolation)
    Route::view('components', 'showcase')->name('showcase');
});

// Public documentation ("magic link") — NO auth. Access via an opaque token in
// the URL (Notebook::public_token); embedded media is served by a dedicated
// route validated against the caderno's own pages (PublicDocumentationController).
// The path stays `public-docs/{token}` — the token is the only meaningful part
// of it, so every link handed out before the container swap keeps resolving.
Route::get('public-docs/{token}', [PublicDocumentationController::class, 'notebook'])->name('public.docs.notebook');
// {slug} is not model-bound: a page's slug is only unique within its
// notebook, never globally (see PublicDocumentationController::page()).
Route::get('public-docs/{token}/page/{slug}', [PublicDocumentationController::class, 'page'])->name('public.docs.page');
Route::get('public-docs/{token}/file/{media}', [PublicDocumentationController::class, 'file'])->name('public.docs.file');
// The rendered picture of a diagram CITED by a page in the shared caderno. The
// canvas itself stays behind auth — this serves the image and nothing else, the
// same split `public.docs.file` makes for embedded media.
Route::get('public-docs/{token}/diagram/{diagram}', [PublicDocumentationController::class, 'diagramPicture'])->name('public.docs.diagram');
// One protected value of a page in the shared caderno. The magic link does NOT
// carry the right to read it: the token grants the prose, the caderno's secret
// code grants a value inside it, and the same five-per-twelve-hours limit
// applies here as on the authenticated surface (App\Actions\Documentation\RevealPageSecret).
Route::post('public-docs/{token}/secrets/{slug}/{index}', [PublicDocumentationController::class, 'revealSecret'])
    ->whereNumber('index')
    ->name('public.docs.secrets');
// Search over the shared caderno's own corpus (docs-search.js). JSON
// only, and on its own path — it never shares a URL with a document response,
// so the Back-button collision PreventJsonResponseCaching guards against
// cannot arise here (see § Caching in AGENTS.md).
Route::get('public-docs/{token}/search', [PublicDocumentationController::class, 'search'])->name('public.docs.search');

Route::get('/', fn () => auth()->check()
    ? redirect()->route('profile.show')
    : redirect()->route('login.create')
);
