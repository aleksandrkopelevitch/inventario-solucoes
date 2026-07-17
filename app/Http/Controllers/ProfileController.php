<?php

namespace App\Http\Controllers;

use App\Enums\BackgroundTheme;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\FlowspecMessage;
use App\Support\BackgroundPhoto;
use App\View\Components\Layouts\UserMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProfileController extends Controller
{
    public function show()
    {
        $user = auth()->user();

        // Placeholder dashboard content. Real catalog/coverage widgets land in the
        // feature etapas (F1/F7) — this only exercises the post-login landing layout.
        $metrics = [
            ['label' => 'Soluções catalogadas', 'value' => '81', 'detail' => 'no inventário', 'icon' => 'squares-2x2'],
            ['label' => 'Integrações mapeadas', 'value' => '—', 'detail' => 'a detalhar (F2)', 'icon' => 'arrows-right-left'],
            ['label' => 'Cobertura de documentação', 'value' => '—', 'detail' => 'a medir (F7)', 'icon' => 'document-text'],
            [
                'label'  => 'flowSpecs gerados',
                'value'  => (string) FlowspecMessage::query()->where('role', 'assistant')->whereNotNull('flow_spec')->count(),
                'detail' => 'no gerador (F8)',
                'icon'   => 'cpu-chip',
            ],
        ];

        $columns = [
            [
                'title'  => 'A documentar',
                'accent' => 'rgba(20, 184, 166, 0.10)',
                'border' => 'rgba(20, 184, 166, 0.20)',
                'icon'   => 'inbox-stack',
                'cards'  => [
                    ['title' => 'Importar inventário', 'meta' => '81 soluções (F1)', 'tag' => 'Etapa 1', 'tagColor' => '#0f766e'],
                ],
            ],
            [
                'title'  => 'Em documentação',
                'accent' => 'rgba(59, 130, 246, 0.10)',
                'border' => 'rgba(59, 130, 246, 0.18)',
                'icon'   => 'bolt',
                'cards'  => [
                    ['title' => 'Detalhar integrações', 'meta' => 'endpoints e contratos (F2)', 'tag' => 'Etapa 3', 'tagColor' => '#2563eb'],
                ],
            ],
            [
                'title'  => 'Em revisão',
                'accent' => 'rgba(99, 102, 241, 0.10)',
                'border' => 'rgba(99, 102, 241, 0.20)',
                'icon'   => 'clipboard-document-check',
                'cards'  => [
                    ['title' => 'Mapa visual', 'meta' => 'canvas derivado (F3)', 'tag' => 'Etapa 4', 'tagColor' => '#4f46e5'],
                ],
            ],
            [
                'title'  => 'Publicado',
                'accent' => 'rgba(34, 197, 94, 0.10)',
                'border' => 'rgba(34, 197, 94, 0.20)',
                'icon'   => 'check-circle',
                'cards'  => [
                    ['title' => 'Fundação do projeto', 'meta' => 'infra portada (Etapa 0)', 'tag' => 'Concluído', 'tagColor' => '#15803d'],
                ],
            ],
        ];

        $agenda = [
            ['time' => 'F4', 'title' => 'Documentação por blocos', 'detail' => 'GitbookRenderer · docBlocks.js'],
            ['time' => 'F5', 'title' => 'People & Companies', 'detail' => 'responsáveis por solução'],
        ];

        $feed = [
            ['title' => 'Infra genérica portada', 'detail' => 'Forms, slots, módulos JS e shells de layout.', 'color' => '#2563eb'],
            ['title' => 'Domínio legado removido', 'detail' => 'Multi-tenancy e módulos do projeto de referência fora deste escopo.', 'color' => '#d95f02'],
            ['title' => 'Gerador de flowSpec publicado', 'detail' => 'Chat em /flowspec: corpus curado + laravel/ai + validação (F8).', 'color' => '#15803d'],
        ];

        return view('profile.index', [
            'user'      => $user,
            'firstName' => explode(' ', $user->name)[0],
            'metrics'   => $metrics,
            'columns'   => $columns,
            'agenda'    => $agenda,
            'feed'      => $feed,
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
