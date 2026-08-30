<?php

use App\Support\Documentation\LiteralVault;

/**
 * Fixtures are SYNTHETIC on purpose — same shape as the SAP CPI header that
 * exposed the bug (base64 of `sb-<uuid>!b<n>|<subaccount>:<uuid>$<secret>`),
 * never a real credential.
 */
function fakeToken(string $environment = 'prod'): string
{
    return base64_encode(
        "sb-04938d98-2ea2-4495-835c-b8e028f11818!b38080|it-rt-btp{$environment}-exemplo"
        . '!b106:aa793318-afe5-4231-825d-ddb5a3894178$oOOeDJVf3UKpiGNl2yfgZpl1b3ee4kKU9ozu3aqMU6s='
    );
}

it('freezes opaque literals and leaves technical prose alone', function () {
    $token = fakeToken();
    $hash = str_repeat('a1b2c3d4', 8);
    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    $text = <<<MD
        | Coluna | Valor |
        | ------------------------------------ | --- |
        | additionalData_payload_transaction_authorizationCode | ok |

        Modelo `S4hana/depara_fornecedor_QAS500/Fornecedor_Carga_QS4_500_De_Para`.

        Authorization: Basic {$token}
        Digest: {$hash}
        Bearer {$jwt}
        MD;

    $masked = LiteralVault::from([$text])->mask($text);

    expect($masked)->not->toContain($token)
        ->and($masked)->not->toContain($hash)
        ->and($masked)->not->toContain($jwt)
        ->and($masked)->toContain('[[LIT-1]]')
        ->and($masked)->toContain('[[LIT-2]]')
        ->and($masked)->toContain('[[LIT-3]]')
        // Long identifiers, table rules and paths are content, not secrets.
        ->and($masked)->toContain('additionalData_payload_transaction_authorizationCode')
        ->and($masked)->toContain('S4hana/depara_fornecedor_QAS500/Fornecedor_Carga_QS4_500_De_Para')
        ->and($masked)->toContain('| ------------------------------------ |');
});

it('gives one marker to a value however often it appears', function () {
    $token = fakeToken();
    $vault = LiteralVault::from(["PRD: {$token}", "QAS usa o mesmo: {$token}"]);

    $masked = $vault->mask("linha 1 {$token}\nlinha 2 {$token}");

    expect($vault->stats()['frozen'])->toBe(1)
        ->and(substr_count($masked, '[[LIT-1]]'))->toBe(2);
});

it('restores the exact bytes the model was never shown', function () {
    $token = fakeToken();
    $vault = LiteralVault::from(["Authorization: Basic {$token}"]);

    $restored = $vault->restore("Pronto.\n\n````\n## Auth\n\n`Basic [[LIT-1]]`\n````");

    expect($restored)->toContain("`Basic {$token}`")
        ->and($restored)->not->toContain('[[LIT-1]]')
        ->and($vault->stats()['unresolved'])->toBe(0);
});

it('repairs a literal the model retyped instead of using its marker', function () {
    $token = fakeToken();
    // The exact corruption reported: the tail rewritten, everything else right.
    $mangled = substr($token, 0, -6) . 'N=';

    $vault = LiteralVault::from([$token]);
    $restored = $vault->restore("Corrigido: {$mangled}");

    expect($restored)->toBe("Corrigido: {$token}")
        ->and($vault->stats()['repaired'])->toBe(1);
});

it('refuses to repair when two vaulted literals share the prefix', function () {
    $prd = fakeToken('prod');
    $qas = fakeToken('qas');
    $mangled = substr($prd, 0, -6) . 'N=';

    $vault = LiteralVault::from([$prd, $qas]);
    $restored = $vault->restore("Ambíguo: {$mangled}");

    // Both are base64 of nearly the same plaintext: guessing which one the
    // model meant would swap production for QA.
    expect($vault->stats()['frozen'])->toBe(2)
        ->and(substr($prd, 0, 24))->toBe(substr($qas, 0, 24))
        ->and($restored)->toBe("Ambíguo: {$mangled}")
        ->and($vault->stats()['repaired'])->toBe(0);
});

it('counts a marker the model invented instead of silently dropping it', function () {
    $vault = LiteralVault::from([fakeToken()]);

    $restored = $vault->restore('Veja [[LIT-1]] e [[LIT-7]].');

    expect($restored)->toContain('[[LIT-7]]')
        ->and($vault->stats()['unresolved'])->toBe(1);
});

it('is a no-op when there is nothing opaque to protect', function () {
    $vault = LiteralVault::from(['Integração REST síncrona com o S/4.']);

    expect($vault->isEmpty())->toBeTrue()
        ->and($vault->mask('texto'))->toBe('texto')
        ->and($vault->restore('texto'))->toBe('texto');
});
