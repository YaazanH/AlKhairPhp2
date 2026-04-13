<?php

namespace App\Http\Controllers\IdCards;

use App\Http\Controllers\Controller;
use App\Models\IdCardTemplate;
use App\Models\Student;
use App\Services\IdCards\IdCardRenderService;
use App\Services\IdCards\IdCardTemplateLayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class IdCardTemplateController extends Controller
{
    public function __construct(
        protected IdCardTemplateLayoutService $layoutService,
        protected IdCardRenderService $renderService,
    ) {
    }

    public function index(): View
    {
        return view('id-cards.templates.index', [
            'templates' => IdCardTemplate::query()->latest()->get(),
        ]);
    }

    public function create(): View
    {
        $template = new IdCardTemplate([
            'name' => __('id_cards.templates.defaults.name'),
            'width_mm' => 85.6,
            'height_mm' => 53.98,
            'layout_json' => $this->defaultLayout(),
            'is_active' => true,
        ]);

        return view('id-cards.templates.form', $this->formPayload($template));
    }

    public function store(Request $request): RedirectResponse
    {
        $template = new IdCardTemplate();
        $template->fill($this->validatedPayload($request, $template));
        $template->save();

        return redirect()
            ->route('id-cards.templates.edit', $template)
            ->with('status', __('id_cards.templates.messages.created'));
    }

    public function edit(IdCardTemplate $template): View
    {
        return view('id-cards.templates.form', $this->formPayload($template));
    }

    public function update(Request $request, IdCardTemplate $template): RedirectResponse
    {
        $template->fill($this->validatedPayload($request, $template));
        $template->save();

        return redirect()
            ->route('id-cards.templates.edit', $template)
            ->with('status', __('id_cards.templates.messages.updated'));
    }

    public function destroy(IdCardTemplate $template): RedirectResponse
    {
        if ($template->background_image) {
            Storage::disk('public')->delete($template->background_image);
        }

        $template->delete();

        return redirect()
            ->route('id-cards.templates.index')
            ->with('status', __('id_cards.templates.messages.deleted'));
    }

    protected function formPayload(IdCardTemplate $template): array
    {
        $sampleStudents = Student::query()
            ->with(['gradeLevel', 'parentProfile', 'quranCurrentJuz', 'enrollments.group'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->take(24)
            ->get();
        $sampleStudent = $sampleStudents->first();

        return [
            'template' => $template,
            'fieldOptions' => $this->renderService->fieldOptions(),
            'sampleStudents' => $sampleStudents,
            'sampleStudentId' => $sampleStudent?->id,
            'samplePayloads' => $sampleStudents
                ->mapWithKeys(fn (Student $student) => [(string) $student->id => $this->renderService->samplePayload($student)])
                ->all(),
            'layoutJson' => json_encode($template->layout_json ?: $this->defaultLayout(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];
    }

    protected function validatedPayload(Request $request, IdCardTemplate $template): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'width_mm' => ['required', 'numeric', 'min:35', 'max:160'],
            'height_mm' => ['required', 'numeric', 'min:20', 'max:120'],
            'layout_json' => ['nullable', 'string'],
            'background_image' => ['nullable', 'image', 'max:4096'],
            'remove_background_image' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $layout = $this->layoutService->normalize(
            $this->layoutService->decode($validated['layout_json'] ?? '[]'),
            app(\App\Services\IdCards\StudentCardFieldRegistry::class),
        );

        if (($validated['remove_background_image'] ?? false) && $template->background_image) {
            Storage::disk('public')->delete($template->background_image);
            $template->background_image = null;
        }

        if ($request->hasFile('background_image')) {
            if ($template->background_image) {
                Storage::disk('public')->delete($template->background_image);
            }

            $template->background_image = $request->file('background_image')->store('id-cards/backgrounds', 'public');
        }

        return [
            'name' => $validated['name'],
            'width_mm' => (float) $validated['width_mm'],
            'height_mm' => (float) $validated['height_mm'],
            'background_image' => $template->background_image,
            'layout_json' => $layout,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];
    }

    protected function defaultLayout(): array
    {
        return [
            [
                'id' => 'photo',
                'type' => 'image',
                'field' => 'photo',
                'x' => 5,
                'y' => 6,
                'width' => 20,
                'height' => 24,
                'z_index' => 1,
                'styling' => [
                    'border_radius' => 3,
                    'object_fit' => 'cover',
                ],
            ],
            [
                'id' => 'full-name',
                'type' => 'text',
                'field' => 'full_name',
                'x' => 28,
                'y' => 8,
                'width' => 50,
                'height' => 8,
                'z_index' => 2,
                'styling' => [
                    'font_size' => 5.2,
                    'font_weight' => '700',
                    'color' => '#ffffff',
                    'text_align' => 'left',
                ],
            ],
            [
                'id' => 'student-number',
                'type' => 'text',
                'field' => 'student_number',
                'x' => 28,
                'y' => 18,
                'width' => 42,
                'height' => 6,
                'z_index' => 3,
                'styling' => [
                    'font_size' => 3.4,
                    'font_weight' => '600',
                    'color' => '#dff8e7',
                    'text_align' => 'left',
                ],
            ],
            [
                'id' => 'barcode',
                'type' => 'barcode',
                'field' => 'student_number',
                'x' => 9,
                'y' => 35,
                'width' => 68,
                'height' => 14,
                'z_index' => 4,
                'styling' => [
                    'font_size' => 2.8,
                    'show_text' => true,
                    'color' => '#0f2414',
                ],
            ],
        ];
    }
}
