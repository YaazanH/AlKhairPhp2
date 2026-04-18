<?php

namespace App\Http\Controllers;

use App\Services\AccessScopeService;
use App\Services\ReportingService;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    /**
     * Download the attendance report as XLSX.
     */
    public function attendance(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'attendance-report',
            ['Date', 'Academic Year', 'Group', 'Course', 'Student', 'Status', 'Status Code', 'Notes'],
            app(ReportingService::class)->attendanceRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the assessment report as XLSX.
     */
    public function assessments(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'assessment-report',
            ['Scheduled At', 'Academic Year', 'Group', 'Course', 'Assessment', 'Type', 'Student', 'Score', 'Status', 'Attempt', 'Teacher', 'Notes'],
            app(ReportingService::class)->assessmentRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the memorization report as XLSX.
     */
    public function memorization(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'memorization-report',
            ['Recorded On', 'Academic Year', 'Group', 'Course', 'Student', 'Teacher', 'Entry Type', 'From Page', 'To Page', 'Pages Count', 'Notes'],
            app(ReportingService::class)->memorizationRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the point ledger report as XLSX.
     */
    public function points(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'points-report',
            ['Entered At', 'Academic Year', 'Group', 'Course', 'Student', 'Point Type', 'Policy', 'Source Type', 'Points', 'Notes'],
            app(ReportingService::class)->pointRows($this->validatedFilters($request)),
        );
    }

    protected function xlsxDownload(string $filenamePrefix, array $headers, array $rows): StreamedResponse
    {
        return app(XlsxExportService::class)->download($filenamePrefix, $headers, $rows);
    }

    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'assessment_type_id' => ['nullable', 'integer', 'exists:assessment_types,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
        ]);

        if (($validated['group_id'] ?? null) !== null) {
            $scopedGroup = app(AccessScopeService::class)
                ->scopeGroups(\App\Models\Group::query(), $request->user())
                ->whereKey($validated['group_id'])
                ->exists();

            abort_unless($scopedGroup, 403);
        }

        return $validated;
    }
}
