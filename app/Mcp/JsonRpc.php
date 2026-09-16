<?php

namespace App\Mcp;

/**
 * The JSON-RPC 2.0 envelope MCP speaks, and nothing else.
 *
 * Small on purpose: MCP is JSON-RPC over one HTTP endpoint, and the parts of
 * JSON-RPC it uses are a request, a notification, a result and an error. There
 * is no library here because a dependency for four array shapes buys nothing
 * and hides the one thing worth reading — which codes we answer with, and why.
 */
final class JsonRpc
{
    /** Malformed JSON — the body could not be parsed at all. */
    public const PARSE_ERROR = -32700;

    /** Parsed, but not a JSON-RPC request. */
    public const INVALID_REQUEST = -32600;

    /** A method this server does not implement. */
    public const METHOD_NOT_FOUND = -32601;

    /** The method exists; the params are wrong. */
    public const INVALID_PARAMS = -32602;

    /** Anything that got as far as running and threw. */
    public const INTERNAL_ERROR = -32603;

    /** @param array<string, mixed> $result */
    public static function result(string|int|null $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @param array<string, mixed>|null $data */
    public static function error(string|int|null $id, int $code, string $message, ?array $data = null): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => array_filter(
                ['code' => $code, 'message' => $message, 'data' => $data],
                fn ($value) => $value !== null,
            ),
        ];
    }
}
