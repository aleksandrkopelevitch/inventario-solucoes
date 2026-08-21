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
        | Attached context (FlowspecAttachment)
        |----------------------------------------------------------------------
        |
        | A conversation's attached context is re-sent on EVERY turn, so these
        | bound what one chat can accumulate.
        |
        */

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

        // Aggregate byte ceiling for native attachments (PDF/image) in one
        // request, mirroring documentation_ai.
        'max_attachment_bytes' => env('CATI_MAX_ATTACHMENT_BYTES', 20971520),

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
        'max_asset_bytes' => (int) env('GITBOOK_MAX_ASSET_BYTES', 20971520),

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
