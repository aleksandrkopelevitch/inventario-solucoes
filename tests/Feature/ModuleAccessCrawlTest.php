<?php

use App\Contracts\Documentable;
use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\DocumentationChat;
use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Models\SubmissionDiagram;
use App\Models\SubmissionSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A module at level None is closed on EVERY route, not only the ones we remember
|--------------------------------------------------------------------------
|
| The policies are the only gate since the per-module levels landed (there is
| no route-group middleware any more), so a GET action that forgets its
| `authorize()` is a module leaking to accounts that may not see it. This walks
| the real route table: every authenticated GET is either on the short list of
| routes that belong to no module, or is mapped to its module below — and a new
| route that is neither fails here until somebody decides which it is.
|
*/

/** Routes every account reaches, whatever its levels. */
const OPEN_ROUTES = [
    'profile.show', 'profile.edit', 'showcase', 'heroicons.outline',
    // The ecosystem map belongs to no module, by decision.
    'solutions.map', 'solutions.map.data',
    // Connecting a chat client is the account's own business.
    'mcp.connect',
    // The knowledge base: published cadernos, any account (docs-reader-surfaces).
    'docs.index', 'docs.notebook', 'docs.page', 'docs.search', 'docs.file', 'docs.diagram',
    // Admin screens: closed to every member by their own policies.
    'mcp-tokens.index', 'people.accounts', 'attribute-options.index', 'attribute-options.options',
];

/** @return array<string, AccessModule> route name => module */
function routeModules(): array
{
    $map = [];
    foreach (['companies.', 'people.', 'solutions.'] as $prefix) {
        $map[$prefix] = AccessModule::Catalog;
    }
    foreach (['notebooks.', 'diagrams.', 'documentation.', 'files.'] as $prefix) {
        $map[$prefix] = AccessModule::Documentation;
    }
    $map['flowspec.'] = AccessModule::Integrations;
    $map['submissions.'] = AccessModule::Committee;

    return $map;
}

function moduleOf(string $name): ?AccessModule
{
    foreach (routeModules() as $prefix => $module) {
        if (str_starts_with($name, $prefix)) {
            return $module;
        }
    }

    return null;
}

/** One record of everything a route might bind, keyed by parameter name. */
function crawlFixtures(): array
{
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create(['documentation' => '# Texto']);
    $media = $page->addMediaFromString('bytes')->usingFileName('a.png')->toMediaCollection(Documentable::DOCS_COLLECTION);
    $flowspec = FlowspecChat::factory()->create();
    $submission = Submission::factory()->create();

    return [
        'company'    => Company::factory()->create(),
        'person'     => Person::factory()->create(),
        'solution'   => Solution::factory()->create(),
        'notebook'   => $notebook,
        'page'       => $page,
        'media'      => $media,
        'diagram'    => Diagram::factory()->create(['notebook_id' => $notebook->id]),
        'flowspec'   => $flowspec,
        'message'    => FlowspecMessage::factory()->for($flowspec, 'chat')->create(),
        'submission' => $submission,
        'subchat'    => SubmissionChat::factory()->for($submission)->create(),
        'docchat'    => DocumentationChat::factory()->create(),
        'subdiagram' => SubmissionDiagram::factory()->for($submission)->create(),
        'source'     => SubmissionSource::factory()->for($submission)->create(),
        'group'      => 'x',
    ];
}

/** Parameters for `$route`, resolved from the fixtures by name and module. */
function crawlParameters(Route $route, array $fx): array
{
    $params = [];
    foreach ($route->parameterNames() as $name) {
        $params[$name] = match ($name) {
            'chat' => str_starts_with($route->getName(), 'submissions.') ? $fx['subchat']
                : (str_starts_with($route->getName(), 'notebooks.') ? $fx['docchat'] : $fx['flowspec']),
            'diagram' => str_starts_with($route->getName(), 'submissions.') ? $fx['subdiagram'] : $fx['diagram'],
            default   => $fx[$name] ?? 'x',
        };
    }

    return $params;
}

function authenticatedGetRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
        ->filter(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
        ->filter(fn (Route $r) => $r->getName() !== null)
        ->values()
        ->all();
}

it('maps every authenticated GET route either to a module or to the open list', function () {
    $unmapped = collect(authenticatedGetRoutes())
        ->map(fn (Route $r) => $r->getName())
        ->reject(fn (string $name) => in_array($name, OPEN_ROUTES, true) || moduleOf($name) !== null)
        ->values()
        ->all();

    expect($unmapped)->toBe([]);
});

it('closes every route of a module to an account whose level there is None', function (AccessModule $closed) {
    $fx = crawlFixtures();

    // None in the module under test, Editor everywhere else: whatever leaks,
    // leaks because of the module, not for want of some other permission.
    $user = User::factory()->editor(...array_filter(AccessModule::cases(), fn ($m) => $m !== $closed))->create();
    $user->setAccessLevel($closed, AccessLevel::None);
    $user->save();

    $leaks = [];
    foreach (authenticatedGetRoutes() as $route) {
        if (in_array($route->getName(), OPEN_ROUTES, true) || moduleOf($route->getName()) !== $closed) {
            continue;
        }

        $status = $this->actingAs($user)
            ->get(route($route->getName(), crawlParameters($route, $fx)))
            ->getStatusCode();

        if ($status < 400) {
            $leaks[] = "{$route->getName()} → {$status}";
        }
    }

    expect($leaks)->toBe([]);
})->with(AccessModule::cases());

it('keeps the open routes open to an account that is None everywhere', function () {
    $user = User::factory()->create(['role' => UserRole::Member->value]);
    foreach (AccessModule::cases() as $module) {
        $user->setAccessLevel($module, AccessLevel::None);
    }
    $user->save();

    foreach (['profile.show', 'solutions.map', 'mcp.connect', 'docs.index'] as $name) {
        $this->actingAs($user)->get(route($name))->assertOk();
    }
});
