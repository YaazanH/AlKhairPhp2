<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Services\AccessScopeService;
use App\Services\PdfBrandingService;
use App\Support\ArabicKashidaJustifier;
use App\Support\ArchivePdfWatermark;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class TeacherAttendanceExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['course_id' => ['nullable', 'integer', 'exists:courses,id'], 'date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from']]);
        $scopes = app(AccessScopeService::class);
        $courseId = $validated['course_id'] ?? null;
        $course = $courseId ? Course::findOrFail($courseId) : Course::query()->where('is_default', true)->first();
        $archivedCourse = $course && ! $course->is_active;
        $archiveCourse = $archivedCourse ? $course : null;
        $scopeRecordsToCourse = function ($query) use ($archivedCourse, $courseId) {
            return $archivedCourse
                ? $query->where(fn ($records) => $records
                    ->where('archived_course_id', $courseId)
                    ->orWhere(fn ($legacy) => $legacy
                        ->whereNull('archived_course_id')
                        ->whereNotNull('course_finished_at')
                        ->whereHas('attendanceDay', fn ($day) => $day->where('course_id', $courseId))))
                : $query->whereNull('course_finished_at');
        };
        $lastDay = $scopes->scopeTeacherAttendanceDays(TeacherAttendanceDay::query(), $request->user())
            ->when($courseId, fn ($query) => $archivedCourse
                ? $query->where(fn ($days) => $days
                    ->where('course_id', $courseId)
                    ->orWhereHas('records', fn ($records) => $records->where('archived_course_id', $courseId)))
                : $query->where('course_id', $courseId))
            ->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']])
            ->whereHas('records', $scopeRecordsToCourse)
            ->latest('attendance_date')
            ->latest('id')
            ->first();
        $teacherIds = $lastDay ? $scopeRecordsToCourse($lastDay->records())->pluck('teacher_id') : collect();
        $teachers = $scopes->scopeTeachers(Teacher::query(), $request->user())->with(['accessRole', 'jobTitle'])->whereIn('id', $teacherIds)->get()->map(function (Teacher $teacher) use ($validated, $scopeRecordsToCourse): array {
            $records = $teacher->attendanceRecords()
                ->whereHas('attendanceDay', fn ($query) => $query->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']]));
            $scopeRecordsToCourse($records);
            $listedDays = (clone $records)->distinct('teacher_attendance_day_id')->count('teacher_attendance_day_id');
            $present = (clone $records)->whereHas('status', fn ($query) => $query->where('is_present', true))->count();
            $role = $teacher->accessRole?->name ?: $teacher->jobTitle?->name ?: $teacher->job_title;
            if (in_array($role, ['super_admin', 'superadmin', 'admin', 'manager'], true)) {
                $role = 'manager';
            }
            $translatedRole = $role ? __('ui.roles.'.$role) : '';
            if ($role && $translatedRole === 'ui.roles.'.$role) {
                $translatedRole = str_replace(['_', '-'], ' ', $role);
            }

            return ['name' => trim($teacher->first_name.' '.$teacher->last_name), 'role' => $translatedRole, 'percentage' => $listedDays > 0 ? (int) ceil(($present / $listedDays) * 100) : 0];
        })->sortBy(fn (array $row) => mb_strtolower($row['name']))->values();
        PdfOptions::ensureMemoryCapacity();
        $mpdf = new Mpdf(PdfOptions::make([
            'format' => 'A4',
            'orientation' => 'P',
            'setAutoTopMargin' => 'stretch',
            'autoMarginPadding' => 4,
        ]));
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');

        if (app()->isLocale('ar') && $teachers->isNotEmpty()) {
            $mpdf->SetFont('dubai', '', 11);
            $justifiedRoles = ArabicKashidaJustifier::column(
                $teachers->pluck('role')->all(),
                fn (string $value): float => $mpdf->GetStringWidth($value),
            );
            $teachers = $teachers->map(function (array $teacher, int $index) use ($justifiedRoles): array {
                $teacher['role_label'] = $teacher['role'];
                $teacher['role'] = $justifiedRoles[$index];

                return $teacher;
            });
        } else {
            $teachers = $teachers->map(function (array $teacher): array {
                $teacher['role_label'] = $teacher['role'];

                return $teacher;
            });
        }

        $logo = app(PdfBrandingService::class)->logoSource();
        $html = view('reports.teacher-attendance', compact('teachers', 'course', 'archiveCourse', 'validated', 'logo'))->render();
        ArchivePdfWatermark::apply($mpdf, $archiveCourse);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('exports.pdf.teacher_attendance'),
                __('exports.pdf.date_range', [
                    'from' => Carbon::parse($validated['date_from'])->format('d-m-Y'),
                    'to' => Carbon::parse($validated['date_to'])->format('d-m-Y'),
                ]),
            ], 'teacher-attendance.pdf'),
        ]);
    }
}
