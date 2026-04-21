<?php

namespace App\Services;

use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\AssessmentResult;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MemorizationSession;
use App\Models\Payment;
use App\Models\PointTransaction;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ReportingService
{
    public function assessmentRows(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->scopedAssessmentResultsQuery($filters)
            ->with(['assessment.group.academicYear', 'assessment.group.course', 'assessment.type', 'enrollment.student', 'teacher'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (AssessmentResult $result) => [
                $result->assessment?->scheduled_at?->format('Y-m-d H:i'),
                $result->assessment?->group?->academicYear?->name,
                $result->assessment?->group?->name,
                $result->assessment?->group?->course?->name,
                $result->assessment?->title,
                $result->assessment?->type?->name,
                trim(($result->enrollment?->student?->first_name ?? '').' '.($result->enrollment?->student?->last_name ?? '')),
                $result->score !== null ? (float) $result->score : null,
                $result->status,
                $result->attempt_no,
                trim(($result->teacher?->first_name ?? '').' '.($result->teacher?->last_name ?? '')),
                $result->notes,
            ])
            ->all();
    }

    public function attendanceRows(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->scopedStudentAttendanceRecordsQuery($filters)
            ->with(['attendanceDay.group.academicYear', 'attendanceDay.group.course', 'enrollment.student', 'status'])
            ->orderByDesc(
                GroupAttendanceDay::query()
                    ->select('attendance_date')
                    ->whereColumn('group_attendance_days.id', 'student_attendance_records.group_attendance_day_id')
                    ->limit(1),
            )
            ->orderByDesc('student_attendance_records.id')
            ->get()
            ->map(fn (\App\Models\StudentAttendanceRecord $record) => [
                $record->attendanceDay?->attendance_date?->format('Y-m-d'),
                $record->attendanceDay?->group?->academicYear?->name,
                $record->attendanceDay?->group?->name,
                $record->attendanceDay?->group?->course?->name,
                trim(($record->enrollment?->student?->first_name ?? '').' '.($record->enrollment?->student?->last_name ?? '')),
                $record->status?->name,
                $record->status?->code,
                $record->notes,
            ])
            ->all();
    }

    public function overview(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return Cache::remember(
            $this->overviewCacheKey($filters),
            now()->addSeconds((int) config('performance.report_cache_ttl_seconds', 30)),
            fn () => [
                'filters' => $filters,
                'headline' => $this->headline($filters),
                'attendance' => $this->attendance($filters),
                'assessments' => $this->assessments($filters),
                'memorization_leaderboard' => $this->memorizationLeaderboard($filters),
                'points_leaderboard' => $this->pointsLeaderboard($filters),
                'finance' => $this->finance($filters),
                'outstanding_invoices' => $this->outstandingInvoices($filters),
            ],
        );
    }

    public function memorizationRows(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->scopedMemorizationSessionsQuery($filters)
            ->with(['enrollment.group.academicYear', 'enrollment.group.course', 'student', 'teacher'])
            ->orderByDesc('recorded_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MemorizationSession $session) => [
                $session->recorded_on?->format('Y-m-d'),
                $session->enrollment?->group?->academicYear?->name,
                $session->enrollment?->group?->name,
                $session->enrollment?->group?->course?->name,
                trim(($session->student?->first_name ?? '').' '.($session->student?->last_name ?? '')),
                trim(($session->teacher?->first_name ?? '').' '.($session->teacher?->last_name ?? '')),
                $session->entry_type,
                $session->from_page,
                $session->to_page,
                $session->pages_count,
                $session->notes,
            ])
            ->all();
    }

    public function pointRows(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->scopedPointTransactionsQuery($filters)
            ->with(['enrollment.group.academicYear', 'enrollment.group.course', 'pointType', 'policy', 'student'])
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PointTransaction $transaction) => [
                $transaction->entered_at?->format('Y-m-d H:i'),
                $transaction->enrollment?->group?->academicYear?->name,
                $transaction->enrollment?->group?->name,
                $transaction->enrollment?->group?->course?->name,
                trim(($transaction->student?->first_name ?? '').' '.($transaction->student?->last_name ?? '')),
                $transaction->pointType?->name,
                $transaction->policy?->name,
                $transaction->source_type,
                $transaction->points,
                $transaction->notes,
            ])
            ->all();
    }

    protected function assessments(array $filters): array
    {
        $resultsQuery = $this->scopedAssessmentResultsQuery($filters);

        return [
            'results_recorded' => (clone $resultsQuery)->count(),
            'passed' => (clone $resultsQuery)->where('status', 'passed')->count(),
            'failed' => (clone $resultsQuery)->where('status', 'failed')->count(),
            'average_score' => $this->decimal((clone $resultsQuery)->avg('score')),
        ];
    }

    protected function attendance(array $filters): array
    {
        $statusCounts = $this->scopedStudentAttendanceRecordsQuery($filters)
            ->selectRaw('attendance_status_id, COUNT(*) as total')
            ->groupBy('attendance_status_id')
            ->pluck('total', 'attendance_status_id');

        $statuses = AttendanceStatus::query()
            ->whereIn('scope', ['student', 'both'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return [
            'days_recorded' => $this->scopedAttendanceDaysQuery($filters)->count(),
            'breakdown' => $statuses
                ->map(fn (AttendanceStatus $status) => [
                    'code' => $status->code,
                    'count' => (int) ($statusCounts[$status->id] ?? 0),
                    'name' => $status->name,
                ])
                ->values()
                ->all(),
        ];
    }

    protected function finance(array $filters): array
    {
        $invoiceBilled = $this->decimal($this->scopedInvoiceItemsQuery($filters)->sum('amount'));
        $invoiceCollected = $this->decimal($this->scopedInvoicePaymentsQuery($filters)->sum('amount'));
        $activityExpected = $this->decimal($this->scopedActivityRegistrationsQuery($filters)->sum('fee_amount'));
        $activityCollected = $this->decimal($this->scopedActivityPaymentsQuery($filters)->sum('amount'));
        $activityExpenses = $this->decimal($this->scopedActivityExpensesQuery($filters)->sum('amount'));

        return [
            'activity_collected' => $activityCollected,
            'activity_expenses' => $activityExpenses,
            'activity_expected' => $activityExpected,
            'activity_net' => $this->decimal($activityCollected - $activityExpenses),
            'invoice_billed' => $invoiceBilled,
            'invoice_collected' => $invoiceCollected,
        ];
    }

    protected function headline(array $filters): array
    {
        return [
            'active_enrollments' => $this->scopedEnrollmentsQuery($filters)->where('status', 'active')->count(),
            'cash_collected' => $this->decimal(
                $this->scopedInvoicePaymentsQuery($filters)->sum('amount')
                + $this->scopedActivityPaymentsQuery($filters)->sum('amount')
            ),
            'invoiced_amount' => $this->decimal($this->scopedInvoiceItemsQuery($filters)->sum('amount')),
            'memorized_pages' => (int) $this->scopedMemorizationSessionsQuery($filters)->sum('pages_count'),
            'net_points' => (int) $this->scopedPointTransactionsQuery($filters)->sum('points'),
            'students_in_scope' => $this->scopedStudentsQuery($filters)->count(),
        ];
    }

    protected function memorizationLeaderboard(array $filters): array
    {
        $rows = $this->scopedMemorizationSessionsQuery($filters)
            ->selectRaw('student_id, SUM(pages_count) as total_pages, COUNT(*) as sessions_count')
            ->groupBy('student_id')
            ->orderByDesc('total_pages')
            ->orderByDesc('sessions_count')
            ->limit(5)
            ->get();

        $students = Student::query()
            ->whereIn('id', $rows->pluck('student_id'))
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($students) {
            $student = $students->get($row->student_id);

            return [
                'pages' => (int) $row->total_pages,
                'sessions' => (int) $row->sessions_count,
                'student_id' => (int) $row->student_id,
                'student_name' => trim(($student?->first_name ?? '').' '.($student?->last_name ?? '')),
            ];
        })->values()->all();
    }

    protected function normalizeFilters(array $filters): array
    {
        return [
            'academic_year_id' => $this->normalizeNullableInteger($filters['academic_year_id'] ?? null),
            'assessment_type_id' => $this->normalizeNullableInteger($filters['assessment_type_id'] ?? null),
            'date_from' => $this->normalizeNullableString($filters['date_from'] ?? null),
            'date_to' => $this->normalizeNullableString($filters['date_to'] ?? null),
            'group_id' => $this->normalizeNullableInteger($filters['group_id'] ?? null),
        ];
    }

    protected function outstandingInvoices(array $filters): array
    {
        $invoices = $this->scopedInvoicesQuery($filters)
            ->with('parentProfile')
            ->withSum(['payments as paid_total' => fn (Builder $query) => $query->whereNull('voided_at')], 'amount')
            ->get();

        return $invoices
            ->map(function (Invoice $invoice) {
                $paidTotal = $this->decimal($invoice->paid_total);
                $balance = $this->decimal(((float) $invoice->total) - $paidTotal);

                return [
                    'balance' => $balance,
                    'invoice_no' => $invoice->invoice_no,
                    'issue_date' => $invoice->issue_date?->format('Y-m-d'),
                    'parent_name' => $invoice->parentProfile?->father_name ?: 'Unknown parent',
                    'status' => $invoice->status,
                ];
            })
            ->filter(fn (array $invoice) => $invoice['balance'] > 0)
            ->sortByDesc('balance')
            ->take(5)
            ->values()
            ->all();
    }

    protected function pointsLeaderboard(array $filters): array
    {
        $rows = $this->scopedPointTransactionsQuery($filters)
            ->whereNotNull('student_id')
            ->selectRaw('student_id, SUM(points) as net_points, COUNT(*) as transaction_count')
            ->groupBy('student_id')
            ->orderByDesc('net_points')
            ->orderByDesc('transaction_count')
            ->limit(5)
            ->get();

        $students = Student::query()
            ->whereIn('id', $rows->pluck('student_id'))
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($students) {
            $student = $students->get($row->student_id);

            return [
                'net_points' => (int) $row->net_points,
                'student_id' => (int) $row->student_id,
                'student_name' => trim(($student?->first_name ?? '').' '.($student?->last_name ?? '')),
                'transactions' => (int) $row->transaction_count,
            ];
        })->values()->all();
    }

    protected function scopedActivityExpensesQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeActivityExpenses(ActivityExpense::query(), auth()->user());

        $this->applyDateRange($query, 'spent_on', $filters);
        $this->applyActivityGroupScope($query, $filters);

        return $query;
    }

    protected function scopedActivityPaymentsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)
            ->scopeActivityPayments(ActivityPayment::query(), auth()->user())
            ->whereNull('voided_at');

        $this->applyDateRange($query, 'paid_at', $filters);

        $query->whereHas('registration.activity', function (Builder $builder) use ($filters) {
            $this->applyGroupScope($builder, $filters);
        });

        return $query;
    }

    protected function scopedActivityRegistrationsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeActivityRegistrations(ActivityRegistration::query(), auth()->user());

        $this->applyActivityRegistrationScope($query, $filters, includeDateRange: true);

        return $query;
    }

    protected function scopedAssessmentResultsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeAssessmentResults(AssessmentResult::query(), auth()->user());

        $query->whereHas('assessment', function (Builder $builder) use ($filters) {
            $this->applyDateRange($builder, 'scheduled_at', $filters);

            if ($filters['assessment_type_id']) {
                $builder->where('assessment_type_id', $filters['assessment_type_id']);
            }

            if ($filters['group_id'] || $filters['academic_year_id']) {
                $builder->where(function (Builder $assessmentBuilder) use ($filters) {
                    $assessmentBuilder
                        ->whereHas('group', fn (Builder $groupBuilder) => $this->applyGroupScope($groupBuilder, $filters))
                        ->orWhereHas('groups', fn (Builder $groupBuilder) => $this->applyGroupScope($groupBuilder, $filters));
                });
            }
        });

        return $query;
    }

    protected function scopedAttendanceDaysQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeGroupAttendanceDays(GroupAttendanceDay::query(), auth()->user());

        $this->applyDateRange($query, 'attendance_date', $filters);

        if ($filters['group_id'] || $filters['academic_year_id']) {
            $query->whereHas('group', fn (Builder $builder) => $this->applyGroupScope($builder, $filters));
        }

        return $query;
    }

    protected function scopedEnrollmentsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeEnrollments(Enrollment::query(), auth()->user());

        $this->applyEnrollmentScope($query, $filters);

        return $query;
    }

    protected function scopedInvoiceItemsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeInvoiceItems(InvoiceItem::query(), auth()->user());

        $query->whereHas('invoice', fn (Builder $builder) => $this->applyDateRange($builder, 'issue_date', $filters));
        $this->applyInvoiceItemScope($query, $filters);

        return $query;
    }

    protected function scopedInvoicePaymentsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)
            ->scopePayments(Payment::query(), auth()->user())
            ->whereNull('voided_at');

        $this->applyDateRange($query, 'paid_at', $filters);

        if ($filters['group_id'] || $filters['academic_year_id']) {
            $query->whereHas('invoice.items', fn (Builder $builder) => $this->applyInvoiceItemScope($builder, $filters));
        }

        return $query;
    }

    protected function scopedInvoicesQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeInvoices(Invoice::query(), auth()->user());

        $this->applyDateRange($query, 'issue_date', $filters);

        if ($filters['group_id'] || $filters['academic_year_id']) {
            $query->whereHas('items', fn (Builder $builder) => $this->applyInvoiceItemScope($builder, $filters));
        }

        return $query;
    }

    protected function scopedMemorizationSessionsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeMemorizationSessions(MemorizationSession::query(), auth()->user());

        $this->applyDateRange($query, 'recorded_on', $filters);
        $this->applyEnrollmentRelationshipScope($query, $filters);

        return $query;
    }

    protected function scopedPointTransactionsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)
            ->scopePointTransactions(PointTransaction::query(), auth()->user())
            ->whereNull('voided_at');

        $this->applyDateRange($query, 'entered_at', $filters);
        $this->applyEnrollmentRelationshipScope($query, $filters);

        return $query;
    }

    protected function scopedStudentsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeStudents(Student::query(), auth()->user());

        if ($filters['group_id'] || $filters['academic_year_id']) {
            $query->whereHas('enrollments.group', fn (Builder $builder) => $this->applyGroupScope($builder, $filters));
        }

        return $query;
    }

    protected function scopedStudentAttendanceRecordsQuery(array $filters): Builder
    {
        $query = app(AccessScopeService::class)->scopeStudentAttendanceRecords(\App\Models\StudentAttendanceRecord::query(), auth()->user());

        $query->whereHas('attendanceDay', function (Builder $builder) use ($filters) {
            $this->applyDateRange($builder, 'attendance_date', $filters);
        });

        if ($filters['group_id'] || $filters['academic_year_id']) {
            $query->whereHas('attendanceDay.group', fn (Builder $builder) => $this->applyGroupScope($builder, $filters));
        }

        return $query;
    }

    protected function applyActivityGroupScope(Builder $query, array $filters): void
    {
        if (! $filters['group_id'] && ! $filters['academic_year_id']) {
            return;
        }

        $query->whereHas('activity.group', fn (Builder $builder) => $this->applyGroupScope($builder, $filters));
    }

    protected function applyActivityRegistrationScope(Builder $query, array $filters, bool $includeDateRange = false): void
    {
        $query->whereHas('activity', function (Builder $builder) use ($filters, $includeDateRange) {
            if ($includeDateRange) {
                $this->applyDateRange($builder, 'activity_date', $filters);
            }

            $this->applyGroupScope($builder, $filters);
        });
    }

    protected function applyDateRange(Builder $query, string $column, array $filters): void
    {
        if ($filters['date_from']) {
            $query->whereDate($column, '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->whereDate($column, '<=', $filters['date_to']);
        }
    }

    protected function applyEnrollmentRelationshipScope(Builder $query, array $filters): void
    {
        if (! $filters['group_id'] && ! $filters['academic_year_id']) {
            return;
        }

        $query->whereHas('enrollment.group', fn (Builder $builder) => $this->applyGroupScope($builder, $filters));
    }

    protected function applyEnrollmentScope(Builder $query, array $filters): void
    {
        if (! $filters['group_id'] && ! $filters['academic_year_id']) {
            return;
        }

        $query->whereHas('group', fn (Builder $builder) => $this->applyGroupScope($builder, $filters));
    }

    protected function applyGroupScope(Builder $query, array $filters): void
    {
        if ($filters['group_id']) {
            $query->whereKey($filters['group_id']);
        }

        if ($filters['academic_year_id']) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }
    }

    protected function applyInvoiceItemScope(Builder $query, array $filters): void
    {
        if (! $filters['group_id'] && ! $filters['academic_year_id']) {
            return;
        }

        $query->where(function (Builder $builder) use ($filters) {
            $builder
                ->whereHas('enrollment.group', fn (Builder $groupBuilder) => $this->applyGroupScope($groupBuilder, $filters))
                ->orWhereHas('activity.group', fn (Builder $groupBuilder) => $this->applyGroupScope($groupBuilder, $filters));
        });
    }

    protected function decimal(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    protected function overviewCacheKey(array $filters): string
    {
        $user = auth()->user();

        return 'reports.overview.'.md5(json_encode([
            'filters' => $filters,
            'locale' => app()->getLocale(),
            'roles' => $user?->getRoleNames()->values()->all() ?? [],
            'user_id' => $user?->id,
        ]));
    }

    protected function normalizeNullableInteger(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = collect($value)
                ->filter(fn (mixed $item) => $item !== null && $item !== '')
                ->first();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
