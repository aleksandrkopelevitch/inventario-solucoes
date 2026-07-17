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
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $tag) => $tag->value, self::cases());
    }
}
