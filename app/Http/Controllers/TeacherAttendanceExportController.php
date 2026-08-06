<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\TeacherAttendanceDay;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class TeacherAttendanceExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from']]);
        $days = TeacherAttendanceDay::query()->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']])->count();
        $teachers = Teacher::query()->with(['accessRole', 'jobTitle'])->where('is_helping', true)->get()->sortBy('first_name')->map(function (Teacher $teacher) use ($validated, $days): array {
            $present = $teacher->attendanceRecords()->whereHas('attendanceDay', fn ($query) => $query->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']]))->whereHas('status', fn ($query) => $query->where('is_present', true))->count();
            return ['name' => trim($teacher->first_name.' '.$teacher->last_name), 'role' => $teacher->accessRole?->name ?: $teacher->jobTitle?->name ?: $teacher->job_title, 'percentage' => $days > 0 ? (int) ceil(($present / $days) * 100) : 0];
        })->sortBy(fn (array $row) => mb_strtolower($row['name']))->values();
        $course = Course::query()->where('is_default', true)->first();
        $html = view('reports.teacher-attendance', compact('teachers', 'course', 'validated'))->render();
        $mpdf = new Mpdf(['format' => 'A4', 'orientation' => 'P', 'mode' => 'utf-8', 'default_font' => 'dejavusans']);
        $mpdf->SetDirectionality('rtl'); $mpdf->WriteHTML($html);
        return response($mpdf->Output('', 'S'), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="teacher-attendance.pdf"']);
    }
}
