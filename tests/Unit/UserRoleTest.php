<?php

use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\UserRole;
use App\Models\User;

it('has two roles, member and admin', function () {
    expect(array_map(fn (UserRole $r) => $r->value, UserRole::cases()))->toBe(['member', 'admin'])
        ->and(UserRole::Member->label())->toBe('Usuário')
        ->and(UserRole::Admin->label())->toBe('Administrador')
        ->and(UserRole::Admin->isAdmin())->toBeTrue()
        ->and(UserRole::Member->isAdmin())->toBeFalse();
});

it('has four modules and three levels, labelled in Portuguese', function () {
    expect(array_map(fn (AccessModule $m) => $m->value, AccessModule::cases()))
        ->toBe(['catalog', 'documentation', 'integrations', 'committee'])
        ->and(AccessLevel::None->label())->toBe('Nenhum')
        ->and(AccessLevel::Reader->label())->toBe('Leitor')
        ->and(AccessLevel::Editor->label())->toBe('Editor');
});

it('gives a member each module\'s default unless told otherwise', function () {
    $user = new User(['role' => UserRole::Member]);

    expect($user->accessLevel(AccessModule::Catalog))->toBe(AccessLevel::Reader)
        ->and($user->accessLevel(AccessModule::Documentation))->toBe(AccessLevel::Reader)
        ->and($user->accessLevel(AccessModule::Integrations))->toBe(AccessLevel::None)
        ->and($user->accessLevel(AccessModule::Committee))->toBe(AccessLevel::None);
});

it('makes a member an Editor only in the modules granted', function () {
    $user = new User(['role' => UserRole::Member, 'access' => ['catalog' => 'editor']]);

    expect($user->canEdit(AccessModule::Catalog))->toBeTrue()
        ->and($user->canEdit(AccessModule::Documentation))->toBeFalse()
        ->and($user->canEdit(AccessModule::Committee))->toBeFalse();
});

it('makes an admin an Editor everywhere, whatever is stored', function () {
    $user = new User(['role' => UserRole::Admin, 'access' => null]);

    foreach (AccessModule::cases() as $module) {
        expect($user->canEdit($module))->toBeTrue();
    }
});

it('stores a module\'s default as an absent key, so the column only records differences', function () {
    $user = new User(['role' => UserRole::Member]);

    $user->setAccessLevel(AccessModule::Committee, AccessLevel::Reader);
    expect($user->access)->toBe(['committee' => 'reader']);

    $user->setAccessLevel(AccessModule::Committee, AccessLevel::None);
    $user->setAccessLevel(AccessModule::Catalog, AccessLevel::Reader);
    expect($user->access)->toBeNull();
});

it('treats an unknown stored level as a Reader', function () {
    $user = new User(['role' => UserRole::Member, 'access' => ['catalog' => 'owner']]);

    expect($user->accessLevel(AccessModule::Catalog))->toBe(AccessLevel::Reader);
});

it('stores None explicitly and closes the module with it', function () {
    $user = new User(['role' => UserRole::Member]);

    $user->setAccessLevel(AccessModule::Catalog, AccessLevel::None);

    expect($user->access)->toBe(['catalog' => 'none'])
        ->and($user->canView(AccessModule::Catalog))->toBeFalse()
        ->and($user->canEdit(AccessModule::Catalog))->toBeFalse()
        ->and($user->canView(AccessModule::Documentation))->toBeTrue();
});

it('never closes a module to an admin', function () {
    $user = new User(['role' => UserRole::Admin, 'access' => ['catalog' => 'none']]);

    expect($user->canView(AccessModule::Catalog))->toBeTrue();
});
