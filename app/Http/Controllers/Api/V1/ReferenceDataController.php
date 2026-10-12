<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AssessmentScoreBand;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\ExpenseCategory;
use App\Models\FinanceCurrency;
use App\Models\GradeLevel;
use App\Models\PaymentMethod;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTestType;
use App\Models\School;
use App\Models\TeacherJobTitle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferenceDataController extends Controller
{
    public function academicYears(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['courses.view', 'groups.view', 'enrollments.view', 'assessments.view', 'reports.view']);
        $filters = $this->filters($request);
        $years = AcademicYear::query();
        $this->applyActiveFilter($years, $filters);
        $this->applySearch($years, $filters, ['name']);

        return $this->respond($years->orderByDesc('is_current')->orderByDesc('starts_on')->orderByDesc('id')->get()
            ->map(fn (AcademicYear $year): array => [
                'id' => $year->id,
                'name' => $year->name,
                'starts_on' => $year->starts_on?->toDateString(),
                'ends_on' => $year->ends_on?->toDateString(),
                'is_current' => (bool) $year->is_current,
                'is_active' => (bool) $year->is_active,
            ])->all());
    }

    public function gradeLevels(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['students.view', 'students.create', 'students.update', 'groups.view', 'groups.create', 'groups.update']);
        $filters = $this->filters($request);
        $levels = GradeLevel::query();
        $this->applyActiveFilter($levels, $filters);
        $this->applySearch($levels, $filters, ['name']);

        return $this->respond($levels->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (GradeLevel $level): array => [
                'id' => $level->id,
                'name' => $level->name,
                'sort_order' => $level->sort_order,
                'is_active' => (bool) $level->is_active,
            ])->all());
    }

    public function attendanceStatuses(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['attendance.student.view', 'attendance.student.take', 'attendance.teacher.view', 'attendance.teacher.take']);
        $filters = $this->filters($request, [
            'scope' => ['nullable', Rule::in(['student', 'teacher', 'both'])],
        ]);
        $statuses = AttendanceStatus::query();
        $this->applyActiveFilter($statuses, $filters);
        $this->applySearch($statuses, $filters, ['name', 'code']);
        $statuses->when($filters['scope'] ?? null, fn (Builder $query, string $scope) => $query
            ->whereIn('scope', $scope === 'both' ? ['student', 'teacher', 'both'] : [$scope, 'both']));

        return $this->respond($statuses->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (AttendanceStatus $status): array => [
                'id' => $status->id,
                'name' => $status->name,
                'code' => $status->code,
                'scope' => $status->scope,
                'default_points' => $status->default_points,
                'color' => $status->color,
                'is_present' => (bool) $status->is_present,
                'is_default' => (bool) $status->is_default,
                'is_active' => (bool) $status->is_active,
            ])->all());
    }

    public function assessmentTypes(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['assessments.view', 'assessments.create', 'assessments.update', 'assessment-results.view', 'assessment-results.record']);
        $filters = $this->filters($request);
        $types = AssessmentType::query();
        $this->applyActiveFilter($types, $filters);
        $this->applySearch($types, $filters, ['name', 'code']);

        return $this->respond($types->orderBy('name')->get()
            ->map(fn (AssessmentType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'is_scored' => (bool) $type->is_scored,
                'is_active' => (bool) $type->is_active,
            ])->all());
    }

    public function assessmentScoreBands(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['assessments.view', 'assessment-results.view', 'assessment-results.record', 'assessment-score-bands.view']);
        $filters = $this->filters($request, [
            'assessment_type_id' => ['nullable', 'integer', 'exists:assessment_types,id'],
        ]);
        $bands = AssessmentScoreBand::query()->with(['assessmentType:id,name,code', 'pointType:id,name,code']);
        $this->applyActiveFilter($bands, $filters);
        $this->applySearch($bands, $filters, ['name']);
        $bands->when($filters['assessment_type_id'] ?? null, fn (Builder $query, $typeId) => $query->where('assessment_type_id', $typeId));

        return $this->respond($bands->orderBy('assessment_type_id')->orderBy('from_mark')->orderBy('id')->get()
            ->map(fn (AssessmentScoreBand $band): array => [
                'id' => $band->id,
                'name' => $band->name,
                'from_mark' => $band->from_mark,
                'to_mark' => $band->to_mark,
                'points' => $band->points,
                'is_fail' => (bool) $band->is_fail,
                'is_active' => (bool) $band->is_active,
                'assessment_type' => $band->assessmentType ? [
                    'id' => $band->assessmentType->id,
                    'name' => $band->assessmentType->name,
                    'code' => $band->assessmentType->code,
                ] : null,
                'point_type' => $band->pointType ? [
                    'id' => $band->pointType->id,
                    'name' => $band->pointType->name,
                    'code' => $band->pointType->code,
                ] : null,
            ])->all());
    }

    public function quranJuz(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['memorization.view', 'memorization.record', 'quran-tests.view', 'quran-tests.record', 'quran-awqaf-tests.view', 'quran-awqaf-tests.record']);
        $filters = $this->filters($request);
        $query = QuranJuz::query();
        $query->when($filters['q'] ?? null, fn (Builder $builder, string $search) => $builder->where('juz_number', (int) $search));

        return $this->respond($query->orderBy('juz_number')->get()
            ->map(fn (QuranJuz $juz): array => [
                'id' => $juz->id,
                'name' => __('Juz :number', ['number' => $juz->juz_number]),
                'juz_number' => $juz->juz_number,
                'from_page' => $juz->from_page,
                'to_page' => $juz->to_page,
            ])->all());
    }

    public function quranTestTypes(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['quran-tests.view', 'quran-tests.record', 'quran-awqaf-tests.view', 'quran-awqaf-tests.record']);
        $filters = $this->filters($request);
        $types = QuranTestType::query();
        $this->applyActiveFilter($types, $filters);
        $this->applySearch($types, $filters, ['name', 'code']);

        return $this->respond($types->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (QuranTestType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'sort_order' => $type->sort_order,
                'is_active' => (bool) $type->is_active,
            ])->all());
    }

    public function pointTypes(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['points.view', 'points.create-manual', 'assessment-results.record']);
        $filters = $this->filters($request, ['manual_only' => ['nullable', 'boolean']]);
        $types = PointType::query();
        $this->applyActiveFilter($types, $filters);
        $this->applySearch($types, $filters, ['name', 'code', 'category']);
        $types->when($filters['manual_only'] ?? false, fn (Builder $query) => $query->where('allow_manual_entry', true));

        return $this->respond($types->orderBy('category')->orderBy('name')->get()
            ->map(fn (PointType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'category' => $type->category,
                'default_points' => $type->default_points,
                'allow_manual_entry' => (bool) $type->allow_manual_entry,
                'allow_negative' => (bool) $type->allow_negative,
                'is_active' => (bool) $type->is_active,
            ])->all());
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['payments.view', 'payments.create', 'activities.payments.manage', 'invoices.view']);
        $filters = $this->filters($request);
        $methods = PaymentMethod::query();
        $this->applyActiveFilter($methods, $filters);
        $this->applySearch($methods, $filters, ['name', 'code']);

        return $this->respond($methods->orderBy('name')->get()
            ->map(fn (PaymentMethod $method): array => [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code,
                'is_active' => (bool) $method->is_active,
            ])->all());
    }

    public function expenseCategories(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['activities.finance.view', 'activities.expenses.manage', 'finance.categories.manage', 'finance.expense-requests.view']);
        $filters = $this->filters($request);
        $categories = ExpenseCategory::query();
        $this->applyActiveFilter($categories, $filters);
        $this->applySearch($categories, $filters, ['name', 'code']);

        return $this->respond($categories->orderBy('name')->get()
            ->map(fn (ExpenseCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'code' => $category->code,
                'is_active' => (bool) $category->is_active,
            ])->all());
    }

    public function currencies(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['invoices.view', 'payments.view', 'finance.currencies.manage', 'finance.exchange.view', 'finance.reports.view']);
        $filters = $this->filters($request, ['include_hidden' => ['nullable', 'boolean']]);
        $currencies = FinanceCurrency::query();
        $this->applyActiveFilter($currencies, $filters);
        $this->applySearch($currencies, $filters, ['name', 'code', 'symbol']);
        $currencies->when(! ($filters['include_hidden'] ?? false), fn (Builder $query) => $query->where('show_in_dropdowns', true));

        return $this->respond($currencies->orderByDesc('is_base')->orderByDesc('is_local')->orderBy('code')->get()
            ->map(fn (FinanceCurrency $currency): array => [
                'id' => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => $currency->decimal_places,
                'rate_to_base' => $currency->rate_to_base,
                'is_base' => (bool) $currency->is_base,
                'is_local' => (bool) $currency->is_local,
                'show_in_dropdowns' => (bool) $currency->show_in_dropdowns,
                'is_active' => (bool) $currency->is_active,
            ])->all());
    }

    public function teacherJobTitles(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['teachers.view', 'teachers.create', 'teachers.update', 'groups.view']);
        $filters = $this->filters($request);
        $titles = TeacherJobTitle::query();
        $this->applyActiveFilter($titles, $filters);
        $this->applySearch($titles, $filters, ['name']);

        return $this->respond($titles->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (TeacherJobTitle $title): array => [
                'id' => $title->id,
                'name' => $title->name,
                'sort_order' => $title->sort_order,
                'is_active' => (bool) $title->is_active,
            ])->all());
    }

    public function schools(Request $request): JsonResponse
    {
        $this->authorizeAny($request, ['students.view', 'students.create', 'students.update']);
        $filters = $this->filters($request);
        $schools = School::query();
        $this->applyActiveFilter($schools, $filters);
        $this->applySearch($schools, $filters, ['name']);

        return $this->respond($schools->orderBy('name')->get()
            ->map(fn (School $school): array => [
                'id' => $school->id,
                'name' => $school->name,
                'is_active' => (bool) $school->is_active,
            ])->all());
    }

    private function filters(Request $request, array $extraRules = []): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'include_inactive' => ['nullable', 'boolean'],
            ...$extraRules,
        ]);
    }

    private function applyActiveFilter(Builder $query, array $filters): void
    {
        if (! ($filters['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }
    }

    private function applySearch(Builder $query, array $filters, array $columns): void
    {
        $search = trim((string) ($filters['q'] ?? ''));

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($columns, $search): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', '%'.$search.'%');
            }
        });
    }

    private function authorizeAny(Request $request, array $permissions): void
    {
        abort_unless(collect($permissions)->contains(
            fn (string $permission): bool => $request->user()->can($permission)
        ), 403);
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}
