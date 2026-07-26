<?php

namespace App\Http\Controllers;

use App\Enums\BackgroundTheme;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Company;
use App\Models\FlowspecMessage;
use App\Models\Integration;
use App\Models\Person;
use App\Models\Solution;
use App\Services\DocumentationCoverageService;
use App\Support\BackgroundPhoto;
use App\View\Components\Layouts\UserMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProfileController extends Controller
{
    public function show(DocumentationCoverageService $coverage)
    {
        $user = auth()->user();

        $counters = $coverage->counters();

        // Live inventory snapshot — each card links to its section, so the
        // landing page doubles as the fastest way into the four catalogs.
        $metrics = [
            ['label' => 'Soluções', 'value' => Solution::query()->count(), 'detail' => 'catalogadas', 'icon' => 'squares-2x2', 'url' => route('solutions.index')],
            ['label' => 'Integrações', 'value' => Integration::query()->count(), 'detail' => 'mapeadas', 'icon' => 'arrows-right-left', 'url' => route('solutions.map')],
            ['label' => 'Pessoas', 'value' => Person::query()->count(), 'detail' => 'responsáveis', 'icon' => 'users', 'url' => route('people.index')],
            ['label' => 'Empresas', 'value' => Company::query()->count(), 'detail' => 'fornecedores', 'icon' => 'building-office-2', 'url' => route('companies.index')],
        ];

        // Real documentation coverage (whole inventory), measured by content.
        $coverageBars = [
            ['label' => 'Soluções', 'icon' => 'squares-2x2'] + $counters['solutions'],
            ['label' => 'Integrações', 'icon' => 'arrows-right-left'] + $counters['integrations'],
        ];

        // Shortcuts into the work — the things staff actually come here to do.
        $shortcuts = [
            ['label' => 'Documentação', 'detail' => 'Hub de cobertura por conteúdo', 'icon' => 'book-open', 'url' => route('documentation.index')],
            ['label' => 'Mapa do ecossistema', 'detail' => 'Grafo das integrações', 'icon' => 'share', 'url' => route('solutions.map')],
            ['label' => 'Especialista em Integrações', 'detail' => 'Gera flowSpecs para a Digibee', 'icon' => 'cpu-chip', 'url' => route('flowspec.index')],
        ];

        $flowspecCount = FlowspecMessage::query()
            ->where('role', 'assistant')
            ->whereNotNull('flow_spec')
            ->count();

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

    public function customizePanel()
    {
        $user = auth()->user();

        $currentBackground = $user->preference('background', [
            'type'  => 'gradient',
            'value' => BackgroundTheme::default()->value,
        ]);

        return response()->json([
            'content' => view('profile.panels.customize', [
                'themes'       => BackgroundTheme::cases(),
                'photos'       => BackgroundPhoto::all(),
                'currentType'  => $currentBackground['type'],
                'currentValue' => $currentBackground['value'],
            ])->render(),
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

    public function updatePreferences(Request $request)
    {
        $request->validate([
            'type'  => ['required', 'in:gradient,photo'],
            'value' => ['required', 'string', 'max:100'],
        ]);

        $user = auth()->user();

        $user->update([
            'preferences' => array_merge($user->preferences ?? [], [
                'background' => ['type' => $request->type, 'value' => $request->value],
            ]),
        ]);

        Cache::forget("user.{$user->id}.background_css");

        $css = BackgroundPhoto::cssFromPreference($request->type, $request->value);

        return response()->json([
            'type' => 'success',
            'js'   => 'document.getElementById("dashboard-bg").style.background = ' . json_encode($css) . ';',
        ]);
    }
}
