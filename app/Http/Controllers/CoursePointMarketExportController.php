<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CoursePointMarketDepartment;
use App\Services\FinanceService;
use App\Services\PdfBrandingService;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Illuminate\Http\Response;
use Mpdf\Mpdf;

class CoursePointMarketExportController extends Controller
{
    public function __invoke(Course $course, CoursePointMarketDepartment $department): Response
    {
        abort_unless((int) $department->course_id === (int) $course->id, 404);

        $department->load(['items' => fn ($query) => $query->inInvoiceOrder()]);
        $localCurrency = app(FinanceService::class)->localCurrency();
        $logo = app(PdfBrandingService::class)->logoSource();
        $mpdf = new Mpdf(PdfOptions::make([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_right' => 14,
            'margin_bottom' => 14,
            'margin_left' => 14,
            'setAutoTopMargin' => 'stretch',
            'autoMarginPadding' => 4,
        ]));
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');
        $mpdf->WriteHTML(view('reports.course-point-market-department', compact(
            'course',
            'department',
            'localCurrency',
            'logo',
        ))->render());

        return response($mpdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('course_end.point_market.pdf_title'),
                $department->name,
                $course->name,
            ], 'point-market-'.$course->id.'-'.$department->id.'.pdf'),
        ]);
    }
}
