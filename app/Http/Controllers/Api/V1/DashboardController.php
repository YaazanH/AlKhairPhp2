<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MobileDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MobileDashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->for($request->user())]);
    }
}
