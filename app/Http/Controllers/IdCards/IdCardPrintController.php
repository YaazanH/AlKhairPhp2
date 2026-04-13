<?php

namespace App\Http\Controllers\IdCards;

use App\Http\Controllers\Controller;
use App\Models\IdCardTemplate;
use App\Models\Student;
use App\Services\IdCards\IdCardPrintLayoutService;
use App\Services\IdCards\IdCardRenderService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IdCardPrintController extends Controller
{
    public function __construct(
        protected IdCardPrintLayoutService $printLayoutService,
        protected IdCardRenderService $renderService,
    ) {
    }

    public function create(): View
    {
        return view('id-cards.print.setup', [
            'templates' => IdCardTemplate::query()->where('is_active', true)->orderBy('name')->get(),
            'students' => Student::query()
                ->with(['gradeLevel', 'parentProfile'])
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'defaults' => $this->printLayoutService->defaults(),
        ]);
    }

    public function preview(Request $request): View
    {
        $validated = $request->validate([
            'template_id' => ['required', 'exists:id_card_templates,id'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'page_width_mm' => ['required', 'numeric', 'min:80', 'max:500'],
            'page_height_mm' => ['required', 'numeric', 'min:80', 'max:500'],
            'margin_top_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_right_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_bottom_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'margin_left_mm' => ['required', 'numeric', 'min:0', 'max:40'],
            'gap_x_mm' => ['required', 'numeric', 'min:0', 'max:30'],
            'gap_y_mm' => ['required', 'numeric', 'min:0', 'max:30'],
        ]);

        $template = IdCardTemplate::query()->findOrFail($validated['template_id']);
        $selectedIds = array_map('intval', $validated['student_ids']);
        $students = Student::query()
            ->with(['gradeLevel', 'parentProfile', 'quranCurrentJuz', 'enrollments.group'])
            ->whereIn('id', $selectedIds)
            ->get();
        $students = $students
            ->sortBy(fn (Student $student) => array_search($student->id, $selectedIds, true))
            ->values();

        $layout = $this->printLayoutService->paginate($template, $students, $validated);
        $pages = collect($layout['pages'])
            ->map(fn ($pageStudents) => collect($pageStudents)
                ->map(fn (Student $student) => $this->renderService->render($template, $student))
                ->all())
            ->all();

        return view('id-cards.print.preview', [
            'template' => $template,
            'students' => $students,
            'pages' => $pages,
            'layout' => $layout,
        ]);
    }
}
