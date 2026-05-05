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
use App\Models\FinanceInvoiceKind;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PrintTemplate;
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
        app(FinanceService::class)->postTransaction([
            'cash_box_id' => app(FinanceService::class)->defaultCashBox()->id,
            'currency_id' => app(FinanceService::class)->localCurrency()->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 20,
            'description' => 'Void test reserve balance',
        ]);

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

        $invoiceKind = FinanceInvoiceKind::query()->where('code', 'general')->firstOrFail();

        Volt::test('invoices.index')
            ->set('invoicer_name', 'Office Supplies Store')
            ->set('finance_invoice_kind_id', $invoiceKind->id)
            ->set('invoice_type', 'finance')
            ->set('issue_date', '2026-11-02')
            ->set('due_date', '2026-11-15')
            ->set('status', 'issued')
            ->set('discount', '5')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::query()->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('item_name', 'Activity fee')
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
        $pullKind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_COUNT)->firstOrFail();

        Volt::test('finance.pull-requests')
            ->set('requested_amount', '1,500')
            ->call('submitRequest')
            ->assertHasErrors(['accepted_terms' => 'accepted']);

        Volt::test('finance.pull-requests')
            ->set('finance_pull_request_kind_id', $pullKind->id)
            ->set('requested_amount', '1,500')
            ->set('requested_count', '1,250')
            ->set('requested_reason', 'Class materials')
            ->set('accepted_terms', true)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->firstOrFail();

        $this->assertSame(FinanceRequest::STATUS_PENDING, $request->status);
        $this->assertSame($teacher->id, $request->teacher_id);
        $this->assertSame('1500.00', $request->requested_amount);
        $this->assertSame(1250, $request->requested_count);
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
        app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => app(FinanceService::class)->localCurrency()->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 2000,
            'description' => 'Pull request test balance',
            'entered_by' => $manager->id,
        ]);

        Volt::test('finance.pull-requests')
            ->set("review_amounts.{$request->id}", '1,075')
            ->set("review_cash_boxes.{$request->id}", $cashBox->id)
            ->set("review_notes.{$request->id}", 'Approved lower amount')
            ->call('accept', $request->id)
            ->assertHasNoErrors();

        $request->refresh();

        $this->assertSame(FinanceRequest::STATUS_ACCEPTED, $request->status);
        $this->assertSame('1075.00', $request->accepted_amount);
        $this->assertSame($cashBox->id, $request->cash_box_id);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'cash_box_id' => $cashBox->id,
            'type' => 'pull_request',
            'signed_amount' => -1075,
        ]);

        $this->get(route('finance.requests.print', $request))->assertOk()->assertSee('PUL-000001');

        $template = PrintTemplate::query()->create([
            'name' => 'Pull Request Receipt',
            'width_mm' => 80,
            'height_mm' => 40,
            'data_sources' => [
                ['entity' => 'finance_request', 'mode' => 'single'],
            ],
            'layout_json' => [
                [
                    'type' => 'dynamic_text',
                    'source' => 'finance_request',
                    'field' => 'request_no',
                    'x' => 5,
                    'y' => 5,
                    'width' => 60,
                    'height' => 8,
                    'z_index' => 1,
                    'styling' => [
                        'font_size' => 4,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'left',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        AppSetting::storeValue('finance', 'default_pull_print_template_id', $template->id, 'integer');

        $this->get(route('finance.requests.print', $request))
            ->assertOk()
            ->assertSee(__('print_templates.print.preview.title'))
            ->assertSee('PUL-000001')
            ->assertSee('Pull Request Receipt');

        $this->get(route('finance.requests.print', ['financeRequest' => $request, 'choose' => 1]))
            ->assertOk()
            ->assertSee(__('finance.print.title'))
            ->assertSee('Pull Request Receipt');
    }

    public function test_finance_settings_currency_and_cash_box_rules(): void
    {
        $this->signIn();

        $localCurrency = FinanceCurrency::query()->where('is_local', true)->firstOrFail();

        Volt::test('settings.finance')
            ->call('editCurrency', $localCurrency->id)
            ->set('currency_rate_input', '12800')
            ->call('saveCurrency')
            ->assertHasNoErrors();

        $localCurrency->refresh();

        $this->assertEqualsWithDelta(1 / 12800, (float) $localCurrency->rate_to_base, 0.000000000001);
        $this->assertSame('12,800', app(FinanceService::class)->currencyRateInput($localCurrency));
        $this->assertSame('1 USD = 12,800 SYP', app(FinanceService::class)->currencyRateLabel($localCurrency));

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

    public function test_finance_manager_can_create_teacher_pull_request_and_teacher_scope_is_limited(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'name' => 'Pull Creator',
            'username' => 'pull-creator',
            'phone' => '0991111666',
        ]);
        $manager->assignRole('manager');

        $teacherUser = User::factory()->create([
            'name' => 'Scoped Pull Teacher',
            'username' => 'scoped-pull-teacher',
            'phone' => '0991111777',
        ]);
        $teacherUser->givePermissionTo('finance.pull-requests.view');
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Scoped',
            'last_name' => 'Teacher',
            'phone' => '0944000922',
            'status' => 'active',
        ]);

        $otherTeacherUser = User::factory()->create([
            'name' => 'Other Scoped Teacher',
            'username' => 'other-scoped-teacher',
            'phone' => '0991111888',
        ]);
        $otherTeacherUser->givePermissionTo('finance.pull-requests.view');
        Teacher::create([
            'user_id' => $otherTeacherUser->id,
            'first_name' => 'Other',
            'last_name' => 'Scoped',
            'phone' => '0944000933',
            'status' => 'active',
        ]);

        $cashBox = FinanceCashBox::query()->firstOrFail();
        $this->actingAs($manager);
        app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => app(FinanceService::class)->localCurrency()->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 60,
            'description' => 'Manager pull request balance',
            'entered_by' => $manager->id,
        ]);

        Volt::test('finance.pull-requests')
            ->set('finance_pull_request_kind_id', FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_COUNT)->firstOrFail()->id)
            ->set('requested_amount', '40')
            ->set('requested_count', '4')
            ->set('teacher_id', $teacher->id)
            ->set('cash_box_id', $cashBox->id)
            ->set('requested_reason', 'Teacher supplies')
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->firstOrFail();

        $this->assertSame(FinanceRequest::STATUS_ACCEPTED, $request->status);
        $this->assertSame($teacher->id, $request->teacher_id);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'signed_amount' => -40,
        ]);
        Volt::test('finance.expense-requests')
            ->assertSee($request->request_no)
            ->assertSee('Count request');

        $this->actingAs($teacherUser);
        Volt::test('finance.pull-requests')->assertSee($request->request_no);

        $this->actingAs($otherTeacherUser);
        Volt::test('finance.pull-requests')->assertDontSee($request->request_no);
    }

    public function test_count_pull_request_settlement_posts_remaining_income(): void
    {
        $this->signIn();

        $cashBox = FinanceCashBox::query()->firstOrFail();
        app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => app(FinanceService::class)->localCurrency()->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 100,
            'description' => 'Count pull balance',
        ]);
        $teacher = Teacher::create([
            'first_name' => 'Count',
            'last_name' => 'Teacher',
            'phone' => '0944000991',
            'status' => 'active',
        ]);

        Volt::test('finance.pull-requests')
            ->set('finance_pull_request_kind_id', FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_COUNT)->firstOrFail()->id)
            ->set('requested_amount', '80')
            ->set('requested_count', '8')
            ->set('teacher_id', $teacher->id)
            ->set('cash_box_id', $cashBox->id)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->firstOrFail();

        Volt::test('finance.pull-requests')
            ->set("settlement_counts.{$request->id}", '7')
            ->set("settlement_remaining_amounts.{$request->id}", '15')
            ->call('settleCount', $request->id)
            ->assertHasNoErrors();

        $request->refresh();

        $this->assertSame(FinanceRequest::STATUS_SETTLED, $request->status);
        $this->assertSame(7, $request->final_count);
        $this->assertSame('15.00', $request->remaining_amount);
        $returnRequest = FinanceRequest::query()
            ->where('type', FinanceRequest::TYPE_RETURN)
            ->where('requested_reason', 'like', '%'.$request->request_no.'%')
            ->firstOrFail();

        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $returnRequest->id,
            'type' => 'pull_request_return',
            'signed_amount' => 15,
        ]);

        Volt::test('finance.revenue-requests')
            ->assertSee($returnRequest->request_no)
            ->assertSee($request->request_no);
    }

    public function test_invoice_pull_request_creates_invoice_and_closes_remaining_money(): void
    {
        $this->signIn();

        $cashBox = FinanceCashBox::query()->firstOrFail();
        app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => app(FinanceService::class)->localCurrency()->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 120,
            'description' => 'Invoice pull balance',
        ]);
        $teacher = Teacher::create([
            'first_name' => 'Invoice',
            'last_name' => 'Teacher',
            'phone' => '0944000992',
            'status' => 'active',
        ]);

        Volt::test('finance.pull-requests')
            ->set('finance_pull_request_kind_id', FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_INVOICE)->firstOrFail()->id)
            ->set('requested_amount', '100')
            ->set('teacher_id', $teacher->id)
            ->set('cash_box_id', $cashBox->id)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->firstOrFail();

        Volt::test('finance.pull-requests')
            ->call('insertInvoice', $request->id)
            ->assertHasNoErrors();

        $invoice = Invoice::query()->where('finance_request_id', $request->id)->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('item_name', 'Printed materials')
            ->set('item_quantity', '2')
            ->set('item_unit_price', '40')
            ->call('saveItem')
            ->assertHasNoErrors()
            ->call('settleLinkedPullRequest')
            ->assertHasNoErrors();

        $request->refresh();

        $this->assertSame(FinanceRequest::STATUS_SETTLED, $request->status);
        $this->assertSame($invoice->id, $request->invoice_id);
        $this->assertSame('20.00', $request->remaining_amount);
        $returnRequest = FinanceRequest::query()
            ->where('type', FinanceRequest::TYPE_RETURN)
            ->where('invoice_id', $invoice->id)
            ->firstOrFail();

        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $returnRequest->id,
            'source_type' => FinanceRequest::class,
            'type' => 'invoice_pull_return',
            'signed_amount' => 20,
        ]);

        Volt::test('finance.revenue-requests')
            ->assertSee($returnRequest->request_no)
            ->assertSee($invoice->invoice_no);
    }

    public function test_invoice_pull_request_closing_extra_amount_appears_in_expense_grid(): void
    {
        $this->signIn();

        $cashBox = FinanceCashBox::query()->firstOrFail();
        app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => app(FinanceService::class)->localCurrency()->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 200,
            'description' => 'Invoice closing balance',
        ]);
        $teacher = Teacher::create([
            'first_name' => 'Closing',
            'last_name' => 'Teacher',
            'phone' => '0944000993',
            'status' => 'active',
        ]);

        Volt::test('finance.pull-requests')
            ->set('finance_pull_request_kind_id', FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_INVOICE)->firstOrFail()->id)
            ->set('requested_amount', '100')
            ->set('teacher_id', $teacher->id)
            ->set('cash_box_id', $cashBox->id)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_PULL)->firstOrFail();

        Volt::test('finance.pull-requests')
            ->call('insertInvoice', $request->id)
            ->assertHasNoErrors();

        $invoice = Invoice::query()->where('finance_request_id', $request->id)->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('item_name', 'Extra materials')
            ->set('item_quantity', '3')
            ->set('item_unit_price', '40')
            ->call('saveItem')
            ->assertHasNoErrors()
            ->call('settleLinkedPullRequest')
            ->assertHasNoErrors();

        $expenseRequest = FinanceRequest::query()
            ->where('type', FinanceRequest::TYPE_EXPENSE)
            ->where('invoice_id', $invoice->id)
            ->firstOrFail();

        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $expenseRequest->id,
            'source_type' => FinanceRequest::class,
            'type' => 'invoice_pull_closing_expense',
            'signed_amount' => -20,
        ]);

        Volt::test('finance.expense-requests')
            ->assertSee($expenseRequest->request_no)
            ->assertSee($invoice->invoice_no);
    }

    public function test_cash_box_cannot_go_below_zero_and_requires_supported_currency(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $localCurrency = $service->localCurrency();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $localCurrency->id,
            'type' => 'manual_adjustment',
            'direction' => 'out',
            'amount' => 1,
            'description' => 'Blocked overdraft',
        ]);
    }

    public function test_pull_request_review_maps_insufficient_balance_to_modal_amount_field(): void
    {
        $this->signIn();

        $teacher = Teacher::create([
            'first_name' => 'Short',
            'last_name' => 'Balance',
            'phone' => '0944000800',
            'status' => 'active',
        ]);
        $currency = app(FinanceService::class)->localCurrency();
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $pullKind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_INVOICE)->firstOrFail();

        $request = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $pullKind->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 100,
            'teacher_id' => $teacher->id,
            'requested_by' => auth()->id(),
            'requested_reason' => 'Needs visible error',
        ]);
        $expectedMessage = __('finance.validation.insufficient_cash_box_balance', [
            'available' => number_format(0, 2),
            'currency' => $currency->code,
            'cash_box' => $cashBox->name,
        ]);

        Volt::test('finance.pull-requests')
            ->call('openReviewModal', $request->id)
            ->set("review_amounts.{$request->id}", '100')
            ->set("review_cash_boxes.{$request->id}", $cashBox->id)
            ->call('accept', $request->id)
            ->assertHasErrors(["review_amounts.{$request->id}"])
            ->assertSee($expectedMessage);

        $this->assertSame(FinanceRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_cash_box_currency_assignment_filters_and_blocks_unsupported_currency(): void
    {
        $this->signIn();

        $cashBox = FinanceCashBox::query()->firstOrFail();
        $localCurrency = app(FinanceService::class)->localCurrency();
        $baseCurrency = app(FinanceService::class)->baseCurrency();

        $cashBox->currencies()->sync([$localCurrency->id]);

        $this->assertTrue(app(FinanceService::class)->currenciesForCashBox($cashBox->id)->whereKey($localCurrency->id)->exists());
        $this->assertFalse(app(FinanceService::class)->currenciesForCashBox($cashBox->id)->whereKey($baseCurrency->id)->exists());

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $baseCurrency->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 10,
            'description' => 'Unsupported currency',
        ]);
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
        $syp->update(['rate_to_base' => 1 / 12800]);
        $secondBox->currencies()->sync([$syp->id]);

        $service->postTransaction([
            'cash_box_id' => $mainBox->id,
            'currency_id' => $usd->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => '2026-01-10',
            'description' => 'USD opening balance',
        ]);

        $toAmount = $service->calculateExchangeToAmount($usd, $syp, 10);

        $this->assertSame(128000.0, $toAmount);
        $this->assertSame('1 USD = 12,800 SYP', $service->exchangeRateLabel((float) $usd->rate_to_base, (float) $syp->rate_to_base, 'USD', 'SYP'));

        $exchange = $service->recordCurrencyExchange(
            $mainBox,
            $usd,
            10,
            $secondBox,
            $syp,
            $toAmount,
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
            'signed_amount' => $toAmount,
        ]);

        $service->recordCashBoxTransfer($secondBox, $mainBox, $syp, 3000, '2026-01-13', auth()->user(), 'Move local cash');

        $balances = $service->cashBoxBalances(auth()->user());
        $mainBalance = $balances->firstWhere('cash_box.id', $mainBox->id);
        $secondBalance = $balances->firstWhere('cash_box.id', $secondBox->id);

        $this->assertSame(90.0, $mainBalance['currencies']->firstWhere('currency.id', $usd->id)['balance']);
        $this->assertSame(3000.0, $mainBalance['currencies']->firstWhere('currency.id', $syp->id)['balance']);
        $this->assertSame(125000.0, $secondBalance['currencies']->firstWhere('currency.id', $syp->id)['balance']);

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
