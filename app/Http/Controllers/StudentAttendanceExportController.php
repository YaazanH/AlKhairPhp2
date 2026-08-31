<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Services\AccessScopeService;
use App\Services\PdfBrandingService;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class StudentAttendanceExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $course = Course::query()->findOrFail($validated['course_id']);
        $groups = Group::query()->with('course')->where('course_id', $course->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Group $group) => app(AccessScopeService::class)->canAccessGroup($request->user(), $group))->values();
        abort_if($groups->isEmpty(), 403);

        $groupReports = $groups->map(function (Group $group) use ($validated): array {
            $students = Enrollment::query()
                ->with('student.parentProfile')
                ->where('group_id', $group->id)
                ->where(function ($query) use ($validated) {
                    $query->where('status', 'active')
                        ->orWhereHas('studentAttendanceRecords.attendanceDay', fn ($attendanceQuery) => $attendanceQuery
                            ->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']]));
                })->get()->map(function (Enrollment $enrollment) use ($validated): array {
                    $records = $enrollment->studentAttendanceRecords()->whereHas('attendanceDay', fn ($query) => $query
                        ->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']]));
                    $listedDays = (clone $records)->distinct('group_attendance_day_id')->count('group_attendance_day_id');
                    $presentDays = (clone $records)->whereHas('status', fn ($query) => $query->where('is_present', true))->count();

                    return [
                        'name' => $enrollment->student?->full_name ?: __('crud.common.not_available'),
                        'student_number' => $enrollment->student?->student_number ?: '-',
                        'percentage' => $listedDays > 0 ? (int) ceil(($presentDays / $listedDays) * 100) : 0,
                    ];
                })->sortBy(fn (array $row) => mb_strtolower($row['name']))->values();

            return compact('group', 'students');
        });

        $logo = app(PdfBrandingService::class)->logoSource();
        $html = view('reports.student-attendance', compact('groupReports', 'course', 'validated', 'logo'))->render();
        PdfOptions::ensureMemoryCapacity();
        $mpdf = new Mpdf(PdfOptions::make(['format' => 'A4', 'orientation' => 'P']));
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('exports.pdf.student_attendance'),
                $course->name,
                __('exports.pdf.date_range', [
                    'from' => Carbon::parse($validated['date_from'])->format('d-m-Y'),
                    'to' => Carbon::parse($validated['date_to'])->format('d-m-Y'),
                ]),
            ], 'student-attendance-'.$course->id.'.pdf'),
        ]);
    }
}
