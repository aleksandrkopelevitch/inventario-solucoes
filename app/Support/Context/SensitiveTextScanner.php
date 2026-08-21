<?php

namespace App\Support\Context;

/**
 * Flags likely credentials in gathered material — and only flags them.
 *
 * Two deliberate decisions:
 *
 * - **It warns, it never rewrites.** Silently redacting a value in the middle
 *   of someone's architecture document is worse than the leak it prevents:
 *   the author can no longer tell what the document says. The finding is shown
 *   next to the file, and removing it stays the person's call.
 * - **Internal IPs and ports are NOT flagged.** They are the legitimate
 *   content of these documents — the reference deck is largely about VLANs,
 *   firewall rules and port 9180. A warning that fires on every architecture
 *   submission is a warning everyone learns to dismiss.
 *
 * The vocabulary comes from App\Services\Flowspec\CredentialScrubber, which
 * cannot be reused directly: its public API takes a structured flowSpec
 * document (`violations(array)`), not free text.
 */
class SensitiveTextScanner
{
    /** @var array<string, string> label => pattern */
    private const PATTERNS = [
        'Token JWT'                       => '/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/',
        'Token Bearer'                    => '/\bBearer\s+[A-Za-z0-9._~+\/=-]{20,}/i',
        'Chave de acesso AWS'             => '/\bAKIA[0-9A-Z]{16}\b/',
        'Chave privada'                   => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'Senha em string de conexão'      => '/\b[a-z][a-z0-9+.-]*:\/\/[^\s:@\/]+:[^\s:@\/]{3,}@/i',
        'Credencial atribuída a um campo' => '/\b(?:password|senha|secret|client[_-]?secret|api[_-]?key|access[_-]?token|refresh[_-]?token)\b\s*[:=]\s*["\']?\S{6,}/i',
    ];

    /**
     * @return list<array{type: string, sample: string}> empty = nothing found
     */
    public function scan(string $text): array
    {
        $findings = [];

        foreach (self::PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $findings[] = ['type' => $label, 'sample' => $this->mask($matches[0])];
            }
        }

        return $findings;
    }

    /** Enough of the match to find it in the document, never enough to use it. */
    private function mask(string $match): string
    {
        $match = trim(preg_replace('/\s+/', ' ', $match));

        return mb_strlen($match) <= 12 ? $match : mb_substr($match, 0, 12) . '…';
    }
}
