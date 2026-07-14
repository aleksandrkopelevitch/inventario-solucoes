<?php

namespace App\Http\Controllers;

use App\Support\Heroicons;
use Illuminate\Http\JsonResponse;

/**
 * Lista os ícones outline do heroicons para o picker dos callouts da
 * documentação (`resources/js/modules/heroicon-picker.js`). Só o editor
 * (autenticado) consome isto — as views read-only exibem o SVG já
 * renderizado no servidor pelo `GitbookRenderer`.
 */
class HeroiconController extends Controller
{
    public function outline(): JsonResponse
    {
        return response()->json(Heroicons::allOutline());
    }
}
