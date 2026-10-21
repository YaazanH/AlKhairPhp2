<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Group;
use App\Services\AccessScopeService;
use App\Services\PdfBrandingService;
use App\Support\ArabicKashidaJustifier;
use App\Support\ArchivePdfWatermark;
use App\Support\ExportFilename;
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

        $archiveCourseId = $request->integer('archive_course_id') ?: null;
        $archiveCourse = null;
        if ($archiveCourseId !== null) {
            $archiveCourse = Course::query()->findOrFail($archiveCourseId);
            abort_unless(
                Group::query()->whereIn('id', $groupIds)->where('course_id', $archiveCourseId)->exists(),
                404,
            );
        }

        $selectedGroupId = $request->integer('group_id');
        $sortStudentsByMark = $selectedGroupId === 0;
        if ($selectedGroupId !== 0) {
            abort_unless($groupIds->contains($selectedGroupId), 404);
            $groupIds = collect([$selectedGroupId]);
        }

        $groups = $scopes->scopeGroups(Group::query()->whereIn('id', $groupIds), $request->user())
            ->with(['enrollments' => fn ($query) => $query
                ->whereIn('status', $archiveCourseId !== null ? ['active', 'completed'] : ['active'])
                ->with([
                    'student',
                    'assessmentResults' => fn ($results) => $results->where('assessment_id', $assessment->id),
                ])])
            ->orderBy('name')
            ->get()
            ->map(function (Group $group) use ($sortStudentsByMark) {
                $group->setRelation('enrollments', $group->enrollments
                    ->sort(function ($first, $second) use ($sortStudentsByMark) {
                        $firstScore = (float) ($first->assessmentResults->first()?->score ?? 0);
                        $secondScore = (float) ($second->assessmentResults->first()?->score ?? 0);

                        if ($sortStudentsByMark && ($scoreComparison = $secondScore <=> $firstScore) !== 0) {
                            return $scoreComparison;
                        }

                        return strnatcasecmp(
                            mb_strtolower($first->student?->full_name ?? ''),
                            mb_strtolower($second->student?->full_name ?? ''),
                        );
                    })
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

        $assessmentMetaLabels = [
            'date' => __('workflow.assessments.results.pdf.due_date'),
            'average' => __('workflow.assessments.results.pdf.average_mark'),
        ];
        $assessmentStatusLabels = [];

        if (app()->isLocale('ar')) {
            $mpdf->SetFont('dubaimedium', '', 9);
            $assessmentMetaLabels = ArabicKashidaJustifier::column(
                $assessmentMetaLabels,
                fn (string $value): float => $mpdf->GetStringWidth($value),
            );

            $statusKeys = $groups
                ->flatMap(fn (Group $group) => $group->enrollments->map(
                    fn ($enrollment): string => $enrollment->assessmentResults->first()?->status ?? 'absent',
                ))
                ->unique()
                ->values();
            $statusLabels = $statusKeys->mapWithKeys(
                fn (string $status): array => [$status => __('workflow.common.result_status.'.$status)],
            )->all();
            $mpdf->SetFont('dubai', '', 11);
            $assessmentStatusLabels = ArabicKashidaJustifier::column(
                $statusLabels,
                fn (string $value): float => $mpdf->GetStringWidth($value),
            );
        }

        ArchivePdfWatermark::apply($mpdf, $archiveCourse);
        $mpdf->WriteHTML(view('exports.assessment-results-pdf', [
            'assessment' => $assessment,
            'assessmentMetaLabels' => $assessmentMetaLabels,
            'assessmentStatusLabels' => $assessmentStatusLabels,
            'archiveCourse' => $archiveCourse,
            'groups' => $groups,
            'isSingleGroupPdf' => $selectedGroupId !== 0,
            'logo' => app(PdfBrandingService::class)->logoSource(),
        ])->render());

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Disposition' => ExportFilename::inlinePdf([
                __('exports.pdf.assessment_results'),
                $assessment->title,
                $groups->count() === 1 ? $groups->first()?->name : null,
                $assessment->scheduled_at?->format('d-m-Y'),
            ], 'assessment-results-'.$assessment->id.'.pdf'),
            'Content-Type' => 'application/pdf',
        ]);
    }
}
