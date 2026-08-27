<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CoursePointMarketDepartment;
use App\Models\CoursePointMarketItem;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CoursePointMarketService;
use App\Support\NumberFormatter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CoursePointMarketTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->actingAs($this->manager);
        FinanceCurrency::query()->where('is_local', true)->update(['rate_to_base' => 0.01]);
    }

    public function test_end_of_course_shows_the_course_scoped_point_market_workspace(): void
    {
        $course = Course::query()->create([
            'name' => 'Summer Course',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-08-31',
            'is_active' => false,
        ]);

        $this->get(route('courses.end', $course))
            ->assertOk()
            ->assertSee(__('course_end.point_market.title'))
            ->assertSee(route('courses.end.point-market', $course), false)
            ->assertSee('wire:navigate', false)
            ->assertSee('data-course-point-market-tab', false);

        $this->get(route('courses.end.point-market', $course))
            ->assertOk()
            ->assertSee(__('course_end.point_market.added_invoices'))
            ->assertSee(__('course_end.point_market.departments'))
            ->assertSee(__('course_end.point_market.summary.exchange_rate'))
            ->assertSee(__('course_end.point_market.summary.total_points_after_rules'))
            ->assertSee(__('course_end.point_market.summary.departments_total'))
            ->assertSee('data-point-market-summary', false)
            ->assertSee('data-point-market-generic-table', false);

        $endView = file_get_contents(resource_path('views/livewire/courses/end.blade.php'));
        $this->assertStringNotContainsString('route(\'courses.end.point-market\', $course) }}" target="_blank"', $endView);
    }

    public function test_only_unadded_expense_invoices_within_course_dates_can_be_added(): void
    {
        $course = Course::query()->create([
            'name' => 'Invoice Date Course',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-08-31',
            'is_active' => false,
        ]);
        $inside = $this->createExpenseInvoice('INV-POINT-001', '2026-07-15', 'Market stationery');
        $outside = $this->createExpenseInvoice('INV-POINT-002', '2026-09-01', 'Late stationery');

        Volt::test('courses.point-market', ['course' => $course])
            ->call('openInvoiceModal')
            ->assertSee('wire:model.live="selectedInvoiceIds" value="'.$inside->id.'"', false)
            ->assertDontSee('wire:model.live="selectedInvoiceIds" value="'.$outside->id.'"', false)
            ->assertDontSee('Market stationery')
            ->set('selectedInvoiceIds', [$inside->id])
            ->call('addInvoices')
            ->assertHasNoErrors()
            ->assertSee('10 $')
            ->call('openInvoiceModal')
            ->assertDontSee('wire:model.live="selectedInvoiceIds" value="'.$inside->id.'"', false)
            ->assertDontSee('wire:model.live="selectedInvoiceIds" value="'.$outside->id.'"', false);

        $this->assertDatabaseHas('course_point_market_invoices', [
            'course_id' => $course->id,
            'invoice_id' => $inside->id,
        ]);

        $otherCourse = Course::query()->create([
            'name' => 'Other Course',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-08-31',
            'is_active' => false,
        ]);

        $this->assertDatabaseMissing('course_point_market_invoices', [
            'course_id' => $otherCourse->id,
            'invoice_id' => $inside->id,
        ]);
    }

    public function test_invoice_items_are_snapshotted_converted_assigned_and_rounded_to_nearest_ten(): void
    {
        $course = Course::query()->create([
            'name' => 'Allocation Course',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-08-31',
            'is_active' => false,
        ]);
        $invoice = $this->createExpenseInvoice('INV-POINT-003', '2026-07-20', 'Prizes', [
            ['item_name' => 'Notebook', 'quantity' => 2, 'unit_price' => 3],
            ['item_name' => 'Pen', 'quantity' => 5, 'unit_price' => 1],
        ]);

        $component = Volt::test('courses.point-market', ['course' => $course])
            ->set('selectedInvoiceIds', [$invoice->id])
            ->call('addInvoices')
            ->set('departmentName', 'Stationery')
            ->call('createDepartment')
            ->assertHasNoErrors();

        $department = CoursePointMarketDepartment::query()->where('course_id', $course->id)->firstOrFail();
        $notebook = $invoice->items()->where('item_name', 'Notebook')->firstOrFail();

        $component
            ->set('selectedItemIds', [$notebook->id])
            ->call('openAssignmentModal', $invoice->id)
            ->set('assignmentDepartmentId', $department->id)
            ->call('addSelectedItems')
            ->assertHasNoErrors();

        $allocation = CoursePointMarketItem::query()->firstOrFail();
        $this->assertSame('300.00', $allocation->local_unit_price);
        $this->assertSame('$', $allocation->currency_symbol);
        $this->assertSame('SYP', $allocation->local_currency_code);
        $this->assertSame(300, $allocation->points());
        $this->assertSame('3 $', $allocation->formattedAmount('unit_price'));
        $this->assertSame('2', NumberFormatter::trimmed($allocation->quantity, 2));

        $summary = app(CoursePointMarketService::class)->summary($course);
        $this->assertSame(0, $summary['total_points_after_rules']);
        $this->assertSame(600.0, $summary['departments_local_total']);
        $this->assertSame(6.0, $summary['departments_base_total']);
        $this->assertSame('1 USD = 100 SYP', $summary['exchange_rate']);

        $component
            ->call('openDepartmentSettings', $department->id)
            ->set('pointPrice', '20')
            ->call('saveDepartmentSettings')
            ->assertHasNoErrors()
            ->assertSee('20');

        $this->assertSame(20, $allocation->fresh()->points($department->fresh()->point_price));
        $this->assertDatabaseHas('course_point_market_items', [
            'course_id' => $course->id,
            'course_point_market_department_id' => $department->id,
            'invoice_item_id' => $notebook->id,
            'item_name' => 'Notebook',
        ]);

        $response = $this->get(route('courses.end.point-market.departments.pdf', [$course, $department]));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString(rawurlencode(__('course_end.point_market.pdf_title')), (string) $response->headers->get('content-disposition'));

        $component
            ->assertSee('removeDepartmentItem('.$allocation->id.')', false)
            ->call('removeDepartmentItem', $allocation->id)
            ->assertHasNoErrors()
            ->call('toggleInvoice', $invoice->id)
            ->assertSee('wire:model.live="selectedItemIds" value="'.$notebook->id.'"', false);

        $this->assertDatabaseMissing('course_point_market_items', ['id' => $allocation->id]);
    }

    public function test_point_market_uses_compact_numbers_and_standard_department_labels(): void
    {
        $pointMarketView = file_get_contents(resource_path('views/livewire/courses/point-market.blade.php'));
        $pdfTemplate = file_get_contents(resource_path('views/reports/course-point-market-department.blade.php'));

        $this->assertSame('1,200', NumberFormatter::trimmed('1200.00', 2));
        $this->assertSame('1,200.5', NumberFormatter::trimmed('1200.50', 2));
        $this->assertSame('0', NumberFormatter::trimmed(0, 0));
        $this->assertSame('التصنيف', __('course_end.point_market.invoice_table.category', [], 'ar'));
        $this->assertSame('سعر الوحدة', __('course_end.point_market.department.invoice_unit_price', [], 'ar'));
        $this->assertSame('قسم القرطاسية', __('course_end.point_market.department.title', ['name' => 'القرطاسية'], 'ar'));
        $this->assertSame('قسم', __('course_end.point_market.department_prefix', [], 'ar'));
        $this->assertSame('Department', __('course_end.point_market.department_prefix', [], 'en'));
        $this->assertSame('عدد الفواتير المضافة', __('course_end.point_market.added_invoices_count', [], 'ar'));
        $this->assertSame('Number of added invoices', __('course_end.point_market.added_invoices_count', [], 'en'));
        $this->assertStringContainsString('class="point-market-detail-header"', $pointMarketView);
        $this->assertStringContainsString('class="point-market-detail-row"', $pointMarketView);
        $this->assertStringContainsString('background: rgba(35, 28, 22, .98);', $pointMarketView);
        $this->assertStringContainsString('background: rgba(19, 16, 13, .98);', $pointMarketView);
        $this->assertStringContainsString('<section class="surface-table" data-point-market-generic-table>', $pointMarketView);
        $this->assertStringContainsString('class="surface-table settings-record-table point-market-modal-table-wrap"', $pointMarketView);
        $this->assertStringContainsString("html[dir='rtl'] [data-course-point-market] .point-market-generic-table :is(th, td) { text-align: right; }", $pointMarketView);
        $this->assertStringContainsString('.point-market-generic-table .point-market-numeric { text-align: right !important; }', $pointMarketView);
        $this->assertStringContainsString('point-market-col--text', $pointMarketView);
        $this->assertStringContainsString('point-market-col--medium', $pointMarketView);
        $this->assertStringContainsString('point-market-col--amount', $pointMarketView);
        $this->assertStringContainsString('point-market-department-table', $pointMarketView);
        $this->assertStringContainsString('point-market-col--equal', $pointMarketView);
        $this->assertStringContainsString("formattedAmount('unit_price')", $pointMarketView);
        $this->assertStringContainsString("formattedAmount('local_unit_price', true)", $pointMarketView);
        $this->assertStringNotContainsString('$showLocalPrice', $pointMarketView);
        $this->assertStringContainsString('class="admin-icon-button" title="{{ __(\'course_end.point_market.export_pdf\') }}"', $pointMarketView);
        $this->assertStringNotContainsString('admin-icon-button point-market-collapse-button', $pointMarketView);
        $this->assertStringContainsString("__('course_end.point_market.invoice_table.original_invoice_no')", $pointMarketView);
        $this->assertStringContainsString("__('course_end.point_market.item_table.quantity')", $pointMarketView);
        $this->assertStringContainsString('wire:click="toggleInvoice({{ $invoice->id }})"', $pointMarketView);
        $this->assertStringContainsString('wire:click="toggleDepartment({{ $department->id }})"', $pointMarketView);
        $this->assertStringContainsString('class="point-market-prefixed-input__prefix"', $pointMarketView);
        $this->assertStringContainsString('point-market-hero__metric shrink-0 rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-5 py-3 text-center shadow-inner', $pointMarketView);
        $this->assertStringContainsString('mt-1 block text-lg font-semibold text-emerald-100', $pointMarketView);
        $this->assertStringContainsString("__('course_end.point_market.department.title', ['name' => \$department->name])", $pointMarketView);
        $this->assertStringContainsString('colspan="14"', $pointMarketView);
        $this->assertStringNotContainsString('point-market-detail-table', $pointMarketView);
        $this->assertStringNotContainsString('point-market-invoice-items__panel', $pointMarketView);
        $this->assertStringNotContainsString('linear-gradient(180deg, rgba(3, 55, 25', $pointMarketView);
        $this->assertStringNotContainsString("__('course_end.point_market.department.point_price_help')", $pointMarketView);
        $this->assertStringContainsString('.meta-label{font-family:dubaimedium,sans-serif;font-weight:normal', $pdfTemplate);
        $this->assertStringContainsString('class="header-meta-table"', $pdfTemplate);
        $this->assertStringContainsString('.header-meta-table{margin:0 auto}', $pdfTemplate);
        $this->assertStringContainsString('.header-meta-row{width:26mm;table-layout:auto;direction:ltr;margin:0 auto}', $pdfTemplate);
        $this->assertSame(4, substr_count($pdfTemplate, 'class="header-meta-row"'));
        $this->assertStringContainsString('class="meta-value" style="text-align:left"', $pdfTemplate);
        $this->assertStringContainsString('class="meta-label" dir="rtl" style="text-align:right"', $pdfTemplate);
        $this->assertStringContainsString('NumberFormatter::withSuffix($department->point_price, $localCurrency->symbol ?: $localCurrency->code, 2)', $pdfTemplate);
        $this->assertStringContainsString('class="department-items-table"', $pdfTemplate);
        $this->assertStringContainsString('.numeric.quantity-value,.numeric.points-value{text-align:center;padding-right:7px}', $pdfTemplate);
        $this->assertStringContainsString('class="numeric points-value"', $pdfTemplate);
        $this->assertStringContainsString(".item-name{padding-right:{{ app()->isLocale('ar') ? '14px' : '7px' }}}", $pdfTemplate);
        $this->assertStringContainsString('class="item-name"', $pdfTemplate);
        $this->assertStringNotContainsString('$showLocalCurrency', $pdfTemplate);
        $this->assertStringContainsString("text-align:{{ app()->isLocale('ar') ? 'right' : 'left' }}", $pdfTemplate);
        $this->assertStringContainsString("app()->isLocale('ar') ? '36px' : '7px'", $pdfTemplate);
        $this->assertStringContainsString("__('course_end.point_market.department.title'", $pdfTemplate);
    }

    public function test_invoice_and_department_sections_only_expand_one_record_at_a_time(): void
    {
        $course = Course::query()->create([
            'name' => 'Accordion Course',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-08-31',
            'is_active' => false,
        ]);

        $component = Volt::test('courses.point-market', ['course' => $course])
            ->call('toggleInvoice', 101)
            ->assertSet('expandedInvoiceIds', [101])
            ->call('toggleInvoice', 202)
            ->assertSet('expandedInvoiceIds', [202])
            ->call('toggleInvoice', 202)
            ->assertSet('expandedInvoiceIds', [])
            ->set('departmentName', 'First')
            ->call('createDepartment')
            ->set('departmentName', 'Second')
            ->call('createDepartment');

        $firstDepartment = CoursePointMarketDepartment::query()->where('name', 'First')->firstOrFail();
        $secondDepartment = CoursePointMarketDepartment::query()->where('name', 'Second')->firstOrFail();

        $component
            ->assertSet('expandedDepartmentIds', [$secondDepartment->id])
            ->call('toggleDepartment', $firstDepartment->id)
            ->assertSet('expandedDepartmentIds', [$firstDepartment->id])
            ->call('toggleDepartment', $firstDepartment->id)
            ->assertSet('expandedDepartmentIds', []);
    }

    public function test_removed_and_readded_items_return_to_their_original_invoice_order(): void
    {
        $course = Course::query()->create([
            'name' => 'Stable Item Order Course',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-08-31',
            'is_active' => false,
        ]);
        $invoice = $this->createExpenseInvoice('INV-POINT-ORDER', '2026-07-20', 'Ordered items', [
            ['item_name' => 'First item', 'quantity' => 1, 'unit_price' => 1],
            ['item_name' => 'Second item', 'quantity' => 1, 'unit_price' => 2],
            ['item_name' => 'Third item', 'quantity' => 1, 'unit_price' => 3],
        ]);
        $invoiceItems = $invoice->items->keyBy('item_name');
        $service = app(CoursePointMarketService::class);
        $service->addInvoices($course, [$invoice->id], $this->manager);
        $department = $service->createDepartment($course, 'Ordered department', $this->manager);

        $service->allocateItems($course, $invoice, $department, [
            $invoiceItems['Third item']->id,
            $invoiceItems['First item']->id,
            $invoiceItems['Second item']->id,
        ], $this->manager);

        $firstAllocation = CoursePointMarketItem::query()
            ->where('invoice_item_id', $invoiceItems['First item']->id)
            ->firstOrFail();
        $service->removeItem($course, $firstAllocation);
        $service->allocateItems(
            $course,
            $invoice,
            $department,
            [$invoiceItems['First item']->id],
            $this->manager,
        );

        $orderedItems = $service->departments($course)->firstOrFail()->items;

        $this->assertSame(['First item', 'Second item', 'Third item'], $orderedItems->pluck('item_name')->all());
        $this->assertSame([1, 2, 3], $orderedItems->pluck('invoice_item_sort_order')->all());
    }

    private function createExpenseInvoice(string $invoiceNo, string $date, string $description, ?array $items = null): Invoice
    {
        $usd = FinanceCurrency::query()->where('code', 'USD')->firstOrFail();
        $cashBox = FinanceCashBox::query()->firstOrFail();
        $category = FinanceCategory::query()->where('type', 'expense')->firstOrFail();
        $items ??= [['item_name' => 'Item', 'quantity' => 1, 'unit_price' => 10]];
        $total = collect($items)->sum(fn (array $item) => $item['quantity'] * $item['unit_price']);
        $request = FinanceRequest::query()->create([
            'request_no' => 'REQ-'.$invoiceNo,
            'expense_no' => 'EXP-'.$invoiceNo,
            'type' => FinanceRequest::TYPE_EXPENSE,
            'status' => FinanceRequest::STATUS_SETTLED,
            'requested_currency_id' => $usd->id,
            'requested_amount' => $total,
            'accepted_currency_id' => $usd->id,
            'accepted_amount' => $total,
            'cash_box_id' => $cashBox->id,
            'finance_category_id' => $category->id,
            'requested_by' => $this->manager->id,
            'requested_reason' => $description,
            'settled_at' => $date.' 12:00:00',
            'settled_by' => $this->manager->id,
        ]);
        $transaction = FinanceTransaction::query()->create([
            'transaction_no' => 'TX-'.$invoiceNo,
            'cash_box_id' => $cashBox->id,
            'currency_id' => $usd->id,
            'finance_category_id' => $category->id,
            'finance_request_id' => $request->id,
            'source_type' => FinanceRequest::class,
            'source_id' => $request->id,
            'type' => 'expense',
            'direction' => 'out',
            'amount' => $total,
            'signed_amount' => -$total,
            'rate_to_base' => 1,
            'base_amount' => -$total,
            'local_amount' => -($total / 0.01),
            'transaction_date' => $date,
            'description' => $description,
            'entered_by' => $this->manager->id,
        ]);
        $request->update(['posted_transaction_id' => $transaction->id]);
        $invoice = Invoice::query()->create([
            'invoice_no' => $invoiceNo,
            'original_invoice_no' => 'ORIGINAL-'.$invoiceNo,
            'invoicer_name' => 'Supplier',
            'invoice_type' => 'finance',
            'finance_request_id' => $request->id,
            'issue_date' => $date,
            'status' => 'issued',
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'finalised_at' => $date.' 12:00:00',
            'finalised_by' => $this->manager->id,
        ]);

        foreach ($items as $index => $item) {
            $invoice->items()->create([
                'line_no' => $index + 1,
                'item_name' => $item['item_name'],
                'description' => $item['item_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'amount' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        return $invoice->fresh('items');
    }
}
