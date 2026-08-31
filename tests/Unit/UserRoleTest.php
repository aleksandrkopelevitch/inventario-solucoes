<?php

use App\Enums\UserRole;

it('exposes the three application roles', function () {
    expect(UserRole::cases())->toHaveCount(3)
        ->and(array_map(fn (UserRole $r) => $r->value, UserRole::cases()))
        ->toBe(['viewer', 'writer', 'admin']);
});

it('maps each role to a Portuguese label', function () {
    expect(UserRole::Viewer->label())->toBe('Visualizador')
        ->and(UserRole::Writer->label())->toBe('Editor')
        ->and(UserRole::Admin->label())->toBe('Administrador');
});

it('lets the writer write but not delete or administer', function () {
    expect(UserRole::Writer->canWrite())->toBeTrue()
        ->and(UserRole::Writer->canDelete())->toBeFalse()
        ->and(UserRole::Writer->isAdmin())->toBeFalse();
});

it('lets the admin do everything and the viewer nothing', function () {
    expect(UserRole::Admin->canWrite())->toBeTrue()
        ->and(UserRole::Admin->canDelete())->toBeTrue()
        ->and(UserRole::Admin->isAdmin())->toBeTrue()
        ->and(UserRole::Viewer->canWrite())->toBeFalse()
        ->and(UserRole::Viewer->canDelete())->toBeFalse()
        ->and(UserRole::Viewer->isAdmin())->toBeFalse();
});
