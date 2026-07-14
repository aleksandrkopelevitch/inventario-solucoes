<?php

use App\Enums\UserRole;

it('exposes the three application roles', function () {
    expect(UserRole::cases())->toHaveCount(3)
        ->and(array_map(fn (UserRole $r) => $r->value, UserRole::cases()))
        ->toBe(['viewer', 'agent', 'admin']);
});

it('maps each role to a Portuguese label', function () {
    expect(UserRole::Viewer->label())->toBe('Visualizador')
        ->and(UserRole::Agent->label())->toBe('Agente')
        ->and(UserRole::Admin->label())->toBe('Administrador');
});
