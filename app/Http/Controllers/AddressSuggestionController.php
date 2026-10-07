<?php

namespace App\Http\Controllers;

use App\Services\AddressSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressSuggestionController extends Controller
{
    public function __invoke(Request $request, AddressSuggestionService $suggestions): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:3', 'max:200']]);

        return response()->json(['suggestions' => $suggestions->suggest($validated['q'])])
            ->header('Cache-Control', 'private, no-store');
    }
}
