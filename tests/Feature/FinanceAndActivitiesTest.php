<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Activity;
use App\Models\ActivityPayment;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceCurrencyExchange;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Group;
use App\Models\User;
use App\Services\ActivityAudienceService;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class FinanceAndActivitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_components_support_registrations_payments_and_expenses(): void
    {
        $this->signIn();

        [$parent, $student, $group, $enrollment] = $this->financeContext();
        $paymentMethod = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
        $expenseCategory = ExpenseCategory::query()->where('code', 'transport')->firstOrFail();

        Volt::test('activities.index')
            ->set('title', 'Spring Trip')
            ->set('activity_date', '2026-10-10')
            ->set('group_id', $group->id)
            ->set('fee_amount', '30')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $activity = Activity::query()->firstOrFail();

        Volt::test('activities.finance', ['activity' => $activity])
            ->set('registration_student_id', $student->id)
            ->set('registration_enrollment_id', $enrollment->id)
            ->set('registration_fee_amount', '30')
            ->set('registration_status', 'registered')
            ->call('saveRegistration')
            ->assertHasNoErrors();

        $registration = $activity->registrations()->firstOrFail();

        Volt::test('activities.finance', ['activity' => $activity])
            ->set('payment_registration_id', $registration->id)
            ->set('payment_method_id', $paymentMethod->id)
            ->set('payment_paid_at', '2026-10-11')
            ->set('payment_amount', '20')
            ->call('savePayment')
            ->assertHasNoErrors();

        Volt::test('activities.finance', ['activity' => $activity])
            ->set('expense_category_id', $expenseCategory->id)
            ->set('expense_amount', '8')
            ->set('expense_spent_on', '2026-10-12')
            ->set('expense_description', 'Bus rental')
            ->call('saveExpense')
            ->assertHasNoErrors();

        $activity->refresh();

        $this->assertSame('30.00', $activity->expected_revenue_cached);
        $this->assertSame('20.00', $activity->collected_revenue_cached);
        $this->assertSame('8.00', $activity->expense_total_cached);
        $this->assertDatabaseHas('finance_transactions', [
            'activity_id' => $activity->id,
            'source_type' => ActivityPayment::class,
            'type' => 'activity_payment',
            'signed_amount' => 20,
        ]);
        $this->assertDatabaseHas('finance_transactions', [
            'activity_id' => $activity->id,
            'type' => 'activity_expense',
            'signed_amount' => -8,
        ]);

        $payment = ActivityPayment::query()->firstOrFail();

        Volt::test('activities.finance', ['activity' => $activity])
            ->call('voidPayment', $payment->id);

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame('0.00', $activity->fresh()->collected_revenue_cached);
        $this->assertDatabaseHas('finance_transactions', [
            'source_type' => ActivityPayment::class,
            'source_id' => $payment->id,
            'type' => 'activity_payment_reversal',
            'signed_amount' => -20,
        ]);
    }

    public function test_invoice_components_support_items_and_payments(): void
    {
        $this->signIn();

        [$parent, $student, $group, $enrollment] = $this->financeContext();
        $paymentMethod = PaymentMethod::query()->where('code', 'cash')->firstOrFail();

        $activity = Activity::create([
            'title' => 'Invoice Activity',
            'activity_date' => '2026-11-01',
            'group_id' => $group->id,
            'fee_amount' => 30,
            'is_active' => true,
        ]);

        Volt::test('invoices.index')
            ->set('parent_id', $parent->id)
            ->set('invoice_type', 'activity')
            ->set('issue_date', '2026-11-02')
            ->set('due_date', '2026-11-15')
            ->set('status', 'issued')
            ->set('discount', '5')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::query()->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('item_description', 'Activity fee')
            ->set('item_student_id', $student->id)
            ->set('item_enrollment_id', $enrollment->id)
            ->set('item_activity_id', $activity->id)
            ->set('item_quantity', '1')
            ->set('item_unit_price', '30')
            ->call('saveItem')
            ->assertHasNoErrors();

        $invoice->refresh();

        $this->assertSame('30.00', $invoice->subtotal);
        $this->assertSame('25.00', $invoice->total);
        $this->assertSame('issued', $invoice->status);

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('payment_method_id', $paymentMethod->id)
            ->set('paid_at', '2026-11-03')
            ->set('payment_amount', '25')
            ->call('savePayment')
            ->assertHasNoErrors();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertDatabaseHas('finance_transactions', [
            'source_type' => Payment::class,
            'type' => 'invoice_payment',
            'signed_amount' => 25,
        ]);

        $payment = Payment::query()->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->call('voidPayment', $payment->id);

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseHas('finance_transactions', [
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'type' => 'invoice_payment_reversal',
            'signed_amount' => -25,
        ]);
    }

    public function test_teacher_pull_request_terms_review_and_printing_flow(): void
    {
        $this->seed();

        AppSetting::storeValue('finance', 'request_terms', 'I accept the finance pull terms.');

        $teacherUser = User::factory()->create([
            'name' => 'Pull Teacher',
            'username' => 'pull-teacher',
            'phone' => '0991111444',
        ]);
        $teacherUser->givePermissionTo([
            'finance.pull-requests.view',
            'finance.pull-requests.create',
        ]);

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Pull',
            'last_name' => 'Teacher',
            'phone' => '0944000911',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser);

        Volt::test('finance.pull-requests')
            ->set('requested_amount', '100')
            ->call('submitRequest')
            ->assertHasErrors(['accepted_terms' => 'accepted']);

        Volt::test('finance.pull-requests')
            ->set('requested_amount', '100')
            ->set('requested_reason', 'Class materials')
            ->set('accepted_terms', true)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->firstOrFail();

        $this->assertSame(FinanceRequest::STATUS_PENDING, $request->status);
        $this->assertSame($teacher->id, $request->teacher_id);
        $this->assertSame('I accept the finance pull terms.', $request->terms_snapshot);
        $this->assertNotNull($request->terms_accepted_at);

        $manager = User::factory()->create([
            'name' => 'Finance Manager',
            'username' => 'finance-manager-2',
            'phone' => '0991111555',
        ]);
        $manager->assignRole('manager');
        $cashBox = FinanceCashBox::query()->firstOrFail();

        $this->actingAs($manager);

        Volt::test('finance.pull-requests')
            ->set("review_amounts.{$request->id}", '75')
            ->set("review_cash_boxes.{$request->id}", $cashBox->id)
            ->set("review_notes.{$request->id}", 'Approved lower amount')
            ->call('accept', $request->id)
            ->assertHasNoErrors();

        $request->refresh();

        $this->assertSame(FinanceRequest::STATUS_ACCEPTED, $request->status);
        $this->assertSame('75.00', $request->accepted_amount);
        $this->assertSame($cashBox->id, $request->cash_box_id);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'cash_box_id' => $cashBox->id,
            'type' => 'pull_request',
            'signed_amount' => -75,
        ]);

        $this->get(route('finance.requests.print', $request))->assertOk()->assertSee('PUL-000001');
    }

    public function test_finance_settings_currency_and_cash_box_rules(): void
    {
        $this->signIn();

        $localCurrency = FinanceCurrency::query()->where('is_local', true)->firstOrFail();

        Volt::test('settings.finance')
            ->call('editCurrency', $localCurrency->id)
            ->set('currency_is_local', false)
            ->call('saveCurrency')
            ->assertHasErrors(['currency_is_local']);

        $cashBox = FinanceCashBox::query()->firstOrFail();

        app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $localCurrency->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 50,
            'description' => 'Opening balance',
        ]);

        Volt::test('settings.finance')
            ->call('editCashBox', $cashBox->id)
            ->set('cash_box_is_active', false)
            ->call('saveCashBox')
            ->assertHasErrors(['cash_box_is_active']);
    }

    public function test_finance_exchange_transfer_balances_and_report_snapshots(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $mainBox = FinanceCashBox::query()->where('code', 'main')->firstOrFail();
        $secondBox = FinanceCashBox::query()->create([
            'name' => 'Secondary Cash Box',
            'code' => 'secondary',
            'is_active' => true,
        ]);

        $usd = FinanceCurrency::query()->where('is_base', true)->firstOrFail();
        $syp = FinanceCurrency::query()->where('is_local', true)->firstOrFail();

        $service->postTransaction([
            'cash_box_id' => $mainBox->id,
            'currency_id' => $usd->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => '2026-01-10',
            'description' => 'USD opening balance',
        ]);

        $exchange = $service->recordCurrencyExchange(
            $mainBox,
            $usd,
            10,
            $secondBox,
            $syp,
            123000,
            '2026-01-12',
            auth()->user(),
            'Test exchange',
        );

        $this->assertInstanceOf(FinanceCurrencyExchange::class, $exchange);
        $this->assertSame(2, FinanceTransaction::query()->where('pair_uuid', $exchange->pair_uuid)->count());
        $this->assertDatabaseHas('finance_transactions', [
            'cash_box_id' => $mainBox->id,
            'currency_id' => $usd->id,
            'type' => 'currency_exchange',
            'signed_amount' => -10,
        ]);
        $this->assertDatabaseHas('finance_transactions', [
            'cash_box_id' => $secondBox->id,
            'currency_id' => $syp->id,
            'type' => 'currency_exchange',
            'signed_amount' => 123000,
        ]);

        $service->recordCashBoxTransfer($secondBox, $mainBox, $syp, 3000, '2026-01-13', auth()->user(), 'Move local cash');

        $balances = $service->cashBoxBalances(auth()->user());
        $mainBalance = $balances->firstWhere('cash_box.id', $mainBox->id);
        $secondBalance = $balances->firstWhere('cash_box.id', $secondBox->id);

        $this->assertSame(90.0, $mainBalance['currencies']->firstWhere('currency.id', $usd->id)['balance']);
        $this->assertSame(3000.0, $mainBalance['currencies']->firstWhere('currency.id', $syp->id)['balance']);
        $this->assertSame(120000.0, $secondBalance['currencies']->firstWhere('currency.id', $syp->id)['balance']);

        $report = app(FinanceReportService::class)->report(2026, 1);

        $this->assertGreaterThan(0, $report['summary']['transactions']);
        $this->assertNotEmpty($report['quarter_totals']);
    }

    public function test_parent_users_can_view_and_respond_to_targeted_activities(): void
    {
        $this->seed();

        [$parent, $student, $group, $enrollment] = $this->financeContext();

        $parentUser = User::factory()->create([
            'username' => 'family-parent',
            'phone' => '0992222333',
        ]);
        $parentUser->assignRole('parent');
        $parent->update(['user_id' => $parentUser->id]);

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0944000999',
            'status' => 'active',
        ]);

        $otherGroup = Group::create([
            'course_id' => $group->course_id,
            'academic_year_id' => $group->academic_year_id,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Family Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $targetedActivity = Activity::create([
            'title' => 'Family Picnic',
            'activity_date' => '2026-10-20',
            'audience_scope' => 'multiple_groups',
            'fee_amount' => 18,
            'is_active' => true,
        ]);

        app(ActivityAudienceService::class)->syncTargets($targetedActivity, 'multiple_groups', null, [$group->id, $otherGroup->id]);

        $hiddenActivity = Activity::create([
            'title' => 'Hidden Trip',
            'activity_date' => '2026-11-05',
            'audience_scope' => 'single_group',
            'group_id' => $otherGroup->id,
            'fee_amount' => 22,
            'is_active' => true,
        ]);

        app(ActivityAudienceService::class)->syncTargets($hiddenActivity, 'single_group', $otherGroup->id);

        $response = $this->actingAs($parentUser)->get(route('activities.family'));

        $response
            ->assertOk()
            ->assertSee('Family Picnic')
            ->assertSee('18.00')
            ->assertDontSee('Hidden Trip');

        $this->actingAs($parentUser);

        Volt::test('activities.family')
            ->call('respond', $targetedActivity->id, $student->id, 'registered')
            ->assertHasNoErrors();

        $registration = $targetedActivity->registrations()->firstOrFail();

        $this->assertSame($student->id, $registration->student_id);
        $this->assertSame($enrollment->id, $registration->enrollment_id);
        $this->assertSame('registered', $registration->status);
        $this->assertSame('18.00', $targetedActivity->fresh()->expected_revenue_cached);
    }

    private function financeContext(): array
    {
        $parent = ParentProfile::create([
            'father_name' => 'Finance Parent',
            'father_phone' => '0944000900',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Finance',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Finance',
            'last_name' => 'Teacher',
            'phone' => '0944000901',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Finance Course',
            'is_active' => true,
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Finance Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        return [$parent, $student, $group, $enrollment];
    }

    private function signIn(): void
    {
        $this->seed();

        $user = User::factory()->create([
            'name' => 'Manager User',
            'username' => 'finance-manager',
            'phone' => '0991111222',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);
    }
}
