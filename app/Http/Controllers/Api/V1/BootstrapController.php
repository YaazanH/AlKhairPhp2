<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MobileBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request, MobileBootstrapService $bootstrap): JsonResponse
    {
        return response()->json(['data' => $bootstrap->for($request->user())]);
    }
}
