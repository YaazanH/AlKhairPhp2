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
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceInvoiceKind;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceReportTemplate;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\ParentProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PrintPageSize;
use App\Models\PrintTemplate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ActivityAudienceService;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use App\Services\SidebarNavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Mpdf\Mpdf;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;
use ZipArchive;

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
            'type' => 'income',
            'signed_amount' => 20,
        ]);
        $this->assertDatabaseHas('finance_transactions', [
            'activity_id' => $activity->id,
            'type' => 'expense',
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
            'type' => 'expense',
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
            ->set('notes', 'This must not be stored for a new invoice')
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
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->due_date);
        $this->assertNull($invoice->notes);

        $systemInvoiceNumber = $invoice->invoice_no;
        $invoiceItem = $invoice->items()->firstOrFail();
        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('maintenanceMode', true)
            ->assertDontSee('wire:model="invoice_no"', false)
            ->assertSee('wire:model="invoice_deduction"', false)
            ->assertSee(__('finance.actions.add'))
            ->assertDontSee('<p class="admin-section-card__copy">'.__('invoices.detail.tables.items.title').'</p>', false)
            ->call('editItem', $invoiceItem->id)
            ->assertSee(__('crud.common.actions.save'))
            ->call('cancelItem')
            ->set('invoice_no', 'INV-MUST-NOT-CHANGE')
            ->set('original_invoice_no', 'ORIGINAL-22')
            ->set('invoicer_name', 'Updated supplies store')
            ->set('issue_date', '2026-11-04')
            ->set('invoice_deduction', '12')
            ->set('invoice_notes', 'Updated invoice basics')
            ->call('saveInvoiceBasics')
            ->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame($systemInvoiceNumber, $invoice->invoice_no);
        $this->assertSame('12.00', $invoice->discount);
        $this->assertSame('18.00', $invoice->total);

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('invoice_deduction', '10')
            ->call('saveDeduction')
            ->assertHasNoErrors();

        $this->assertSame('10.00', $invoice->fresh()->discount);
        $this->assertSame('20.00', $invoice->fresh()->total);

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->set('payment_method_id', $paymentMethod->id)
            ->set('paid_at', '2026-11-03')
            ->set('payment_amount', '20')
            ->call('savePayment')
            ->assertHasNoErrors();

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status);
        $this->assertDatabaseHas('finance_transactions', [
            'source_type' => Payment::class,
            'type' => 'income',
            'signed_amount' => 20,
        ]);

        $payment = Payment::query()->firstOrFail();

        Volt::test('invoices.payments', ['invoice' => $invoice])
            ->call('voidPayment', $payment->id);

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseHas('finance_transactions', [
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'type' => 'expense',
            'signed_amount' => -20,
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
            'type' => 'expense',
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
            ->assertDontSee('Pull Request Receipt');

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
            'width_mm' => 80,
            'height_mm' => 60,
            'paper_size' => 'a6',
            'orientation' => 'portrait',
            'margin_top_mm' => 7,
            'margin_right_mm' => 8,
            'margin_bottom_mm' => 9,
            'margin_left_mm' => 6,
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
            ->assertDontSee('Revenue Receipt')
            ->assertSee($revenueRequest->request_no)
            ->assertSee(now()->format('d-m-Y'))
            ->assertSee('Page 1')
            ->assertSee('width: 105mm', false)
            ->assertSee('padding: 7mm 8mm 9mm 6mm', false);

        $this->get(route('finance.requests.print', ['financeRequest' => $revenueRequest, 'auto_print' => 1]))
            ->assertOk()
            ->assertSee('data-auto-print', false)
            ->assertSee("window.addEventListener('load', () => window.print()", false)
            ->assertDontSee('<div class="print-template-toolbar">', false);

        $this->get(route('finance.requests.print', ['financeRequest' => $revenueRequest, 'choose' => 1]))
            ->assertOk()
            ->assertDontSee('Revenue Receipt')
            ->assertDontSee(__('finance.print.title'));
    }

    public function test_finance_settings_currency_and_cash_box_rules(): void
    {
        $this->signIn();

        $localCurrency = FinanceCurrency::query()->where('is_local', true)->firstOrFail();

        Volt::test('settings.finance')
            ->call('openCurrencyModal')
            ->assertSee('data-finance-currency-checkbox-box', false)
            ->assertSee('data-finance-currency-checkbox-grid', false)
            ->assertSee('sm:grid-cols-2 lg:grid-cols-3', false)
            ->assertSee('إظهار في القوائم');

        $financeSettingsSource = file_get_contents(resource_path('views/livewire/settings/finance.blade.php'));
        $this->assertStringContainsString("app()->isLocale('ar') ? 'إظهار في القوائم' : 'Show in lists'", $financeSettingsSource);
        $this->assertStringNotContainsString('إظهار العملة في القوائم المنسدلة', $financeSettingsSource);
        $this->assertLessThan(
            strpos($financeSettingsSource, 'wire:model="currency_is_active"'),
            strpos($financeSettingsSource, 'wire:model="currency_is_local"'),
            'The Local Currency checkbox should occupy the former Active position.',
        );

        Volt::test('settings.finance')
            ->set('invoice_prefix', 'alk')
            ->set('transaction_prefix', 'mov')
            ->set('pull_request_prefix', 'wdr')
            ->set('expense_request_prefix', 'cst')
            ->set('revenue_request_prefix', 'inc')
            ->set('exchange_prefix', 'fx')
            ->set('report_prefix', 'rpt')
            ->call('saveFinanceSettings')
            ->assertHasNoErrors();

        $financeService = app(FinanceService::class);

        $this->assertSame('ALK-000001', $financeService->nextInvoiceNumber());
        $this->assertSame('WDR-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_PULL));
        $this->assertSame('CST-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_EXPENSE));
        $this->assertSame('INC-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_REVENUE));
        $this->assertSame('RET-000001', $financeService->nextRequestNumber(FinanceRequest::TYPE_RETURN));
        $this->assertSame('FX-000001', $financeService->nextExchangeNumber());
        $this->assertSame('RPT', app(FinanceReportService::class)->reportPrefix());
        $this->assertFalse(Route::has('finance.reports.export'));

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
            ->where('posted_transaction_id', $request->return_transaction_id)
            ->firstOrFail();

        $this->assertDatabaseHas('finance_transactions', [
            'finance_request_id' => $returnRequest->id,
            'type' => 'return',
            'signed_amount' => 15,
        ]);

        Volt::test('finance.revenue-requests')
            ->assertSee($returnRequest->request_no);
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
            'type' => 'income',
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
            ->assertSee('admin-modal__dialog--3xl', false)
            ->assertSee('data-finance-entry-create-form', false)
            ->assertSee('data-income-request-save', false)
            ->assertSee('data-icon-name="save"', false)
            ->assertDontSee('data-create-and-new-action', false)
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
            ->assertSee('admin-modal__dialog--3xl', false)
            ->assertSee('data-finance-entry-amount', false)
            ->assertSee('data-finance-entry-date', false)
            ->assertSee('data-finance-entry-fund', false)
            ->assertSee('data-finance-entry-description', false)
            ->assertSee('data-finance-entry-attachments', false)
            ->assertSee('data-expense-request-save', false)
            ->assertSee('data-icon-name="save"', false)
            ->assertDontSee('data-create-and-new-action', false)
            ->set('amount', '40')
            ->set('currency_id', $currency->id)
            ->set('cash_box_id', $cashBox->id)
            ->set('finance_pull_request_kind_id', $pullKind->id)
            ->call('submitRequest')
            ->assertHasNoErrors()
            ->assertSet('showCreateModal', false);

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

        $ledgerOnlyExpense = $service->postTransaction([
            'amount' => 5,
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'description' => 'Ledger-only expense',
            'direction' => 'out',
            'finance_category_id' => $pullKind->id,
            'transaction_date' => '2026-02-11',
            'type' => 'expense',
        ]);

        Volt::test('finance.expense-requests')
            ->assertSee('Updated expense')
            ->assertSee('Ledger-only expense')
            ->assertSee($ledgerOnlyExpense->transaction_no);
    }

    public function test_expenses_can_use_any_active_fund_and_active_dropdown_currency(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $fund = FinanceCashBox::query()->firstOrFail();
        $localCurrency = $service->localCurrency();
        $otherCurrency = FinanceCurrency::query()
            ->where('is_active', true)
            ->where('show_in_dropdowns', true)
            ->whereKeyNot($localCurrency->id)
            ->firstOrFail();
        $pullKind = FinancePullRequestKind::query()->where('is_active', true)->firstOrFail();

        $fund->currencies()->syncWithoutDetaching([$otherCurrency->id]);
        $service->postTransaction([
            'amount' => 100,
            'cash_box_id' => $fund->id,
            'currency_id' => $otherCurrency->id,
            'direction' => 'in',
            'type' => 'opening_balance',
        ]);
        $fund->currencies()->sync([$localCurrency->id]);

        Volt::test('finance.expense-requests')
            ->call('openCreateModal')
            ->assertSee($fund->name)
            ->assertSee($otherCurrency->code)
            ->set('amount', '25')
            ->set('currency_id', $otherCurrency->id)
            ->set('cash_box_id', $fund->id)
            ->set('finance_pull_request_kind_id', $pullKind->id)
            ->call('submitRequest')
            ->assertHasNoErrors();

        $this->assertTrue($fund->currencies()->whereKey($otherCurrency->id)->exists());
        $this->assertDatabaseHas('finance_transactions', [
            'cash_box_id' => $fund->id,
            'currency_id' => $otherCurrency->id,
            'signed_amount' => -25,
            'type' => 'expense',
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
            ->set('finance_category_id', $category->id)
            ->assertSee('data-donor-row-compact="true"', false)
            ->assertDontSee(__('finance.messages.revenue_name_mask_help'))
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
            ->assertSee('data-income-direct-print', false)
            ->assertSee('auto_print=1', false)
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

        $ledger = app(FinanceReportService::class)->ledgerReport(
            FinanceReportTemplate::query()->firstOrFail(),
            $cashBox,
            $localCurrency,
            '2026-02-01',
            '2026-02-28',
            auth()->user(),
        );
        $this->assertStringStartsWith('U****** D**** — ', $ledger['rows'][0]['description']);

        Volt::test('finance.revenue-requests')
            ->call('openFinanceRequestDeleteModal', $request->id)
            ->set('delete_reason', 'Duplicate entry')
            ->call('deleteFinanceRequestEntry')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('finance_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('finance_transactions', [
            'source_type' => FinanceRequest::class,
            'source_id' => $request->id,
            'type' => 'expense',
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
            'type' => 'return',
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
            'type' => 'expense',
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

        $this->expectException(ValidationException::class);

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

        $this->expectException(ValidationException::class);

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
        $manualToAmount = 127000.0;

        $exchange = $service->recordCurrencyExchange(
            $mainBox,
            $usd,
            10,
            $secondBox,
            $syp,
            $manualToAmount,
            '2026-01-12',
            auth()->user(),
            'Test exchange',
        );

        $this->assertInstanceOf(FinanceCurrencyExchange::class, $exchange);
        $this->assertSame('EXC-000001', $exchange->exchange_no);
        $this->assertSame('1 USD = 12,700 SYP', $service->exchangeRateLabel((float) $exchange->from_rate_to_base, (float) $exchange->to_rate_to_base, 'USD', 'SYP'));
        $this->assertSame(2, FinanceTransaction::query()->where('pair_uuid', $exchange->pair_uuid)->count());
        $exchangeTransactions = FinanceTransaction::query()
            ->where('pair_uuid', $exchange->pair_uuid)
            ->orderBy('id')
            ->get();
        $this->assertSame('EXC-000001', data_get($exchangeTransactions[0]->metadata, 'reference'));
        $this->assertSame('EXC-000001', $exchangeTransactions[0]->special_transaction_no);
        $this->assertSame('[خارج] Test exchange', $exchangeTransactions[0]->description);
        $this->assertDatabaseHas('finance_transactions', [
            'cash_box_id' => $mainBox->id,
            'currency_id' => $usd->id,
            'type' => 'exchange',
            'signed_amount' => -10,
        ]);
        $this->assertDatabaseHas('finance_transactions', [
            'cash_box_id' => $secondBox->id,
            'currency_id' => $syp->id,
            'type' => 'exchange',
            'signed_amount' => $manualToAmount,
        ]);

        $service->recordCashBoxTransfer($secondBox, $mainBox, $syp, 3000, '2026-01-13', auth()->user(), 'Move local cash');

        $balances = $service->cashBoxBalances(auth()->user());
        $mainBalance = $balances->firstWhere('cash_box.id', $mainBox->id);
        $secondBalance = $balances->firstWhere('cash_box.id', $secondBox->id);

        $this->assertSame(90.0, $mainBalance['currencies']->firstWhere('currency.id', $usd->id)['balance']);
        $this->assertSame(3000.0, $mainBalance['currencies']->firstWhere('currency.id', $syp->id)['balance']);
        $this->assertSame(124000.0, $secondBalance['currencies']->firstWhere('currency.id', $syp->id)['balance']);

        $report = app(FinanceReportService::class)->report(2026, 1);

        $this->assertGreaterThan(0, $report['summary']['transactions']);
        $this->assertNotEmpty($report['quarter_totals']);
        $this->assertCount(4, $report['previous_year_quarter_totals']);

        Volt::test('finance.dashboard')
            ->assertSee('data-finance-dashboard-details', false)
            ->assertSee('aria-label="'.__('finance.actions.details', [], 'ar').'"', false)
            ->set('showQuarterDetailsModal', true)
            ->assertSeeText(__('finance.dashboard.quarter_expense_comparison', [], 'ar'))
            ->assertSee('finance-quarter-chart__legend', false)
            ->assertSee('finance-quarter-chart__tooltip', false)
            ->assertSeeText('2025');

        Volt::test('finance.exchange')
            ->assertSee('EXC-000001')
            ->assertSee('Test exchange')
            ->assertSee('dir="ltr" data-exchange-total-amount', false)
            ->assertViewHas('toCurrencies', fn ($currencies) => $currencies->doesntContain('id', $usd->id) && $currencies->contains('id', $syp->id))
            ->assertDontSeeText(__('finance.exchange.rate_board_title'));
    }

    public function test_quarter_comparison_converts_mixed_currencies_to_the_local_currency(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $fund = FinanceCashBox::query()->firstOrFail();
        $baseCurrency = $service->baseCurrency();
        $localCurrency = $service->localCurrency();
        $localCurrency->update(['rate_to_base' => 1 / 100]);

        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $baseCurrency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 10, 'transaction_date' => now()->toDateString()]);
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $localCurrency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 1000, 'transaction_date' => now()->toDateString()]);
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $baseCurrency->id, 'type' => 'mixed_currency_expense', 'direction' => 'out', 'amount' => 2, 'transaction_date' => now()->toDateString()]);
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $localCurrency->id, 'type' => 'mixed_currency_expense', 'direction' => 'out', 'amount' => 100, 'transaction_date' => now()->toDateString()]);

        $report = app(FinanceReportService::class)->report((int) now()->year, (int) now()->quarter);
        $quarter = collect($report['quarter_totals'])->firstWhere('quarter', now()->quarter);

        $this->assertSame($localCurrency->id, $report['summary']['local_currency']->id);
        $this->assertSame(300.0, $quarter['expense']);
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

        AppSetting::storeValue('finance', 'report_prefix', 'RPT');
        $report = app(FinanceReportService::class)->ledgerReport($template, $cashBox, $currency, '2026-02-01', '2026-02-28', auth()->user(), 'Only for this ledger');
        $rtlExportHtml = view('reports.finance-ledger-pdf-export', [
            'generatedReport' => null,
            'report' => $report,
            'service' => app(FinanceReportService::class),
        ])->render();

        $this->assertSame(100.0, $report['opening_balance']);
        $this->assertSame(75.0, $report['income']);
        $this->assertSame(20.0, $report['expense']);
        $this->assertSame(155.0, $report['closing_balance']);
        $this->assertSame('Only for this ledger', $report['notes']);
        $this->assertSame('RPT', $report['report_prefix']);
        $this->assertCount(2, $report['rows']);
        $this->assertSame(175.0, $report['rows'][0]['_running_balance_raw']);
        $this->assertSame(155.0, $report['rows'][1]['_running_balance_raw']);
        $this->assertStringContainsString('dir="rtl"', $rtlExportHtml);
        $this->assertStringNotContainsString('size: A4 portrait', $rtlExportHtml);
        $this->assertStringContainsString('تقرير مالي', $rtlExportHtml);
        $this->assertStringContainsString('سري وهام - غير معد للمداولة', $rtlExportHtml);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $rtlExportHtml);
        $this->assertStringNotContainsString('type="QR"', $rtlExportHtml);
        $this->assertStringContainsString('font-family: code39', $rtlExportHtml);
        $this->assertStringContainsString('*RPT-000000*', $rtlExportHtml);
        $this->assertStringNotContainsString('type="C39"', $rtlExportHtml);
        $this->assertStringContainsString('RPT-000000', $rtlExportHtml);
        $this->assertStringNotContainsString('background-image-resize', $rtlExportHtml);
        $this->assertStringContainsString('.summary td { border: 0;', $rtlExportHtml);
        $this->assertStringContainsString('.summary { border-collapse: collapse;', $rtlExportHtml);
        $this->assertStringContainsString('table-layout:fixed; width:100%;', $rtlExportHtml);
        $this->assertStringContainsString('class="stamp-block"', $rtlExportHtml);
        $this->assertStringNotContainsString('signature-line', $rtlExportHtml);
        $this->assertStringContainsString('max-height:40mm; max-width:40mm', $rtlExportHtml);
        $this->assertStringContainsString('.signature-block { text-align:center; vertical-align:bottom; width:50%; }', $rtlExportHtml);
        $this->assertStringContainsString('إجمالي المصاريف', $rtlExportHtml);
        $this->assertStringContainsString('إجمالي الإيرادات', $rtlExportHtml);
        $this->assertStringContainsString('class="debit-value"', $rtlExportHtml);
        $this->assertStringContainsString('class="credit-value"', $rtlExportHtml);
        $this->assertStringContainsString('@page { margin: 0 12mm 18mm; margin-header: 0;', $rtlExportHtml);
        $this->assertStringNotContainsString('continuation-header-gap', $rtlExportHtml);
        $this->assertSame(AcademicYear::query()->where('is_current', true)->value('name'), $report['academic_year']);
        $this->assertStringContainsString('العام الأكاديمي', $rtlExportHtml);
        $this->assertStringNotContainsString('<td class="meta-label">الدورة</td>', $rtlExportHtml);
        $this->assertStringContainsString('.meta-wrap { background: transparent; margin: 0 0 1.65mm -1mm; padding: 0; }', $rtlExportHtml);
        $this->assertStringContainsString('.meta-qr { direction: ltr !important; padding-left: 0 !important; text-align: left !important; width: 15mm; }', $rtlExportHtml);
        $this->assertStringContainsString("output(\$qrCode, 56, 'transparent', 'black')", file_get_contents(resource_path('views/reports/finance-ledger-pdf-export.blade.php')));
        $this->assertStringContainsString('.meta-qr img { display: block; height: 6.3mm; margin: 0; width: 6.3mm; }', $rtlExportHtml);
        $this->assertStringContainsString('.meta-table { table-layout: fixed; }', $rtlExportHtml);
        $this->assertStringContainsString('white-space: nowrap;', $rtlExportHtml);
        $this->assertStringContainsString('<colgroup><col style="width:9%"><col style="width:22%"><col style="width:9%"><col style="width:20%"><col style="width:9%"><col style="width:23%"><col style="width:8%"></colgroup>', $rtlExportHtml);
        $this->assertStringNotContainsString('border-bottom: 1px solid #bad1be;', $rtlExportHtml);
        $this->assertStringContainsString('border-bottom: 3px double #9fbea5;', $rtlExportHtml);
        $this->assertStringContainsString('background: rgba(220, 239, 220, .4);', $rtlExportHtml);
        $this->assertStringContainsString('.ledger td.date, .ledger td.money { vertical-align: middle; }', $rtlExportHtml);
        $this->assertStringContainsString('.ledger-page-gap th { background: transparent; border: 0; height: 2.1mm;', $rtlExportHtml);
        $this->assertStringContainsString('<tr class="ledger-page-gap"><th colspan="5"></th></tr>', $rtlExportHtml);
        $this->assertStringContainsString('.title { color: #164d27; font-size: 20pt;', $rtlExportHtml);
        $this->assertStringContainsString('class="footer-notice"><span>سري وهام - غير معد للمداولة</span></td>', $rtlExportHtml);
        $this->assertStringContainsString('padding-right: 0 !important; text-align: right;', $rtlExportHtml);
        $this->assertStringContainsString('padding-left: 0 !important; text-align: left;', $rtlExportHtml);
        $this->assertStringContainsString('.footer-table td { background: #dcefdc; border: 0; height: 8mm; padding: 0;', $rtlExportHtml);
        $this->assertStringNotContainsString('class="notice"', $rtlExportHtml);
        $this->assertStringContainsString('<th>الوصف</th>', $rtlExportHtml);
        $this->assertStringNotContainsString('التصنيف والوصف', $rtlExportHtml);
        $this->assertStringNotContainsString('statement-gap', $rtlExportHtml);

        $reportWithBackground = $report;
        $reportWithBackground['template']['background_image_pdf_src'] = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $reportWithBackground['rows'] = collect(range(1, 50))->map(fn (int $index): array => array_merge($report['rows'][$index % 2], ['transaction_date' => sprintf('%02d-02-2026', ($index % 28) + 1)]))->all();
        $reportWithoutBackground = $reportWithBackground;
        $reportWithoutBackground['template']['background_image_pdf_src'] = null;
        $plainPdf = app(FinanceReportService::class)->renderLedgerPdf($reportWithoutBackground);
        $backgroundPdf = app(FinanceReportService::class)->renderLedgerPdf($reportWithBackground);
        $this->assertStringStartsWith('%PDF', $backgroundPdf);
        $this->assertGreaterThan(1000, strlen($backgroundPdf));
        $pdfInspector = new Mpdf(['tempDir' => storage_path('app/mpdf')]);
        $smallPageCount = $pdfInspector->setSourceFile(StreamReader::createByString(app(FinanceReportService::class)->renderLedgerPdf($report)));
        $plainPageCount = $pdfInspector->setSourceFile(StreamReader::createByString($plainPdf));
        $backgroundPageCount = $pdfInspector->setSourceFile(StreamReader::createByString($backgroundPdf));
        $this->assertGreaterThanOrEqual(2, $backgroundPageCount);
        $this->assertLessThan(10, $backgroundPageCount, 'small='.$smallPageCount.', plain='.$plainPageCount.', background='.$backgroundPageCount);
        $this->assertStringNotContainsString('ملخص التقرير المالي', $rtlExportHtml);
        $this->assertStringContainsString('Only for this ledger', $rtlExportHtml);

        Volt::test('finance.reports')
            ->call('openCreateReport')
            ->assertDontSee(__('finance.reports.ledger_export_title'))
            ->assertSee(__('finance.reports.generated_reports'))
            ->assertSee($cashBox->name)
            ->assertDontSee('<select wire:model.live="ledger_currency_id"', false);

        $this->get(route('finance.reports.index'))
            ->assertOk()
            ->assertSee('wire:click="openCreateReport"', false)
            ->assertSee('wire:click="openReportSettings"', false)
            ->assertSee('financial-report-symbol-button', false)
            ->assertSee('data-finance-report-generate-action', false)
            ->assertSee('financial-report-generate-button', false)
            ->assertDontSee('<span class="text-xl font-semibold text-white">', false);

        $financeReportStyles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString(".financial-report-symbol-button {\n    align-items: center;\n    aspect-ratio: 1 / 1;\n    border-radius: 0.85rem;", $financeReportStyles);

        $pdfResponse = $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'ledger_notes' => 'Quarter-specific note',
            'format' => 'pdf',
        ]));
        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdfResponse->getContent());
        $this->assertGreaterThan(1000, strlen((string) $pdfResponse->getContent()));

        $this->assertSame(1, FinanceGeneratedReport::query()->count());

        $generatedReport = FinanceGeneratedReport::query()->latest('id')->firstOrFail();
        $this->assertSame('Quarter-specific note', data_get($generatedReport->report_data, 'notes'));
        $this->assertSame('RPT', data_get($generatedReport->report_data, 'report_prefix'));
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
        $this->assertGreaterThan(1000, strlen((string) $savedPdfResponse->getContent()));
        $this->assertNotSame('legacy-pdf', Storage::disk('local')->get($generatedReport->pdf_path));
        $this->assertSame('mpdf-fixed-ledger-v27', FinanceGeneratedReport::query()->findOrFail($generatedReport->id)->report_data['pdf_renderer']);

        $this->get(route('finance.reports.generated.show', ['generatedReport' => $generatedReport, 'format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'format' => 'xlsx',
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors('format');

        $this->assertSame(1, FinanceGeneratedReport::query()->count());
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
        ]));
        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $this->get(route('finance.reports.generated.show', 1))
            ->assertNotFound();
    }

    public function test_finance_reports_table_paginates_every_saved_report_ten_at_a_time(): void
    {
        $this->signIn();

        foreach (range(1, 11) as $reportNumber) {
            $report = FinanceGeneratedReport::query()->create([
                'report_type' => 'ledger',
                'filters' => [
                    'date_from' => '2026-01-01',
                    'date_to' => '2026-03-31',
                    'cash_box_name' => 'Pagination fund',
                    'currency_code' => 'SYP',
                ],
                'report_data' => [
                    'original_report_number' => 'PAGE-REPORT-'.$reportNumber,
                    'issuer_name' => 'Pagination manager',
                ],
                'generated_by' => auth()->id(),
            ]);
            $report->forceFill([
                'created_at' => now()->startOfDay()->addMinutes($reportNumber),
                'updated_at' => now()->startOfDay()->addMinutes($reportNumber),
            ])->saveQuietly();
        }

        Volt::test('finance.reports')
            ->assertSee('PAGE-REPORT-11')
            ->assertSee('PAGE-REPORT-2')
            ->assertDontSee('>PAGE-REPORT-1</div>', false)
            ->call('setPage', 2, 'generatedReportsPage')
            ->assertSee('>PAGE-REPORT-1</div>', false)
            ->assertDontSee('PAGE-REPORT-11');
    }

    public function test_generated_financial_report_quarters_are_hidden_and_cannot_be_generated_twice(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = $service->localCurrency();

        foreach (['2026-02-15', '2026-05-15'] as $transactionDate) {
            $service->postTransaction([
                'cash_box_id' => $cashBox->id,
                'currency_id' => $currency->id,
                'type' => 'manual_adjustment',
                'direction' => 'in',
                'amount' => 10,
                'transaction_date' => $transactionDate,
                'description' => 'Quarter availability fixture',
            ], auth()->user());
        }

        FinanceGeneratedReport::query()->create([
            'report_type' => 'ledger',
            'filters' => [
                'date_from' => '2026-01-01',
                'date_to' => '2026-03-31',
                'period_mode' => 'quarter',
            ],
            'report_data' => [],
            'generated_by' => auth()->id(),
        ]);

        $this->assertSame([
            ['year' => 2026, 'quarters' => [2]],
        ], app(FinanceReportService::class)->availableUnreportedLedgerPeriods(auth()->user())->all());

        Volt::test('finance.reports')
            ->call('openCreateReport')
            ->assertSet('ledger_year', 2026)
            ->assertSet('ledger_quarter', '2')
            ->assertSee('data-unreported-ledger-years', false)
            ->assertSee('data-unreported-ledger-quarters', false)
            ->assertSee('>Q2</option>', false)
            ->assertDontSee('>Q1</option>', false);

        $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-03-31',
            'period_mode' => 'quarter',
            'format' => 'pdf',
        ]))
            ->assertStatus(422)
            ->assertSee(__('finance.reports.period_already_generated'));

        $this->assertSame(1, FinanceGeneratedReport::query()->count());
    }

    public function test_financial_report_generation_requires_the_current_users_signature(): void
    {
        $this->signIn();
        $user = auth()->user();
        Storage::disk('public')->delete((string) $user->finance_signature_path);
        $user->forceFill(['finance_signature_path' => null])->save();

        $cashBox = FinanceCashBox::query()->firstOrFail();

        Volt::test('finance.reports')
            ->call('openCreateReport')
            ->assertHasErrors('createReport');

        $this->get(route('finance.reports.ledger.export', [
            'cash_box_id' => $cashBox->id,
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-28',
            'format' => 'pdf',
        ]))->assertStatus(422);
    }

    public function test_finance_settings_can_import_old_reports_until_uploading_is_finished(): void
    {
        $this->signIn();
        Storage::fake('local');

        $component = Volt::test('settings.finance')
            ->set('legacy_report_pdf', UploadedFile::fake()->create('old-report.pdf', 100, 'application/pdf'))
            ->set('legacy_report_number', 'OLD-0042')
            ->set('legacy_report_period_mode', 'quarter')
            ->set('legacy_report_year', 2024)
            ->set('legacy_report_quarter', '3')
            ->set('legacy_report_cash_box', 'Old fund')
            ->set('legacy_report_currency', 'USD')
            ->set('legacy_report_generated_at', '2024-04-01')
            ->call('importLegacyReport')
            ->assertHasNoErrors();

        $report = FinanceGeneratedReport::query()->firstOrFail();
        $this->assertSame('OLD-0042', data_get($report->report_data, 'original_report_number'));
        $this->assertTrue((bool) data_get($report->report_data, 'imported_legacy'));
        $this->assertSame('2024-07-01', data_get($report->filters, 'date_from'));
        $this->assertSame('2024-09-30', data_get($report->filters, 'date_to'));
        $this->assertSame('Q3-2024', app(FinanceReportService::class)->savedReportPeriodLabel($report));
        Storage::disk('local')->assertExists($report->pdf_path);

        $component->call('finishLegacyReportImport')->assertHasNoErrors();
        $this->assertTrue((bool) AppSetting::groupValues('finance')->get('legacy_report_import_finished'));
    }

    public function test_finance_settings_accept_numeric_pre_2023_reports_and_delete_them_by_original_number(): void
    {
        $this->signIn();
        Storage::fake('local');

        Volt::test('settings.finance')
            ->set('legacy_report_pdf', UploadedFile::fake()->create('report-0042.pdf', 100, 'application/pdf'))
            ->set('legacy_report_number', '0042')
            ->set('legacy_report_period_mode', 'quarter')
            ->set('legacy_report_year', 2022)
            ->set('legacy_report_quarter', '4')
            ->set('legacy_report_cash_box', 'Legacy fund')
            ->set('legacy_report_currency', 'SYP')
            ->set('legacy_report_generated_at', '2022-12-31')
            ->call('importLegacyReport')
            ->assertHasNoErrors();

        $report = FinanceGeneratedReport::query()->firstOrFail();
        $this->assertSame('0042', data_get($report->report_data, 'original_report_number'));
        $this->assertSame('2022-10-01', data_get($report->filters, 'date_from'));
        $this->assertSame('2022-12-31', data_get($report->filters, 'date_to'));
        Storage::disk('local')->assertExists($report->pdf_path);

        Volt::test('settings.finance')
            ->set('report_lookup_no', '0042')
            ->call('deleteGeneratedReport')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('finance_generated_reports', ['id' => $report->id]);
        Storage::disk('local')->assertMissing($report->pdf_path);
    }

    public function test_multi_fund_ledger_is_saved_and_can_be_reopened(): void
    {
        $this->signIn();
        Storage::fake('local');

        $firstFund = FinanceCashBox::query()->firstOrFail();
        $secondFund = FinanceCashBox::query()->create(['name' => 'Second report fund', 'code' => 'RPT-SECOND', 'is_active' => true]);

        $response = $this->get(route('finance.reports.ledger.export', [
            'cash_box_ids' => [$firstFund->id, $secondFund->id],
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'format' => 'pdf',
        ]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $generated = FinanceGeneratedReport::query()->latest('id')->firstOrFail();
        $expectedStatements = max(1, $firstFund->currencies()->count()) + max(1, $secondFund->currencies()->count());
        $this->assertCount($expectedStatements, $generated->report_data['fund_reports']);
        $this->assertCount($expectedStatements, collect($generated->report_data['fund_reports'])->unique(fn (array $statement) => data_get($statement, 'cash_box.name').'|'.data_get($statement, 'currency.code')));
        $this->assertSame([$firstFund->id, $secondFund->id], $generated->filters['cash_box_ids']);
        $this->assertNotNull($generated->pdf_path);
        Storage::disk('local')->assertExists($generated->pdf_path);

        $this->get(route('finance.reports.generated.show', $generated))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_finance_generated_report_can_only_be_deleted_from_finance_settings(): void
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
        ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $generatedReport = FinanceGeneratedReport::query()->latest('id')->firstOrFail();
        $this->assertNotNull($generatedReport->pdf_path);
        Storage::disk('local')->assertExists($generatedReport->pdf_path);

        Volt::test('finance.reports')
            ->assertDontSee(__('finance.reports.delete_saved_report'));

        Volt::test('settings.finance')
            ->assertSee(__('finance.settings.generated_report_maintenance'))
            ->set('report_lookup_no', 'FINR-'.str_pad((string) $generatedReport->id, 6, '0', STR_PAD_LEFT))
            ->call('deleteGeneratedReport')
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

    public function test_finance_reports_store_fixed_ledger_background_and_logo_in_modal(): void
    {
        $this->signIn();
        Storage::fake('public');

        Volt::test('finance.reports')
            ->call('openReportSettings')
            ->assertSet('showReportSettingsModal', true)
            ->assertDontSee('data-finance-report-settings-close-action', false)
            ->assertSee('data-finance-report-settings-save-action', false)
            ->assertSee('data-icon-name="save"', false)
            ->set('report_background_upload', UploadedFile::fake()->image('background.jpg', 1200, 1600))
            ->set('report_logo_upload', UploadedFile::fake()->image('logo.png', 300, 120))
            ->set('report_stamp_upload', UploadedFile::fake()->image('stamp.png', 300, 300))
            ->call('saveReportSettings')
            ->assertSet('showReportSettingsModal', false)
            ->assertHasNoErrors();

        $settings = app(FinanceReportService::class)->defaultLedgerTemplate()->fresh();
        $this->assertSame('تقرير مالي', $settings->title);
        $this->assertSame('Financial ledger', $settings->name);
        $this->assertNull($settings->custom_text);
        $this->assertSame(FinanceReportTemplate::LANGUAGE_AR, $settings->language);
        $this->assertSame(FinanceReportTemplate::DEFAULT_COLUMNS, $settings->normalizedColumns());
        $this->assertTrue($settings->show_page_numbers);
        Storage::disk('public')->assertExists($settings->background_image);
        Storage::disk('public')->assertExists($settings->logo_image);
        Storage::disk('public')->assertExists(AppSetting::groupValues('finance')->get('report_stamp_path'));
        $this->assertFalse(Route::has('settings.finance.report-templates'));
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
        $this->assertSame('[خارج] Rebalance funds', FinanceTransaction::query()->where('pair_uuid', $transfer->pair_uuid)->where('direction', 'out')->value('description'));
        $this->assertSame('[داخل] Rebalance funds', FinanceTransaction::query()->where('pair_uuid', $transfer->pair_uuid)->where('direction', 'in')->value('description'));

        $out = FinanceTransaction::query()->where('pair_uuid', $transfer->pair_uuid)->where('direction', 'out')->firstOrFail();
        $service->updateTransaction($out, [
            'amount' => 30,
            'cash_box_id' => $from->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $out->finance_category_id,
            'description' => 'Updated transfer',
            'direction' => 'out',
            'entered_by' => auth()->id(),
            'special_transaction_no' => 'MOVE-000009',
            'transaction_date' => now()->toDateString(),
            'type' => 'transfer',
        ], auth()->user());

        $this->assertSame('30.00', $transfer->fresh()->amount);
        $this->assertSame('MOVE-000009', $transfer->fresh()->transfer_no);
        $this->assertTrue(FinanceTransaction::query()->where('pair_uuid', $transfer->pair_uuid)->get()->every(
            fn (FinanceTransaction $transaction) => $transaction->amount === '30.00' && $transaction->special_transaction_no === 'MOVE-000009'
        ));
        $this->assertSame('[خارج] Updated transfer', FinanceTransaction::query()->where('pair_uuid', $transfer->pair_uuid)->where('direction', 'out')->value('description'));
        $this->assertSame('[داخل] Updated transfer', FinanceTransaction::query()->where('pair_uuid', $transfer->pair_uuid)->where('direction', 'in')->value('description'));
    }

    public function test_exchange_ledger_rows_use_direction_markers_and_edits_sync_to_the_exchange(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $fromCurrency = $service->baseCurrency();
        $toCurrency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $fund->currencies()->syncWithoutDetaching([$fromCurrency->id, $toCurrency->id]);
        $service->postTransaction([
            'cash_box_id' => $fund->id,
            'currency_id' => $fromCurrency->id,
            'type' => 'income',
            'direction' => 'in',
            'amount' => 100,
        ]);

        $exchange = $service->recordCurrencyExchange(
            $fund,
            $fromCurrency,
            10,
            $fund,
            $toCurrency,
            $service->calculateExchangeToAmount($fromCurrency, $toCurrency, 10),
            '2026-08-10',
            auth()->user(),
            'Test exchange',
        );
        $out = FinanceTransaction::query()->where('pair_uuid', $exchange->pair_uuid)->where('direction', 'out')->firstOrFail();
        $in = FinanceTransaction::query()->where('pair_uuid', $exchange->pair_uuid)->where('direction', 'in')->firstOrFail();

        $this->assertSame('[خارج] Test exchange', $out->description);
        $this->assertSame('[داخل] Test exchange', $in->description);

        $service->updateTransaction($out, [
            'amount' => 12,
            'cash_box_id' => $fund->id,
            'currency_id' => $fromCurrency->id,
            'finance_category_id' => $out->finance_category_id,
            'description' => 'Updated exchange',
            'direction' => 'out',
            'entered_by' => auth()->id(),
            'special_transaction_no' => $exchange->exchange_no,
            'transaction_date' => '2026-08-11',
            'type' => 'exchange',
        ], auth()->user());

        $this->assertSame('12.00', $exchange->fresh()->from_amount);
        $this->assertSame('Updated exchange', $exchange->fresh()->notes);
        $this->assertSame('[داخل] Updated exchange', $in->fresh()->description);
        $this->assertSame('2026-08-11', $exchange->fresh()->exchange_date?->toDateString());
    }

    public function test_transaction_maintenance_finds_withdrawal_number_and_syncs_the_expense_source(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $category = FinanceCategory::query()->where('type', 'expense')->firstOrFail();
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'income', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $category->id,
            'finance_category_id' => $category->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 20,
            'requested_reason' => 'Original reason',
            'requested_by' => auth()->id(),
        ]);
        $request = $service->acceptRequest($request, 20, $fund, auth()->user(), null, 1, '2026-08-01');
        $originalExpenseNo = $request->expense_no;

        Volt::test('settings.finance')
            ->assertSee('data-transaction-maintenance-search-action', false)
            ->set('transaction_lookup_no', $request->request_no)
            ->call('findTransaction')
            ->assertSet('maintaining_transaction_id', $request->posted_transaction_id)
            ->assertSee('data-transaction-maintenance-save-action', false)
            ->set('maint_amount', '25')
            ->set('maint_description', 'Updated reason')
            ->set('maint_special_transaction_no', 'EXP-000999')
            ->set('maint_transaction_date', '2026-08-02')
            ->call('saveTransactionMaintenance')
            ->assertHasNoErrors()
            ->assertSet('maintaining_transaction_id', null)
            ->assertSee(__('finance.messages.transaction_updated'));

        $request->refresh();
        $this->assertSame('25.00', $request->accepted_amount);
        $this->assertSame('25.00', $request->requested_amount);
        $this->assertSame($originalExpenseNo, $request->expense_no);
        $this->assertSame('Updated reason', $request->requested_reason);
        $this->assertSame($originalExpenseNo, $request->postedTransaction->fresh()->special_transaction_no);
    }

    public function test_historical_finance_source_repair_uses_the_ledger_as_its_base(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $category = FinanceCategory::query()->where('type', 'expense')->firstOrFail();
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'income', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => 'PUL-HISTORICAL-1',
            'expense_no' => 'EXP-HISTORICAL-1',
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_ACCEPTED,
            'finance_pull_request_kind_id' => $category->id,
            'finance_category_id' => $category->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 10,
            'accepted_currency_id' => $currency->id,
            'accepted_amount' => 10,
            'cash_box_id' => $fund->id,
            'requested_reason' => 'Stale source reason',
        ]);
        $transaction = $service->postTransaction([
            'cash_box_id' => $fund->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $category->id,
            'finance_request_id' => $request->id,
            'source_type' => FinanceRequest::class,
            'source_id' => $request->id,
            'type' => 'expense',
            'direction' => 'out',
            'amount' => 18,
            'special_transaction_no' => 'EXP-000888',
            'description' => 'Correct ledger reason',
        ]);
        $request->update(['posted_transaction_id' => $transaction->id]);

        $migration = require database_path('migrations/2026_08_10_000000_sync_finance_sources_from_ledger.php');
        $migration->up();

        $request->refresh();
        $this->assertSame('18.00', $request->requested_amount);
        $this->assertSame('18.00', $request->accepted_amount);
        $this->assertSame('EXP-000888', $request->expense_no);
        $this->assertSame('Correct ledger reason', $request->requested_reason);
        $this->assertSame($transaction->id, $request->posted_transaction_id);
    }

    public function test_invoice_scan_reference_fields_are_saved_and_system_number_is_protected(): void
    {
        $this->signIn();
        Storage::fake('public');

        Volt::test('invoices.index')
            ->call('create')
            ->assertDontSee('wire:model="invoice_no"', false);

        Volt::test('invoices.index')
            ->set('invoice_no', 'INV-CUSTOM-1')
            ->set('original_invoice_no', 'PAPER-44')
            ->set('invoicer_name', 'Original issuer')
            ->set('issue_date', '2026-08-10')
            ->set('status', 'draft')
            ->set('discount', '0')
            ->set('invoice_scan', UploadedFile::fake()->image('invoice-scan.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::query()->where('original_invoice_no', 'PAPER-44')->firstOrFail();
        $systemInvoiceNumber = $invoice->invoice_no;
        $this->assertNotSame('INV-CUSTOM-1', $systemInvoiceNumber);
        Storage::disk('public')->assertExists($invoice->original_image_path);

        Volt::test('invoices.index')
            ->call('edit', $invoice->id)
            ->assertDontSee('wire:model="invoice_no"', false)
            ->set('invoice_no', 'INV-CUSTOM-2')
            ->set('original_invoice_no', 'PAPER-45')
            ->set('invoicer_name', 'Updated issuer')
            ->call('save')
            ->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame($systemInvoiceNumber, $invoice->invoice_no);
        $this->assertSame('PAPER-45', $invoice->original_invoice_no);
        $this->assertSame('Updated issuer', $invoice->invoicer_name);
    }

    public function test_legacy_expenses_are_finalised_and_renumbered_with_the_configured_prefix(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $kind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_COUNT)->firstOrFail();
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_EXPENSE),
            'type' => FinanceRequest::TYPE_EXPENSE,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 20,
            'requested_by' => auth()->id(),
        ]);
        $request = $service->acceptRequest($request, 20, $fund, auth()->user(), null, 1);
        $this->assertStringStartsWith('EXP-', $request->expense_no);

        AppSetting::storeValue('finance', 'expense_request_prefix', 'DBIT');
        $migration = require database_path('migrations/2026_08_02_020000_finalise_and_renumber_legacy_expenses.php');
        $migration->up();

        $request->refresh();
        $this->assertSame(FinanceRequest::STATUS_SETTLED, $request->status);
        $this->assertSame('DBIT-000001', $request->expense_no);
        $this->assertNotNull($request->settled_at);
        $this->assertSame('DBIT-000001', $request->postedTransaction->fresh()->special_transaction_no);
    }

    public function test_existing_incomes_returns_and_exchanges_are_renumbered_from_settings(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $revenueCategory = FinanceCategory::query()->where('type', FinanceRequest::TYPE_REVENUE)->firstOrFail();
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 100]);

        $revenue = FinanceRequest::query()->create([
            'request_no' => 'OLD-INCOME-9',
            'type' => FinanceRequest::TYPE_REVENUE,
            'status' => FinanceRequest::STATUS_ACCEPTED,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 25,
            'accepted_currency_id' => $currency->id,
            'accepted_amount' => 25,
            'cash_box_id' => $fund->id,
            'finance_category_id' => $revenueCategory->id,
        ]);
        $revenueTransaction = $service->postTransaction([
            'cash_box_id' => $fund->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $revenueCategory->id,
            'finance_request_id' => $revenue->id,
            'type' => 'revenue_request',
            'special_transaction_no' => $revenue->request_no,
            'direction' => 'in',
            'amount' => 25,
        ]);
        $revenue->update(['posted_transaction_id' => $revenueTransaction->id]);

        $return = FinanceRequest::query()->create([
            'request_no' => 'OLD-RETURN-4',
            'type' => FinanceRequest::TYPE_RETURN,
            'status' => FinanceRequest::STATUS_ACCEPTED,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 10,
            'accepted_currency_id' => $currency->id,
            'accepted_amount' => 10,
            'cash_box_id' => $fund->id,
            'finance_category_id' => null,
        ]);
        $returnTransaction = $service->postTransaction([
            'cash_box_id' => $fund->id,
            'currency_id' => $currency->id,
            'finance_request_id' => $return->id,
            'type' => 'return_request',
            'special_transaction_no' => $return->request_no,
            'direction' => 'in',
            'amount' => 10,
        ]);
        $return->update(['posted_transaction_id' => $returnTransaction->id]);

        $pairUuid = (string) Str::uuid();
        $exchange = FinanceCurrencyExchange::query()->create([
            'pair_uuid' => $pairUuid,
            'exchange_no' => 'OLD-EXCHANGE-7',
            'from_cash_box_id' => $fund->id,
            'to_cash_box_id' => $fund->id,
            'from_currency_id' => $currency->id,
            'to_currency_id' => $currency->id,
            'from_amount' => 5,
            'to_amount' => 5,
            'from_rate_to_base' => $currency->rate_to_base,
            'to_rate_to_base' => $currency->rate_to_base,
            'base_amount' => 5,
            'local_amount' => 5,
            'exchange_date' => now()->toDateString(),
        ]);

        foreach (['out', 'in'] as $direction) {
            $service->postTransaction([
                'cash_box_id' => $fund->id,
                'currency_id' => $currency->id,
                'source_type' => FinanceCurrencyExchange::class,
                'source_id' => $exchange->id,
                'type' => 'currency_exchange',
                'special_transaction_no' => $exchange->exchange_no,
                'direction' => $direction,
                'amount' => 5,
                'pair_uuid' => $pairUuid,
                'metadata' => ['exchange_no' => $exchange->exchange_no, 'reference' => $exchange->exchange_no],
            ]);
        }

        AppSetting::storeValue('finance', 'revenue_request_prefix', 'CRDT');
        AppSetting::storeValue('finance', 'return_request_prefix', 'RTRN');
        AppSetting::storeValue('finance', 'exchange_prefix', 'XCHG');

        $migration = require database_path('migrations/2026_08_02_030000_renumber_incomes_exchanges_and_categorize_returns.php');
        $migration->up();

        $returnCategory = FinanceCategory::query()->where('code', 'return')->firstOrFail();
        $this->assertSame('إرجاع', $returnCategory->name);
        $this->assertSame('CRDT-000001', $revenue->fresh()->request_no);
        $this->assertSame('CRDT-000001', $revenueTransaction->fresh()->special_transaction_no);
        $this->assertSame('RTRN-000001', $return->fresh()->request_no);
        $this->assertSame($returnCategory->id, $return->fresh()->finance_category_id);
        $this->assertSame('RTRN-000001', $returnTransaction->fresh()->special_transaction_no);
        $this->assertSame($returnCategory->id, $returnTransaction->fresh()->finance_category_id);
        $this->assertSame('XCHG-000001', $exchange->fresh()->exchange_no);

        $exchangeTransactions = FinanceTransaction::query()->where('pair_uuid', $pairUuid)->get();
        $this->assertCount(2, $exchangeTransactions);
        $this->assertTrue($exchangeTransactions->every(fn (FinanceTransaction $transaction) => $transaction->special_transaction_no === 'XCHG-000001'));
        $this->assertTrue($exchangeTransactions->every(fn (FinanceTransaction $transaction) => data_get($transaction->metadata, 'reference') === 'XCHG-000001'));
    }

    public function test_finance_dashboard_limits_latest_activity_and_exposes_category_percentage(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $service->postTransaction([
            'cash_box_id' => $fund->id,
            'currency_id' => $currency->id,
            'type' => 'opening_balance',
            'direction' => 'in',
            'amount' => 100,
            'transaction_date' => now()->toDateString(),
        ]);

        foreach (range(1, 5) as $index) {
            $transaction = $service->postTransaction([
                'cash_box_id' => $fund->id,
                'currency_id' => $currency->id,
                'type' => 'manual_expense_'.$index,
                'direction' => 'out',
                'amount' => $index,
                'transaction_date' => now()->toDateString(),
                'description' => 'Dashboard expense '.$index,
            ]);
            $transaction->update(['local_amount' => -$index]);
        }

        $report = app(FinanceReportService::class)->report((int) now()->year, (int) now()->quarter);
        $this->assertCount(4, $report['latest_transactions']);
        $this->assertCount(4, $report['previous_year_quarter_totals']);

        foreach (range(1, 12) as $index) {
            FinanceRequest::query()->create([
                'request_no' => sprintf('WDR-METRIC-%03d', $index),
                'type' => FinanceRequest::TYPE_PULL,
                'status' => FinanceRequest::STATUS_PENDING,
                'requested_currency_id' => $currency->id,
                'requested_amount' => $index,
                'requested_by' => auth()->id(),
            ]);
        }

        foreach ([FinanceRequest::STATUS_ACCEPTED, FinanceRequest::STATUS_DECLINED] as $index => $status) {
            FinanceRequest::query()->create([
                'request_no' => sprintf('WDR-HISTORY-%03d', $index + 1),
                'type' => FinanceRequest::TYPE_PULL,
                'status' => $status,
                'requested_currency_id' => $currency->id,
                'requested_amount' => 20 + $index,
                'requested_by' => auth()->id(),
            ]);
        }

        Volt::test('finance.dashboard')
            ->assertViewHas('pendingRequests', fn ($requests) => $requests->count() === 10)
            ->assertSee('finance-dashboard-header-action', false)
            ->assertSeeInOrder([
                __('finance.dashboard.pending_withdrawals'),
                'data-finance-dashboard-request-history',
                'data-finance-dashboard-new-request',
                __('finance.dashboard.quarter_totals'),
                'data-finance-dashboard-details',
                __('finance.dashboard.latest_activity'),
                'data-view-all-expand',
            ], false)
            ->assertDontSee('data-finance-dashboard-pending-count', false)
            ->assertDontSee('data-finance-dashboard-quarter-year', false)
            ->assertDontSee('data-finance-dashboard-latest-count', false)
            ->assertSee('% ·', false)
            ->assertDontSee('تظهر فقط الطلبات التي تنتظر المراجعة.')
            ->assertDontSee('Only requests awaiting review are shown.')
            ->assertSee('lg:grid-cols-[minmax(0,.8fr)_minmax(17rem,1.2fr)]', false)
            ->assertSee('xl:h-[22rem] xl:w-[22rem]', false)
            ->set('showQuarterDetailsModal', true)
            ->assertSee('data-quarter-chart-step', false)
            ->assertSee('data-quarter-chart-maximum', false)
            ->assertSee('finance-quarter-chart h-80', false)
            ->assertSee('admin-modal__dialog--3xl', false)
            ->set('showQuarterDetailsModal', false)
            ->set('showRequestHistoryModal', true)
            ->assertSee('data-withdrawal-history-table', false)
            ->assertSee('data-settings-record-table', false)
            ->assertSee('admin-modal__dialog--compact', false)
            ->assertViewHas('requestHistory', fn ($requests) => $requests->perPage() === 8 && $requests->total() === 2)
            ->assertSee(__('finance.common.accepted'))
            ->assertSee(__('finance.common.refused'))
            ->assertSee('withdrawal-history-status', false)
            ->assertSee('data-withdrawal-history-datetime', false)
            ->set('showRequestHistoryModal', false)
            ->set('showTransactionsModal', true)
            ->assertSee('data-financial-transactions-table', false)
            ->assertSee('financial-transactions-table', false)
            ->assertSee('data-finance-transaction-datetime', false)
            ->assertSee(__('reports.filters.date_from'))
            ->assertSee(__('reports.filters.date_to'))
            ->assertViewHas('transactions', fn ($transactions) => $transactions->perPage() === 8)
            ->set('showTransactionsModal', false)
            ->set('showTransferModal', true)
            ->assertSee('data-finance-amount-input', false)
            ->assertSee('data-clearable="false"', false)
            ->assertSee('data-finance-currency-required="true"', false)
            ->assertSee('data-search-placeholder=""', false)
            ->assertSee('data-finance-dashboard-transfer-action', false)
            ->assertSee('data-icon-name="transfer"', false)
            ->set('showTransferModal', false)
            ->set('showCreateRequestModal', true)
            ->assertSee('data-finance-amount-input', false)
            ->assertSee('data-finance-dashboard-create-request-save', false)
            ->assertSee('data-icon-name="save"', false)
            ->assertDontSee('class="pill-link pill-link--accent">'.__('finance.actions.create'), false);

        $financeTableCss = file_get_contents(resource_path('css/app.css'));
        $financeDashboardSource = file_get_contents(resource_path('views/livewire/finance/dashboard.blade.php'));
        $this->assertSame(3, substr_count($financeDashboardSource, 'data-finance-dashboard-inline-header'));
        $this->assertStringContainsString('data-finance-dashboard-period-filters', $financeDashboardSource);
        $this->assertStringContainsString('<label for="finance-dashboard-year" class="sr-only">', $financeDashboardSource);
        $this->assertStringContainsString('<label for="finance-dashboard-quarter" class="sr-only">', $financeDashboardSource);
        $financeReportsSource = file_get_contents(resource_path('views/livewire/finance/reports.blade.php'));
        $this->assertStringContainsString('wire:model.live="ledger_period_mode" class="h-[3.125rem] min-h-[3.125rem] w-full rounded-xl px-4 py-3 text-sm" data-ledger-period-mode', $financeReportsSource);
        $this->assertStringNotContainsString('wire:model.live="ledger_period_mode" data-searchable="false"', $financeReportsSource);
        $this->assertStringContainsString(".finance-dashboard-period-filters {\n    width: fit-content;\n    grid-template-columns: repeat(2, minmax(0, 6rem));", $financeTableCss);
        $this->assertStringContainsString(".finance-dashboard-period-filters > * {\n    width: 6rem;", $financeTableCss);
        $this->assertStringContainsString('.finance-dashboard .finance-dashboard-table-header {', $financeTableCss);
        $this->assertStringContainsString('flex-wrap: nowrap;', $financeTableCss);
        $this->assertStringContainsString('align-items: center;', $financeTableCss);
        $this->assertStringNotContainsString('[data-finance-generic-table] thead {', $financeTableCss);
        $this->assertStringContainsString('.admin-modal__dialog:has([data-withdrawal-history-table])', $financeTableCss);
        $this->assertStringContainsString('.admin-modal__dialog:has([data-financial-transactions-table])', $financeTableCss);
        $this->assertStringContainsString(".financial-transactions-table {\n    width: 100%;\n    min-width: 0;", $financeTableCss);
        $this->assertStringContainsString('.finance-transaction-datetime {', $financeTableCss);
        $this->assertStringContainsString('width: calc(100vw - 1rem);', $financeTableCss);
        $this->assertStringContainsString('<col style="width: 3%"><col style="width: 7.25%"><col style="width: 10.7%"><col style="width: 9.05%"><col style="width: 5.8%"><col style="width: 20.25%">', $financeDashboardSource);
        $this->assertStringContainsString('<th class="px-2 py-3 text-center">#</th>', $financeDashboardSource);
        $this->assertStringContainsString('{{ $transactions->firstItem() + $loop->index }}', $financeDashboardSource);
        $this->assertStringContainsString('<div class="finance-transaction-reference"><div class="finance-transaction-primary" aria-label="{{ $specialTransactionNumber }}">', $financeDashboardSource);
        $this->assertSame(6, substr_count($financeDashboardSource, 'wire:key="finance-transactions-filter-'));
        $this->assertStringContainsString('justify-content: space-between;', $financeTableCss);
        $this->assertStringContainsString('@foreach (mb_str_split($specialTransactionNumber) as $referenceCharacter)', $financeDashboardSource);
        $this->assertStringContainsString('@foreach (mb_str_split($generalTransactionNumber) as $referenceCharacter)', $financeDashboardSource);
        $this->assertSame(2, substr_count($financeDashboardSource, 'finance-transaction-secondary mt-1 text-xs text-neutral-500'));
        $this->assertStringNotContainsString("transaction_date?->format('d-m-Y') }}</span><span>{{ \$transaction->created_at?->format('H:i')", $financeDashboardSource);
        $this->assertStringContainsString(".withdrawal-history-status {\n    width: 5.5rem;", $financeTableCss);
        $this->assertStringContainsString('data-date-placeholder="{{ __(\'reports.filters.date_from\') }}"', $financeDashboardSource);
        $this->assertStringContainsString('data-date-placeholder="{{ __(\'reports.filters.date_to\') }}"', $financeDashboardSource);
        $this->assertStringContainsString("->whereIn('status', [FinanceRequest::STATUS_ACCEPTED, FinanceRequest::STATUS_DECLINED, FinanceRequest::STATUS_SETTLED])", $financeDashboardSource);
        $this->assertStringContainsString('grid-template-columns: 5.5rem minmax(0, 1fr);', $financeTableCss);
        $this->assertStringContainsString("html[dir='rtl'] .finance-amount-input__currency + .searchable-select {", $financeTableCss);
        $this->assertStringContainsString("html[dir='rtl'] .finance-amount-input__currency + .searchable-select .searchable-select__search {", $financeTableCss);
        $this->assertStringContainsString('.finance-amount-input__currency + .searchable-select .searchable-select__search--trigger::placeholder {', $financeTableCss);
        $this->assertStringContainsString('.finance-amount-input__currency + .searchable-select.searchable-select--invalid .searchable-select__search--trigger {', $financeTableCss);
        $this->assertStringContainsString('.finance-currency-save-disabled {', $financeTableCss);
        $this->assertStringContainsString(".searchable-select__clear {\n    position: absolute;\n    inset-inline-end: 1rem;\n    top: 50%;", $financeTableCss);
        $this->assertStringContainsString('.finance-amount-input__currency + .searchable-select .searchable-select__option {', $financeTableCss);
        $this->assertStringContainsString('.finance-amount-input__currency + .searchable-select .searchable-select__chevron--input {', $financeTableCss);
    }

    public function test_count_expense_finalisation_edits_the_original_expense_without_posting_income(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $kind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_COUNT)->firstOrFail();

        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'finance_category_id' => $kind->id,
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

    public function test_editing_an_expense_category_mode_changes_the_finalisation_popup(): void
    {
        $this->signIn();

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $category = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_COUNT)->firstOrFail();

        $service->postTransaction([
            'cash_box_id' => $fund->id,
            'currency_id' => $currency->id,
            'type' => 'opening_balance',
            'direction' => 'in',
            'amount' => 100,
        ]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_EXPENSE),
            'type' => FinanceRequest::TYPE_EXPENSE,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $category->id,
            'finance_category_id' => $category->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 20,
            'requested_count' => 2,
            'requested_by' => auth()->id(),
        ]);
        $request = $service->acceptRequest($request, 20, $fund, auth()->user(), acceptedCount: 2);

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->assertSee('data-count-finalisation-form', false)
            ->assertDontSee('data-invoice-finalisation-form', false);

        Volt::test('settings.finance')
            ->call('editFinanceCategory', $category->id)
            ->assertSet('finance_category_type', 'expense')
            ->assertSet('finance_category_mode', FinancePullRequestKind::MODE_COUNT)
            ->set('finance_category_mode', FinancePullRequestKind::MODE_INVOICE)
            ->call('saveFinanceCategory')
            ->assertHasNoErrors();

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->assertSee('data-invoice-finalisation-form', false)
            ->assertDontSee('data-count-finalisation-form', false);

        $this->assertSame('Pending', __('finance.statuses.accepted', locale: 'en'));
        $this->assertSame('Pending', __('finance.expense_statuses.accepted', locale: 'en'));
        $this->assertSame('معلق', __('finance.statuses.accepted', locale: 'ar'));
        $this->assertSame('معلق', __('finance.expense_statuses.accepted', locale: 'ar'));
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

    public function test_deleting_an_expense_deletes_its_withdrawal_request_and_does_not_reuse_finance_numbers(): void
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
            'requested_amount' => 25,
            'requested_by' => auth()->id(),
        ]);
        $request = $service->acceptRequest($request, 25, $fund, auth()->user());
        $expense = FinanceTransaction::query()->findOrFail($request->posted_transaction_id);
        $oldRequestNumber = $request->request_no;
        $oldExpenseNumber = $request->expense_no;
        $oldTransactionNumber = $expense->transaction_no;

        $service->deleteTransactionRecord($expense, auth()->user(), 'Remove linked expense');

        $this->assertSoftDeleted('finance_requests', ['id' => $request->id]);
        $this->assertSoftDeleted('finance_transactions', ['id' => $expense->id]);
        $this->assertNotSame($oldRequestNumber, $service->nextRequestNumber(FinanceRequest::TYPE_PULL));
        $this->assertNotSame($oldExpenseNumber, $service->nextExpenseNumber());

        $newTransaction = $service->postTransaction([
            'cash_box_id' => $fund->id,
            'currency_id' => $currency->id,
            'type' => 'opening_balance',
            'direction' => 'in',
            'amount' => 1,
        ]);
        $this->assertNotSame($oldTransactionNumber, $newTransaction->transaction_no);
    }

    public function test_withdrawal_cleanup_deletes_only_the_selected_request_and_handles_invalid_numbers_inline(): void
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
            'requested_amount' => 15,
            'requested_by' => auth()->id(),
        ]);
        $request = $service->acceptRequest($request, 15, $fund, auth()->user());
        $otherRequest = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'finance_category_id' => $category->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 12,
            'requested_by' => auth()->id(),
        ]);
        $otherRequest = $service->acceptRequest($otherRequest, 12, $fund, auth()->user());

        $component = Volt::test('settings.finance')
            ->assertSee('data-withdrawal-request-cleanup', false)
            ->assertSee('data-withdrawal-cleanup-request-number', false)
            ->call('deleteWithdrawalRequest')
            ->assertHasErrors(['withdrawal_cleanup_request_no' => 'required'])
            ->set('withdrawal_cleanup_request_no', 'PUL-999999')
            ->call('deleteWithdrawalRequest')
            ->assertHasErrors(['withdrawal_cleanup_request_no'])
            ->set('withdrawal_cleanup_request_no', strtolower($request->request_no))
            ->call('deleteWithdrawalRequest')
            ->assertHasNoErrors()
            ->assertSet('withdrawal_cleanup_request_no', '')
            ->assertSee('data-withdrawal-request-cleanup', false);

        $this->assertSoftDeleted('finance_requests', ['id' => $request->id]);
        $this->assertSoftDeleted('finance_transactions', ['id' => $request->posted_transaction_id]);
        $this->assertDatabaseHas('finance_requests', ['id' => $otherRequest->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('finance_transactions', ['id' => $otherRequest->posted_transaction_id, 'deleted_at' => null]);

        $component
            ->call('finishWithdrawalRequestCleanup')
            ->assertHasNoErrors()
            ->assertDontSee('data-withdrawal-request-cleanup', false);

        $this->assertTrue((bool) AppSetting::groupValues('finance')->get('withdrawal_request_cleanup_finished'));
    }

    public function test_invoice_expense_finalisation_uses_the_locked_invoice_total(): void
    {
        $this->signIn();
        Storage::fake('public');

        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $kind = FinancePullRequestKind::query()->where('mode', FinancePullRequestKind::MODE_INVOICE)->firstOrFail();
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_PULL),
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'finance_category_id' => $kind->id,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 40,
            'requested_by' => auth()->id(),
            'requested_reason' => 'Invoice supplies',
        ]);
        $request->update(['requested_amount' => 60]);
        $request = $service->acceptRequest($request, 60, $fund, auth()->user());

        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->assertSet('original_invoice_no', '№')
            ->assertSee('data-original-invoice-no-input', false)
            ->assertSeeInOrder([
                'data-invoice-finalisation-metrics',
                'data-invoice-scan-fields',
                'data-invoice-items-table',
            ], false)
            ->assertSee(__('finance.fields.item_name'))
            ->assertSee(__('finance.fields.quantity'))
            ->assertSee(__('finance.fields.unit_price'))
            ->assertSee(__('finance.fields.amount'))
            ->assertSee('data-invoice-items-header-divider', false)
            ->assertSet('invoice_items', [])
            ->assertSee('data-invoice-item-draft-row', false)
            ->assertSee('x-on:invoice-item-saved.window', false)
            ->assertSee('x-ref="invoiceItemName"', false)
            ->assertSee('wire:keydown.tab.prevent.stop="saveInvoiceItem"', false)
            ->assertDontSee('wire:click="addInvoiceItem"', false)
            ->assertDontSee('wire:model="invoice_notes"', false)
            ->assertDontSee('mb-5 soft-callout p-4', false)
            ->set('invoice_item_name', 'Supplies')
            ->set('invoice_item_quantity', '1')
            ->set('invoice_item_unit_price', '50')
            ->call('saveInvoiceItem')
            ->assertDispatched('invoice-item-saved')
            ->assertSet('invoice_items.0.item_name', 'Supplies')
            ->assertSet('invoice_items.0.unit_price', '50')
            ->assertSee('data-invoice-item-edit', false)
            ->assertSee('data-invoice-item-delete', false)
            ->call('editInvoiceItem', 0)
            ->assertSet('editing_invoice_item_index', 0)
            ->assertSet('invoice_item_name', 'Supplies')
            ->assertSee('data-invoice-item-edit-row', false)
            ->assertDontSee('data-invoice-item-draft-row', false)
            ->set('invoice_item_unit_price', '55')
            ->call('saveInvoiceItem')
            ->assertDispatched('invoice-item-saved')
            ->assertSet('editing_invoice_item_index', null)
            ->assertSet('invoice_items.0.unit_price', '55')
            ->assertSee('data-invoice-item-saved-row', false)
            ->assertSee('data-invoice-item-row-tone="odd"', false)
            ->set('original_invoice_no', 'VENDOR-10')
            ->set('invoice_issuer', 'Vendor')
            ->set('invoice_date', now()->toDateString())
            ->set('invoice_deduction', '5')
            ->call('closeFinaliseModal')
            ->assertSet('finalisingRequestId', null)
            ->assertSet('paused_invoice_draft_request_id', $request->id)
            ->assertSee(__('finance.messages.invoice_draft_saved'));

        $this->assertSame(FinanceRequest::STATUS_ACCEPTED, $request->fresh()->status);
        $this->assertDatabaseMissing('invoices', ['finance_request_id' => $request->id]);

        $component
            ->call('openFinaliseModal', $request->id)
            ->assertSet('original_invoice_no', 'VENDOR-10')
            ->assertSet('invoice_issuer', 'Vendor')
            ->assertSet('invoice_deduction', '5')
            ->assertSet('invoice_items.0.item_name', 'Supplies')
            ->assertSet('invoice_items.0.unit_price', '55')
            ->set('invoice_image', UploadedFile::fake()->create('vendor-scan.jpg', 15360, 'image/jpeg'))
            ->call('finaliseInvoiceExpense')
            ->assertHasNoErrors();

        $this->assertFalse(session()->has('finance.invoice_expense_drafts.'.auth()->id().'.'.$request->id));

        $invoice = Invoice::query()->where('finance_request_id', $request->id)->firstOrFail();

        $this->assertSame(FinanceRequest::STATUS_SETTLED, $request->fresh()->status);
        $this->assertSame('50.00', $request->fresh()->accepted_amount);
        $this->assertSame('55.00', $invoice->subtotal);
        $this->assertSame('5.00', $invoice->discount);
        $this->assertSame('50.00', $invoice->total);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->finalised_at);
        Storage::disk('public')->assertExists($invoice->original_image_path);
        $this->assertDatabaseHas('finance_transactions', ['id' => $request->posted_transaction_id, 'signed_amount' => -50]);

        Volt::test('finance.expense-requests')
            ->set('viewingInvoiceId', $invoice->id)
            ->assertSee('class="admin-modal__close"', false)
            ->assertSee('title="'.__('finance.actions.view_attachment').'"', false)
            ->assertSee('<svg class="size-5"', false)
            ->assertSee('data-invoice-original-scan-action', false)
            ->assertSee('data-icon-name="scanner"', false)
            ->assertSee('data-scanner-artwork="open-flatbed-scanner"', false)
            ->assertSee('data-invoice-print-icon', false)
            ->assertSee('data-invoice-xlsx-icon', false)
            ->assertSee('data-invoice-xlsx-artwork="supplied-xlsx-file"', false)
            ->assertSee('title="'.__('finance.actions.export_excel').'"', false)
            ->assertSee('aria-label="'.__('finance.actions.export_excel').'"', false)
            ->assertSee('data-invoice-view-items-box', false)
            ->assertSee('data-finance-generic-table', false)
            ->assertSee('data-settings-record-table', false)
            ->assertDontSee('class="pill-link">'.__('finance.actions.view_attachment').'</a>', false);

        $xlsxResponse = $this->get(route('finance.invoices.items.xlsx', $invoice))->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $xlsxResponse->headers->get('content-type'));
        $xlsxPath = tempnam(sys_get_temp_dir(), 'invoice-items-xlsx-');
        file_put_contents($xlsxPath, $xlsxResponse->streamedContent());
        $xlsx = new ZipArchive;
        $this->assertTrue($xlsx->open($xlsxPath) === true);
        $sheet = $xlsx->getFromName('xl/worksheets/sheet1.xml');
        $xlsx->close();
        @unlink($xlsxPath);
        $this->assertIsString($sheet);
        foreach (['#', 'name', 'qty', 'individual price', 'amount', 'Supplies', '55.00'] as $value) {
            $this->assertStringContainsString($value, $sheet);
        }

        $invoiceEditUrl = route('finance.expense-requests.index', ['edit_invoice' => $invoice->id]);
        $this->get($invoiceEditUrl)
            ->assertOk()
            ->assertSee('data-invoice-items-table', false)
            ->assertSee('>#<', false);

        Volt::test('settings.finance')
            ->set('transaction_lookup_no', $request->postedTransaction->transaction_no)
            ->call('findTransaction')
            ->assertSee('data-transaction-maintenance-save-action', false)
            ->assertSee('data-transaction-maintenance-receipt-action', false)
            ->assertSee($invoiceEditUrl, false)
            ->assertDontSee(route('invoices.payments', ['invoice' => $invoice, 'maintenance' => 1]), false);

        Volt::test('finance.expense-requests')
            ->call('editInvoice', $invoice->id)
            ->assertSee('data-invoice-items-table', false)
            ->assertSee('>#<', false)
            ->set('original_invoice_no', 'VENDOR-11')
            ->set('invoice_issuer', 'Updated Vendor')
            ->set('invoice_date', now()->subDay()->toDateString())
            ->set('invoice_items', [['item_name' => 'Updated supplies', 'quantity' => '2', 'unit_price' => '20']])
            ->set('invoice_deduction', '2')
            ->set('invoice_notes', 'Updated notes')
            ->set('invoice_image', UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'))
            ->call('saveInvoiceExpense')
            ->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame('VENDOR-11', $invoice->original_invoice_no);
        $this->assertSame('Updated Vendor', $invoice->invoicer_name);
        $this->assertSame('38.00', $invoice->total);
        $this->assertSame('Updated notes', $invoice->notes);
        $this->assertSame($currency->id, $invoice->financeRequest->accepted_currency_id);
        Storage::disk('public')->assertExists($invoice->original_image_path);
        $this->assertDatabaseHas('finance_transactions', ['id' => $request->posted_transaction_id, 'signed_amount' => -38]);
        $response = $this->get(route('finance.invoices.print', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
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
        $teacherItems = collect(app(SidebarNavigationService::class)->sidebarFor($teacherUser))->pluck('items')->flatten(1);
        $this->assertTrue($teacherItems->contains(fn (array $item) => $item['key'] === 'finance_pull_requests'));
        $this->assertFalse($teacherItems->contains(fn (array $item) => $item['key'] === 'finance_dashboard'));

        AppSetting::storeValue('finance', 'withdrawal_requests_enabled', false, 'boolean');
        $disabledTeacherItems = collect(app(SidebarNavigationService::class)->sidebarFor($teacherUser))->pluck('items')->flatten(1);
        $this->assertFalse($disabledTeacherItems->contains(fn (array $item) => $item['key'] === 'finance_pull_requests'));
        AppSetting::storeValue('finance', 'withdrawal_requests_enabled', true, 'boolean');

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $managerItems = collect(app(SidebarNavigationService::class)->sidebarFor($manager))->pluck('items')->flatten(1);
        $this->assertFalse($managerItems->contains(fn (array $item) => $item['key'] === 'finance_pull_requests'));
        $this->assertTrue($managerItems->contains(fn (array $item) => $item['key'] === 'finance_dashboard'));
    }

    public function test_historical_finance_transactions_are_cleaned_categorized_and_renumbered(): void
    {
        $this->signIn();

        $cashBox = FinanceCashBox::query()->firstOrFail();
        $currency = FinanceCurrency::query()->firstOrFail();
        $expenseCategory = FinanceCategory::query()->where('type', 'expense')->firstOrFail();
        $exchangeCategory = FinanceCategory::query()->where('code', 'currency-exchange')->firstOrFail();
        $request = FinanceRequest::query()->create([
            'request_no' => 'PUL-000099',
            'expense_no' => 'DBIT-000777',
            'type' => FinanceRequest::TYPE_PULL,
            'status' => FinanceRequest::STATUS_ACCEPTED,
            'requested_currency_id' => $currency->id,
            'requested_amount' => 25,
            'accepted_currency_id' => $currency->id,
            'accepted_amount' => 25,
            'cash_box_id' => $cashBox->id,
            'finance_category_id' => $expenseCategory->id,
            'requested_reason' => 'Books for students',
        ]);

        $base = [
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'amount' => 25,
            'rate_to_base' => 1,
            'base_amount' => 25,
            'local_amount' => 25,
            'transaction_date' => '2026-08-01',
        ];
        $expense = FinanceTransaction::query()->create($base + [
            'transaction_no' => 'OLD-900',
            'special_transaction_no' => 'DBIT-000777',
            'finance_request_id' => $request->id,
            'source_type' => FinanceRequest::class,
            'source_id' => $request->id,
            'type' => 'expense',
            'direction' => 'out',
            'signed_amount' => -25,
            'description' => '(DBIT-000777) / [PUL-000099]',
        ]);
        $exchange = FinanceTransaction::query()->create($base + [
            'transaction_no' => 'OLD-901',
            'special_transaction_no' => 'EXC-000002',
            'type' => 'exchange',
            'direction' => 'in',
            'signed_amount' => 25,
            'description' => 'EXC-000002',
            'metadata' => ['exchange_no' => 'EXC-000002'],
        ]);
        $income = FinanceTransaction::query()->create($base + [
            'transaction_no' => 'OLD-902',
            'special_transaction_no' => 'CRDT-000010',
            'type' => 'income',
            'direction' => 'in',
            'signed_amount' => 25,
            'description' => 'CRDT-000010 Donation',
        ]);

        AppSetting::storeValue('finance', 'transaction_prefix', 'GEN');
        $migration = require database_path('migrations/2026_08_09_010000_repair_historical_finance_transactions.php');
        $migration->up();

        $this->assertSame($expenseCategory->id, $expense->fresh()->finance_category_id);
        $this->assertSame('Books for students', $expense->fresh()->description);
        $this->assertSame($exchangeCategory->id, $exchange->fresh()->finance_category_id);
        $this->assertNull($exchange->fresh()->description);
        $this->assertSame('Donation', $income->fresh()->description);
        $this->assertSame(
            ['GEN-00000001', 'GEN-00000002', 'GEN-00000003'],
            FinanceTransaction::query()->orderBy('id')->pluck('transaction_no')->all(),
        );

        $newTransaction = app(FinanceService::class)->postTransaction([
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'finance_category_id' => $expenseCategory->id,
            'finance_request_id' => $request->id,
            'source_type' => FinanceRequest::class,
            'source_id' => $request->id,
            'type' => 'expense',
            'direction' => 'out',
            'amount' => 5,
            'special_transaction_no' => 'DBIT-000777',
            'description' => '[DBIT-000777]',
        ]);

        $this->assertSame('GEN-00000004', $newTransaction->transaction_no);
        $this->assertSame('Books for students', $newTransaction->description);
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
        Storage::disk('public')->put('tests/finance-signature.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $user->forceFill(['finance_signature_path' => 'tests/finance-signature.png'])->save();

        $user->assignRole('manager');

        $this->actingAs($user);
    }
}
