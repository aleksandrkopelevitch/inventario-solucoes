<?php

namespace App\Support\Documentation;

/**
 * Freezes opaque literals — tokens, keys, hashes, base64 payloads — across a
 * round trip through the model.
 *
 * The Documentation Assistant answers with the WHOLE page rewritten (the
 * 4-backtick draft block in DocumentationChatPromptBuilder), so every literal
 * on the page has to survive being copied character by character by a language
 * model. Long high-entropy strings are exactly what that copying gets wrong,
 * and it fails SILENTLY: a 212-character SAP CPI `Authorization` header came
 * back with its tail rewritten, and asking the assistant to fix it produced a
 * third variant of the same string.
 *
 * So the model never sees them. Every literal is replaced by a short marker
 * (`[[LIT-1]]`) before the prompt is built, and put back once the reply
 * arrives — in the conversational text as well as in the draft, so a reply
 * that quotes the value still reads correctly to the person. The model can
 * still MOVE a value (that is copying a nine-character marker), and can still
 * be told which is which by the legend, but it can no longer retype one.
 */
final class LiteralVault
{
    /** Deliberately ASCII and short: the model's one job with it is to copy it back byte for byte. */
    private const MARKER_FORMAT = '[[LIT-%d]]';

    private const MARKER_PATTERN = '/\[\[LIT-\d+\]\]/';

    /** A run of token characters long enough to be worth examining. */
    private const CANDIDATE_PATTERN = '/[A-Za-z0-9+\/=_.~-]{32,}/';

    /** Structural shapes that are opaque whatever their entropy says. */
    private const JWT_PATTERN = '/^eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/';

    private const HEX_PATTERN = '/^[0-9a-f]{32,}$/i';

    /**
     * Thresholds measured 2026-08-30 over the whole dev corpus (207 pages with
     * documentation). These rules flag 9 distinct strings, every one of them
     * genuinely opaque (base64 PDF payloads, a JWT, hashes), and none of the
     * ~570 long identifiers the same corpus contains. Both numbers matter and
     * neither is a guess: real tokens sit at H >= 5.0 with a longest
     * same-class run of 3-6, while identifiers
     * (`additionalData_payload_transaction_authorizationCode`, H=3.8;
     * `S4hana/depara_fornecedor_QAS500/...`, H=4.1) keep runs of 8-13, because
     * a word IS a run of one class. Loosen either and field names start
     * disappearing behind markers.
     */
    private const MIN_LENGTH = 40;

    private const MIN_ENTROPY = 4.5;

    private const MAX_CLASS_RUN = 8;

    /**
     * A mangled copy is recognised by a shared prefix, and only repaired when
     * exactly ONE vaulted literal owns it: two tokens for the same service in
     * different environments are base64 of nearly the same plaintext and can
     * share a long prefix, so "closest match" would happily swap PRD for QAS.
     */
    private const REPAIR_PREFIX = 24;

    private const REPAIR_SIMILARITY = 90.0;

    /** @var array<string, string> marker => literal */
    private array $literals = [];

    private int $repaired = 0;

    private int $unresolved = 0;

    /** @param list<string|null> $texts everything this turn will show the model */
    public static function from(array $texts): self
    {
        $vault = new self;

        foreach ($texts as $text) {
            $vault->capture((string) $text);
        }

        return $vault;
    }

    public function isEmpty(): bool
    {
        return $this->literals === [];
    }

