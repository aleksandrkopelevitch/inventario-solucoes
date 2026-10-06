<?php

use App\Enums\PersonSolutionRole;
use App\Enums\SpreadsheetAudience;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\PublicLink;
use App\Models\Solution;
use App\Models\User;
use App\Services\SolutionSpreadsheetService;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;

uses(LazilyRefreshDatabase::class);

function sheetUser(UserRole $role = UserRole::Member): User
{
    return User::factory()->create(['role' => $role->value]);
}

/** A solution with a vendor, two owners in two roles and a documented caderno. */
function sheetSolution(): Solution
{
    $solution = Solution::factory()->create([
        'name'              => 'Alpha ERP',
        'category'          => 'erp',
        'status'            => 'active',
        'vendor_company_id' => Company::factory()->create(['name' => 'Fornecedora SA'])->id,
    ]);

    $solution->people()->attach(Person::factory()->create(['name' => 'Ana Técnica', 'email' => 'ana@leo.test', 'phone' => '11 9999-0000'])->id, [
        'role' => PersonSolutionRole::Technical->value, 'is_primary' => true,
    ]);
    $solution->people()->attach(Person::factory()->create(['name' => 'Bruno Negócio', 'email' => 'bruno@leo.test'])->id, [
        'role' => PersonSolutionRole::Business->value, 'is_primary' => false,
    ]);

    $notebook = Notebook::factory()->create(['name' => 'Manual do Alpha']);
    DocumentationPage::factory()->for($notebook)->create(['documentation' => '# Conteúdo']);
    $solution->notebooks()->attach($notebook);

    return $solution;
}

/** @return list<list<string>> */
function csvLines(string $content): array
{
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue();

    return array_map(
        fn (string $line) => str_getcsv($line, ';', '"', ''),
        array_values(array_filter(explode("\n", substr($content, 3)))),
    );
}

beforeEach(fn () => $this->seed(AttributeOptionSeeder::class));

/*
|--------------------------------------------------------------------------
| The dataset
|--------------------------------------------------------------------------
*/

it('builds one row per solution with labels, owners by role and documentation', function () {
    sheetSolution();

    $row = app(SolutionSpreadsheetService::class)->rows(SpreadsheetAudience::Internal)[0];

    expect($row['cells'])
        ->name->toBe('Alpha ERP')
        ->vendor->toBe('Fornecedora SA')
        ->category->toBe('ERP')
        ->role_technical->toBe(['Ana Técnica'])
        ->role_business->toBe(['Bruno Negócio'])
        ->role_manager->toBe([])
        ->notebooks->toBe(['Manual do Alpha'])
        ->documented->toBe('Sim')
        ->contacts->toContain('Ana Técnica — ana@leo.test · 11 9999-0000')
        ->and($row['url'])->toBe(route('solutions.show', Solution::first()));
});

it('withholds contacts and inventory links from the shared audience', function () {
    sheetSolution();
    $service = app(SolutionSpreadsheetService::class);

    $row = $service->rows(SpreadsheetAudience::Shared)[0];

    expect(array_column($service->columns(SpreadsheetAudience::Shared), 'key'))->not->toContain('contacts')
        ->and($row['cells'])->not->toHaveKey('contacts')
        ->and($row['url'])->toBeNull();
});

it('returns only the requested rows, in the requested order', function () {
    $a = Solution::factory()->create(['name' => 'Aaa']);
    $b = Solution::factory()->create(['name' => 'Bbb']);
    Solution::factory()->create(['name' => 'Ccc']);

    $rows = app(SolutionSpreadsheetService::class)->rows(SpreadsheetAudience::Internal, [$b->id, $a->id]);

    expect(array_column($rows, 'id'))->toBe([$b->id, $a->id]);
});

/*
|--------------------------------------------------------------------------
| The inventory screen
|--------------------------------------------------------------------------
*/

it('renders the spreadsheet for an inventory reader', function () {
    sheetSolution();

    $this->actingAs(sheetUser())
        ->get(route('solutions.spreadsheet'))
        ->assertOk()
        ->assertSee('data-ak-sheet', false)
        ->assertSee('Alpha ERP')
        ->assertSee('ana@leo.test');
});

it('links to the spreadsheet from the catalog', function () {
    $this->actingAs(sheetUser())
        ->get(route('solutions.index'))
        ->assertSee(route('solutions.spreadsheet'));
});

it('shows the share panel to an admin only', function () {
    $this->actingAs(sheetUser(UserRole::Admin))->get(route('solutions.spreadsheet'))->assertSee('Compartilhar planilha');
    $this->actingAs(User::factory()->editor()->create())->get(route('solutions.spreadsheet'))->assertDontSee('Compartilhar planilha');
});

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

