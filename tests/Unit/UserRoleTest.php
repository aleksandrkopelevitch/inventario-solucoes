<?php

use App\Enums\UserRole;

it('exposes the four application roles, floor tier first', function () {
    expect(UserRole::cases())->toHaveCount(4)
        ->and(array_map(fn (UserRole $r) => $r->value, UserRole::cases()))
        ->toBe(['reader', 'viewer', 'writer', 'admin']);
});

it('maps each role to a Portuguese label and a sentence saying what it is for', function () {
    expect(UserRole::Reader->label())->toBe('Leitor (base de conhecimento)')
        ->and(UserRole::Viewer->label())->toBe('Visualizador')
        ->and(UserRole::Writer->label())->toBe('Editor')
        ->and(UserRole::Admin->label())->toBe('Administrador');

    foreach (UserRole::cases() as $role) {
        expect($role->description())->not->toBe('');
    }
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

/**
 * The predicate the knowledge base turns on. It is the only one shaped as a
 * FLOOR rather than as an added power, so it is asserted for every case
 * explicitly — a new tier inserted below `Reader` has to make a deliberate
 * choice here rather than inherit one.
 */
it('withholds the inventory from the reader and from nobody else', function () {
    expect(UserRole::Reader->canReadInventory())->toBeFalse()
        ->and(UserRole::Viewer->canReadInventory())->toBeTrue()
        ->and(UserRole::Writer->canReadInventory())->toBeTrue()
        ->and(UserRole::Admin->canReadInventory())->toBeTrue();
});

it('gives the reader no power at all beyond reading the knowledge base', function () {
    expect(UserRole::Reader->canWrite())->toBeFalse()
        ->and(UserRole::Reader->canDelete())->toBeFalse()
        ->and(UserRole::Reader->isAdmin())->toBeFalse();
});