    /** Replaces every vaulted literal in $text with its marker. */
    public function mask(?string $text): string
    {
        $text = (string) $text;

        if ($text === '' || $this->isEmpty()) {
            return $text;
        }

        // Longest first: one literal can contain another (a token inside a
        // connection string), and replacing the short one first would leave a
        // marker embedded in a value the long one no longer matches.
        $literals = $this->literals;
        uasort($literals, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace(array_values($literals), array_keys($literals), $text);
    }

    /**
     * Puts the real values back, then repairs any literal the model wrote out
     * by hand instead of using its marker — which it can still do from a
     * native attachment (a PDF is handed to the model as-is, so its text never
     * passed through mask()).
     */
    public function restore(string $text): string
    {
        if ($this->isEmpty()) {
            return $text;
        }

        $text = str_replace(array_keys($this->literals), array_values($this->literals), $text);
        $text = $this->repairMangledCopies($text);

        $this->unresolved = (int) preg_match_all(self::MARKER_PATTERN, $text);

        return $text;
    }

    /**
     * The markers, described well enough for the model to tell two tokens
     * apart and to be told "troque o de produção" — never well enough to
     * reconstruct one. Eight characters is the same disclosure
     * App\Support\Context\SensitiveTextScanner already makes when it reports a
     * finding.
     */
    public function legend(): string
    {
        $lines = [];

        foreach ($this->literals as $marker => $literal) {
            $lines[] = sprintf(
                '- %s = %s de %d caracteres, começa com "%s"',
                $marker,
                $this->describe($literal),
                strlen($literal),
                substr($literal, 0, 8),
            );
        }

        return implode("\n", $lines);
    }

    /** @return array{frozen: int, repaired: int, unresolved: int} audit trail for the reply's meta */
    public function stats(): array
    {
        return [
            'frozen'     => count($this->literals),
            'repaired'   => $this->repaired,
            'unresolved' => $this->unresolved,
        ];
    }

    private function capture(string $text): void
    {
        if ($text === '' || preg_match_all(self::CANDIDATE_PATTERN, $text, $matches) === 0) {
            return;
        }

        foreach ($matches[0] as $candidate) {
            // A literal ending a sentence swallows the period; the value is
            // the token, not the punctuation after it.
            $candidate = rtrim($candidate, '.');

            if (! $this->isOpaque($candidate) || in_array($candidate, $this->literals, true)) {
                continue;
            }

            $this->literals[sprintf(self::MARKER_FORMAT, count($this->literals) + 1)] = $candidate;
        }
    }

    private function isOpaque(string $candidate): bool
    {
        if (preg_match(self::JWT_PATTERN, $candidate) === 1 || preg_match(self::HEX_PATTERN, $candidate) === 1) {
            return true;
        }

        return strlen($candidate) >= self::MIN_LENGTH
            && self::entropy($candidate) >= self::MIN_ENTROPY
            && self::longestClassRun($candidate) <= self::MAX_CLASS_RUN;
    }

    private function repairMangledCopies(string $text): string
    {
        if (preg_match_all(self::CANDIDATE_PATTERN, $text, $matches) === 0) {
            return $text;
        }

        $replacements = [];

        foreach (array_unique($matches[0]) as $candidate) {
            $candidate = rtrim($candidate, '.');

            if (! $this->isOpaque($candidate) || in_array($candidate, $this->literals, true)) {
                continue;
            }

            $original = $this->soleOwnerOfPrefix($candidate);

            if ($original === null) {
                continue;
            }

            similar_text($candidate, $original, $percent);

            if ($percent >= self::REPAIR_SIMILARITY) {
                $replacements[$candidate] = $original;
                $this->repaired++;
            }
        }

        return $replacements === [] ? $text : str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /** The one vaulted literal sharing this candidate's prefix, or null when none or several do. */
    private function soleOwnerOfPrefix(string $candidate): ?string
    {
        $prefix = substr($candidate, 0, self::REPAIR_PREFIX);

        if (strlen($prefix) < self::REPAIR_PREFIX) {
            return null;
        }

        $owners = array_values(array_filter(
            $this->literals,
            fn (string $literal) => str_starts_with($literal, $prefix),
        ));

        return count($owners) === 1 ? $owners[0] : null;
    }

    private function describe(string $literal): string
    {
        return match (true) {
            preg_match(self::JWT_PATTERN, $literal) === 1 => 'token JWT',
            preg_match(self::HEX_PATTERN, $literal) === 1 => 'valor hexadecimal',
            default                                       => 'valor opaco (base64/token)',
        };
    }

    /** Shannon entropy in bits per character. */
    private static function entropy(string $value): float
    {
        $length = strlen($value);
        $entropy = 0.0;

        foreach (count_chars($value, 1) as $occurrences) {
            $p = $occurrences / $length;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }

    /** Longest run of a single character class — a word is one long run, a random token is not. */
    private static function longestClassRun(string $value): int
    {
        $longest = 0;
        $current = 0;
        $previous = null;

        foreach (str_split($value) as $char) {
            $class = match (true) {
                ctype_lower($char) => 'lower',
                ctype_upper($char) => 'upper',
                ctype_digit($char) => 'digit',
                default            => 'symbol',
            };

            $current = $class === $previous ? $current + 1 : 1;
            $previous = $class;
            $longest = max($longest, $current);
        }

        return $longest;
    }
}
