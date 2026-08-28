<?php

namespace App\Enums;

/**
 * Closed vocabulary of tags for the flowSpec example corpus (F8).
 * Standardizes seeder/curation tagging and feeds the example selector:
 * `keywords()` is the request-word -> tag map used by
 * FlowspecContextResolver to derive candidate tags without RAG.
 */
enum FlowspecTag: string
{
    case TokenCache = 'token-cache';
    case Token = 'token';
    case Rest = 'rest';
    case Soap = 'soap';
    case Ldap = 'ldap';
    case ObjectStore = 'object-store';
    case Choice = 'choice';
    case Webhook = 'webhook';
    case DePara = 'de-para';
    case JsltAlias = 'jslt-alias';
    case Fallbacks = 'fallbacks';
    case ClientCredentials = 'client-credentials';
    case ApiKey = 'api-key';
    case Search = 'search';
    case Modify = 'modify';
    case Rabbitmq = 'rabbitmq';
    case Retry = 'retry';
    case Email = 'email';
    case Hash = 'hash';
    case Pipeline = 'pipeline';
    case Loop = 'loop';
    case Validation = 'validacao';
    case Sftp = 'sftp';
    case Script = 'script';
    case FileStorage = 'file-storage';
    case GoogleStorage = 'google-storage';
    case DigibeeJwt = 'digibee-jwt';
    case Bigquery = 'bigquery';

    /**
     * Words/stems (lowercase, no accents) that, when present in the user's
     * request, suggest this tag.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::TokenCache        => ['token', 'jwt', 'cache'],
            self::Token             => ['token', 'jwt', 'oauth', 'autenticacao', 'autenticar'],
            self::Rest              => ['rest', 'http', 'api', 'post', 'get', 'endpoint', 'json'],
            self::Soap              => ['soap', 'sap', 'wsdl', 'xml'],
            self::Ldap              => ['ldap', 'ldaps', 'ad', 'active directory', 'directory'],
            self::ObjectStore       => ['object store', 'objectstore', 'cache', 'armazenar', 'persistir'],
            self::Choice            => ['choice', 'roteamento', 'rotear', 'condicao', 'condicional', 'se', 'caso'],
            self::Webhook           => ['webhook', 'callback', 'notificacao'],
            self::DePara            => ['de-para', 'de para', 'depara', 'mapeamento', 'traduzir', 'converter'],
            self::JsltAlias         => ['jslt', 'transformacao', 'transformar'],
            self::Fallbacks         => ['fallback', 'padrao', 'default', 'valor ausente'],
            self::ClientCredentials => ['client_credentials', 'client credentials', 'oauth'],
            self::ApiKey            => ['api key', 'api-key', 'x-api-key', 'apikey'],
            self::Search            => ['busca', 'buscar', 'consulta', 'consultar', 'pesquisar', 'search'],
            self::Modify            => ['alterar', 'modificar', 'atualizar', 'desbloquear', 'modify'],
            self::Rabbitmq          => ['rabbitmq', 'rabbit', 'amqp', 'fila', 'filas', 'exchange', 'mensageria', 'broker'],
            self::Retry             => ['retry', 'retentativa', 'reenviar', 'reenvio', 'reprocessar', 'reprocessamento', 'tentativa', 'backoff'],
            self::Email             => ['email', 'e-mail', 'notificar', 'notificacao', 'alerta', 'smtp'],
            self::Hash              => ['hash', 'hashing', 'criptografar', 'criptografia', 'md5', 'sha', 'bcrypt', 'mascarar'],
            self::Pipeline          => ['pipeline', 'sub-pipeline', 'subprocesso', 'chamar outro fluxo', 'orquestrar'],
            self::Loop              => ['loop', 'laco', 'repetir', 'iteracao', 'while', 'do-while'],
            self::Validation        => ['validar', 'validacao', 'json schema', 'schema', 'assert', 'obrigatorio'],
            self::Sftp              => ['sftp', 'ftp', 'arquivo remoto', 'transferencia de arquivo'],
            self::Script            => ['script', 'javascript', 'codigo customizado', 'js'],
            self::FileStorage       => ['storage', 'armazenar arquivo', 'upload de arquivo', 'digibee storage'],
            // Narrower than FileStorage on purpose, and the GCS example carries
            // BOTH: `storage` alone leaves it tied with digibee-storage-upload
            // (identical tags), so a GCS-specific word is what ranks it first.
            // `gcs` and `bucket` previously matched no tag at all, so "grava no
            // bucket GCS" derived zero tags and fell through to the generic
            // fallback example — with the Google Storage example absent from the
            // prompt entirely. Note `gcp`/`google cloud` belong to Bigquery and
            // are deliberately left there, so a BigQuery request does not drag
            // storage examples in.
            self::GoogleStorage => ['gcs', 'bucket', 'google storage', 'cloud storage'],
            self::DigibeeJwt    => ['gerar jwt', 'assinar jwt', 'proteger rest trigger', 'autenticar pipeline'],
            self::Bigquery      => ['bigquery', 'big query', 'google cloud', 'gcp'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $tag) => $tag->value, self::cases());
    }
}
