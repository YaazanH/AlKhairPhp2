<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TeacherDailySummaryService;
use Illuminate\Http\Request;

class TeacherDailySummaryController extends Controller
{
    /**
     * Return a teacher-grouped daily operations digest for automations.
     */
    public function __invoke(Request $request, TeacherDailySummaryService $summaryService): array
    {
        abort_unless($request->user()?->can('reports.view'), 403);

        $filters = $request->validate([
            'date' => ['nullable', 'date'],
            'include_empty' => ['nullable', 'boolean'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
        ]);

        return $summaryService->summary($request->user(), $filters);
    }
}
