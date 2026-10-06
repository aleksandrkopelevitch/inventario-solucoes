<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Services\DocumentationCoverageService;
use App\View\Components\Layouts\UserMenu;

class ProfileController extends Controller
{
    public function show(DocumentationCoverageService $coverage)
    {
        $user = auth()->user();

        // The landing page is reached by every account, so each card asks the
        // policy of the module it opens: a module at level None
        // (App\Enums\AccessLevel) is not shown here either — not even as a count.
        $can = fn (string $model) => $user->can('viewAny', $model);
        $seesDocumentation = $can(Notebook::class);

        // Live inventory snapshot — each card links to its section, so the
        // landing page doubles as the fastest way into the four catalogs.
        $metrics = [
            ['model' => Solution::class, 'label' => 'Soluções', 'detail' => 'catalogadas', 'icon' => 'squares-2x2', 'url' => route('solutions.index')],
            ['model' => Diagram::class, 'label' => 'Diagramas', 'detail' => 'desenhados', 'icon' => 'share', 'url' => route('diagrams.index')],
            ['model' => Person::class, 'label' => 'Pessoas', 'detail' => 'responsáveis', 'icon' => 'users', 'url' => route('people.index')],
            ['model' => Company::class, 'label' => 'Empresas', 'detail' => 'fornecedores', 'icon' => 'building-office-2', 'url' => route('companies.index')],
        ];
        $metrics = collect($metrics)
            ->filter(fn (array $metric) => $can($metric['model']))
            ->map(fn (array $metric) => $metric + ['value' => $metric['model']::query()->count()])
            ->values()
            ->all();

        // Real documentation coverage (whole inventory), measured by content.
        // Both bars read the page tree now — a solution is documented when one
        // of its pages has content, and the second bar is those pages
        // themselves. There is no third documentation surface to chart.
        $coverageBars = [];
        if ($seesDocumentation) {
            $counters = $coverage->counters();
            $coverageBars = [
                ['label' => 'Soluções', 'icon' => 'squares-2x2'] + $counters['solutions'],
                ['label' => 'Páginas', 'icon' => 'document-text'] + $counters['pages'],
            ];
        }

        // Shortcuts into the work — the things staff actually come here to do.
        $shortcuts = [
            ['model' => Notebook::class, 'label' => 'Documentação', 'detail' => 'Hub de cobertura por conteúdo', 'icon' => 'book-open', 'url' => route('documentation.index')],
            ['model' => Diagram::class, 'label' => 'Diagramas', 'detail' => 'Fluxos desenhados do ecossistema', 'icon' => 'share', 'url' => route('diagrams.index')],
            // The map belongs to no module: every account reads it.
            ['model' => null, 'label' => 'Mapa do ecossistema', 'detail' => 'Grafo das integrações', 'icon' => 'globe-alt', 'url' => route('solutions.map')],
            ['model' => FlowspecChat::class, 'label' => 'Especialista em Integrações', 'detail' => 'Gera flowSpecs para a Digibee', 'icon' => 'cpu-chip', 'url' => route('flowspec.index')],
        ];
        $shortcuts = array_values(array_filter($shortcuts, fn (array $shortcut) => $shortcut['model'] === null || $can($shortcut['model'])));

        $flowspecCount = $can(FlowspecChat::class)
            ? FlowspecMessage::query()->where('role', 'assistant')->whereNotNull('flow_spec')->count()
            : 0;

        return view('profile.index', [
            'user'          => $user,
            'firstName'     => explode(' ', $user->name)[0],
            'metrics'       => $metrics,
            'coverageBars'  => $coverageBars,
            'shortcuts'     => $shortcuts,
            'flowspecCount' => $flowspecCount,
        ]);
    }

    /** Profile edit form (name/email/avatar) — always in a Modal, never its own page. */
    public function edit()
    {
        return response()->json([
            'content' => view('profile.edit', ['user' => auth()->user()])->render(),
        ]);
    }

    public function update(ProfileUpdateRequest $request)
    {
        $user = auth()->user();

        $user->update($request->only('name', 'email'));

        if ($request->hasFile('avatar')) {
            $user->clearMediaCollection('avatar');
            $user->addMediaFromRequest('avatar')
                ->toMediaCollection('avatar');
        }

        return response()->json([
            'message'        => 'Dados atualizados com sucesso.',
            'type'           => 'success',
            'updatableSlots' => [UserMenu::slot()],
            'modalIdToClose' => 'main-modal',
        ]);
    }
}
