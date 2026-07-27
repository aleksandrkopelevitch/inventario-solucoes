<?php

use App\Enums\UserRole;

it('exposes the two application roles', function () {
    expect(UserRole::cases())->toHaveCount(2)
        ->and(array_map(fn (UserRole $r) => $r->value, UserRole::cases()))
        ->toBe(['viewer', 'admin']);
});

it('maps each role to a Portuguese label', function () {
    expect(UserRole::Viewer->label())->toBe('Visualizador')
        ->and(UserRole::Admin->label())->toBe('Administrador');
});
