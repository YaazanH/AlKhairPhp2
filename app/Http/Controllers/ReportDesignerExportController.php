<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ReportDefinition;
use App\Services\PdfBrandingService;
use App\Services\ReportDefinitionAccess;
use App\Services\ReportDesignerCatalog;
use App\Services\ReportDesignerQueryService;
use App\Services\XlsxExportService;
use App\Support\ExportFilename;
use App\Support\PdfOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportDesignerExportController extends Controller
{
    public function xlsx(Request $request, ReportDefinition $reportDefinition): StreamedResponse
    {
        $result = $this->exportResult($request, $reportDefinition);
        $headers = collect($result['columns'])->pluck('label')->values()->all();
        $fieldKeys = array_keys($result['columns']);
        $rows = collect($result['rows'])
            ->map(fn (array $row) => collect($fieldKeys)->map(fn (string $field) => $row[$field] ?? null)->all())
            ->all();

        $prefix = Str::slug($reportDefinition->name);

        return app(XlsxExportService::class)->download(
            $prefix !== '' ? $prefix : 'report-'.$reportDefinition->id,
            $headers,
            $rows,
        );
    }

    public function pdf(Request $request, ReportDefinition $reportDefinition): Response
    {
        $result = $this->exportResult($request, $reportDefinition);
        $columnCount = count($result['columns']);
        $pdf = new Mpdf(PdfOptions::make([
            'autoLangToFont' => false,
            'autoScriptToLang' => false,
            'format' => 'A4',
            'orientation' => $columnCount > 5 ? 'L' : 'P',
            'margin_bottom' => 16,
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 8,
        ]));
        $pdf->autoLangToFont = false;
        $pdf->autoScriptToLang = false;
        $pdf->useSubstitutions = true;
        $pdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');
        $pdf->WriteHTML(view('exports.report-designer-pdf', [
            'definition' => $reportDefinition,
            'result' => $result,
            'source' => app(ReportDesignerCatalog::class)->sources($request->user())[$reportDefinition->data_source],
            'organisationName' => AppSetting::groupValues('general')->get('school_name') ?: config('app.name'),
            'logo' => app(PdfBrandingService::class)->logoSource(),
        ])->render());

        return response($pdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Disposition' => ExportFilename::inlinePdf(
                [$reportDefinition->name, now()->format('Y-m-d')],
                'report-'.$reportDefinition->id.'.pdf',
            ),
            'Content-Type' => 'application/pdf',
        ]);
    }

    protected function exportResult(Request $request, ReportDefinition $definition): array
    {
        $user = $request->user();
        abort_unless(app(ReportDefinitionAccess::class)->canView($user, $definition), 404);

        $result = app(ReportDesignerQueryService::class)->export([
            'data_source' => $definition->data_source,
            'selected_fields' => $definition->selected_fields,
            'calculations' => $definition->calculations ?? [],
            'group_by' => $definition->group_by,
            'filters' => $definition->filters ?? [],
            'sort_field' => $definition->sort_field,
            'sort_direction' => $definition->sort_direction,
        ], $user);

        abort_if(
            $result['total'] > ReportDesignerQueryService::EXPORT_LIMIT,
            422,
            __('report_designer.exports.too_many_rows', ['count' => ReportDesignerQueryService::EXPORT_LIMIT]),
        );

        return $result;
    }
}
