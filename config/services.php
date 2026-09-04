<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Digibee flowSpec generator (F8, /flowspec chat). Mirrors the Laravel\Ai
    | AnonymousAgent pattern: resolve provider/model from config so the agent
    | stays env-driven.
    */
    'flowspec' => [
        'provider' => env('DIGIBEE_FLOWSPEC_AI_PROVIDER', 'gemini'),
        'model'    => env('DIGIBEE_FLOWSPEC_AI_MODEL', 'gemini-3.6-flash'),

        // Corpus selection and correction loop (FlowspecGenerationService).
        // 2-3 examples: more dilutes the signal.
        'max_examples'     => env('DIGIBEE_FLOWSPEC_MAX_EXAMPLES', 3),
        'max_attempts'     => env('DIGIBEE_FLOWSPEC_MAX_ATTEMPTS', 3),
        'fallback_example' => env('DIGIBEE_FLOWSPEC_FALLBACK_EXAMPLE', 'update-bigquery-rest'),
        'timeout'          => env('DIGIBEE_FLOWSPEC_AI_TIMEOUT', 180),

        /*
        |----------------------------------------------------------------------
        | Context window (FlowspecContextBudget)
        |----------------------------------------------------------------------
        |
        | The conversation's attached context is chat-scoped, so it is re-sent
        | on EVERY turn — which is exactly how a long thread with a few big
        | documents turns into runaway spend. These three numbers are the guard,
        | and they are the only thing standing between a full inventory dump and
        | the provider's bill. See App\Support\Context\TokenEstimator for how
        | a token count is estimated from characters and bytes.
        |
        */

        // Total estimated tokens one request may carry: fixed prompt + corpus
        // examples + attached context + conversation history.
        'context_limit_tokens' => env('DIGIBEE_FLOWSPEC_CONTEXT_LIMIT_TOKENS', 500000),

        // Slice of that limit attachments may NEVER take, so the conversation
        // itself always has room. Attaching is blocked at
        // `context_limit_tokens - history_reserve_tokens`; the history then uses
        // whatever is actually left and trims its oldest turns to fit (see
        // FlowspecPromptBuilder::historySection). Without this reserve, filling
        // the window with documents would lock someone out of their own chat.
        'history_reserve_tokens' => env('DIGIBEE_FLOWSPEC_HISTORY_RESERVE_TOKENS', 40000),

        // Count ceiling on one chat's attachments, independent of their size:
        // bounds the per-turn query/render work even when every attachment is
        // tiny. Far above any realistic hand-picked selection.
        'max_attachments' => env('DIGIBEE_FLOWSPEC_MAX_ATTACHMENTS', 40),

        // Aggregate byte ceiling for native attachments (PDF/image) in one
        // request, mirroring documentation_ai — below the provider's inline
        // request limit. Past it, further files are omitted and flagged.
        'max_attachment_bytes' => env('DIGIBEE_FLOWSPEC_MAX_ATTACHMENT_BYTES', 18000000),

        // Pasting more than this many characters into the composer turns the
        // paste into a text attachment instead of stuffing the textarea — the
        // behavior the Claude client has. Mirrored client-side in
        // flowspec-chat.js, so keep the two in step.
        'paste_threshold_chars' => env('DIGIBEE_FLOWSPEC_PASTE_THRESHOLD_CHARS', 2000),

        // Char ceiling for ONE pasted text attachment. Sized for a full pasted
        // pipeline JSON (recognized as a flowSpec reference and minified by
        // NormalizeReferenceFlowspec before it reaches the prompt), which
        // easily exceeds the prose `message` cap of 8000.
        'max_reference_chars' => env('DIGIBEE_FLOWSPEC_MAX_REFERENCE_CHARS', 200000),

        // "Add documentation" buttons offered next to the composer and
        // alongside a conversational reply
        // (FlowspecContextResolver::suggestFor) — more than this turns
        // into noise. These are SUGGESTIONS only: nothing enters the context
        // until someone clicks, which is why name-matching no longer injects
        // documentation on its own.
        'max_suggested_documents' => env('DIGIBEE_FLOWSPEC_MAX_SUGGESTED_DOCUMENTS', 6),

        // Validation ceiling for a single FlowspecGuideline document (always
        // folded into systemPrompt() in full — no runtime budget/omission
        // like doc_budget_chars, since this content is curated, not an open
        // corpus). 20k chars is several pages — enough for any legitimate
        // note, small enough to catch someone pasting a whole document in.
        'max_guideline_chars' => env('DIGIBEE_FLOWSPEC_MAX_GUIDELINE_CHARS', 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digibee knowledge base — two corpora, deliberately separate
    |--------------------------------------------------------------------------
    |
    | The flowSpec generator answers with a document that has to paste into the
    | Digibee canvas, and until now the only thing it knew about a connector was
    | its NAME (database/data/digibee_component_catalog.json, 34 of them). Two
    | sources fill that in, and they are not interchangeable:
    |
    | - The PLATFORM DOCS say what a connector can do — parameters, types,
    |   defaults, which ones appear only under a condition. Public HTTP, no
    |   credential, so the sync is safe to schedule inside the app.
    | - Our own PIPELINES say how we spell it — the real JSON keys (the docs
    |   name UI labels: "Stop On Client Error", not `stopOnClientError`), our
    |   `global.*` names and account labels. That one comes out of `digibeectl`,
    |   whose credential can create and delete deployments in production, so it
    |   NEVER runs on the server. See App\Support\Digibee\DigibeectlClient.
    |
    */
    'digibee' => [
        // The published documentation, mirrored by `php artisan digibee:docs:sync`.
        'docs_url'         => env('DIGIBEE_DOCS_URL', 'https://docs.digibee.com'),
        'docs_index'       => env('DIGIBEE_DOCS_INDEX', '/llms.txt'),
        'docs_timeout'     => env('DIGIBEE_DOCS_TIMEOUT', 30),
        'docs_retries'     => env('DIGIBEE_DOCS_RETRIES', 3),
        'docs_retry_sleep' => env('DIGIBEE_DOCS_RETRY_SLEEP', 500),

        // Pages fetched per pool. 581 pages at 8.5 KB average is a ~5 MB
        // corpus; batching keeps the whole sync to a couple of minutes without
        // opening 581 sockets at once against somebody else's docs site.
        'docs_batch' => env('DIGIBEE_DOCS_BATCH', 12),

        // The caderno `digibee:docs:import` writes into. Named, not id'd, for
        // the same reason ImportGitbookSpace resolves one by name: a re-import
        // has to land in the caderno the last one made.
        'docs_notebook' => env('DIGIBEE_DOCS_NOTEBOOK', 'Digibee — Documentação da plataforma'),

        // digibeectl (Windows binary on a developer's machine, authenticated
        // against the real tenant) and where its export lands. The export is
        // under storage/app/private, which is gitignored in full: real
        // pipelines carry internal hostnames and IPs, and only the DERIVED,
        // scrubbed vocabulary is committed.
        'ctl_bin'       => env('DIGIBEECTL_BIN', '/mnt/c/Users/alexandre.kopelevitc/digibeectl/digibeectl.exe'),
        'ctl_timeout'   => env('DIGIBEECTL_TIMEOUT', 120),
        'pipelines_dir' => env('DIGIBEE_PIPELINES_DIR', 'digibee-pipelines'),

        // The two DERIVED artifacts, committed beside the component catalog so
        // a generated flowSpec stays reproducible from a checkout and a change
        // to either shows up in a diff somebody reads. Configurable because a
        // test that rebuilds them must not overwrite the ones in the repo.
        'cards_path'      => env('DIGIBEE_CARDS_PATH', database_path('data/digibee_connector_cards.json')),
        'vocabulary_path' => env('DIGIBEE_VOCABULARY_PATH', database_path('data/digibee_tenant_vocabulary.json')),

        // How many real usages of one connector's params the vocabulary keeps.
        // Enough to show the shape and the optional keys; not so many that the
        // artifact becomes a copy of the export.
        'usage_samples' => env('DIGIBEE_USAGE_SAMPLES', 3),

        // Connector reference cards folded into one flowSpec prompt. A card is
        // ~1-3 KB, so this is small against the 500k context limit — the cap is
        // about ATTENTION, the same reason max_examples is 3.
        'max_connector_cards' => env('DIGIBEE_MAX_CONNECTOR_CARDS', 6),

        /*
        |----------------------------------------------------------------------
        | Platform API (APLA — the autonomous lifecycle)
        |----------------------------------------------------------------------
        |
        | The lifecycle agent needs five operations `digibeectl` already covers
        | (deploy, redeploy, deployment status, metrics, deployment history) and
        | one it does not: writing a flowSpec into a pipeline. `create pipeline`
        | takes only --name/--description/--project and makes an empty shell —
        | confirmed in the CLI's own help and in Digibee's published operations
        | table — so the upsert has no supported interface and goes through the
        | platform's own (undocumented) design routes.
        |
        | **This reverses the boundary stated in DigibeectlClient, deliberately
        | and only for a NARROWER credential.** That rule — "the artifact
        | travels, the credential does not" — was written about the interactive
        | login, which can delete production deployments. Digibee documents
        | per-operation permissions (PIPELINE:READ, DEPLOYMENT:CREATE,
        | DEPLOYMENT:CREATE:REDEPLOY, DEPLOYMENT:DELETE, CONFIGURATION:*) and
        | digibeectl authenticates with a key pair rather than a login, so the
        | credential this reads is meant to be a realm user restricted to the
        | operations below. `digibee:pipelines:pull` stays off the server
        | regardless: it still needs the broad one.
        |
        */
        'design' => [
            // Resolved by App\Support\Digibee\DigibeeAuthResolver, environment
            // first. These four MUST be encrypted environment variables on the
            // server (AGENTS.md § Security) — `jwt` in particular is a bearer
            // credential for the whole realm the key pair is scoped to.
            'endpoint' => env('DIGIBEE_ENDPOINT'),
            'realm'    => env('DIGIBEE_REALM'),
            'jwt'      => env('DIGIBEE_JWT'),
            'apikey'   => env('DIGIBEE_APIKEY'),

            // Fallback for a workstation, where the session already exists.
            // Empty means "$HOME/.digibeectl/config.json" — which under WSL is
            // NOT where the real file lives (digibeectl is a Windows binary, so
            // its config sits under /mnt/c/Users/...), so on a dev machine this
            // is set rather than defaulted.
            'config_path' => env('DIGIBEECTL_CONFIG', ''),

            'timeout'     => env('DIGIBEE_API_TIMEOUT', 30),
            'retries'     => env('DIGIBEE_API_RETRIES', 2),
            'retry_sleep' => env('DIGIBEE_API_RETRY_SLEEP', 500),

            // Where a deployed pipeline is CALLED, which is a different host
            // from the design API and belongs to the test runner:
            // {runtime_url}/pipeline/{realm}/{environment}/v1/{pipelineName}.
            'runtime_url' => env('DIGIBEE_RUNTIME_URL', 'https://api.godigibee.io'),

            // The guardrail that actually matters, and it is not the delete
            // routes §5 of the spec worries about: `create deployment -e prod`
            // is the destructive verb here, because promotion is what reaches
            // real traffic. Nothing may deploy to an environment absent from
            // this list, so opening production is an explicit act of
            // configuration rather than an argument the agent can choose.
            'deployable_environments' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('DIGIBEE_DEPLOYABLE_ENVIRONMENTS', 'test')),
            ))),
        ],
    ],

    /*
    | Documentation "AI assist" — populates the current page via LLM based on
    | a prompt + Solution context documents (App\Services\Documentation).
    | Same env-driven pattern as flowspec; the API key is read by config/ai.php
    | from the laravel/ai package (provider gemini => GEMINI_API_KEY).
    */
    /*
    |--------------------------------------------------------------------------
    | CATI submissions — the adaptive interview
    |--------------------------------------------------------------------------
    |
    | Same env-driven pattern as `flowspec` and `documentation_ai`; the API key
    | is read by config/ai.php from the laravel/ai package (provider gemini =>
    | GEMINI_API_KEY).
    |
    */
    'cati' => [
        'provider' => env('CATI_AI_PROVIDER', 'gemini'),
        'model'    => env('CATI_AI_MODEL', 'gemini-3.6-flash'),
        'timeout'  => env('CATI_AI_TIMEOUT', 180),

        // 2-3 past submissions: more dilutes the signal instead of adding to
        // it — the same conclusion the flowSpec corpus reached.
        'max_examples' => env('CATI_MAX_EXAMPLES', 3),

        // Correction loop for the slide-condensation pass: a section that comes
        // back too long is asked again, naming what was wrong. Only that pass
        // uses it — the interview has nothing to validate against.
        'max_attempts' => env('CATI_MAX_ATTEMPTS', 3),

        // Ceiling on the gathered material folded into one turn. A single old
        // deck is ~10k chars, so this holds several without crowding out the
        // checklist and the conversation history.
        'doc_budget_chars' => env('CATI_DOC_BUDGET_CHARS', 60000),

        // Ceiling on the conversation history folded into one turn.
        //
        // Everything else in this prompt is already bounded — material by
        // `doc_budget_chars`, examples by `max_examples`, guidelines by
        // curation — and the history was the one part that grew forever while
        // being re-sent on EVERY turn. A long interview is the normal case
        // here, not the edge one, so the failure mode was a provider error
        // arriving halfway through a submission somebody had been filling in
        // all afternoon.
        //
        // Trimmed oldest-first and always reported to the model
        // (SubmissionChatPromptBuilder::historySection) — a conversation that
        // silently forgot its own beginning reads as the assistant losing
        // track.
        'history_budget_chars' => env('CATI_HISTORY_BUDGET_CHARS', 40000),

        // Aggregate byte ceiling for native attachments (PDF/image) in one
        // request, mirroring documentation_ai.
        'max_attachment_bytes' => env('CATI_MAX_ATTACHMENT_BYTES', 20971520),

        // Past this many characters, a paste into the interview's composer
        // becomes a `text` material source instead of the message itself —
        // the Claude client's behaviour. Mirrored in cati-chat.js (served
        // from here, so the two can't drift), and deliberately the same
        // default as the flowSpec composer: it is the same gesture.
        'paste_threshold_chars' => env('CATI_PASTE_THRESHOLD_CHARS', 2000),

        // Ceiling for ONE pasted text source. Well above the prose `message`
        // cap of 8000 because the whole point is that a pasted document is
        // not prose — a previous CATI deck reads out at ~10k characters, and
        // `doc_budget_chars` is what actually decides how much of it reaches
        // a prompt.
        'max_pasted_chars' => env('CATI_MAX_PASTED_CHARS', 200000),

        // Deck rendering (Fase 2). The renderer is a Python script driven over
        // Symfony Process — python-pptx opens the real corporate template and
        // uses its layouts, which is what keeps a generated deck from looking
        // "almost" corporate. See docs/cati-fase-2.md for the venv setup.
        'python'        => env('CATI_PYTHON', base_path('.venv-cati/bin/python')),
        'deck_script'   => env('CATI_DECK_SCRIPT', base_path('scripts/render_deck.py')),
        'deck_template' => env('CATI_DECK_TEMPLATE', resource_path('cati/cati-template.pptx')),
        'deck_timeout'  => env('CATI_DECK_TIMEOUT', 120),
    ],

    'documentation_ai' => [
        'provider' => env('DOCS_AI_PROVIDER', 'gemini'),
        'model'    => env('DOCS_AI_MODEL', 'gemini-3.6-flash'),
        'timeout'  => env('DOCS_AI_TIMEOUT', 180),
        // Character budget for TEXT context documents embedded in the prompt
        // (PDF/image go as attachments, outside this limit).
        'doc_budget_chars'      => env('DOCS_AI_DOC_BUDGET_CHARS', 60000),
        'max_context_documents' => env('DOCS_AI_MAX_CONTEXT_DOCUMENTS', 10),
        // Other documentation PAGES a turn may be given as reference, and the
        // characters they get between them. Separate from `doc_budget_chars`
        // on purpose: an uploaded document and a page of this app compete for
        // the same prompt, and one runaway 100k page must not be able to push
        // out the PDF somebody attached (or the other way round).
        //
        // Five, and 40k between them: a documentation page in the imported
        // corpus averages ~10k characters, so this is "a handful of pages"
        // rather than "a caderno" — which is the right unit, since the point is
        // the two or three systems on the other end of what is being
        // documented. Past the budget the remaining pages are omitted and
        // FLAGGED (meta.omitted_pages), never dropped in silence: somebody
        // picked them by hand.
        'max_context_pages' => env('DOCS_AI_MAX_CONTEXT_PAGES', 5),
        'page_budget_chars' => env('DOCS_AI_PAGE_BUDGET_CHARS', 40000),
        // Pasting more than this many characters into the Assiste IA composer
        // turns the paste into a context document instead of stuffing the
        // textarea — the same gesture the F8 composer has, and the same number.
        // Mirrored client-side (docs-chat.js reads it off the composer's config
        // attribute, so the two cannot drift).
        'paste_threshold_chars' => env('DOCS_AI_PASTE_THRESHOLD_CHARS', 2000),
        // Ceiling for ONE pasted context document. Sized for a whole pipeline
        // JSON, which is minified on the way in (AttachContextText); what
        // actually reaches the prompt is bounded again by doc_budget_chars.
        'max_pasted_chars' => env('DOCS_AI_MAX_PASTED_CHARS', 200000),
        // Aggregate byte ceiling for native attachments (PDF/image) in one
        // generation — below the Gemini API's ~20MB inline-request limit; once
        // exceeded, further attachments are omitted (flagged in meta.omitted_attachments).
        'max_attachment_bytes' => env('DOCS_AI_MAX_ATTACHMENT_BYTES', 18000000),
        // A generation stuck in `pending` past this many seconds is treated as
        // orphaned: its worker died mid-job (e.g. `composer dev` restarted)
        // without running handle() (=> completed) or failed() (=> failed), so
        // it never leaves `pending` on its own and blocks every future draft
        // for the same target. Matches the queue's retry_after (900s) — the
        // point past which Laravel itself assumes a reserved job is dead — and
        // stays well above the job's own $timeout (600s), so a slow-but-alive
        // generation is never reaped by mistake.
        'stale_after' => (int) env('DOCS_AI_STALE_AFTER', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitBook import — pulling existing spaces into the documentation hub
    |--------------------------------------------------------------------------
    |
    | One-way pull (`php artisan gitbook:import`): a GitBook space becomes a
    | standalone DocumentationGroup and each of its pages a DocumentationPage.
    | Strictly read-only against GitBook — nothing here ever writes back.
    |
    | `token` is a personal API token (GitBook › Developer settings), which
    | only needs the `space:read` scope. It is a credential: in production it
    | belongs in Laravel's encrypted environment file, not in a plain .env.
    |
    */
    'gitbook' => [
        'token' => env('GITBOOK_API_TOKEN'),
        'url'   => env('GITBOOK_API_URL', 'https://api.gitbook.com/v1'),

        // The markdown endpoint is one request PER PAGE, so a 200-page space
        // is 200 round trips — worth a generous timeout, but a real one.
        'timeout' => (int) env('GITBOOK_API_TIMEOUT', 30),

        // Embedded images/files are re-hosted into the page's own `docs`
        // collection so the imported Markdown never points back at GitBook's
        // CDN (a link that dies the day the space does). Ceiling per asset.
        // 64MB. Sized from the corpus rather than guessed: the first full
        // import left 12 assets behind for size alone, the largest 59.5MB.
        // Note this is only ever HALF the answer — `GitbookAssetImporter`
        // takes the smaller of this and `media-library.max_file_size`, and it
        // was Spatie's 10MB default doing the clamping, not this number.
        'max_asset_bytes' => (int) env('GITBOOK_MAX_ASSET_BYTES', 67108864),

        // An import is one request per page, so a space is a long chain of
        // them and a single transient blip would otherwise end the whole run.
        // Observed for real on the first live import: WSL2 here reports
        // `System clock synchronized: no` and its DNS resolution intermittently
        // times out, so `cURL error 28: Resolving timed out` landed mid-scan.
        // Retries cover connection errors and 429/5xx only — a 404 is an
        // answer, not a blip. `retry_sleep` is milliseconds; tests set it to 0.
        'retries'     => (int) env('GITBOOK_API_RETRIES', 3),
        'retry_sleep' => (int) env('GITBOOK_API_RETRY_SLEEP', 500),
    ],

];
