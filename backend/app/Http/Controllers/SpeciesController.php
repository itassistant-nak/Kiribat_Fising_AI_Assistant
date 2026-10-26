<?php

namespace App\Http\Controllers;

use App\Models\Species;
use Illuminate\Http\Request;

class SpeciesController extends Controller
{
    public function index(Request $request)
    {
        $species = Species::orderBy('kiribati_name', 'asc')->get();

        return response()->json([
            'data' => $species,
            'count' => $species->count(),
        ]);
    }
}
