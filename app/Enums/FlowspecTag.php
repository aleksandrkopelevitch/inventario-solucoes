<?php

namespace App\Enums;

/**
 * Vocabulário fechado de tags do corpus de exemplos de flowSpec (F8).
 * Padroniza o tagueamento do seeder/curadoria e alimenta o seletor de
 * exemplos: `keywords()` é o mapa palavra-do-pedido -> tag usado pelo
 * FlowspecContextResolver para derivar tags candidatas sem RAG.
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
     * Palavras/radicais (minúsculos, sem acento) que, presentes no pedido do
     * usuário, sugerem esta tag.
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
