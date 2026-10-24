<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\CourseEndService;
use App\Services\PdfBrandingService;
use App\Services\XlsxExportService;
use App\Support\ArabicKashidaJustifier;
use App\Support\ArchivePdfWatermark;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseEndExportController extends Controller
{
    public function students(Course $course, CourseEndService $service, XlsxExportService $xlsx): StreamedResponse
    {
        $rows = $service->studentResultRows($course)->map(fn (array $row, int $index) => [
            $index + 1, $row['name'], $row['group'], $row['points_after'], $row['days_attended'],
            $row['memorized_pages'], $row['final_tests'], $row['final_score'],
        ])->all();

        return $xlsx->download('course-end-'.$course->id, [
            '#', __('course_end.table.name'), __('course_end.table.group'), __('course_end.table.points_after'),
            __('course_end.table.days_attended'), __('course_end.table.pages'), __('course_end.table.final_tests'), __('course_end.table.final_score'),
        ], $rows);
    }

    public function finalTests(Course $course, CourseEndService $service): Response
    {
        $rows = $service->finalTestRows($course);
        $mpdf = new Mpdf(PdfOptions::make([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_right' => 10,
            'margin_bottom' => 14,
            'margin_left' => 10,
            'setAutoTopMargin' => 'stretch',
            'autoMarginPadding' => 4,
        ]));
        $mpdf->SetDirectionality('rtl');
        $dateLabel = __('course_end.date_label');
        $partsLabel = __('course_end.final_tests_total');

        if (app()->isLocale('ar')) {
            $mpdf->SetFont('dubaimedium', '', 9);
            [$dateLabel, $partsLabel] = array_values(ArabicKashidaJustifier::column(
                [$dateLabel, $partsLabel],
                fn (string $value): float => $mpdf->GetStringWidth($value),
            ));
        }

        $logo = app(PdfBrandingService::class)->logoSource();
        $archiveCourse = $course->is_active ? null : $course;
        ArchivePdfWatermark::apply($mpdf, $archiveCourse);
        $mpdf->WriteHTML(view('reports.course-final-tests', compact(
            'course',
            'rows',
            'logo',
            'archiveCourse',
            'dateLabel',
            'partsLabel',
        ))->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('exports.pdf.course_final_tests'),
                $course->name,
            ], 'course-final-tests-'.$course->id.'.pdf'),
        ]);
    }
}
