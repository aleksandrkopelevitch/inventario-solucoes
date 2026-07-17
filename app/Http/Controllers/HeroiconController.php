<?php

namespace App\Http\Controllers;

use App\Support\Heroicons;
use Illuminate\Http\JsonResponse;

/**
 * Lists the heroicons outline icons for the documentation callouts' picker
 * (`resources/js/modules/heroicon-picker.js`). Only the (authenticated)
 * editor consumes this — read-only views display the SVG already rendered
 * server-side by `GitbookRenderer`.
 */
class HeroiconController extends Controller
{
    public function outline(): JsonResponse
    {
        return response()->json(Heroicons::allOutline());
    }
}
