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
        'model'    => env('DIGIBEE_FLOWSPEC_AI_MODEL', 'gemini-3.5-flash'),

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
    ],

    /*
    | Documentation "AI assist" — populates the current page via LLM based on
    | a prompt + Solution context documents (App\Services\Documentation).
    | Same env-driven pattern as flowspec; the API key is read by config/ai.php
    | from the laravel/ai package (provider gemini => GEMINI_API_KEY).
    */
    'documentation_ai' => [
        'provider' => env('DOCS_AI_PROVIDER', 'gemini'),
        'model'    => env('DOCS_AI_MODEL', 'gemini-3.5-flash'),
        'timeout'  => env('DOCS_AI_TIMEOUT', 180),
        // Character budget for TEXT context documents embedded in the prompt
        // (PDF/image go as attachments, outside this limit).
        'doc_budget_chars'      => env('DOCS_AI_DOC_BUDGET_CHARS', 60000),
        'max_context_documents' => env('DOCS_AI_MAX_CONTEXT_DOCUMENTS', 10),
        // Aggregate byte ceiling for native attachments (PDF/image) in one
        // generation — below the Gemini API's ~20MB inline-request limit; once
        // exceeded, further attachments are omitted (flagged in meta.omitted_attachments).
        'max_attachment_bytes' => env('DOCS_AI_MAX_ATTACHMENT_BYTES', 18000000),
    ],

];
