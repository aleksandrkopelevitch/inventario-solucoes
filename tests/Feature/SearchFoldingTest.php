<?php

use App\Models\Company;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use App\Support\Fold;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * Every search box in this app compares text a person TYPED against text
 * somebody else wrote, so it has to be forgiving about the two things that
 * differ between the two: capitals and accents.
 *
 * These tests run on SQLite (phpunit.xml) while the app runs on Postgres, and
 * that gap is the reason the bug shipped: SQLite's own `LIKE` is
 * case-insensitive for ASCII, Postgres's is not, so `where(..., 'like', …)`
 * passed here and failed there. They are only meaningful because
 * `App\Support\Fold` registers the SAME folding as a real SQLite function —
 * both drivers run identical code on both sides of the comparison.
 *
 * That gap is also why the sample names below carry accents. "big" against
 * "Google BigQuery" is a case the unfixed code passes on SQLite, so on its own
 * it guards nothing here; "ORAMA SERVICOS" against "Órama Serviços" needs both
 * halves of the folding and fails without either, on either driver.
 */

uses(LazilyRefreshDatabase::class);

function searcher(): User
{
    return User::factory()->create();
}

it('finds a solution however the term is capitalised', function () {
    // The reported case: "big" typed into "Soluções documentadas" found
    // nothing, "Big" found Google BigQuery.
    Solution::factory()->create(['name' => 'Google BigQuery']);

    foreach (['big', 'BIG', 'Big', 'bIgQuErY'] as $term) {
        expect(Solution::filter(['search' => $term])->pluck('name')->all())
            ->toBe(['Google BigQuery'], "termo: {$term}");
    }
});

it('treats an accented letter and its bare one as the same letter, both ways', function () {
    $accented = Solution::factory()->create(['name' => 'SAP CPI (integração)']);
    $bare = Solution::factory()->create(['name' => 'Portal de Gestao']);

    // Typed without accents, stored with them…
    expect(Solution::filter(['search' => 'integracao'])->pluck('id')->all())->toBe([$accented->id]);
    // …and typed with accents, stored without. Both directions, or half the
    // catalog stays unreachable depending on who named the row.
    expect(Solution::filter(['search' => 'gestão'])->pluck('id')->all())->toBe([$bare->id]);
});

it('folds the search on every catalog that has one', function () {
    // One rule, everywhere: these all went case-sensitive together on the move
    // to Postgres, and a fix that only reached the one screen somebody
    // reported would leave the rest broken and silent.
    Company::factory()->create(['name' => 'Órama Serviços']);
    Person::factory()->create(['name' => 'Antônio Gonçalves']);
    Diagram::factory()->create(['name' => 'Cadastro de Atendentes']);
    $notebook = Notebook::factory()->create(['name' => 'Integração SAP']);

    expect(Company::filter(['search' => 'ORAMA SERVICOS'])->count())->toBe(1)
        ->and(Person::filter(['search' => 'antonio goncalves'])->count())->toBe(1)
        ->and(Diagram::filter(['search' => 'ATENDENTES'])->count())->toBe(1)
        ->and(Notebook::query()->whereFolded('name', 'integracao sap')->count())->toBe(1)
        ->and($notebook->exists)->toBeTrue();
});

it('reaches a solution through the company and the people attached to it', function () {
    // The macro has to work inside a whereHas closure too — that is where most
    // of these searches actually live.
    $vendor = Company::factory()->create(['name' => 'Órama']);
    $solution = Solution::factory()->create(['name' => 'Sem relação com o termo', 'vendor_company_id' => $vendor->id]);

    expect(Solution::filter(['search' => 'orama'])->pluck('id')->all())->toBe([$solution->id]);
});

it('answers the chips autocomplete the same way', function () {
    // The endpoint behind "Soluções documentadas" — the one that was reported.
    Solution::factory()->create(['name' => 'Google BigQuery']);

    $names = $this->actingAs(searcher())
        ->getJson(route('solutions.search', ['q' => 'big']))
        ->assertOk()
        ->json('results.*.name');

    expect($names)->toBe(['Google BigQuery']);
});

it('searches for a wildcard as a character, not as a wildcard', function () {
    // The term is data. Without escaping, "%" matched the whole catalog and
    // "_" matched any single character — and `_` is in the name of every
    // ZFL_* page in the imported corpus.
    $notebook = Notebook::factory()->create();
    DocumentationPage::factory()->for($notebook)->create(['title' => 'ZFL_BLOQ_CLIENTE']);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Uso de 100% da cota']);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Sem nada especial']);

    $titles = fn (string $term) => DocumentationPage::query()->whereFolded('title', $term)->pluck('title')->all();

    expect($titles('%'))->toBe(['Uso de 100% da cota'])
        ->and($titles('zfl_bloq'))->toBe(['ZFL_BLOQ_CLIENTE'])
        // `_` would match every title if it were still a wildcard.
        ->and($titles('_'))->toBe(['ZFL_BLOQ_CLIENTE']);
});

it('is a no-op for a blank term, so a filter nobody filled in narrows nothing', function () {
    Solution::factory()->count(3)->create();

    expect(Solution::query()->whereFolded('name', null)->count())->toBe(3)
        ->and(Solution::query()->whereFolded('name', '   ')->count())->toBe(3);
});

it('folds text the same way in PHP as the database does', function () {
    // The two halves of every comparison. If they ever drift, searches fail in
    // exactly the cases these tests are about and nothing else notices.
    $samples = ['Solução', 'ÁÉÍÓÚ', 'Àgua Ñandu', 'BigQuery', 'ção'];

    foreach ($samples as $sample) {
        $inDatabase = Solution::query()
            ->selectRaw(Fold::expression('name', DB::connection()) . ' as folded')
            ->where('id', Solution::factory()->create(['name' => $sample])->id)
            ->value('folded');

        expect($inDatabase)->toBe(Fold::text($sample), "amostra: {$sample}");
    }
});
