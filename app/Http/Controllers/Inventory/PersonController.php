<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\ContactType;
use App\Enums\PersonSolutionRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\View\Components\People\DetailHeader;
use App\View\Components\People\FilterChips;
use App\View\Components\People\Index as PeopleIndex;
use App\View\Components\People\ResultsCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PersonController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Person::class);

        $filters = (array) $request->input('filter', []);

        if ($request->wantsJson()) {
            return response()->json(['updatableSlots' => [
                PeopleIndex::slot($filters),
                ResultsCount::slot($filters),
                FilterChips::slot($filters),
            ]]);
        }

        return view('people.index', [
            'filters'   => $filters,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'roles'     => PersonSolutionRole::cases(),
        ]);
    }

    public function show(Request $request, Person $person)
    {
        $this->authorize('view', $person);

        $person->load([
            'company:id,name,slug,logo_path',
            'contacts',
            'solutions:id,name,slug',
        ]);

        if ($request->wantsJson()) {
            return response()->json(['person' => $person]);
        }

        return view('people.show', ['person' => $person]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorize('create', Person::class);

        return $this->panel(new Person, (array) $request->query('filter', []));
    }

    public function edit(Request $request, Person $person): JsonResponse
    {
        $this->authorize('update', $person);

        $person->load(['solutions:id,name,slug', 'contacts']);

        return $this->panel($person, (array) $request->query('filter', []));
    }

    public function store(StorePersonRequest $request): JsonResponse
    {
        $person = Person::create($this->payload($request));
        $this->syncSolutions($request, $person);
        $this->syncContacts($request, $person);

        return $this->saved('Pessoa criada com sucesso.', null, (array) $request->query('filter', []));
    }

    public function update(UpdatePersonRequest $request, Person $person): JsonResponse
    {
        $person->update($this->payload($request, $person));
        $this->syncSolutions($request, $person);
        $this->syncContacts($request, $person);

        return $this->saved('Pessoa atualizada com sucesso.', $person, (array) $request->query('filter', []));
    }

    /**
     * `$filters` são os filtros ativos na listagem que abriu o painel —
     * repassados pela URL até `saved()`, para o slot atualizado após salvar
     * preservar os filtros em vigor.
     */
    private function panel(Person $person, array $filters = []): JsonResponse
    {
        return response()->json([
            'content' => view('people.form', [
                'person'       => $person,
                'filters'      => $filters,
                'companies'    => Company::orderBy('name')->get(['id', 'name']),
                'roles'        => PersonSolutionRole::cases(),
                'contactTypes' => ContactType::cases(),
            ])->render(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(StorePersonRequest|UpdatePersonRequest $request, ?Person $person = null): array
    {
        $data = $request->safe()->except(['photo', 'solutions', 'contacts']);

        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($person?->slug ?? $this->uniqueSlug($data['name'], $person));

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('photos', 'public');
        }

        return $data;
    }

    /** Vínculo pessoa <-> solução (com papel), vindo dos chips do form. */
    private function syncSolutions(StorePersonRequest|UpdatePersonRequest $request, Person $person): void
    {
        if (! $request->has('solutions')) {
            return;
        }

        $sync = [];

        foreach ((array) $request->input('solutions', []) as $link) {
            $value = $link['value'] ?? null;
            if (blank($value)) {
                continue;
            }

            $id = is_numeric($value)
                ? Solution::whereKey((int) $value)->value('id')
                : Solution::where('slug', $value)->value('id');
            if (! $id) {
                continue;
            }

            $sync[$id] = ['role' => $link['role'] ?? PersonSolutionRole::Technical->value];
        }

        $person->solutions()->sync($sync);
    }

    /**
     * Contatos adicionais (`Person::contacts()`) — vindos das linhas
     * repetíveis do form (`data-ak-contacts`). Cada linha com `id` atualiza o
     * contato existente; sem `id`, cria um novo; uma linha em branco
     * (adicionada mas nunca preenchida) é ignorada. Contatos existentes cujo
     * `id` não veio de volta no request foram removidos pelo usuário no
     * form, então são apagados aqui — mesmo espírito de `solutions()->sync()`
     * acima, só que sem um método `sync()` pronto pra isso (a linha carrega
     * mais que um id de pivot: type/value).
     */
    private function syncContacts(StorePersonRequest|UpdatePersonRequest $request, Person $person): void
    {
        if (! $request->has('contacts')) {
            return;
        }

        $keptIds = [];

        foreach ((array) $request->input('contacts', []) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $attributes = [
                'type'  => $row['type'] ?? ContactType::Other->value,
                'value' => $value,
            ];

            $id = $row['id'] ?? null;
            if ($id) {
                $person->contacts()->whereKey($id)->update($attributes);
                $keptIds[] = (int) $id;
            } else {
                $keptIds[] = $person->contacts()->create($attributes)->id;
            }
        }

        // `[0]` como sentinela: `whereNotIn` com array vazio apagaria todos os
        // contatos existentes se o usuário removeu a última linha do form.
        $person->contacts()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    private function saved(string $message, ?Person $person = null, array $filters = []): JsonResponse
    {
        $slots = [PeopleIndex::slot($filters), ResultsCount::slot($filters), FilterChips::slot($filters)];

        if ($person) {
            $slots[] = DetailHeader::slot($person);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => $slots,
            'js'             => "document.querySelector('[data-ak-panel-close]')?.click()",
        ]);
    }

    private function uniqueSlug(string $name, ?Person $person): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Person::where('slug', $slug)
            ->when($person, fn ($q) => $q->whereKeyNot($person->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
