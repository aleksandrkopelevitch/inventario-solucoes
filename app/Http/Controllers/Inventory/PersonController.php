<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\ContactType;
use App\Enums\PersonSolutionRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonContactRequest;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\StorePersonSolutionRequest;
use App\Http\Requests\UpdatePersonContactRequest;
use App\Http\Requests\UpdatePersonFieldRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Requests\UpdatePersonSolutionRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use App\View\Components\People\DetailHeader;
use App\View\Components\People\FilterChips;
use App\View\Components\People\Index as PeopleIndex;
use App\View\Components\People\Notes;
use App\View\Components\People\ResultsCount;
use App\View\Components\People\Systems;
use Illuminate\Contracts\View\View;
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
     * One field, edited in place on the detail page (`<x-ui.inline-edit>`),
     * instead of through the whole panel. Same shape as
     * `SolutionController::updateAttributes()`.
     */
    public function updateField(UpdatePersonFieldRequest $request, Person $person): JsonResponse
    {
        $data = $request->safe()->except(['photo', 'photo_action']);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('photos', 'public');
        } elseif ($request->input('photo_action') === 'remove') {
            // "Remover" on the `<x-forms.image-upload>` tile. The stored file
            // itself is left on disk on purpose: `photo_path` is a plain column
            // (not MediaLibrary), and nothing else in this app deletes an
            // orphaned upload either — doing it here alone would be the odd one
            // out, and a wrong click would be unrecoverable.
            $data['photo_path'] = null;
        }

        $person->update($data);

        // `notes` is edited from its own card, which is its own slot.
        return $this->fieldSaved($person, fn () => [DetailHeader::slot($person), Notes::slot($person)]);
    }

    /**
     * One additional contact (`Person::contacts()`), added / retyped / removed
     * in place on the header. Separate endpoints because the value lives on the
     * Contact row, not on the person.
     */
    public function storeContact(StorePersonContactRequest $request, Person $person): JsonResponse
    {
        $person->contacts()->create($request->validated());

        return $this->fieldSaved($person, fn () => [DetailHeader::slot($person)], 'Contato adicionado.');
    }

    public function updateContact(UpdatePersonContactRequest $request, Person $person, Contact $contact): JsonResponse
    {
        $contact->update($request->validated());

        return $this->fieldSaved($person, fn () => [DetailHeader::slot($person)]);
    }

    public function destroyContact(Request $request, Person $person, Contact $contact): JsonResponse
    {
        $this->authorize('update', $person);

        $contact->delete();

        return $this->fieldSaved($person, fn () => [DetailHeader::slot($person)], 'Contato removido.');
    }

    /**
     * The person↔solution links, from the "Sistemas" card: attach one (with its
     * role), re-role an existing one, or detach it.
     */
    public function storeSolution(StorePersonSolutionRequest $request, Person $person): JsonResponse
    {
        $person->solutions()->attach($request->validated('solution_id'), ['role' => $request->validated('role')]);

        return $this->fieldSaved($person, fn () => [Systems::slot($person)], 'Sistema vinculado.');
    }

    /**
     * Re-role a link (the badge) or re-point it at another system (the name) —
     * two editors on the same row, each sending only its own field.
     */
    public function updateSolution(UpdatePersonSolutionRequest $request, Person $person, Solution $solution): JsonResponse
    {
        $role = $request->validated('role');
        $targetId = (int) $request->validated('solution_id', $solution->getKey());

        if ($targetId !== $solution->getKey()) {
            // The pivot's identity IS the (person, solution) pair, so there's
            // no row to update in place: detach + attach, carrying over what
            // the link already held (the role, unless this same request is
            // changing it, and the `is_primary` flag — dropping it would be a
            // silent second edit the user never asked for). `$solution` comes
            // from a scoped binding, so its pivot is already hydrated.
            $person->solutions()->detach($solution->getKey());
            $person->solutions()->attach($targetId, [
                'role'       => $role ?? $solution->pivot->role,
                'is_primary' => $solution->pivot->is_primary,
            ]);

            return $this->fieldSaved($person, fn () => [Systems::slot($person)], 'Sistema alterado.');
        }

        if ($role) {
            $person->solutions()->updateExistingPivot($solution->getKey(), ['role' => $role]);
        }

        return $this->fieldSaved($person, fn () => [Systems::slot($person)]);
    }

    public function destroySolution(Request $request, Person $person, Solution $solution): JsonResponse
    {
        $this->authorize('update', $person);

        $person->solutions()->detach($solution->getKey());

        return $this->fieldSaved($person, fn () => [Systems::slot($person)], 'Sistema desvinculado.');
    }

    /**
     * Answer for every in-place mutation. Only the detail page's own slots:
     * there's no people-index-slot / ResultsCount / FilterChips on it for the
     * catalog list widgets to land in.
     *
     * The slots are built through a closure so they're always rendered AFTER
     * the reload below: each of them reads a relation that may be exactly what
     * just changed, and the components use `loadMissing()`, which would happily
     * keep the copy loaded before the mutation.
     *
     * @param  \Closure(): array<int, array{id: string, content: string}>  $slots
     */
    private function fieldSaved(Person $person, \Closure $slots, string $message = 'Alteração salva.'): JsonResponse
    {
        $person->load(['company:id,name,slug', 'contacts', 'solutions:id,name,slug']);

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => $slots(),
        ]);
    }

    /**
     * `$filters` are the filters active on the listing that opened the
     * panel — carried through the URL all the way to `saved()`, so the slot
     * updated after saving preserves the filters in effect.
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
        $data = $request->safe()->except(['photo', 'photo_action', 'solutions', 'contacts']);

        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : ($person?->slug ?? $this->uniqueSlug($data['name'], $person));

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('photos', 'public');
        } elseif ($request->input('photo_action') === 'remove') {
            // The form's `<x-forms.image-upload>` has always rendered a
            // "Remover" button writing this hidden field; nothing read it, so
            // it silently did nothing. Same handling as `updateField()`.
            $data['photo_path'] = null;
        }

        return $data;
    }

    /**
     * Person <-> solution link (with role), coming from the form's chips.
     *
     * Checks `solutions_present` (a marker rendered once outside the chips'
     * removable rows, see `people/form.blade.php`) in addition to `solutions`
     * itself: chips.js's hidden inputs live inside each chip, so clearing
     * every chip submits no `solutions[...]` key at all — without the
     * marker, that would be indistinguishable from a request that never
     * mentioned solutions, and the removal would silently never persist.
     */
    private function syncSolutions(StorePersonRequest|UpdatePersonRequest $request, Person $person): void
    {
        if (! $request->has('solutions') && ! $request->has('solutions_present')) {
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
     * Additional contacts (`Person::contacts()`) — coming from the form's
     * repeatable rows (`data-ak-contacts`). Each row with an `id` updates
     * the existing contact; without an `id`, it creates a new one; a blank
     * row (added but never filled in) is ignored. Existing contacts whose
     * `id` didn't come back in the request were removed by the user in the
     * form, so they're deleted here — same spirit as `solutions()->sync()`
     * above, just without a ready-made `sync()` method for this (the row
     * carries more than a pivot id: type/value).
     *
     * Checks `contacts_present` (a marker rendered once outside the
     * repeater's rows, see `people/form.blade.php`) in addition to
     * `contacts` itself: person-contacts.js's hidden inputs live inside each
     * removable row, so deleting every row submits no `contacts[...]` key at
     * all — without the marker, that would be indistinguishable from a
     * request that never mentioned contacts, and the deletion would
     * silently never persist.
     */
    private function syncContacts(StorePersonRequest|UpdatePersonRequest $request, Person $person): void
    {
        if (! $request->has('contacts') && ! $request->has('contacts_present')) {
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

        // `[0]` as a sentinel: `whereNotIn` with an empty array would delete
        // all existing contacts if the user removed the form's last row.
        $person->contacts()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    private function saved(string $message, ?Person $person = null, array $filters = []): JsonResponse
    {
        $slots = [PeopleIndex::slot($filters), ResultsCount::slot($filters), FilterChips::slot($filters)];

        if ($person) {
            // The panel edits notes and system links too, so the detail page's
            // other two slots have to come back with the header — otherwise
            // saving from the panel leaves them showing pre-edit data.
            $person->load(['company:id,name,slug', 'contacts', 'solutions:id,name,slug']);
            $slots[] = DetailHeader::slot($person);
            $slots[] = Notes::slot($person);
            $slots[] = Systems::slot($person);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => $slots,
            'js'             => "document.querySelector('[data-ak-panel-close]')?.click()",
        ]);
    }

    /**
     * Static segments that sit where `people/{person}` does, so a person slugged
     * with one would be permanently unreachable at their own URL.
     *
     * `new` was already exposed before `accounts` joined it — a person actually
     * named "New" would have taken the create route's place — which is why this
     * list exists now rather than one segment later. Check it against
     * `php artisan route:list --path=people` when adding a segment.
     */
    private const RESERVED_SLUGS = ['new', 'accounts'];

    /**
     * "Quem tem acesso": every ACCOUNT, and which person each belongs to.
     *
     * A view of the Pessoas module rather than the modal it replaces, and it has
     * to keep existing even though access is granted on a person's own page:
     * an account does not need a Person. `admin@leomadeiras.com.br` comes from
     * `DatabaseSeeder` and never will have a catalog row, so a screen listing
     * only "people who have accounts" would leave the one account that cannot be
     * locked out with no screen at all.
     */
    public function accounts(): View
    {
        $this->authorize('manage', User::class);

        return view('people.accounts');
    }

    private function uniqueSlug(string $name, ?Person $person): string
    {
        $base = Str::slug($name) ?: 'pessoa';
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, self::RESERVED_SLUGS, true) || Person::where('slug', $slug)
            ->when($person, fn ($q) => $q->whereKeyNot($person->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
