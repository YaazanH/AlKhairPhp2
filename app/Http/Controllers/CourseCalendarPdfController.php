<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\CourseCalendarService;
use App\Services\PdfBrandingService;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use InvalidArgumentException;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class CourseCalendarPdfController extends Controller
{
    public function __invoke(Course $course, CourseCalendarService $calendarService, PdfBrandingService $branding): Response
    {
        $course->loadMissing(['schedules', 'calendarEntries']);

        try {
            $calendar = $calendarService->build($course);
        } catch (InvalidArgumentException) {
            abort(422, __('course_calendar.errors.invalid_dates'));
        }

        $logo = $branding->logoSource();
        $mpdf = new Mpdf(PdfOptions::make([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_top' => 8,
            'margin_right' => 8,
            'margin_bottom' => 8,
            'margin_left' => 8,
        ]));
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');

        $calendarBackground = public_path('images/course-calendar-background.png');

        if (is_file($calendarBackground)) {
            $mpdf->SetWatermarkImage($calendarBackground, 1, [210, 297], [0, 0]);
            $mpdf->showWatermarkImage = true;
        }

        $mpdf->WriteHTML(view('reports.course-calendar', compact('course', 'calendar', 'logo'))->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('course_calendar.filename'),
                $course->name,
            ], 'course-calendar-'.$course->id.'.pdf'),
        ]);
    }
}
