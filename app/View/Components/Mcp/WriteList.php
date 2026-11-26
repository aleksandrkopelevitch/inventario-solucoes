<?php

namespace App\View\Components\Mcp;

use App\Enums\PersonSolutionRole;
use App\Enums\RelationshipDirection;
use App\Mcp\ToolRegistry;
use App\Models\Company;
use App\Models\DocumentationPage;
use App\Models\McpWrite;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Support\Documentation\SecretText;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Laravel\Passport\Client;

/**
 * "Alterações via MCP": what chats changed, newest first, each with its diff
 * and a "Reverter" — the slot every revert answers with.
 *
 * Capped at LIMIT rows with the total said out loud, like every list in this
 * app that could be long; the filters are how somebody narrows to the
 * conversation they are looking for, and "Reverter seleção" acts on the whole
 * filtered set, not on the rows that fit on screen.
 *
 * The rows are shaped HERE, not in Blade: a diff needs labels for tools,
 * record types and foreign keys, and that is lookup logic, not markup.
 */
class WriteList extends Component
{
    use Renderable;

    public const DOM_ID = 'mcp-writes-slot';

    public const LIMIT = 200;

    /** Longest value a diff cell prints before it is cut (a page body is whole in the row). */
    private const VALUE_CHARS = 280;

    /** @param  array<string, string>  $filters */
    public function __construct(public readonly array $filters = []) {}

    /** @param  array<string, string>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $query = McpWrite::query()->filter($this->filters);

        // Multi-row hydration, so every relation the rows print is loaded here
        // (§ Strict mode). `subject` is a morph across several models, loaded
        // per type in one query each.
        $writes = (clone $query)
            ->with([
                'user:id,name',
                'revertedBy:id,name',
                // A page's link needs its caderno: loaded with it, not per row.
                'subject' => fn (MorphTo $morph) => $morph->morphWith([DocumentationPage::class => ['notebook:id,slug']]),
            ])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        $clients = Client::query()->pluck('name', 'id');
        $titles = collect(app(ToolRegistry::class)->every())->mapWithKeys(fn ($tool) => [$tool->name() => $tool->title()]);
        $companies = Company::query()->whereIn('id', $this->companyIds($writes))->pluck('name', 'id');

        return view('components.mcp.write-list', [
            'domId'   => self::DOM_ID,
            'filters' => $this->filters,
            'total'   => (clone $query)->count(),
            'pending' => (clone $query)->whereNull('reverted_at')->count(),
            'rows'    => $writes->map(fn (McpWrite $write) => [
                'write'   => $write,
                'tool'    => $titles[$write->tool] ?? $write->tool,
                'client'  => $write->oauth_client_id ? ($clients[$write->oauth_client_id] ?? 'conector removido') : null,
                'subject' => $this->subject($write),
                'diff'    => collect($write->after)->map(fn ($after, string $field) => [
                    'field'  => $this->fieldLabel($field),
                    'before' => $write->isCreate() ? null : $this->value($field, $write->before[$field] ?? null, $companies),
                    'after'  => $this->value($field, $after, $companies),
                ])->values()->all(),
            ])->all(),
        ]);
    }

    /** @return array{type: string, name: string, url: ?string} */
    private function subject(McpWrite $write): array
    {
        $subject = $write->subject;
        $type = match ($write->subject_type) {
            Solution::class          => 'Solução',
            Person::class            => 'Pessoa',
            Company::class           => 'Empresa',
            Notebook::class          => 'Caderno',
            DocumentationPage::class => 'Página',
            default                  => class_basename($write->subject_type),
        };

        if (! $subject instanceof Model) {
            return ['type' => $type, 'name' => 'registro removido', 'url' => null];
        }

        return [
            'type' => $type,
            // A page is called by its title; everything else here by its name.
            'name' => (string) ($subject instanceof DocumentationPage ? $subject->title : $subject->getAttribute('name')),
            'url'  => match (true) {
                $subject instanceof Solution          => route('solutions.show', $subject),
                $subject instanceof Person            => route('people.show', $subject),
                $subject instanceof Company           => route('companies.show', $subject),
                $subject instanceof Notebook          => route('notebooks.show', $subject),
                $subject instanceof DocumentationPage => route('notebooks.pages.edit', [$subject->notebook, $subject]),
                default                               => null,
            },
        ];
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'name'                   => 'nome',
            'description'            => 'descrição',
            'vendor_company_id'      => 'fornecedor',
            'company_id'             => 'empresa',
            'support_operation_note' => 'nota de suporte',
            'job_title'              => 'cargo',
            'notes'                  => 'observações',
            'people'                 => 'responsáveis',
            'relationships'          => 'interconexões cadastradas',
            'solutions'              => 'soluções documentadas',
            'title'                  => 'título',
            'documentation'          => 'texto',
            'placement'              => 'posição na árvore',
            default                  => str_replace('_', ' ', $field),
        };
    }

    /** @param  Collection<int, string>  $companies */
    private function value(string $field, mixed $value, Collection $companies): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (in_array($field, ['vendor_company_id', 'company_id'], true)) {
            return $companies[$value] ?? "empresa #{$value}";
        }

        // A page body is shown MASKED — this screen is read by admins, and the
        // rule is that a protected value has one door (RevealPageSecret).
        if ($field === 'documentation') {
            return Str::limit(SecretText::mask((string) $value), self::VALUE_CHARS);
        }

        if ($field === 'placement' && is_array($value)) {
            return ($value['parent'] ? 'dentro de "' . $value['parent'] . '"' : 'na raiz') . ', posição ' . ((int) $value['position'] + 1);
        }

        if ($field === 'solutions' && is_array($value)) {
            return implode(', ', $value);
        }

        if ($field === 'relationships' && is_array($value)) {
            return collect($value)
                ->map(fn (array $link) => $link['solution'] . ' (' . (RelationshipDirection::tryFrom((string) $link['direction'])?->label() ?? $link['direction']) . ')')
                ->implode(', ');
        }

        if ($field === 'people' && is_array($value)) {
            return collect($value)
                ->map(fn (array $link) => $link['person'] . ' (' . (PersonSolutionRole::tryFrom((string) $link['role'])?->label() ?? $link['role']) . ')')
                ->implode(', ');
        }

        return Str::limit(is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE), self::VALUE_CHARS);
    }

    /**
     * Every company id a row's diff names, so they resolve in one query.
     *
     * @param  Collection<int, McpWrite>  $writes
     * @return list<int|string>
     */
    private function companyIds(Collection $writes): array
    {
        return $writes
            ->flatMap(fn (McpWrite $write) => [
                ...array_values(array_intersect_key($write->before ?? [], array_flip(['vendor_company_id', 'company_id']))),
                ...array_values(array_intersect_key($write->after, array_flip(['vendor_company_id', 'company_id']))),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
