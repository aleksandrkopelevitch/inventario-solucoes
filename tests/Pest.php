<?php

use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\Flowspec\FlowspecContext;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full application (via Tests\TestCase, which resolves
| the app from bootstrap/app.php). Unit tests stay framework-free unless they
| opt into the base case explicitly.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A flowSpec context with nothing attached — what the prompt-builder tests need,
 * since they exercise one section at a time rather than a resolved conversation.
 */
function emptyFlowspecContext(): FlowspecContext
{
    return new FlowspecContext(
        pages: collect(),
        textDocs: collect(),
        referenceFlowspecs: collect(),
        attachments: [],
        attachedMeta: [],
        omittedAttachments: [],
        examples: collect(),
        tags: [],
    );
}

/** Attaches an inventory documentation page to a conversation's context. */
function attachPage(FlowspecChat $chat, DocumentationPage $page): FlowspecAttachment
{
    return FlowspecAttachment::factory()->for($chat, 'chat')->document($page)->create();
}

/**
 * A caderno that documents `$solution` — the indirection every "documentation
 * about system X" fixture needs now that a Solution owns no pages of its own.
 *
 * Pass the same solution twice to get two cadernos about it; pass several
 * solutions to one call to get the shape the module exists for, one body of
 * text describing all of them.
 */
function notebookFor(Solution ...$solutions): Notebook
{
    $notebook = Notebook::factory()->create();
    $notebook->solutions()->attach(collect($solutions)->pluck('id')->all());

    return $notebook;
}
