<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Student;
use App\Services\ActivityAudienceService;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceWriteController extends Controller
{
    /**
     * Create an activity registration.
     */
    public function storeActivityRegistration(Request $request, Activity $activity)
    {
        $this->authorizePermission($request, 'activities.registrations.manage');

        $registration = ActivityRegistration::query()->create(
            $this->validatedActivityRegistrationData($request, $activity),
        );

        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->json($this->activityRegistrationPayload($registration->fresh(['student', 'enrollment.group'])), 201);
    }

    /**
     * Update an activity registration.
     */
    public function updateActivityRegistration(Request $request, Activity $activity, ActivityRegistration $registration)
    {
        $this->authorizePermission($request, 'activities.registrations.manage');
        abort_unless($registration->activity_id === $activity->id, 404);

        $registration->update($this->validatedActivityRegistrationData($request, $activity, $registration));

        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->json($this->activityRegistrationPayload($registration->fresh(['student', 'enrollment.group'])));
    }

    /**
     * Delete an activity registration without active payments.
     */
    public function destroyActivityRegistration(Request $request, Activity $activity, ActivityRegistration $registration)
    {
        $this->authorizePermission($request, 'activities.registrations.manage');
        abort_unless($registration->activity_id === $activity->id, 404);

        if ($registration->payments()->whereNull('voided_at')->exists()) {
            return response()->json([
                'message' => 'This registration cannot be deleted while active payments exist.',
            ], 422);
        }

        $registration->delete();
        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->noContent();
    }

    /**
     * Record one activity payment.
     */
    public function storeActivityPayment(Request $request, Activity $activity)
    {
        $this->authorizePermission($request, 'activities.payments.manage');

        $validated = $request->validate([
            'activity_registration_id' => ['required', 'integer', Rule::exists('activity_registrations', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'paid_at' => ['required', 'date'],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'reference_no' => ['nullable', 'string', 'max:255'],
        ]);

        $registration = ActivityRegistration::query()->where('activity_id', $activity->id)->findOrFail($validated['activity_registration_id']);

        $payment = ActivityPayment::query()->create([
            'activity_registration_id' => $registration->id,
            'amount' => $validated['amount'],
            'entered_by' => $request->user()->id,
            'notes' => blank($validated['notes'] ?? null) ? null : $validated['notes'],
            'paid_at' => $validated['paid_at'],
            'payment_method_id' => $validated['payment_method_id'],
            'reference_no' => blank($validated['reference_no'] ?? null) ? null : $validated['reference_no'],
        ]);

        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->json($this->activityPaymentPayload($payment->fresh(['paymentMethod', 'registration.student'])), 201);
    }

    /**
     * Void one activity payment.
     */
    public function voidActivityPayment(Request $request, Activity $activity, ActivityPayment $activityPayment)
    {
        $this->authorizePermission($request, 'activities.payments.manage');
        abort_unless($activityPayment->registration?->activity_id === $activity->id, 404);

        if (! $activityPayment->voided_at) {
            $activityPayment->update([
                'void_reason' => 'Voided from the integration API.',
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
            ]);

            app(FinanceService::class)->syncActivityTotals($activity->fresh());
        }

        return response()->json($this->activityPaymentPayload($activityPayment->fresh(['paymentMethod', 'registration.student', 'voidedBy'])));
    }

    /**
     * Create one activity expense.
     */
    public function storeActivityExpense(Request $request, Activity $activity)
    {
        $this->authorizePermission($request, 'activities.expenses.manage');

        $expense = ActivityExpense::query()->create($this->validatedActivityExpenseData($request, $activity));

        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->json($this->activityExpensePayload($expense->fresh('category')), 201);
    }

    /**
     * Update one activity expense.
     */
    public function updateActivityExpense(Request $request, Activity $activity, ActivityExpense $activityExpense)
    {
        $this->authorizePermission($request, 'activities.expenses.manage');
        abort_unless($activityExpense->activity_id === $activity->id, 404);

        $activityExpense->update($this->validatedActivityExpenseData($request, $activity));

        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->json($this->activityExpensePayload($activityExpense->fresh('category')));
    }

    /**
     * Delete one activity expense.
     */
    public function destroyActivityExpense(Request $request, Activity $activity, ActivityExpense $activityExpense)
    {
        $this->authorizePermission($request, 'activities.expenses.manage');
        abort_unless($activityExpense->activity_id === $activity->id, 404);

        $activityExpense->delete();
        app(FinanceService::class)->syncActivityTotals($activity->fresh());

        return response()->noContent();
    }

    /**
     * Create one invoice item.
     */
    public function storeInvoiceItem(Request $request, Invoice $invoice)
    {
        $this->authorizePermission($request, 'invoices.update');

        $item = InvoiceItem::query()->create($this->validatedInvoiceItemData($request, $invoice));

        app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());

        return response()->json($this->invoiceItemPayload($item->fresh(['student', 'enrollment.group', 'activity'])), 201);
    }

    /**
     * Update one invoice item.
     */
    public function updateInvoiceItem(Request $request, Invoice $invoice, InvoiceItem $invoiceItem)
    {
        $this->authorizePermission($request, 'invoices.update');
        abort_unless($invoiceItem->invoice_id === $invoice->id, 404);

        $invoiceItem->update($this->validatedInvoiceItemData($request, $invoice));

        app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());

        return response()->json($this->invoiceItemPayload($invoiceItem->fresh(['student', 'enrollment.group', 'activity'])));
    }

    /**
     * Delete one invoice item.
     */
    public function destroyInvoiceItem(Request $request, Invoice $invoice, InvoiceItem $invoiceItem)
    {
        $this->authorizePermission($request, 'invoices.update');
        abort_unless($invoiceItem->invoice_id === $invoice->id, 404);

        $invoiceItem->delete();
        app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());

        return response()->noContent();
    }

    /**
     * Record one invoice payment.
     */
    public function storeInvoicePayment(Request $request, Invoice $invoice)
    {
        $this->authorizePermission($request, 'payments.create');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'paid_at' => ['required', 'date'],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'reference_no' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = Payment::query()->create([
            'amount' => $validated['amount'],
            'invoice_id' => $invoice->id,
            'notes' => blank($validated['notes'] ?? null) ? null : $validated['notes'],
            'paid_at' => $validated['paid_at'],
            'payment_method_id' => $validated['payment_method_id'],
            'received_by' => $request->user()->id,
            'reference_no' => blank($validated['reference_no'] ?? null) ? null : $validated['reference_no'],
        ]);

        app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());

        return response()->json($this->invoicePaymentPayload($payment->fresh(['paymentMethod', 'receivedBy'])), 201);
    }

    /**
     * Void one invoice payment.
     */
    public function voidInvoicePayment(Request $request, Invoice $invoice, Payment $payment)
    {
        $this->authorizePermission($request, 'payments.void');
        abort_unless($payment->invoice_id === $invoice->id, 404);

        if (! $payment->voided_at) {
            $payment->update([
                'void_reason' => 'Voided from the integration API.',
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
            ]);

            app(FinanceService::class)->syncInvoiceTotals($invoice->fresh());
        }

        return response()->json($this->invoicePaymentPayload($payment->fresh(['paymentMethod', 'receivedBy', 'voidedBy'])));
    }

    protected function activityExpensePayload(ActivityExpense $expense): array
    {
        return [
            'activity_id' => $expense->activity_id,
            'amount' => (float) $expense->amount,
            'description' => $expense->description,
            'expense_category_id' => $expense->expense_category_id,
            'expense_category_name' => $expense->category?->name,
            'id' => $expense->id,
            'spent_on' => $expense->spent_on?->format('Y-m-d'),
        ];
    }

    protected function activityPaymentPayload(ActivityPayment $payment): array
    {
        return [
            'activity_registration_id' => $payment->activity_registration_id,
            'amount' => (float) $payment->amount,
            'id' => $payment->id,
            'paid_at' => $payment->paid_at?->format('Y-m-d'),
            'payment_method_id' => $payment->payment_method_id,
            'payment_method_name' => $payment->paymentMethod?->name,
            'reference_no' => $payment->reference_no,
            'student_id' => $payment->registration?->student_id,
            'voided_at' => $payment->voided_at?->toIso8601String(),
            'voided_by' => $payment->voided_by,
        ];
    }

    protected function activityRegistrationPayload(ActivityRegistration $registration): array
    {
        return [
            'activity_id' => $registration->activity_id,
            'enrollment_id' => $registration->enrollment_id,
            'fee_amount' => (float) $registration->fee_amount,
            'id' => $registration->id,
            'notes' => $registration->notes,
            'status' => $registration->status,
            'student_id' => $registration->student_id,
            'student_name' => trim(($registration->student?->first_name ?? '').' '.($registration->student?->last_name ?? '')),
        ];
    }

    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    protected function invoiceItemPayload(InvoiceItem $item): array
    {
        return [
            'activity_id' => $item->activity_id,
            'amount' => (float) $item->amount,
            'description' => $item->description,
            'enrollment_id' => $item->enrollment_id,
            'id' => $item->id,
            'invoice_id' => $item->invoice_id,
            'quantity' => (float) $item->quantity,
            'student_id' => $item->student_id,
            'student_name' => trim(($item->student?->first_name ?? '').' '.($item->student?->last_name ?? '')),
            'unit_price' => (float) $item->unit_price,
        ];
    }

    protected function invoicePaymentPayload(Payment $payment): array
    {
        return [
            'amount' => (float) $payment->amount,
            'id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'paid_at' => $payment->paid_at?->format('Y-m-d'),
            'payment_method_id' => $payment->payment_method_id,
            'payment_method_name' => $payment->paymentMethod?->name,
            'reference_no' => $payment->reference_no,
            'voided_at' => $payment->voided_at?->toIso8601String(),
            'voided_by' => $payment->voided_by,
        ];
    }

    protected function validatedActivityExpenseData(Request $request, Activity $activity): array
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'spent_on' => ['required', 'date'],
        ]);

        $validated['activity_id'] = $activity->id;
        $validated['entered_by'] = $request->user()->id;

        return $validated;
    }

    protected function validatedActivityRegistrationData(Request $request, Activity $activity, ?ActivityRegistration $registration = null): array
    {
        $validated = $request->validate([
            'enrollment_id' => ['nullable', 'integer', Rule::exists('enrollments', 'id')->whereNull('deleted_at')],
            'fee_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['registered', 'declined', 'cancelled', 'attended'])],
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->whereNull('deleted_at'),
                Rule::unique('activity_registrations', 'student_id')
                    ->where(fn ($query) => $query->where('activity_id', $activity->id)->whereNull('deleted_at'))
                    ->ignore($registration?->id),
            ],
        ]);

        if ($validated['enrollment_id'] ?? null) {
            $enrollment = Enrollment::query()->findOrFail($validated['enrollment_id']);
            $audience = app(ActivityAudienceService::class);

            if ($enrollment->student_id !== (int) $validated['student_id']) {
                abort(response()->json([
                    'message' => 'The selected enrollment does not belong to the selected student.',
                ], 422));
            }

            if (! $audience->enrollmentMatches($activity, $enrollment)) {
                abort(response()->json([
                    'message' => 'The selected enrollment must belong to this activity target audience.',
                ], 422));
            }
        }

        $validated['activity_id'] = $activity->id;
        $validated['enrollment_id'] = $validated['enrollment_id'] ?? null;
        $validated['notes'] = blank($validated['notes'] ?? null) ? null : $validated['notes'];

        return $validated;
    }

    protected function validatedInvoiceItemData(Request $request, Invoice $invoice): array
    {
        $validated = $request->validate([
            'activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:255'],
            'enrollment_id' => ['nullable', 'integer', Rule::exists('enrollments', 'id')->whereNull('deleted_at')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'student_id' => ['nullable', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validated['student_id'] ?? null) {
            $student = Student::query()->findOrFail($validated['student_id']);

            if ($student->parent_id !== $invoice->parent_id) {
                abort(response()->json([
                    'message' => 'The selected student does not belong to this invoice parent.',
                ], 422));
            }
        }

        if ($validated['enrollment_id'] ?? null) {
            $enrollment = Enrollment::query()->with('student')->findOrFail($validated['enrollment_id']);

            if ($validated['student_id'] && $enrollment->student_id !== (int) $validated['student_id']) {
                abort(response()->json([
                    'message' => 'The selected enrollment does not belong to the selected student.',
                ], 422));
            }

            if ($enrollment->student?->parent_id !== $invoice->parent_id) {
                abort(response()->json([
                    'message' => 'The selected enrollment does not belong to this invoice parent.',
                ], 422));
            }

            $validated['student_id'] = $enrollment->student_id;
        }

        $validated['activity_id'] = $validated['activity_id'] ?? null;
        $validated['amount'] = (float) $validated['quantity'] * (float) $validated['unit_price'];
        $validated['enrollment_id'] = $validated['enrollment_id'] ?? null;
        $validated['invoice_id'] = $invoice->id;
        $validated['student_id'] = $validated['student_id'] ?? null;

        return $validated;
    }
}
