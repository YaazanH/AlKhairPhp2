<?php

namespace App\Http\Controllers;

use App\Services\AccessScopeService;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    /**
     * Download the attendance report as CSV.
     */
    public function attendance(Request $request): StreamedResponse
    {
        return $this->csvDownload(
            'attendance-report',
            ['Date', 'Academic Year', 'Group', 'Course', 'Student', 'Status', 'Status Code', 'Notes'],
            app(ReportingService::class)->attendanceRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the assessment report as CSV.
     */
    public function assessments(Request $request): StreamedResponse
    {
        return $this->csvDownload(
            'assessment-report',
            ['Scheduled At', 'Academic Year', 'Group', 'Course', 'Assessment', 'Type', 'Student', 'Score', 'Status', 'Attempt', 'Teacher', 'Notes'],
            app(ReportingService::class)->assessmentRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the memorization report as CSV.
     */
    public function memorization(Request $request): StreamedResponse
    {
        return $this->csvDownload(
            'memorization-report',
            ['Recorded On', 'Academic Year', 'Group', 'Course', 'Student', 'Teacher', 'Entry Type', 'From Page', 'To Page', 'Pages Count', 'Notes'],
            app(ReportingService::class)->memorizationRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the point ledger report as CSV.
     */
    public function points(Request $request): StreamedResponse
    {
        return $this->csvDownload(
            'points-report',
            ['Entered At', 'Academic Year', 'Group', 'Course', 'Student', 'Point Type', 'Policy', 'Source Type', 'Points', 'Notes'],
            app(ReportingService::class)->pointRows($this->validatedFilters($request)),
        );
    }

    protected function csvDownload(string $filenamePrefix, array $headers, array $rows): StreamedResponse
    {
        $filename = sprintf('%s-%s.csv', $filenamePrefix, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($headers, $rows) {
            $stream = fopen('php://output', 'w');

            fputcsv($stream, $headers);

            foreach ($rows as $row) {
                fputcsv($stream, array_map(
                    fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value,
                    $row,
                ));
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
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
