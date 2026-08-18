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

        // Context selection (FlowspecContextResolver) and correction loop
        // (FlowspecGenerationService). 2-3 examples: more dilutes the signal.
        'max_examples'     => env('DIGIBEE_FLOWSPEC_MAX_EXAMPLES', 3),
        'max_attempts'     => env('DIGIBEE_FLOWSPEC_MAX_ATTEMPTS', 3),
        'doc_budget_chars' => env('DIGIBEE_FLOWSPEC_DOC_BUDGET_CHARS', 60000),
        'fallback_example' => env('DIGIBEE_FLOWSPEC_FALLBACK_EXAMPLE', 'update-bigquery-rest'),
        'timeout'          => env('DIGIBEE_FLOWSPEC_AI_TIMEOUT', 180),

        // Char ceiling for the optional reference flowSpec pasted into the
        // composer (NormalizeReferenceFlowspec minifies it and drops the
        // canvas `meta` before it reaches the prompt). The prose `message`
        // stays capped at 8000 — this is separate headroom for a full pasted
        // pipeline JSON, which easily exceeds that.
        'max_reference_chars' => env('DIGIBEE_FLOWSPEC_MAX_REFERENCE_CHARS', 200000),

        // "Add documentation" buttons offered alongside a conversational
        // reply (FlowspecContextResolver::suggestDocumentsFor) — more than
        // this turns into noise in the chat bubble.
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

];
