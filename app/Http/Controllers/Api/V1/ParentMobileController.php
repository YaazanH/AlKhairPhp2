<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use App\Services\ActivityAudienceService;
use App\Services\FinanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ParentMobileController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $children = $this->childrenQuery($parent)->with(['gradeLevel', 'quranCurrentJuz'])->get();

        return response()->json([
            'data' => [
                'id' => $parent->id,
                'parent_number' => $parent->parent_number,
                'father_name' => $parent->father_name,
                'father_work' => $parent->father_work,
                'father_phone' => $parent->father_phone,
                'mother_name' => $parent->mother_name,
                'mother_phone' => $parent->mother_phone,
                'home_phone' => $parent->home_phone,
                'address' => $parent->address,
                'is_active' => (bool) $parent->is_active,
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'username' => $request->user()->username,
                    'email' => $request->user()->email,
                    'phone' => $request->user()->phone,
                ],
                'children_count' => $children->count(),
                'children' => $children->map(fn (Student $student): array => $this->studentSummary($student))->values(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $studentIds = $this->childrenQuery($parent)->pluck('id');

        $activeEnrollments = Enrollment::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', 'active')
            ->get();

        $invoiceTotals = Invoice::query()
            ->with(['payments' => fn ($query) => $query->whereNull('voided_at')])
            ->where('parent_id', $parent->id)
            ->get()
            ->reduce(fn (array $carry, Invoice $invoice): array => [
                'total' => $carry['total'] + (float) $invoice->total,
                'paid' => $carry['paid'] + $this->paidAmount($invoice),
            ], ['total' => 0.0, 'paid' => 0.0]);

        return response()->json([
            'data' => [
                'children' => $studentIds->count(),
                'active_enrollments' => $activeEnrollments->count(),
                'memorized_pages' => (int) $activeEnrollments->sum('memorized_pages_cached'),
                'points' => (int) $activeEnrollments->sum('final_points_cached'),
                'invoice_total' => round($invoiceTotals['total'], 2),
                'paid_total' => round($invoiceTotals['paid'], 2),
                'balance' => round($invoiceTotals['total'] - $invoiceTotals['paid'], 2),
                'unread_notes' => StudentNote::query()
                    ->whereIn('student_id', $studentIds)
                    ->where('visibility', 'visible_to_parent')
                    ->count(),
                'available_activity_responses' => $this->availableActivityCards($parent)->sum(
                    fn (array $card): int => count($card['eligible_students'])
                ),
            ],
        ]);
    }

    public function children(Request $request): JsonResponse
    {
        $parent = $this->parentProfile($request);

        $children = $this->childrenQuery($parent)
            ->with(['gradeLevel', 'quranCurrentJuz', 'enrollments.group.course', 'enrollments.group.teacher'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json([
            'data' => $children->map(fn (Student $student): array => $this->studentDetail($student))->values(),
        ]);
    }

    public function child(Request $request, Student $student): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $student = $this->ownedStudent($student, $parent)
            ->load(['gradeLevel', 'quranCurrentJuz', 'enrollments.group.course', 'enrollments.group.teacher']);

        return response()->json([
            'data' => $this->studentDetail($student),
        ]);
    }

    public function attendance(Request $request, Student $student): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $student = $this->ownedStudent($student, $parent);
        $filters = $this->dateFilters($request);

        $records = StudentAttendanceRecord::query()
            ->with(['attendanceDay.group.course', 'status', 'enrollment.group'])
            ->whereHas('enrollment', fn (Builder $query) => $query->where('student_id', $student->id))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attendanceDay', fn (Builder $dayQuery) => $dayQuery->whereDate('attendance_date', '>=', $date)))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereHas('attendanceDay', fn (Builder $dayQuery) => $dayQuery->whereDate('attendance_date', '<=', $date)))
            ->latest('id')
            ->paginate($filters['per_page']);

        return response()->json($this->paginated($records, fn (StudentAttendanceRecord $record): array => [
            'id' => $record->id,
            'date' => $this->date($record->attendanceDay?->attendance_date),
            'day_status' => $record->attendanceDay?->status,
            'status' => [
                'id' => $record->status?->id,
                'name' => $record->status?->name,
                'code' => $record->status?->code,
            ],
            'group' => $this->groupSummary($record->attendanceDay?->group ?? $record->enrollment?->group),
            'notes' => $record->notes,
        ]));
    }

    public function memorization(Request $request, Student $student): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $student = $this->ownedStudent($student, $parent);
        $filters = $this->dateFilters($request, [
            'entry_type' => ['nullable', Rule::in(['new', 'review', 'correction'])],
        ]);

        $sessions = MemorizationSession::query()
            ->with(['enrollment.group.course', 'teacher', 'pages'])
            ->where('student_id', $student->id)
            ->when($filters['entry_type'] ?? null, fn (Builder $query, string $type) => $query->where('entry_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('recorded_on', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('recorded_on', '<=', $date))
            ->orderByDesc('recorded_on')
            ->orderByDesc('id')
            ->paginate($filters['per_page']);

        return response()->json($this->paginated($sessions, fn (MemorizationSession $session): array => [
            'id' => $session->id,
            'date' => $this->date($session->recorded_on),
            'entry_type' => $session->entry_type,
            'from_page' => $session->from_page,
            'to_page' => $session->to_page,
            'pages_count' => $session->pages_count,
            'pages' => $session->pages->pluck('page_no')->values(),
            'group' => $this->groupSummary($session->enrollment?->group),
            'teacher' => $this->teacherSummary($session->teacher),
            'notes' => $session->notes,
        ]));
    }

    public function points(Request $request, Student $student): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $student = $this->ownedStudent($student, $parent);
        $filters = $this->dateFilters($request);

        $transactions = PointTransaction::query()
            ->with(['pointType', 'policy', 'enrollment.group.course', 'enteredBy'])
            ->notVoided()
            ->where('student_id', $student->id)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('entered_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('entered_at', '<=', $date))
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page']);

        return response()->json($this->paginated($transactions, fn (PointTransaction $transaction): array => [
            'id' => $transaction->id,
            'entered_at' => $this->datetime($transaction->entered_at),
            'points' => $transaction->points,
            'source_type' => $transaction->source_type,
            'point_type' => [
                'id' => $transaction->pointType?->id,
                'name' => $transaction->pointType?->name,
                'code' => $transaction->pointType?->code,
                'category' => $transaction->pointType?->category,
            ],
            'policy' => [
                'id' => $transaction->policy?->id,
                'name' => $transaction->policy?->name,
            ],
            'group' => $this->groupSummary($transaction->enrollment?->group),
            'notes' => $transaction->notes,
        ]));
    }

    public function assessments(Request $request, Student $student): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $student = $this->ownedStudent($student, $parent);
        $filters = $this->dateFilters($request);

        $results = AssessmentResult::query()
            ->with(['assessment.type', 'assessment.group.course', 'teacher', 'enrollment.group'])
            ->where('student_id', $student->id)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query
                ->where(function (Builder $inner) use ($date) {
                    $inner
                        ->whereHas('assessment', fn (Builder $assessment) => $assessment->whereDate('scheduled_at', '>=', $date))
                        ->orWhereDate('created_at', '>=', $date);
                }))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query
                ->where(function (Builder $inner) use ($date) {
                    $inner
                        ->whereHas('assessment', fn (Builder $assessment) => $assessment->whereDate('scheduled_at', '<=', $date))
                        ->orWhereDate('created_at', '<=', $date);
                }))
            ->latest('id')
            ->paginate($filters['per_page']);

        return response()->json($this->paginated($results, fn (AssessmentResult $result): array => [
            'id' => $result->id,
            'assessment' => [
                'id' => $result->assessment?->id,
                'title' => $result->assessment?->title,
                'type' => [
                    'id' => $result->assessment?->type?->id,
                    'name' => $result->assessment?->type?->name,
                    'code' => $result->assessment?->type?->code,
                ],
                'scheduled_at' => $this->datetime($result->assessment?->scheduled_at),
                'due_at' => $this->datetime($result->assessment?->due_at),
                'total_mark' => $this->decimal($result->assessment?->total_mark),
                'pass_mark' => $this->decimal($result->assessment?->pass_mark),
                'group' => $this->groupSummary($result->assessment?->group ?? $result->enrollment?->group),
            ],
            'score' => $this->decimal($result->score),
            'status' => $result->status,
            'attempt_no' => $result->attempt_no,
            'teacher' => $this->teacherSummary($result->teacher),
            'notes' => $result->notes,
        ]));
    }

    public function quranTests(Request $request, Student $student): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $student = $this->ownedStudent($student, $parent);
        $filters = $this->dateFilters($request);

        $items = collect()
            ->merge($this->legacyQuranTests($student))
            ->merge($this->partialQuranTests($student))
            ->merge($this->finalQuranTests($student))
            ->filter(fn (array $item): bool => $this->withinDateRange($item['date'] ?? null, $filters))
            ->sortByDesc(fn (array $item): string => (string) ($item['date'] ?? ''))
            ->values();

        return response()->json($this->paginatedCollection($items, $filters['per_page']));
    }

    public function notes(Request $request, Student $student): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $student = $this->ownedStudent($student, $parent);
        $filters = $this->dateFilters($request);

        $notes = StudentNote::query()
            ->with(['author', 'enrollment.group'])
            ->where('student_id', $student->id)
            ->where('visibility', 'visible_to_parent')
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('noted_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('noted_at', '<=', $date))
            ->orderByDesc('noted_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page']);

        return response()->json($this->paginated($notes, fn (StudentNote $note): array => [
            'id' => $note->id,
            'source' => $note->source,
            'noted_at' => $this->datetime($note->noted_at),
            'body' => $note->body,
            'group' => $this->groupSummary($note->enrollment?->group),
            'author' => [
                'id' => $note->author?->id,
                'name' => $note->author?->name,
            ],
        ]));
    }

    public function invoices(Request $request): JsonResponse
    {
        $parent = $this->parentProfile($request);
        $filters = $this->dateFilters($request, [
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $invoices = Invoice::query()
            ->with(['payments' => fn ($query) => $query->whereNull('voided_at')])
            ->where('parent_id', $parent->id)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issue_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issue_date', '<=', $date))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page']);

        return response()->json($this->paginated($invoices, fn (Invoice $invoice): array => $this->invoiceSummary($invoice)));
    }

    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        $parent = $this->parentProfile($request);

        abort_unless((int) $invoice->parent_id === (int) $parent->id, 404);

        $invoice->load([
            'items.activity',
            'items.enrollment.group.course',
            'items.student',
            'payments' => fn ($query) => $query->whereNull('voided_at')->with('paymentMethod')->orderByDesc('paid_at'),
        ]);

        return response()->json([
            'data' => $this->invoiceDetail($invoice),
        ]);
    }

    public function activities(Request $request): JsonResponse
    {
        $parent = $this->parentProfile($request);

        return response()->json([
            'data' => $this->availableActivityCards($parent)->values(),
        ]);
    }

    public function respondToActivity(Request $request, Activity $activity): JsonResponse
    {
        $parent = $this->parentProfile($request);

        abort_unless($request->user()->can('activities.responses.respond'), 403);
        abort_unless($activity->is_active, 404);

        $validated = $request->validate([
            'response' => ['required', Rule::in(['registered', 'declined'])],
            'student_id' => ['required', 'integer'],
        ]);

        $student = $this->ownedStudent(
            Student::query()->with('enrollments.group')->findOrFail($validated['student_id']),
            $parent,
        );

        $audience = app(ActivityAudienceService::class);
        $enrollment = $audience->resolveEnrollmentForStudent($activity->loadMissing('targetGroups'), $student);

        abort_unless($enrollment, 422, 'The selected student is not eligible for this activity.');

        $registration = ActivityRegistration::query()->firstOrNew([
            'activity_id' => $activity->id,
            'student_id' => $student->id,
        ]);

        abort_if(
            $validated['response'] === 'declined'
            && $registration->exists
            && $registration->payments()->whereNull('voided_at')->exists(),
            422,
            'This activity response cannot be declined after a payment is recorded.'
        );

        $registration->fill([
            'enrollment_id' => $enrollment->id,
            'fee_amount' => $registration->exists ? $registration->fee_amount : ($activity->fee_amount ?? 0),
            'status' => $validated['response'],
            'notes' => $registration->notes,
        ])->save();

        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->json([
            'data' => $this->activityRegistration($registration->fresh(['activity', 'student', 'enrollment.group', 'payments'])),
        ]);
    }

    protected function parentProfile(Request $request): ParentProfile
    {
        $parent = $request->user()?->parentProfile()->first();

        abort_unless($parent && $parent->is_active, 403, 'No active parent profile is linked to this account.');

        return $parent;
    }

    protected function childrenQuery(ParentProfile $parent): Builder
    {
        return Student::query()->where('parent_id', $parent->id);
    }

    protected function ownedStudent(Student $student, ParentProfile $parent): Student
    {
        abort_unless((int) $student->parent_id === (int) $parent->id, 404);

        return $student;
    }

    protected function dateFilters(Request $request, array $extraRules = []): array
    {
        return $request->validate(array_merge([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], $extraRules)) + ['per_page' => 25];
    }

    protected function studentSummary(Student $student): array
    {
        return [
            'id' => $student->id,
            'student_number' => $student->student_number,
            'full_name' => $student->full_name,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'status' => $student->status,
            'birth_date' => $this->date($student->birth_date),
            'gender' => $student->gender,
            'grade_level' => [
                'id' => $student->gradeLevel?->id,
                'name' => $student->gradeLevel?->name,
            ],
            'quran_current_juz' => [
                'id' => $student->quranCurrentJuz?->id,
                'juz_number' => $student->quranCurrentJuz?->juz_number,
            ],
        ];
    }

    protected function studentDetail(Student $student): array
    {
        $activeEnrollments = $student->enrollments
            ->where('status', 'active')
            ->sortByDesc(fn (Enrollment $enrollment): int => (($enrollment->enrolled_at?->getTimestamp() ?? 0) * 1000000) + $enrollment->id)
            ->values();

        return $this->studentSummary($student) + [
            'school_name' => $student->school_name,
            'joined_at' => $this->date($student->joined_at),
            'memorized_pages' => (int) $activeEnrollments->sum('memorized_pages_cached'),
            'points' => (int) $activeEnrollments->sum('final_points_cached'),
            'active_enrollments' => $activeEnrollments
                ->map(fn (Enrollment $enrollment): array => $this->enrollmentSummary($enrollment))
                ->values(),
        ];
    }

    protected function enrollmentSummary(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'status' => $enrollment->status,
            'enrolled_at' => $this->date($enrollment->enrolled_at),
            'left_at' => $this->date($enrollment->left_at),
            'memorized_pages' => $enrollment->memorized_pages_cached,
            'points' => $enrollment->final_points_cached,
            'group' => $this->groupSummary($enrollment->group),
        ];
    }

    protected function groupSummary(?Group $group): ?array
    {
        if (! $group) {
            return null;
        }

        return [
            'id' => $group->id,
            'name' => $group->name,
            'course' => [
                'id' => $group->course?->id,
                'name' => $group->course?->name,
            ],
            'teacher' => $this->teacherSummary($group->teacher),
        ];
    }

    protected function teacherSummary($teacher): ?array
    {
        if (! $teacher) {
            return null;
        }

        return [
            'id' => $teacher->id,
            'full_name' => trim($teacher->first_name.' '.$teacher->last_name),
            'phone' => $teacher->phone,
        ];
    }

    protected function invoiceSummary(Invoice $invoice): array
    {
        $paid = $this->paidAmount($invoice);

        return [
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'invoice_type' => $invoice->invoice_type,
            'issue_date' => $this->date($invoice->issue_date),
            'due_date' => $this->date($invoice->due_date),
            'status' => $invoice->status,
            'subtotal' => $this->decimal($invoice->subtotal),
            'discount' => $this->decimal($invoice->discount),
            'total' => $this->decimal($invoice->total),
            'paid' => round($paid, 2),
            'balance' => round((float) $invoice->total - $paid, 2),
            'notes' => $invoice->notes,
        ];
    }

    protected function invoiceDetail(Invoice $invoice): array
    {
        return $this->invoiceSummary($invoice) + [
            'items' => $invoice->items->map(fn ($item): array => [
                'id' => $item->id,
                'line_no' => $item->line_no,
                'item_name' => $item->item_name,
                'description' => $item->description,
                'quantity' => $this->decimal($item->quantity),
                'unit_price' => $this->decimal($item->unit_price),
                'amount' => $this->decimal($item->amount),
                'student' => $item->student ? $this->studentSummary($item->student) : null,
                'group' => $this->groupSummary($item->enrollment?->group),
                'activity' => $item->activity ? [
                    'id' => $item->activity->id,
                    'title' => $item->activity->title,
                    'activity_date' => $this->date($item->activity->activity_date),
                ] : null,
            ])->values(),
            'payments' => $invoice->payments->map(fn ($payment): array => [
                'id' => $payment->id,
                'paid_at' => $this->date($payment->paid_at),
                'amount' => $this->decimal($payment->amount),
                'reference_no' => $payment->reference_no,
                'payment_method' => [
                    'id' => $payment->paymentMethod?->id,
                    'name' => $payment->paymentMethod?->name,
                    'code' => $payment->paymentMethod?->code,
                ],
                'notes' => $payment->notes,
            ])->values(),
        ];
    }

    protected function paidAmount(Invoice $invoice): float
    {
        return (float) $invoice->payments->whereNull('voided_at')->sum(fn ($payment) => (float) $payment->amount);
    }

    protected function availableActivityCards(ParentProfile $parent): Collection
    {
        $audience = app(ActivityAudienceService::class);
        $students = $this->childrenQuery($parent)
            ->with(['enrollments.group.course'])
            ->where('status', 'active')
            ->get();

        $activities = Activity::query()
            ->with(['group.course', 'targetGroups.course'])
            ->where('is_active', true)
            ->orderBy('activity_date')
            ->orderBy('title')
            ->get();

        $registrations = ActivityRegistration::query()
            ->with(['payments' => fn ($query) => $query->whereNull('voided_at')])
            ->whereIn('activity_id', $activities->pluck('id'))
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy(fn (ActivityRegistration $registration): string => $registration->activity_id.'-'.$registration->student_id);

        return $activities
            ->map(function (Activity $activity) use ($audience, $registrations, $students): ?array {
                $eligibleStudents = $students
                    ->map(function (Student $student) use ($activity, $audience, $registrations): ?array {
                        $enrollment = $audience->resolveEnrollmentForStudent($activity, $student);

                        if (! $enrollment) {
                            return null;
                        }

                        $registration = $registrations->get($activity->id.'-'.$student->id);

                        return [
                            'student' => $this->studentSummary($student),
                            'enrollment' => $this->enrollmentSummary($enrollment->loadMissing('group.course')),
                            'registration' => $registration ? $this->activityRegistration($registration) : null,
                        ];
                    })
                    ->filter()
                    ->values();

                if ($eligibleStudents->isEmpty()) {
                    return null;
                }

                return [
                    'id' => $activity->id,
                    'title' => $activity->title,
                    'description' => $activity->description,
                    'activity_date' => $this->date($activity->activity_date),
                    'fee_amount' => $this->decimal($activity->fee_amount),
                    'audience_scope' => $activity->audience_scope,
                    'group' => $this->groupSummary($activity->group),
                    'target_groups' => $activity->targetGroups->map(fn (Group $group): array => $this->groupSummary($group))->values(),
                    'eligible_students' => $eligibleStudents,
                ];
            })
            ->filter()
            ->values();
    }

    protected function activityRegistration(ActivityRegistration $registration): array
    {
        $paid = $registration->payments->whereNull('voided_at')->sum(fn ($payment) => (float) $payment->amount);

        return [
            'id' => $registration->id,
            'status' => $registration->status,
            'fee_amount' => $this->decimal($registration->fee_amount),
            'paid' => round($paid, 2),
            'balance' => round((float) $registration->fee_amount - $paid, 2),
            'notes' => $registration->notes,
        ];
    }

    protected function legacyQuranTests(Student $student): Collection
    {
        return QuranTest::query()
            ->with(['juz', 'type', 'teacher', 'enrollment.group.course'])
            ->where('student_id', $student->id)
            ->get()
            ->map(fn (QuranTest $test): array => [
                'id' => $test->id,
                'kind' => 'legacy',
                'date' => $this->date($test->tested_on),
                'status' => $test->status,
                'score' => $this->decimal($test->score),
                'attempt_no' => $test->attempt_no,
                'juz' => $this->juzSummary($test->juz),
                'type' => [
                    'id' => $test->type?->id,
                    'name' => $test->type?->name,
                    'code' => $test->type?->code,
                ],
                'group' => $this->groupSummary($test->enrollment?->group),
                'teacher' => $this->teacherSummary($test->teacher),
                'notes' => $test->notes,
            ]);
    }

    protected function partialQuranTests(Student $student): Collection
    {
        return QuranPartialTest::query()
            ->with(['juz', 'enrollment.group.course', 'parts.attempts.teacher'])
            ->where('student_id', $student->id)
            ->get()
            ->map(function (QuranPartialTest $test): array {
                $attempts = $test->parts->flatMap->attempts;
                $latestAttempt = $attempts->sortByDesc('tested_on')->first();

                return [
                    'id' => $test->id,
                    'kind' => 'partial',
                    'date' => $this->date($test->passed_on ?? $latestAttempt?->tested_on ?? $test->created_at),
                    'status' => $test->status,
                    'juz' => $this->juzSummary($test->juz),
                    'group' => $this->groupSummary($test->enrollment?->group),
                    'parts' => $test->parts->map(fn ($part): array => [
                        'id' => $part->id,
                        'part_number' => $part->part_number,
                        'status' => $part->status,
                        'passed_on' => $this->date($part->passed_on),
                        'attempts' => $part->attempts->map(fn ($attempt): array => [
                            'id' => $attempt->id,
                            'tested_on' => $this->date($attempt->tested_on),
                            'score' => $this->decimal($attempt->score),
                            'status' => $attempt->status,
                            'attempt_no' => $attempt->attempt_no,
                            'teacher' => $this->teacherSummary($attempt->teacher),
                            'notes' => $attempt->notes,
                        ])->values(),
                    ])->values(),
                ];
            });
    }

    protected function finalQuranTests(Student $student): Collection
    {
        return QuranFinalTest::query()
            ->with(['juz', 'enrollment.group.course', 'attempts.teacher'])
            ->where('student_id', $student->id)
            ->get()
            ->map(function (QuranFinalTest $test): array {
                $latestAttempt = $test->attempts->sortByDesc('tested_on')->first();

                return [
                    'id' => $test->id,
                    'kind' => 'final',
                    'date' => $this->date($test->passed_on ?? $latestAttempt?->tested_on ?? $test->created_at),
                    'status' => $test->status,
                    'juz' => $this->juzSummary($test->juz),
                    'group' => $this->groupSummary($test->enrollment?->group),
                    'attempts' => $test->attempts->map(fn ($attempt): array => [
                        'id' => $attempt->id,
                        'tested_on' => $this->date($attempt->tested_on),
                        'score' => $this->decimal($attempt->score),
                        'status' => $attempt->status,
                        'attempt_no' => $attempt->attempt_no,
                        'teacher' => $this->teacherSummary($attempt->teacher),
                        'notes' => $attempt->notes,
                    ])->values(),
                ];
            });
    }

    protected function juzSummary($juz): ?array
    {
        if (! $juz) {
            return null;
        }

        return [
            'id' => $juz->id,
            'juz_number' => $juz->juz_number,
            'from_page' => $juz->from_page,
            'to_page' => $juz->to_page,
        ];
    }

    protected function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => $paginator->getCollection()->map($map)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    protected function paginatedCollection(Collection $items, int $perPage): array
    {
        $page = max((int) request('page', 1), 1);
        $total = $items->count();

        return [
            'data' => $items->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) max(ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    protected function withinDateRange(?string $date, array $filters): bool
    {
        if (! $date) {
            return true;
        }

        $value = Carbon::parse($date);

        if (($filters['date_from'] ?? null) && $value->lt(Carbon::parse($filters['date_from']))) {
            return false;
        }

        if (($filters['date_to'] ?? null) && $value->gt(Carbon::parse($filters['date_to']))) {
            return false;
        }

        return true;
    }

    protected function date($value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    protected function datetime($value): ?string
    {
        return $value ? Carbon::parse($value)->toISOString() : null;
    }

    protected function decimal($value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
