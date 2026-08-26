<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use App\Services\PdfBrandingService;
use App\Support\PdfOptions;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class TeacherAttendanceExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from']]);
        $lastDay = TeacherAttendanceDay::query()
            ->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']])
            ->whereHas('records', fn ($query) => $query->whereNull('course_finished_at'))
            ->latest('attendance_date')
            ->latest('id')
            ->first();
        $teacherIds = $lastDay?->records()->whereNull('course_finished_at')->pluck('teacher_id') ?? collect();
        $teachers = Teacher::query()->with(['accessRole', 'jobTitle'])->whereIn('id', $teacherIds)->get()->map(function (Teacher $teacher) use ($validated): array {
            $records = $teacher->attendanceRecords()
                ->whereNull('course_finished_at')
                ->whereHas('attendanceDay', fn ($query) => $query->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']]));
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
        $course = Course::query()->where('is_default', true)->first();
        $logo = app(PdfBrandingService::class)->logoSource();
        $html = view('reports.teacher-attendance', compact('teachers', 'course', 'validated', 'logo'))->render();
        $mpdf = new Mpdf(PdfOptions::make([
            'format' => 'A4',
            'orientation' => 'P',
            'setAutoTopMargin' => 'stretch',
            'autoMarginPadding' => 4,
        ]));
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', 'S'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="teacher-attendance.pdf"']);
    }
}
