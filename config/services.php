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
        'provider' => env('DIGIBEE_FLOWSPEC_AI_PROVIDER', 'anthropic'),
        'model'    => env('DIGIBEE_FLOWSPEC_AI_MODEL', 'claude-sonnet-5'),

        // Seleção de contexto (FlowspecContextResolver) e loop de correção
        // (FlowspecGenerationService). 2-3 exemplos: mais dilui o sinal.
        'max_examples'     => env('DIGIBEE_FLOWSPEC_MAX_EXAMPLES', 3),
        'max_attempts'     => env('DIGIBEE_FLOWSPEC_MAX_ATTEMPTS', 3),
        'doc_budget_chars' => env('DIGIBEE_FLOWSPEC_DOC_BUDGET_CHARS', 60000),
        'fallback_example' => env('DIGIBEE_FLOWSPEC_FALLBACK_EXAMPLE', 'update-bigquery-rest'),
        'timeout'          => env('DIGIBEE_FLOWSPEC_AI_TIMEOUT', 180),

        // Botões de "adicionar documentação" oferecidos junto de uma resposta
        // conversacional (FlowspecContextResolver::suggestDocumentsFor) —
        // mais que isso vira ruído na bolha do chat.
        'max_suggested_documents' => env('DIGIBEE_FLOWSPEC_MAX_SUGGESTED_DOCUMENTS', 6),
    ],

    /*
    | "Assiste IA" da documentação — popula a página corrente por LLM com base
    | num prompt + documentos de contexto da Solução (App\Services\Documentation).
    | Mesmo padrão env-driven do flowspec; a chave da API é lida pelo config/ai.php
    | do pacote laravel/ai (provider anthropic => ANTHROPIC_API_KEY).
    */
    'documentation_ai' => [
        'provider' => env('DOCS_AI_PROVIDER', 'anthropic'),
        'model'    => env('DOCS_AI_MODEL', 'claude-sonnet-5'),
        'timeout'  => env('DOCS_AI_TIMEOUT', 180),
        // Orçamento de caracteres para os documentos de contexto de TEXTO
        // embutidos no prompt (PDF/imagem vão como anexo, fora deste limite).
        'doc_budget_chars'      => env('DOCS_AI_DOC_BUDGET_CHARS', 60000),
        'max_context_documents' => env('DOCS_AI_MAX_CONTEXT_DOCUMENTS', 10),
        // Teto agregado de bytes dos anexos nativos (PDF/imagem) numa geração —
        // abaixo do limite de ~32MB por requisição da API do Claude; excedeu,
        // os anexos seguintes são omitidos (sinalizados em meta.omitted_attachments).
        'max_attachment_bytes' => env('DOCS_AI_MAX_ATTACHMENT_BYTES', 28000000),
    ],

];
