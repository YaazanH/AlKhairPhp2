<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\AccessScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class FinanceRecordsController extends Controller
{
    public function activity(Request $request, Activity $activity)
    {
        $this->authorizePermission($request, 'activities.view');
        $this->authorizeActivityScope($request, $activity);
        $activity->load(['group.course', 'targetGroups'])->loadCount(['registrations', 'expenses']);
        $paymentsCount = app(AccessScopeService::class)->scopeActivityPayments(
            ActivityPayment::query()->whereNull('voided_at')->whereHas('registration', fn (Builder $query) => $query->where('activity_id', $activity->id)),
            $request->user(),
        )->count();

        return response()->json(['data' => [
            'activity_date' => $activity->activity_date?->format('Y-m-d'),
            'audience_scope' => $activity->audience_scope,
            'collected_revenue' => (float) $activity->collected_revenue_cached,
            'description' => $activity->description,
            'expense_total' => (float) $activity->expense_total_cached,
            'expenses_count' => $activity->expenses_count,
            'expected_revenue' => (float) $activity->expected_revenue_cached,
            'fee_amount' => (float) $activity->fee_amount,
            'group' => $activity->group ? ['course_name' => $activity->group->course?->name, 'id' => $activity->group->id, 'name' => $activity->group->name] : null,
            'id' => $activity->id,
            'is_active' => $activity->is_active,
            'payments_count' => $paymentsCount,
            'registrations_count' => $activity->registrations_count,
            'status' => $activity->status,
            'target_groups' => $activity->targetGroups->map(fn ($group) => ['id' => $group->id, 'name' => $group->name])->values(),
            'title' => $activity->title,
        ]]);
    }

    public function activityRegistrations(Request $request, Activity $activity)
    {
        $this->authorizeAnyPermission($request, ['activities.finance.view', 'activities.registrations.manage']);
        $this->authorizeActivityScope($request, $activity);
        $validated = $this->validatedPagination($request, [
            'status' => ['nullable', 'string', 'max:50'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
        ]);

        return app(AccessScopeService::class)->scopeActivityRegistrations(
            ActivityRegistration::query()->where('activity_id', $activity->id),
            $request->user(),
        )->with(['enrollment.group', 'student'])
            ->withSum(['payments as paid_total' => fn (Builder $query) => $query->whereNull('voided_at')], 'amount')
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['student_id'] ?? null, fn (Builder $query, int $studentId) => $query->where('student_id', $studentId))
            ->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (ActivityRegistration $registration) => [
                'balance' => round((float) $registration->fee_amount - (float) ($registration->paid_total ?? 0), 2),
                'enrollment_id' => $registration->enrollment_id,
                'fee_amount' => (float) $registration->fee_amount,
                'group_name' => $registration->enrollment?->group?->name,
                'id' => $registration->id,
                'notes' => $registration->notes,
                'paid_total' => round((float) ($registration->paid_total ?? 0), 2),
                'status' => $registration->status,
                'student' => $registration->student ? ['id' => $registration->student->id, 'name' => $registration->student->full_name] : null,
            ]);
    }

    public function activityPayments(Request $request, Activity $activity)
    {
        $this->authorizeAnyPermission($request, ['activities.finance.view', 'activities.payments.manage']);
        $this->authorizeActivityScope($request, $activity);
        $validated = $this->validatedDatePagination($request, ['state' => ['nullable', 'in:active,voided']]);

        return app(AccessScopeService::class)->scopeActivityPayments(
            ActivityPayment::query()->whereHas('registration', fn (Builder $query) => $query->where('activity_id', $activity->id)),
            $request->user(),
        )->with(['paymentMethod', 'registration.student'])
            ->when(($validated['state'] ?? null) === 'active', fn (Builder $query) => $query->whereNull('voided_at'))
            ->when(($validated['state'] ?? null) === 'voided', fn (Builder $query) => $query->whereNotNull('voided_at'))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date))
            ->latest('paid_at')->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (ActivityPayment $payment) => $this->activityPaymentPayload($payment));
    }

    public function activityExpenses(Request $request, Activity $activity)
    {
        $this->authorizeAnyPermission($request, ['activities.finance.view', 'activities.expenses.manage']);
        $this->authorizeActivityScope($request, $activity);
        $validated = $this->validatedDatePagination($request, ['expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id']]);

        return app(AccessScopeService::class)->scopeActivityExpenses(
            ActivityExpense::query()->where('activity_id', $activity->id),
            $request->user(),
        )->with('category')
            ->when($validated['expense_category_id'] ?? null, fn (Builder $query, int $categoryId) => $query->where('expense_category_id', $categoryId))
            ->when($validated['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('spent_on', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('spent_on', '<=', $date))
            ->latest('spent_on')->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (ActivityExpense $expense) => [
                'amount' => (float) $expense->amount,
                'description' => $expense->description,
                'expense_category' => $expense->category ? ['id' => $expense->category->id, 'name' => $expense->category->name] : null,
                'id' => $expense->id,
                'spent_on' => $expense->spent_on?->format('Y-m-d'),
            ]);
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        $this->authorizePermission($request, 'invoices.view');
        $this->authorizeInvoiceScope($request, $invoice);
        $scopes = app(AccessScopeService::class);
        $invoice->load('parentProfile');
        $items = $scopes->scopeInvoiceItems(InvoiceItem::query()->where('invoice_id', $invoice->id), $request->user())
            ->with(['activity', 'enrollment.group', 'student'])->orderBy('id')->get();
        $payments = $scopes->scopePayments(Payment::query()->where('invoice_id', $invoice->id), $request->user())
            ->with('paymentMethod')->latest('paid_at')->latest('id')->get();
        $paidTotal = $payments->whereNull('voided_at')->sum(fn (Payment $payment) => (float) $payment->amount);

        return response()->json(['data' => [
            'balance' => round((float) $invoice->total - $paidTotal, 2),
            'discount' => (float) $invoice->discount,
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'invoice_type' => $invoice->invoice_type,
            'issue_date' => $invoice->issue_date?->format('Y-m-d'),
            'items' => $items->map(fn (InvoiceItem $item) => $this->invoiceItemPayload($item))->values(),
            'notes' => $invoice->notes,
            'paid_total' => round($paidTotal, 2),
            'parent' => $invoice->parentProfile ? ['id' => $invoice->parentProfile->id, 'name' => $invoice->parentProfile->father_name] : null,
            'payments' => $payments->map(fn (Payment $payment) => $this->invoicePaymentPayload($payment))->values(),
            'status' => $invoice->status,
            'subtotal' => (float) $invoice->subtotal,
            'total' => (float) $invoice->total,
        ]]);
    }

    public function invoicePayments(Request $request, Invoice $invoice)
    {
        $this->authorizePermission($request, 'invoices.view');
        $this->authorizeInvoiceScope($request, $invoice);
        $validated = $this->validatedDatePagination($request, ['state' => ['nullable', 'in:active,voided']]);

        return $this->paymentsQuery($request, Payment::query()->where('invoice_id', $invoice->id), $validated);
    }

    public function payments(Request $request)
    {
        $this->authorizePermission($request, 'payments.view');
        $validated = $this->validatedDatePagination($request, [
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'state' => ['nullable', 'in:active,voided'],
        ]);

        return $this->paymentsQuery($request, Payment::query(), $validated);
    }

    protected function paymentsQuery(Request $request, Builder $query, array $validated)
    {
        return app(AccessScopeService::class)->scopePayments($query, $request->user())
            ->with(['invoice.parentProfile', 'paymentMethod'])
            ->when($validated['invoice_id'] ?? null, fn (Builder $builder, int $invoiceId) => $builder->where('invoice_id', $invoiceId))
            ->when($validated['payment_method_id'] ?? null, fn (Builder $builder, int $methodId) => $builder->where('payment_method_id', $methodId))
            ->when(($validated['state'] ?? null) === 'active', fn (Builder $builder) => $builder->whereNull('voided_at'))
            ->when(($validated['state'] ?? null) === 'voided', fn (Builder $builder) => $builder->whereNotNull('voided_at'))
            ->when($validated['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate('paid_at', '<=', $date))
            ->latest('paid_at')->latest('id')->paginate($validated['per_page'] ?? 15)
            ->through(fn (Payment $payment) => $this->invoicePaymentPayload($payment));
    }

    protected function activityPaymentPayload(ActivityPayment $payment): array
    {
        return [
            'activity_registration_id' => $payment->activity_registration_id,
            'amount' => (float) $payment->amount,
            'id' => $payment->id,
            'paid_at' => $payment->paid_at?->format('Y-m-d'),
            'payment_method' => $payment->paymentMethod ? ['id' => $payment->paymentMethod->id, 'name' => $payment->paymentMethod->name] : null,
            'reference_no' => $payment->reference_no,
            'student' => $payment->registration?->student ? ['id' => $payment->registration->student->id, 'name' => $payment->registration->student->full_name] : null,
            'void_reason' => $payment->void_reason,
            'voided_at' => $payment->voided_at?->toIso8601String(),
        ];
    }

    protected function invoiceItemPayload(InvoiceItem $item): array
    {
        return [
            'activity_id' => $item->activity_id,
            'amount' => (float) $item->amount,
            'description' => $item->description,
            'enrollment_id' => $item->enrollment_id,
            'group_name' => $item->enrollment?->group?->name,
            'id' => $item->id,
            'quantity' => (float) $item->quantity,
            'student' => $item->student ? ['id' => $item->student->id, 'name' => $item->student->full_name] : null,
            'unit_price' => (float) $item->unit_price,
        ];
    }

    protected function invoicePaymentPayload(Payment $payment): array
    {
        return [
            'amount' => (float) $payment->amount,
            'id' => $payment->id,
            'invoice' => $payment->invoice ? ['id' => $payment->invoice->id, 'invoice_no' => $payment->invoice->invoice_no, 'parent_name' => $payment->invoice->parentProfile?->father_name] : null,
            'invoice_id' => $payment->invoice_id,
            'notes' => $payment->notes,
            'paid_at' => $payment->paid_at?->format('Y-m-d'),
            'payment_method' => $payment->paymentMethod ? ['id' => $payment->paymentMethod->id, 'name' => $payment->paymentMethod->name] : null,
            'reference_no' => $payment->reference_no,
            'void_reason' => $payment->void_reason,
            'voided_at' => $payment->voided_at?->toIso8601String(),
        ];
    }

    protected function validatedPagination(Request $request, array $rules = []): array
    {
        return $request->validate(array_merge(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']], $rules));
    }

    protected function validatedDatePagination(Request $request, array $rules = []): array
    {
        return $this->validatedPagination($request, array_merge([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], $rules));
    }

    protected function authorizeActivityScope(Request $request, Activity $activity): void
    {
        abort_unless(app(AccessScopeService::class)->scopeActivities(Activity::query()->whereKey($activity->id), $request->user())->exists(), 403);
    }

    protected function authorizeInvoiceScope(Request $request, Invoice $invoice): void
    {
        abort_unless(app(AccessScopeService::class)->canAccessInvoice($request->user(), $invoice), 403);
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function authorizeAnyPermission(Request $request, array $permissions): void
    {
        abort_unless(collect($permissions)->contains(fn (string $permission) => $request->user()?->can($permission)), 403);
    }
}
