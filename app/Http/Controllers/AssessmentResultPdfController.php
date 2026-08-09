<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Group;
use App\Services\AccessScopeService;
use App\Support\PdfOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class AssessmentResultPdfController extends Controller
{
    public function __invoke(Request $request, Assessment $assessment, AccessScopeService $scopes): Response
    {
        abort_unless($request->user()?->can('assessment-results.view'), 403);
        abort_unless($scopes->canAccessAssessment($request->user(), $assessment), 403);

        $groupIds = $assessment->groups()->pluck('groups.id');
        if ($assessment->group_id !== null && ! $groupIds->contains($assessment->group_id)) {
            $groupIds->push($assessment->group_id);
        }

        $groups = $scopes->scopeGroups(Group::query()->whereIn('id', $groupIds), $request->user())
            ->with(['enrollments' => fn ($query) => $query
                ->where('status', 'active')
                ->with([
                    'student',
                    'assessmentResults' => fn ($results) => $results->where('assessment_id', $assessment->id),
                ])])
            ->orderBy('name')
            ->get()
            ->map(function (Group $group) {
                $group->setRelation('enrollments', $group->enrollments
                    ->sortBy(fn ($enrollment) => mb_strtolower($enrollment->student?->full_name ?? ''))
                    ->values());

                $group->setAttribute('average_mark', $group->enrollments
                    ->map(fn ($enrollment) => ($result = $enrollment->assessmentResults->first()) && $result->status !== 'absent' ? $result->score : null)
                    ->filter(fn ($score) => $score !== null)
                    ->avg());

                return $group;
            });

        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf(PdfOptions::make([
            'autoLangToFont' => false,
            'autoScriptToLang' => false,
            'format' => 'A4',
            'orientation' => 'P',
            'margin_bottom' => 12,
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 12,
        ]));
        $mpdf->autoLangToFont = false;
        $mpdf->autoScriptToLang = false;
        $mpdf->useSubstitutions = true;
        $mpdf->SetDirectionality(app()->isLocale('ar') ? 'rtl' : 'ltr');
        $mpdf->WriteHTML(view('exports.assessment-results-pdf', [
            'assessment' => $assessment,
            'groups' => $groups,
        ])->render());

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Disposition' => 'inline; filename="assessment-results-'.$assessment->id.'.pdf"',
            'Content-Type' => 'application/pdf',
        ]);
    }
}
