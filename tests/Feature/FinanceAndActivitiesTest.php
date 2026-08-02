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
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PrintPageSize;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Group;
use App\Models\User;
use App\Services\ActivityAudienceService;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        AppSetting::storeValue('finance', 'expense_request_prefix', 'DBIT', 'string');
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
        $this->assertSame('DBIT-000001', $request->expense_no);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'cash_box_id' => $cashBox->id,
            'type' => 'pull_request',
            'special_transaction_no' => 'DBIT-000001',
            'description' => 'Class materials',
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
                [
                    'type' => 'dynamic_text',
                    'source' => 'finance_request',
                    'field' => 'description',
                    'x' => 5,
                    'y' => 14,
                    'width' => 70,
                    'height' => 10,
                    'z_index' => 2,
                    'styling' => [
                        'font_size' => 3.2,
                        'font_weight' => '500',
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
            ->assertSee('Class materials')
            ->assertSee('Pull Request Receipt');

        $this->get(route('finance.requests.print', ['financeRequest' => $request, 'choose' => 1]))
            ->assertOk()
            ->assertSee(__('finance.print.title'))
            ->assertSee('Pull Request Receipt');

        $localCurrency = app(FinanceService::class)->localCurrency();
        $revenueRequest = FinanceRequest::query()->create([
            'request_no' => app(FinanceService::class)->nextRequestNumber(FinanceRequest::TYPE_REVENUE),
            'type' => FinanceRequest::TYPE_REVENUE,
            'status' => FinanceRequest::STATUS_ACCEPTED,
            'requested_currency_id' => $localCurrency->id,
            'requested_amount' => 75,
            'accepted_currency_id' => $localCurrency->id,
            'accepted_amount' => 75,
            'cash_box_id' => $cashBox->id,
            'requested_by' => $manager->id,
            'reviewed_by' => $manager->id,
            'requested_reason' => 'Returned class balance',
            'accepted_at' => now(),
        ]);

        $revenueTemplate = PrintTemplate::query()->create([
            'name' => 'Revenue Receipt',
            'width_mm' => 100,
            'height_mm' => 60,
            'data_sources' => [
                ['entity' => 'revenue', 'mode' => 'single'],
            ],
            'layout_json' => [
                [
                    'type' => 'shape',
                    'x' => 4,
                    'y' => 4,
                    'width' => 18,
                    'height' => 18,
                    'z_index' => 1,
                    'styling' => [
                        'color' => '#0f7a3d',
                        'shape_type' => 'circle',
                        'fill_opacity' => 0.18,
                    ],
                ],
                [
                    'type' => 'dynamic_text',
                    'source' => 'revenue',
                    'field' => 'request_no',
                    'x' => 10,
                    'y' => 8,
                    'width' => 70,
                    'height' => 8,
                    'z_index' => 2,
                    'styling' => [
                        'font_size' => 4.2,
                        'font_weight' => '700',
                        'color' => '#102316',
                        'text_align' => 'left',
                    ],
                ],
                [
                    'type' => 'date_text',
                    'content' => 'Date {{ date }}',
                    'x' => 10,
                    'y' => 20,
                    'width' => 55,
                    'height' => 8,
                    'z_index' => 3,
                    'styling' => [
                        'font_size' => 3.8,
                        'font_weight' => '500',
                        'color' => '#102316',
                        'text_align' => 'left',
                        'date_mode' => 'today',
                    ],
                ],
                [
                    'type' => 'page_number',
                    'content' => 'Page {{ page_number }}',
                    'x' => 10,
                    'y' => 32,
                    'width' => 40,
                    'height' => 8,
                    'z_index' => 4,
                    'styling' => [
                        'font_size' => 3.8,
                        'font_weight' => '500',
                        'color' => '#102316',
                        'text_align' => 'left',
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        AppSetting::storeValue('finance', 'default_revenue_print_template_id', $revenueTemplate->id, 'integer');

        $this->get(route('finance.requests.print', $revenueRequest))
            ->assertOk()
            ->assertSee('Revenue Receipt')
            ->assertSee($revenueRequest->request_no)
            ->assertSee(now()->format('Y-m-d'))
            ->assertSee('Page 1');

        $this->get(route('finance.requests.print', ['financeRequest' => $revenueRequest, 'choose' => 1]))
            ->assertOk()
            ->assertSee('Revenue Receipt')
            ->assertDontSee(__('finance.print.title'));
    }

    public function test_finance_settings_currency_and_cash_box_rules(): void
    {
        $this->signIn();

        $localCurrency = FinanceCurrency::query()->where('is_local', true)->firstOrFail();

        Volt::test('settings.finance')
            ->set('invoice_prefix', 'alk')
            ->set('transaction_prefix', 'mov')
            ->set('pull_request_prefix', 'wdr')
            ->set('expense_request_prefix', 'cst')
            ->set('revenue_request_prefix', 'inc')
            ->set('return_request_prefix', 'rfd')
            ->set('exchange_prefix', 'fx')
            ->call('saveFinanceSettings')
            ->assertHasNoErrors();

        $financeService = app(FinanceService::class);

        $this->assertSame('ALK-000001', $financeService->nextInvoiceNumber());
        $this->assertSame('WDR-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_PULL));
        $this->assertSame('CST-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_EXPENSE));
        $this->assertSame('INC-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_REVENUE));
        $this->assertSame('RFD-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_RETURN));
        $this->assertSame('FX-000001', $financeService->nextExchangeNumber());

        Volt::test('settings.finance')
            ->call('editCurrency', $localCurrency->id)
            ->set('currency_rate_input', '12800')
            ->set('currency_decimal_places', '0')
            ->call('saveCurrency')
            ->assertHasNoErrors();

        $localCurrency->refresh();

        $this->assertEqualsWithDelta(1 / 12800, (float) $localCurrency->rate_to_base, 0.000000000001);
        $this->assertSame(0, $localCurrency->decimal_places);
        $this->assertSame('12,800', $financeService->currencyRateInput($localCurrency));
        $this->assertSame('1 USD = 12,800 SYP', $financeService->currencyRateLabel($localCurrency));
        $this->assertSame('1,235 SYP', $financeService->formatCurrencyAmount(1234.56, $localCurrency));
        $this->assertSame('-1,235 SYP', $financeService->formatCurrencyAmount(-1234.56, $localCurrency));

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
            ->assertSee(__('finance.pull_modes.count'));

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

    public function test_revenue_requests_support_configurable_revenue_categories(): void
    {
        $this->signIn();

        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = app(FinanceService::class)->localCurrency();
        $category = FinanceCategory::query()->create([
            'code' => 'donations',
            'is_active' => true,
            'name' => 'Donations',
            'type' => 'revenue',
        ]);

        Volt::test('finance.revenue-requests')
            ->set('request_type', FinanceRequest::TYPE_REVENUE)
            ->set('finance_category_id', $category->id)
            ->set('amount', '45')
            ->set('currency_id', $currency->id)
            ->set('cash_box_id', $cashBox->id)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_REVENUE)->firstOrFail();

        $this->assertSame($category->id, $request->finance_category_id);
        $this->assertNull($request->requested_reason);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_category_id' => $category->id,
            'finance_request_id' => $request->id,
            'signed_amount' => 45,
            'type' => 'revenue_request',
        ]);
    }

    public function test_revenue_requests_filter_cash_boxes_and_currencies_by_supported_assignments(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $localCurrency = $service->localCurrency();
        $baseCurrency = $service->baseCurrency();
        $localOnlyBox = FinanceCashBox::query()->firstOrFail();
        $localOnlyBox->currencies()->sync([$localCurrency->id]);

        $baseOnlyBox = FinanceCashBox::query()->create([
            'code' => 'base-only-revenue',
            'is_active' => true,
            'name' => 'Base Only Revenue Box',
        ]);
        $baseOnlyBox->currencies()->sync([$baseCurrency->id]);

        Volt::test('finance.revenue-requests')
            ->call('openCreateModal')
            ->assertSee($localOnlyBox->name)
            ->assertDontSee($baseOnlyBox->name)
            ->set('cash_box_id', $localOnlyBox->id)
            ->assertSee($localCurrency->code)
            ->assertDontSee($baseCurrency->code)
            ->set('currency_id', $baseCurrency->id)
            ->assertSee($baseOnlyBox->name)
            ->assertDontSee($localOnlyBox->name);
    }

    public function test_expense_requests_allow_empty_reason_and_edit_posted_amount_details(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = $service->localCurrency();
        $pullKind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_INVOICE)->firstOrFail();

        $service->postTransaction([
            'amount' => 100,
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'description' => 'Expense edit balance',
            'direction' => 'in',
            'type' => 'manual_adjustment',
        ]);

        Volt::test('finance.expense-requests')
            ->call('openCreateModal')
            ->set('amount', '40')
            ->set('currency_id', $currency->id)
            ->set('cash_box_id', $cashBox->id)
            ->set('finance_pull_request_kind_id', $pullKind->id)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_EXPENSE)->firstOrFail();

        $this->assertNull($request->requested_reason);
        $this->assertSame(FinanceRequest::STATUS_ACCEPTED, $request->status);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'signed_amount' => -40,
        ]);

        Volt::test('finance.expense-requests')
            ->call('openFinanceRequestEditModal', $request->id)
            ->set('edit_amount', '60')
            ->set('edit_currency_id', $currency->id)
            ->set('edit_cash_box_id', $cashBox->id)
            ->set('edit_finance_pull_request_kind_id', $pullKind->id)
            ->set('edit_request_date', '2026-02-10')
            ->set('edit_requested_reason', 'Updated expense')
            ->call('saveFinanceRequestEdit')
            ->assertHasNoErrors();

        $request->refresh();

        $this->assertSame('60.00', $request->requested_amount);
        $this->assertSame('60.00', $request->accepted_amount);
        $this->assertSame('Updated expense', $request->requested_reason);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'signed_amount' => -60,
            'transaction_date' => '2026-02-10 00:00:00',
        ]);
    }

    public function test_finance_revenue_entries_can_be_named_edited_and_reversed(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $localCurrency = $service->localCurrency();
        $secondBox = FinanceCashBox::query()->create([
            'code' => 'secondary-revenue',
            'is_active' => true,
            'name' => 'Secondary revenue box',
        ]);
        $secondBox->currencies()->sync([$localCurrency->id]);
        $category = FinanceCategory::query()->create([
            'code' => 'privacy-donation',
            'is_active' => true,
            'is_donation' => true,
            'name' => 'Privacy donation',
            'type' => FinanceRequest::TYPE_REVENUE,
        ]);

        Volt::test('finance.revenue-requests')
            ->call('openCreateModal')
            ->assertSet('cash_box_id', $service->defaultCashBoxForUser(auth()->user(), $localCurrency->id)?->id)
            ->assertSee('Secondary revenue box')
            ->set('finance_category_id', $category->id)
            ->set('counterparty_name', 'Yazan Al Hamwi')
            ->set('amount', '75')
            ->set('currency_id', $localCurrency->id)
            ->set('cash_box_id', $cashBox->id)
            ->set('request_date', '2026-02-01')
            ->set('requested_reason', 'Private donation')
            ->call('submitRequest')
            ->assertHasNoErrors();

        $request = FinanceRequest::query()->where('type', FinanceRequest::TYPE_REVENUE)->firstOrFail();

        $this->assertSame('Yazan Al Hamwi', $request->counterparty_name);
        $this->assertSame('Y**** A* H****', $request->maskedCounterpartyName());
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'signed_amount' => 75,
            'transaction_date' => '2026-02-01 00:00:00',
        ]);

        Volt::test('finance.revenue-requests')
            ->assertSee('Y**** A* H****')
            ->call('openFinanceRequestEditModal', $request->id)
            ->set('edit_counterparty_name', 'Updated Donor')
            ->set('edit_request_date', '2026-02-05')
            ->set('edit_requested_reason', 'Updated note')
            ->call('saveFinanceRequestEdit')
            ->assertHasNoErrors();

        $request->refresh();

        $this->assertSame('Updated Donor', $request->counterparty_name);
        $this->assertSame('Updated note', $request->requested_reason);
        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $request->id,
            'signed_amount' => 75,
            'transaction_date' => '2026-02-05 00:00:00',
        ]);

        Volt::test('finance.revenue-requests')
            ->call('openFinanceRequestDeleteModal', $request->id)
            ->set('delete_reason', 'Duplicate entry')
            ->call('deleteFinanceRequestEntry')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('finance_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('finance_transactions', [
            'source_type' => FinanceRequest::class,
            'source_id' => $request->id,
            'type' => 'revenue_request_reversal',
            'signed_amount' => -75,
        ]);
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
        $this->assertSame('EXC-000001', $exchange->exchange_no);
        $this->assertSame(2, FinanceTransaction::query()->where('pair_uuid', $exchange->pair_uuid)->count());
        $exchangeTransactions = FinanceTransaction::query()
            ->where('pair_uuid', $exchange->pair_uuid)
            ->orderBy('id')
            ->get();
        $this->assertSame('EXC-000001', data_get($exchangeTransactions[0]->metadata, 'reference'));
        $this->assertSame('EXC-000001', $exchangeTransactions[0]->special_transaction_no);
        $this->assertSame('Test exchange', $exchangeTransactions[0]->description);
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

        Volt::test('finance.exchange')
            ->assertSee('EXC-000001')
            ->assertSee('Test exchange');
    }

    public function test_finance_ledger_report_exports_opening_running_and_closing_balances(): void
    {
        $this->signIn();
        Storage::fake('local');

        $service = app(FinanceService::class);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = $service->localCurrency();
        $template = FinanceReportTemplate::query()->firstOrFail();
        $template->update(['language' => FinanceReportTemplate::LANGUAGE_AR]);

        $service->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => '2026-01-01',
            'description' => 'Opening',
        ]);
        $service->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'type' => 'revenue_request',
            'direction' => 'in',
            'amount' => 75,
            'transaction_date' => '2026-02-01',
            'description' => 'Income',
        ]);
        $service->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'type' => 'expense_request',
            'direction' => 'out',
            'amount' => 20,
            'transaction_date' => '2026-02-02',
            'description' => 'Expense',
        ]);

        $report = app(FinanceReportService::class)->ledgerReport($template, $cashBox, $currency, '2026-02-01', '2026-02-28');
        $rtlExportHtml = view('reports.finance-ledger-pdf-export', [
            'generatedReport' => null,
            'report' => $report,
            'service' => app(FinanceReportService::class),
        ])->render();

        $this->assertSame(100.0, $report['opening_balance']);
        $this->assertSame(75.0, $report['income']);
        $this->assertSame(20.0, $report['expense']);
        $this->assertSame(155.0, $report['closing_balance']);
        $this->assertCount(2, $report['rows']);
        $this->assertSame(175.0, $report['rows'][0]['_running_balance_raw']);
        $this->assertSame(155.0, $report['rows'][1]['_running_balance_raw']);
        $this->assertStringContainsString('dir="rtl"', $rtlExportHtml);
        $this->assertStringContainsString('font-family:dejavusanscondensed;', $rtlExportHtml);

        Volt::test('finance.reports')
            ->assertSee(__('finance.reports.ledger_export_title'))
            ->assertSee(__('finance.reports.generated_reports'))
            ->assertSee($cashBox->name)
            ->assertSee($currency->code);

        $this->get(route('finance.reports.index'))
            ->assertOk()
            ->assertSee('data-searchable="false"', false);

        $pdfResponse = $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'format' => 'pdf',
            'template_id' => $template->id,
        ]));
        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdfResponse->getContent());
        preg_match('/\/Count\s+(\d+)/', (string) $pdfResponse->getContent(), $pdfPageMatches);
        $this->assertLessThanOrEqual(3, (int) ($pdfPageMatches[1] ?? 0));

        $this->assertSame(1, FinanceGeneratedReport::query()->count());

        $generatedReport = FinanceGeneratedReport::query()->latest('id')->firstOrFail();
        $this->assertNotNull($generatedReport->pdf_path);
        Storage::disk('local')->assertExists($generatedReport->pdf_path);

        $legacyReportData = $generatedReport->report_data;
        unset($legacyReportData['pdf_renderer']);
        $generatedReport->forceFill([
            'report_data' => $legacyReportData,
        ])->save();
        Storage::disk('local')->put($generatedReport->pdf_path, 'legacy-pdf');

        $savedPdfResponse = $this->get(route('finance.reports.generated.show', $generatedReport));
        $savedPdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $savedPdfResponse->getContent());
        preg_match('/\/Count\s+(\d+)/', (string) $savedPdfResponse->getContent(), $savedPdfPageMatches);
        $this->assertLessThanOrEqual(3, (int) ($savedPdfPageMatches[1] ?? 0));
        $this->assertNotSame('legacy-pdf', Storage::disk('local')->get($generatedReport->pdf_path));
        $this->assertSame('mpdf-rtl-v2', FinanceGeneratedReport::query()->findOrFail($generatedReport->id)->report_data['pdf_renderer']);

        $this->get(route('finance.reports.generated.show', ['generatedReport' => $generatedReport, 'format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'format' => 'xlsx',
            'template_id' => $template->id,
        ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertSame(2, FinanceGeneratedReport::query()->count());
    }

    public function test_finance_reports_page_and_ledger_export_handle_missing_generated_report_table(): void
    {
        $this->signIn();
        Storage::fake('local');

        $service = app(FinanceService::class);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = $service->localCurrency();
        $template = FinanceReportTemplate::query()->firstOrFail();

        Schema::dropIfExists('finance_generated_reports');

        $this->get(route('finance.reports.index'))
            ->assertOk()
            ->assertSee(__('finance.reports.generated_reports_unavailable'));

        $response = $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'format' => 'pdf',
            'template_id' => $template->id,
        ]));
        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $this->get(route('finance.reports.generated.show', 1))
            ->assertNotFound();
    }

    public function test_finance_generated_report_can_be_deleted_from_reports_page(): void
    {
        $this->signIn();
        Storage::fake('local');

        $service = app(FinanceService::class);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = $service->localCurrency();
        $template = FinanceReportTemplate::query()->firstOrFail();

        $service->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'type' => 'manual_adjustment',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => '2026-03-01',
            'description' => 'Opening balance',
        ]);

        $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
            'format' => 'pdf',
            'template_id' => $template->id,
        ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $generatedReport = FinanceGeneratedReport::query()->latest('id')->firstOrFail();
        $this->assertNotNull($generatedReport->pdf_path);
        Storage::disk('local')->assertExists($generatedReport->pdf_path);

        Volt::test('finance.reports')
            ->assertSee(__('finance.reports.delete_saved_report'))
            ->call('deleteGeneratedReport', $generatedReport->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('finance_generated_reports', [
            'id' => $generatedReport->id,
        ]);
        Storage::disk('local')->assertMissing($generatedReport->pdf_path);

        $this->get(route('finance.reports.generated.show', $generatedReport->id))
            ->assertNotFound();
    }

    public function test_finance_report_template_page_keeps_one_default_template(): void
    {
        $this->signIn();

        Volt::test('settings.finance-report-templates')
            ->call('openTemplateModal')
            ->set('name', 'Arabic ledger')
            ->set('title', 'تقرير الصندوق')
            ->set('language', FinanceReportTemplate::LANGUAGE_AR)
            ->set('is_default', true)
            ->set('header_text', 'رأس التقرير')
            ->set('footer_text', 'تذييل التقرير')
            ->set('custom_text', 'نص مخصص')
            ->set('date_mode', 'custom')
            ->set('custom_date', '2026-02-15')
            ->set('show_issuer_name', false)
            ->set('shape_type', 'circle')
            ->set('shape_color', '#123456')
            ->set('shape_opacity', '0.30')
            ->set('columns', ['transaction_date', 'income', 'expense', 'running_balance'])
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame(1, FinanceReportTemplate::query()->where('is_default', true)->count());
        $this->assertDatabaseHas('finance_report_templates', [
            'name' => 'Arabic ledger',
            'custom_text' => 'نص مخصص',
            'date_mode' => 'custom',
            'header_text' => 'رأس التقرير',
            'is_default' => true,
            'shape_type' => 'circle',
            'show_issuer_name' => false,
        ]);

        $default = FinanceReportTemplate::query()->where('name', 'Arabic ledger')->firstOrFail();
        $this->assertSame(['transaction_date', 'income', 'expense', 'running_balance'], $default->normalizedColumns());
        $this->assertSame('2026-02-15', $default->custom_date?->format('Y-m-d'));

        Volt::test('settings.finance-report-templates')
            ->call('deleteTemplate', $default->id)
            ->assertHasNoErrors();

        $this->assertSame(1, FinanceReportTemplate::query()->where('is_default', true)->count());
    }

    public function test_finance_report_template_editor_defaults_invalid_legacy_values(): void
    {
        $this->signIn();

        $template = FinanceReportTemplate::query()->firstOrFail();

        DB::table('finance_report_templates')
            ->where('id', $template->id)
            ->update([
                'date_mode' => 'legacy',
            ]);

        Volt::test('settings.finance-report-templates')
            ->call('editTemplate', $template->id)
            ->assertSet('date_mode', 'exported_at')
            ->assertSet('show_issuer_name', true)
            ->assertSet('show_page_numbers', false);
    }

    public function test_print_page_sizes_are_managed_from_organization_settings(): void
    {
        $this->signIn();

        Volt::test('settings.organization')
            ->call('openPrintPageSizeModal')
            ->set('print_page_size_name', 'Compact A5')
            ->set('print_page_width_mm', '148')
            ->set('print_page_height_mm', '210')
            ->set('print_margin_top_mm', '8')
            ->set('print_margin_right_mm', '7')
            ->set('print_margin_bottom_mm', '8')
            ->set('print_margin_left_mm', '7')
            ->set('print_gap_x_mm', '4')
            ->set('print_gap_y_mm', '5')
            ->set('print_page_size_is_default', true)
            ->call('savePrintPageSize')
            ->assertHasNoErrors();

        $this->assertSame(1, PrintPageSize::query()->where('is_default', true)->count());
        $this->assertDatabaseHas('print_page_sizes', [
            'name' => 'Compact A5',
            'is_default' => true,
        ]);
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

    public function test_fund_transfers_have_the_configured_prefix_and_do_not_affect_operating_totals(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $from = FinanceCashBox::query()->firstOrFail();
        $to = FinanceCashBox::query()->create(['code' => 'transfer-target', 'name' => 'Transfer target', 'is_active' => true]);
        $to->currencies()->sync([$currency->id]);
        AppSetting::storeValue('finance', 'transfer_prefix', 'MOVE', 'string');

        $service->postTransaction([
            'cash_box_id' => $from->id,
            'currency_id' => $currency->id,
            'type' => 'opening_balance',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => now()->toDateString(),
        ]);

        $beforeTransfer = app(FinanceReportService::class)->report((int) now()->year, (int) now()->quarter)['summary'];
        $transfer = $service->recordCashBoxTransfer($from, $to, $currency, 25, now()->toDateString(), auth()->user(), 'Rebalance funds');
        $report = app(FinanceReportService::class)->report((int) now()->year, (int) now()->quarter);

        $this->assertSame('MOVE-000001', $transfer->transfer_no);
        $this->assertSame($beforeTransfer['expense'], $report['summary']['expense']);
        $this->assertSame($beforeTransfer['income'], $report['summary']['income']);
        $this->assertSame(2, FinanceTransaction::query()->where('special_transaction_no', $transfer->transfer_no)->count());
    }

    public function test_count_expense_finalisation_edits_the_original_expense_without_posting_income(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $kind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_COUNT)->firstOrFail();
        $category = FinanceCategory::query()->whereIn('type', ['expense', 'management'])->firstOrFail();

        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'finance_category_id' => $category->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 60,
            'requested_count' => 6,
            'requested_by' => auth()->id(),
            'requested_reason' => 'Supplies',
        ]);

        $request = $service->acceptRequest($request, 60, $fund, auth()->user(), null, 6);
        $transactionId = $request->posted_transaction_id;
        $service->finaliseCountExpense($request, 5, 15, auth()->user());

        $this->assertSame(FinanceRequest::STATUS_SETTLED, $request->fresh()->status);
        $this->assertSame('45.00', $request->fresh()->accepted_amount);
        $this->assertDatabaseHas('finance_transactions', ['id' => $transactionId, 'signed_amount' => -45]);
        $this->assertSame(2, FinanceTransaction::query()->count());
    }

    public function test_deleted_transactions_remain_auditable_but_are_excluded_from_active_balances(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $transaction = $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 80]);

        $service->deleteTransactionRecord($transaction, auth()->user(), 'Incorrect entry');

        $this->assertSame(0, FinanceTransaction::query()->count());
        $this->assertDatabaseHas('finance_transactions', ['id' => $transaction->id, 'status' => 'deleted', 'deletion_reason' => 'Incorrect entry']);
        $this->assertSame(0.0, (float) $service->cashBoxBalances(auth()->user())->first()['currencies']->first()['balance']);
    }

    public function test_invoice_expense_finalisation_uses_the_locked_invoice_total(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $kind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_INVOICE)->firstOrFail();
        $category = FinanceCategory::query()->whereIn('type', ['expense', 'management'])->firstOrFail();
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'finance_category_id' => $category->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 40,
            'requested_by' => auth()->id(),
            'requested_reason' => 'Invoice supplies',
        ]);
        $request = $service->acceptRequest($request, 40, $fund, auth()->user());
        $invoice = Invoice::query()->create([
            'invoice_no' => $service->nextInvoiceNumber(),
            'original_invoice_no' => 'VENDOR-10',
            'invoicer_name' => 'Vendor',
            'invoice_type' => 'finance',
            'finance_request_id' => $request->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 55,
            'discount' => 0,
            'total' => 55,
        ]);

        $service->finaliseInvoiceExpense($request, $invoice, auth()->user());

        $this->assertSame(FinanceRequest::STATUS_SETTLED, $request->fresh()->status);
        $this->assertSame('55.00', $request->fresh()->accepted_amount);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->finalised_at);
        $this->assertDatabaseHas('finance_transactions', ['id' => $request->posted_transaction_id, 'signed_amount' => -55]);
    }

    public function test_withdrawal_navigation_is_available_to_teachers_but_hidden_from_finance_admins(): void
    {
        $this->seed();
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        Teacher::query()->create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Withdrawal',
            'last_name' => 'Teacher',
            'phone' => '0944000999',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser)->get(route('finance.pull-requests.index'))->assertOk();
        $teacherItems = collect(app(\App\Services\SidebarNavigationService::class)->sidebarFor($teacherUser))->pluck('items')->flatten(1);
        $this->assertTrue($teacherItems->contains(fn (array $item) => $item['key'] === 'finance_pull_requests'));
        $this->assertFalse($teacherItems->contains(fn (array $item) => $item['key'] === 'finance_dashboard'));

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $managerItems = collect(app(\App\Services\SidebarNavigationService::class)->sidebarFor($manager))->pluck('items')->flatten(1);
        $this->assertFalse($managerItems->contains(fn (array $item) => $item['key'] === 'finance_pull_requests'));
        $this->assertTrue($managerItems->contains(fn (array $item) => $item['key'] === 'finance_dashboard'));
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
