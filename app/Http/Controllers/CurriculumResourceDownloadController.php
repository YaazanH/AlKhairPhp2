<?php

namespace App\Http\Controllers;

use App\Models\CurriculumResource;
use App\Services\CurriculumAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CurriculumResourceDownloadController extends Controller
{
    public function __invoke(Request $request, CurriculumResource $resource)
    {
        abort_unless($resource->pdf_path && Storage::disk('local')->exists($resource->pdf_path), 404);

        $curriculumIds = $resource->curriculumSubjects()->pluck('curriculum_id')
            ->merge($resource->curricula()->pluck('curricula.id'))->unique();
        $access = app(CurriculumAccessService::class);
        $allowed = $access->canManage($request->user()) || $access->groupsQuery($request->user())
            ->whereIn('curriculum_id', $curriculumIds)->exists();
        abort_unless($allowed, 403);

        return Storage::disk('local')->download($resource->pdf_path, $resource->book_name.'.pdf');
    }
}
