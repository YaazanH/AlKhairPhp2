<?php

namespace App\Http\Controllers;

use App\Services\Landlord\CurrentModuleAccess;
use App\Services\StudentRosterImportService;
use App\Services\XlsxExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentImportTemplateController extends Controller
{
    public function __invoke(
        CurrentModuleAccess $modules,
        StudentRosterImportService $importer,
        XlsxExportService $xlsx,
    ): StreamedResponse {
        $parentsEnabled = $modules->enabled('parents');

        return $xlsx->download(
            'student-import-template',
            $importer->templateHeaders($parentsEnabled),
            [$importer->templateExample($parentsEnabled)],
        );
    }
}
