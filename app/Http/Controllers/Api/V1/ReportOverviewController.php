<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use Illuminate\Http\Request;

class ReportOverviewController extends Controller
{
    /**
     * Return the high-level operational report for integrations.
     */
    public function __invoke(Request $request, ReportingService $reportingService): array
    {
        abort_unless($request->user()?->can('reports.view'), 403);

        $filters = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
        ]);

        return $reportingService->overview($filters);
    }
}