it('exports the visible columns and rows as CSV', function () {
    $alpha = sheetSolution();
    Solution::factory()->create(['name' => 'Beta CRM']);

    $response = $this->actingAs(sheetUser())
        ->get(route('solutions.spreadsheet.export', [
            'format'  => 'csv',
            'columns' => ['name', 'role_technical', 'vendor'],
            'ids'     => [$alpha->id],
        ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect(csvLines($response->streamedContent()))->toBe([
        ['Solução', 'Owner técnico', 'Fornecedor'],
        ['Alpha ERP', 'Ana Técnica', 'Fornecedora SA'],
    ]);
});

it('exports the default columns and every row when nothing is specified', function () {
    sheetSolution();
    Solution::factory()->create(['name' => 'Beta CRM']);

    $lines = csvLines($this->actingAs(sheetUser())
        ->get(route('solutions.spreadsheet.export', ['format' => 'csv']))
        ->streamedContent());

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toContain('Solução', 'Owner técnico')
        ->and($lines[0])->not->toContain('Contatos dos responsáveis');
});

it('exports an xlsx workbook', function () {
    $alpha = sheetSolution();

    $response = $this->actingAs(sheetUser())
        ->get(route('solutions.spreadsheet.export', ['format' => 'xlsx', 'columns' => ['name', 'diagrams'], 'ids' => [$alpha->id]]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $path = tempnam(sys_get_temp_dir(), 'sheet') . '.xlsx';
    file_put_contents($path, $response->streamedContent());

    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    expect($rows)->toBe([['Solução', 'Diagramas'], ['Alpha ERP', 0]]);
});

it('refuses an unknown format or column', function () {
    $this->actingAs(sheetUser())
        ->getJson(route('solutions.spreadsheet.export', ['format' => 'pdf']))
        ->assertUnprocessable();

    $this->actingAs(sheetUser())
        ->getJson(route('solutions.spreadsheet.export', ['format' => 'csv', 'columns' => ['password']]))
        ->assertUnprocessable();
});

/*
|--------------------------------------------------------------------------
| The magic link
|--------------------------------------------------------------------------
*/

it('lets an admin generate the public link, once', function () {
    $admin = sheetUser(UserRole::Admin);

    $response = $this->actingAs($admin)
        ->postJson(route('solutions.spreadsheet.share'))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $token = PublicLink::for(PublicLink::SOLUTIONS_SPREADSHEET)->token;

    $this->actingAs($admin)->postJson(route('solutions.spreadsheet.share'))->assertOk();

    expect($token)->toMatch('/^[A-Za-z0-9]{12,}$/')
        ->and(PublicLink::for(PublicLink::SOLUTIONS_SPREADSHEET)->token)->toBe($token)
        ->and($response->json('updatableSlots.0.id'))->toBe('solutions-sheet-share-slot')
        ->and($response->json('updatableSlots.0.content'))->toContain(route('public.solutions.spreadsheet', $token));
});

it('refuses sharing to anybody but an admin', function () {
    $this->actingAs(User::factory()->editor()->create())->postJson(route('solutions.spreadsheet.share'))->assertForbidden();
    $this->actingAs(User::factory()->editor()->create())->deleteJson(route('solutions.spreadsheet.unshare'))->assertForbidden();

    expect(PublicLink::count())->toBe(0);
});

it('serves the spreadsheet on its public link, without contacts', function () {
    sheetSolution();
    $link = PublicLink::generate(PublicLink::SOLUTIONS_SPREADSHEET);

    $this->get(route('public.solutions.spreadsheet', $link->token))
        ->assertOk()
        ->assertSee('Alpha ERP')
        ->assertSee('Ana Técnica')
        ->assertDontSee('ana@leo.test')
        ->assertDontSee(route('solutions.show', Solution::first()));
});

it('never exports contacts through the public link, even when asked', function () {
    sheetSolution();
    $link = PublicLink::generate(PublicLink::SOLUTIONS_SPREADSHEET);

    $lines = csvLines($this->get(route('public.solutions.spreadsheet.export', [
        'token'   => $link->token,
        'format'  => 'csv',
        'columns' => ['name', 'contacts'],
    ]))->assertOk()->streamedContent());

    expect($lines)->toBe([['Solução'], ['Alpha ERP']]);
});

it('stops resolving a revoked or wrong token', function () {
    $link = PublicLink::generate(PublicLink::SOLUTIONS_SPREADSHEET);

    // Compared as bytes: the same letters in another case are another token.
    $this->get(route('public.solutions.spreadsheet', strrev($link->token)))->assertNotFound();
    $this->get(route('public.solutions.spreadsheet', strtoupper($link->token) === $link->token ? strtolower($link->token) : strtoupper($link->token)))
        ->assertNotFound();

    $this->actingAs(sheetUser(UserRole::Admin))->deleteJson(route('solutions.spreadsheet.unshare'))->assertOk();
    auth()->logout();

    $this->get(route('public.solutions.spreadsheet', $link->token))->assertNotFound();
    $this->get(route('public.solutions.spreadsheet.export', [$link->token, 'format' => 'csv']))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| `spreadsheet` is a route segment, so never a solution's slug
|--------------------------------------------------------------------------
*/

it('never generates a reserved slug for a solution', function () {
    $this->actingAs(sheetUser(UserRole::Admin))
        ->postJson(route('solutions.store'), ['name' => 'Spreadsheet', 'category' => 'erp', 'status' => 'active'])
        ->assertOk();

    expect(Solution::where('name', 'Spreadsheet')->value('slug'))->toBe('spreadsheet-2');
});

it('refuses a reserved slug posted by the client', function () {
    $this->actingAs(sheetUser(UserRole::Admin))
        ->postJson(route('solutions.store'), ['name' => 'Qualquer', 'slug' => 'spreadsheet', 'category' => 'erp', 'status' => 'active'])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect(Solution::where('slug', 'spreadsheet')->exists())->toBeFalse();
});
