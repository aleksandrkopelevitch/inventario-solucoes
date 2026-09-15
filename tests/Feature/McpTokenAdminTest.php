<?php

use App\Enums\UserRole;
use App\Models\McpToken;
use App\Models\User;
use App\View\Components\Mcp\TokenList;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function mcpUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role->value]);
}

it('is an admin screen, and every other tier is refused', function () {
    // Narrower than `canWrite()` on purpose: an editor writes CONTENT, and a
    // token hands out the whole catalog to a program.
    $this->actingAs(mcpUser(UserRole::Admin))->get(route('mcp-tokens.index'))->assertOk();

    foreach ([UserRole::Writer, UserRole::Viewer] as $role) {
        $this->actingAs(mcpUser($role))->get(route('mcp-tokens.index'))->assertForbidden();
    }

    // A Reader is refused one step earlier, by `EnsureInventoryAccess` on the
    // route group, and is REDIRECTED rather than shown a 403 — for that tier
    // this is not a refusal at all, it is somebody landing one screen away from
    // the only thing they have.
    $this->actingAs(mcpUser(UserRole::Reader))
        ->get(route('mcp-tokens.index'))
        ->assertRedirect(route('docs.index'));

});

it('sends a guest to the login screen', function () {
    // Its own test: `actingAs()` persists for the rest of a test, so a guest
    // assertion after one is not about a guest at all.
    $this->get(route('mcp-tokens.index'))->assertRedirect(route('login.create'));
});

it('refuses minting and deleting to anybody but an admin', function () {
    $token = McpToken::factory()->create();

    // A Reader is included here and answers 403 rather than a redirect: the
    // middleware `abort_if`s on a JSON caller, which is what every mutation in
    // this app is.
    foreach ([UserRole::Writer, UserRole::Viewer, UserRole::Reader] as $role) {
        $this->actingAs(mcpUser($role))
            ->postJson(route('mcp-tokens.store'), ['name' => 'Meu token'])
            ->assertForbidden();

        $this->actingAs(mcpUser($role))
            ->deleteJson(route('mcp-tokens.destroy', $token))
            ->assertForbidden();
    }

    $this->assertModelExists($token);
});

it('shows the plaintext exactly once, in the slot that created it', function () {
    $admin = mcpUser(UserRole::Admin);

    $response = $this->actingAs($admin)
        ->postJson(route('mcp-tokens.store'), ['name' => 'Claude Desktop'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $token = McpToken::where('name', 'Claude Desktop')->firstOrFail();
    $html = collect($response->json('updatableSlots'))->firstWhere('id', TokenList::DOM_ID)['content'];

    // The one response that carries it: the person has to be able to select and
    // copy the value, so it is rendered into the list rather than flashed.
    expect($html)->toContain(McpToken::PREFIX)
        ->and($html)->toContain('Copie agora');

    // And never again. A second render of the same list has no plaintext to
    // print, because nothing anywhere stored one.
    $later = $this->actingAs($admin)->get(route('mcp-tokens.index'))->assertOk();
    expect($later->getContent())->not->toContain('Copie agora')
        ->and($later->getContent())->toContain($token->masked());
});

it('records who minted a token, and survives that account being deleted', function () {
    $admin = mcpUser(UserRole::Admin);

    $this->actingAs($admin)->postJson(route('mcp-tokens.store'), ['name' => 'Do Alex'])->assertOk();
    $token = McpToken::where('name', 'Do Alex')->firstOrFail();

    expect($token->created_by_user_id)->toBe($admin->id);

    // `nullOnDelete`: revoking the admin's own account must not take every
    // token they created down with it.
    $admin->forceDelete();
    expect($token->fresh())->not->toBeNull()
        ->and($token->fresh()->created_by_user_id)->toBeNull();
});

it('requires a name, so a token can always be told apart later', function () {
    $response = $this->actingAs(mcpUser(UserRole::Admin))
        ->postJson(route('mcp-tokens.store'), ['name' => ''])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    // This app reshapes every validation response to `{message, title, type}` —
    // there is no `errors` key to assert against (§ ValidationException JSON shape).
    expect($response->json('message'))->toContain('nome');

    expect(McpToken::count())->toBe(0);
});

it('deletes a token, and the deleted token stops authenticating immediately', function () {
    $admin = mcpUser(UserRole::Admin);
    ['token' => $token, 'plain' => $plain] = McpToken::mint('Para apagar');

    // It works first — otherwise the assertion below proves nothing.
    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], [
        'Authorization' => 'Bearer ' . $plain,
    ])->assertOk();

    $this->actingAs($admin)->deleteJson(route('mcp-tokens.destroy', $token))->assertOk();

    $this->assertModelMissing($token);

    // Deleting IS revoking: the row is the credential, there is no soft delete
    // and nothing to restore.
    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], [
        'Authorization' => 'Bearer ' . $plain,
    ])->assertStatus(401);
});

it('hands out the endpoint built from the route, never a literal', function () {
    $html = $this->actingAs(mcpUser(UserRole::Admin))->get(route('mcp-tokens.index'))->assertOk()->getContent();

    // A deploy behind a different host or prefix has to hand out an address
    // that actually works.
    expect($html)->toContain(route('mcp.handle'))
        // And it says what the token reaches, where the token is handed over.
        ->toContain('cadernos publicados');
});

it('lights the sidebar entry for an admin and hides it from everyone else', function () {
    // The rail hides what an account cannot open rather than offering a link
    // that answers 403 — and the `can` there is checked against McpToken, not
    // against the Notebook the sidebar used to hard-code.
    $admin = $this->actingAs(mcpUser(UserRole::Admin))->get(route('solutions.index'))->getContent();
    expect($admin)->toContain(route('mcp-tokens.index'));

    $writer = $this->actingAs(mcpUser(UserRole::Writer))->get(route('solutions.index'))->getContent();
    expect($writer)->not->toContain(route('mcp-tokens.index'))
        // The sibling gated entry still works — the per-item model did not
        // break the one that was already there.
        ->and($this->actingAs(mcpUser(UserRole::Admin))->get(route('solutions.index'))->getContent())
        ->toContain(route('docs.settings'));
});
