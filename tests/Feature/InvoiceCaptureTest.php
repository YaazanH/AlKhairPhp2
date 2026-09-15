<?php

namespace Tests\Feature;

use App\Models\FinanceCashBox;
use App\Models\FinanceCurrency;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\InvoiceCaptureService;
use App\Services\InvoiceDraftParser;
use App\Services\InvoiceVisionDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InvoiceCaptureTest extends TestCase
{
    use RefreshDatabase;

    public static function correctionLanguages(): array
    {
        return [['ar'], ['en']];
    }

    public static function originalNumberCaptures(): array
    {
        return [['ar', '003597'], ['en', '003597'], ['ar', '№003597'], ['en', '№ № 003597']];
    }

    #[DataProvider('originalNumberCaptures')]
    public function test_original_number_prefix_and_leading_zeros_survive_capture_saving_and_editing(string $locale, string $number): void
    {
        $request = $this->invoiceContext();
        app()->setLocale($locale);
        $draft = $this->draft();
        $draft['original_invoice_no'] = $number;
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->assertSee('№ 003597')
            ->call('applyInvoiceCapture')
            ->assertSet('original_invoice_no', '№ 003597')
            ->call('saveInvoiceExpense')
            ->assertHasNoErrors();

        $invoice = Invoice::where('finance_request_id', $request->id)->firstOrFail();
        $this->assertSame('№ 003597', $invoice->getRawOriginal('original_invoice_no'));
        Volt::test('finance.expense-requests')
            ->call('editInvoice', $invoice->id)
            ->assertSet('original_invoice_no', '№ 003597')
            ->call('saveInvoiceExpense')
            ->assertHasNoErrors()
            ->call('editInvoice', $invoice->id)
            ->set('original_invoice_no', '№ № 0000A-2')
            ->call('saveInvoiceExpense')
            ->assertHasNoErrors();
        $this->assertSame('№ 0000A-2', $invoice->fresh()->getRawOriginal('original_invoice_no'));
    }

    public function test_original_number_requires_more_than_the_prefix_and_fits_the_database_column(): void
    {
        $request = $this->invoiceContext();
        $component = Volt::test('finance.expense-requests')->call('openFinaliseModal', $request->id);
        foreach (['№', ' № № ', ''] as $number) {
            $component->set('original_invoice_no', $number)->call('saveInvoiceExpense')->assertHasErrors(['original_invoice_no' => 'required']);
        }
        $component->set('original_invoice_no', str_repeat('A', 254))->call('saveInvoiceExpense')->assertHasErrors(['original_invoice_no' => 'max']);
        $this->assertFalse(Invoice::where('finance_request_id', $request->id)->exists());
    }

    public function test_legacy_original_numbers_display_the_prefix_and_save_it_when_edited(): void
    {
        $this->invoiceContext();
        $invoice = Invoice::create(['invoice_no' => 'LEGACY-1', 'original_invoice_no' => '003597', 'invoicer_name' => 'Legacy supplier', 'invoice_type' => 'finance', 'issue_date' => '2026-09-06', 'status' => 'draft']);
        DB::table('invoices')->where('id', $invoice->id)->update(['original_invoice_no' => '003597']);
        $this->assertSame('№ 003597', $invoice->fresh()->original_invoice_no);
        Volt::test('invoices.index')->call('edit', $invoice->id)
            ->assertSet('original_invoice_no', '№ 003597')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('№ 003597', $invoice->fresh()->getRawOriginal('original_invoice_no'));
    }

    public static function duplicateNumberFormats(): array
    {
        return [['SCAN-18', '№ SCAN-18'], ['№ SCAN-18', 'SCAN-18'], ['№SCAN-18', '№ SCAN-18']];
    }

    #[DataProvider('correctionLanguages')]
    public function test_corrected_item_totals_are_calculated_without_a_manual_total(string $locale): void
    {
        $request = $this->invoiceContext();
        app()->setLocale($locale);
        $draft = $this->draft();
        $draft['invoice_items'] = [['item_name' => 'Receipt item', 'quantity' => '1', 'unit_price' => '1115']];
        $draft['review_items'] = [['item_name' => 'Receipt item', 'quantity' => '1', 'unit_price' => '1115', 'amount' => '1115']];
        $draft['invoice_deduction'] = '0';
        $draft['total'] = 111.5;
        $draft['warnings'] = ['total_mismatch'];
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);
        $transactions = FinanceTransaction::count();
        $invoices = Invoice::count();

        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_issuer', 'Preserved supplier')
            ->set('invoice_items', [['item_name' => 'Preserved item', 'quantity' => '2', 'unit_price' => '10']])
            ->set('invoice_image', UploadedFile::fake()->image('zeros.png'))
            ->call('readInvoiceCapture')
            ->assertSee('data-invoice-capture-correction', false)
            ->assertSee('data-invoice-capture-line-total', false)
            ->assertSee('1,115.00')
            ->assertDontSee('capture-confirmed-total', false)
            ->assertDontSee('capture-corrected-discount', false)
            ->assertDontSee('data-invoice-capture-add-item', false)
            ->assertDontSee('id="capture-item-0-amount"', false)
            ->call('editInvoiceCaptureReviewItem', 0)
            ->set('invoiceCaptureReviewItems.0.quantity', '٢')
            ->set('invoiceCaptureReviewItems.0.unit_price', '١٬١١٥٬٠٠٠')
            ->assertSee('2,230,000.00')
            ->call('applyInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSet('invoiceCaptureReviewOpen', false)
            ->assertSet('invoice_items.0.quantity', '2')
            ->assertSet('invoice_items.0.unit_price', '1115000')
            ->assertSet('invoice_deduction', '0')
            ->assertSet('invoiceCaptureReviewItems', []);

        $this->assertNotNull($component->get('invoice_image'));
        $this->assertSame($transactions, FinanceTransaction::count());
        $this->assertSame($invoices, Invoice::count());
    }

    public function test_review_does_not_accept_a_forged_line_total(): void
    {
        $request = $this->invoiceContext();
        $draft = $this->draft();
        $draft['warnings'] = ['total_mismatch'];
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);
        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->set('invoiceCaptureReviewItems.0.amount', '1')
            ->call('applyInvoiceCapture')
            ->assertHasErrors(['invoiceCaptureReviewItems.0'])
            ->assertSet('invoiceCaptureReviewOpen', true);
        $component->set('invoiceCaptureReviewItems', [['item_name' => 'Oversized amount', 'quantity' => '1000000', 'unit_price' => '1000000000']])
            ->call('applyInvoiceCapture')
            ->assertHasErrors(['invoiceCaptureReviewItems.0.unit_price'])
            ->assertSet('invoiceCaptureReviewOpen', true);
    }

    public static function currencyReviewModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('currencyReviewModes')]
    public function test_review_uses_the_posted_transaction_currency_beside_amounts(bool $editable): void
    {
        $request = $this->invoiceContext();
        $currency = FinanceCurrency::where('id', '!=', $request->accepted_currency_id)->firstOrFail();
        $currency->update(['symbol' => '$', 'decimal_places' => 3]);
        $request->postedTransaction->update(['currency_id' => $currency->id]);
        $draft = $this->draft();
        $draft['currency'] = null;
        $draft['warnings'] = $editable ? ['missing_total'] : [];
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);
        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->assertSet('invoiceCaptureDraft.currency', $currency->code)
            ->assertSet('invoiceCaptureDraft.requires_amount_review', $editable)
            ->assertSee('data-invoice-capture-line-total', false)
            ->assertSee('20.000 $');
        $component->assertSee('10.000 $')->assertSee('data-invoice-capture-item-view-row', false);
        if (! $editable) {
            $component->assertSee('18.000 $')->assertSee('2.000 $');
        }
        $component->call('editInvoiceCaptureReviewItem', 0)
            ->assertSee('data-invoice-capture-price-currency>$</bdi>', false);
    }

    #[DataProvider('currencyReviewModes')]
    public function test_capture_rows_edit_save_and_delete_inside_the_editor(bool $flagged): void
    {
        $request = $this->invoiceContext();
        $draft = $this->draft();
        $draft['warnings'] = $flagged ? ['missing_total'] : [];
        $draft['review_items'] = [...$draft['invoice_items'], ['item_name' => 'Spare row', 'quantity' => '1', 'unit_price' => '5']];
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);
        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->assertSee('data-invoice-capture-item-view-row', false)
            ->assertSee('data-invoice-capture-edit-item', false)
            ->assertDontSee('data-invoice-capture-remove-item', false)
            ->assertDontSee('id="capture-item-0-name"', false)
            ->call('editInvoiceCaptureReviewItem', 0)
            ->assertSee('data-invoice-capture-item-edit-row', false)
            ->assertSee('data-invoice-capture-save-item', false)
            ->assertSee('data-invoice-capture-remove-item', false)
            ->set('invoiceCaptureReviewItems.0.quantity', '٣')
            ->set('invoiceCaptureReviewItems.0.unit_price', '٧٫٢٥')
            ->assertSee('21.75')
            ->call('saveInvoiceCaptureReviewItem')
            ->assertHasNoErrors()
            ->assertSet('invoiceCaptureEditingItemIndex', null)
            ->assertSet('invoiceCaptureReviewItems.0.quantity', '3')
            ->assertSet('invoiceCaptureReviewItems.0.unit_price', '7.25')
            ->assertDontSee('data-invoice-capture-remove-item', false)
            ->call('editInvoiceCaptureReviewItem', 1)
            ->call('removeInvoiceCaptureReviewItem', 1)
            ->assertSet('invoiceCaptureEditingItemIndex', null)
            ->assertCount('invoiceCaptureReviewItems', 1)
            ->call('editInvoiceCaptureReviewItem', 0)
            ->set('invoiceCaptureReviewItems.0.unit_price', '8')
            ->call('applyInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSet('invoice_items.0.quantity', '3')
            ->assertSet('invoice_items.0.unit_price', '8')
            ->assertSet('invoiceCaptureEditingItemIndex', null)
            ->assertSet('invoiceCaptureReviewOpen', false);
        $this->assertFalse(Invoice::where('finance_request_id', $request->id)->exists());
    }

    public static function unchangedCaptures(): array
    {
        return [
            ['ar', 'complete'], ['en', 'complete'],
            ['ar', 'uncertain'], ['en', 'uncertain'],
            ['ar', 'zero'], ['en', 'precision'],
        ];
    }

    #[DataProvider('unchangedCaptures')]
    public function test_accept_works_without_edits_and_missing_values_are_checked_when_saving(string $locale, string $case): void
    {
        $request = $this->invoiceContext();
        app()->setLocale($locale);
        $quantity = $case === 'zero' ? 0 : ($case === 'precision' ? 1.125 : 2);
        $draft = app(InvoiceVisionDraft::class)->normalize([
            'original_invoice_no' => '003597', 'invoice_issuer' => 'Test Supplier', 'invoice_date' => '2026-09-06',
            'currency' => null, 'invoice_deduction' => 0, 'total' => null, 'uncertain_fields' => [], 'notes' => [],
            'invoice_items' => [['item_name' => 'Notebooks', 'quantity' => $quantity, 'unit_price' => 10, 'amount' => null, 'uncertain' => $case === 'uncertain']],
        ]);
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);
        $transactions = FinanceTransaction::count();
        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('unchanged.png'))
            ->call('readInvoiceCapture')
            ->assertSet('invoiceCaptureDraft.requires_amount_review', true)
            ->call('applyInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSet('invoiceCaptureReviewOpen', false)
            ->assertSet('invoiceCaptureApplied', true)
            ->assertSet('original_invoice_no', '№ 003597')
            ->assertSet('invoice_items.0.item_name', 'Notebooks')
            ->assertSet('invoice_items.0.quantity', $case === 'uncertain' ? '' : (string) $quantity)
            ->assertSet('invoice_items.0.unit_price', $case === 'uncertain' ? '' : '10')
            ->assertSee('data-invoice-finalisation-form', false);
        $this->assertSame($transactions, FinanceTransaction::count());
        $this->assertFalse(Invoice::where('finance_request_id', $request->id)->exists());
        $this->assertSame('unchanged.png', $component->get('invoice_image')->getClientOriginalName());

        if (in_array($case, ['uncertain', 'zero'], true)) {
            $component->call('saveInvoiceExpense')
                ->assertHasErrors(['invoice_items.0.quantity']);
            if ($case === 'uncertain') {
                $component->assertHasErrors(['invoice_items.0.unit_price']);
            }
            $this->assertFalse(Invoice::where('finance_request_id', $request->id)->exists());
            $this->assertSame($transactions, FinanceTransaction::count());
            $component->call('editInvoiceItem', 0)
                ->set('invoice_item_quantity', '2')
                ->set('invoice_item_unit_price', '10')
                ->call('saveInvoiceItem')->assertHasNoErrors();
        }
        $component->call('saveInvoiceExpense')->assertHasNoErrors();
        $this->assertTrue(Invoice::where('finance_request_id', $request->id)->exists());
    }

    public function test_uncertain_rows_can_be_removed_and_corrections_can_be_declined(): void
    {
        $request = $this->invoiceContext();
        $draft = $this->draft();
        $draft['invoice_items'] = [];
        $draft['review_items'] = [['item_name' => 'Unclear row', 'quantity' => '', 'unit_price' => '', 'amount' => '']];
        $draft['total'] = null;
        $draft['warnings'] = ['unreadable_rows', 'missing_total'];
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);

        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_issuer', 'Preserved supplier')
            ->set('invoice_image', UploadedFile::fake()->image('uncertain.png'))
            ->call('readInvoiceCapture')
            ->call('removeInvoiceCaptureReviewItem', 0)
            ->assertSet('invoiceCaptureReviewItems', [])
            ->call('discardInvoiceCapture')
            ->assertSet('invoice_issuer', 'Preserved supplier')
            ->assertSet('invoiceCaptureReviewItems', [])
            ->assertSet('invoiceCaptureReviewOpen', false)
            ->assertHasNoErrors();
        $this->assertSame('uncertain.png', $component->get('invoice_image')->getClientOriginalName());
    }

    public function test_review_can_be_accepted_after_removing_all_items(): void
    {
        $request = $this->invoiceContext();
        $draft = $this->draft();
        $draft['warnings'] = ['missing_total'];
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($draft);
        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->call('removeInvoiceCaptureReviewItem', 0)
            ->call('applyInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSet('invoiceCaptureReviewOpen', false)
            ->assertSet('invoice_items', [])
            ->call('saveInvoiceExpense')
            ->assertHasErrors(['invoice_items' => 'required']);
        $this->assertFalse(Invoice::where('finance_request_id', $request->id)->exists());
    }

    public function test_upload_transport_errors_use_only_the_selected_language(): void
    {
        $request = $this->invoiceContext();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $message = $locale === 'ar'
                ? 'تعذّر رفع الملف. يرجى المحاولة مجدداً.'
                : 'The file could not be uploaded. Please try again.';

            Volt::test('finance.expense-requests')
                ->call('openFinaliseModal', $request->id)
                ->call('_uploadErrored', 'invoice_image', null, false)
                ->assertHasErrors(['invoice_image'])
                ->assertSee($message)
                ->assertDontSee('The هذا الحقل')
                ->assertSet('invoiceCaptureReviewOpen', false);
        }
    }

    public function test_existing_attachment_opens_a_separate_review_and_only_applies_an_accepted_draft(): void
    {
        $request = $this->invoiceContext();
        $transactions = FinanceTransaction::query()->count();
        $invoices = Invoice::query()->count();
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->once()->andReturn($this->draft());

        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->assertSee('data-invoice-capture-upload', false)
            ->assertDontSee('data-invoice-capture-button', false)
            ->set('invoice_issuer', 'My existing draft')
            ->set('invoice_items', [['item_name' => 'Existing item', 'quantity' => '1', 'unit_price' => '4']])
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSee('data-invoice-capture-review', false)
            ->assertDontSee('data-invoice-finalisation-form', false)
            ->assertSet('invoiceCaptureReviewOpen', true)
            ->assertSet('invoice_issuer', 'My existing draft')
            ->assertSet('invoice_items.0.item_name', 'Existing item')
            ->assertSet('invoiceCaptureDraft.original_invoice_no', 'SCAN-18');

        $this->assertSame($transactions, FinanceTransaction::query()->count());
        $this->assertSame($invoices, Invoice::query()->count());

        $component->call('applyInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSet('invoice_issuer', 'Test Supplier')
            ->assertSet('original_invoice_no', '№ SCAN-18')
            ->assertSet('invoice_date', '2026-09-06')
            ->assertSet('invoice_items.0.item_name', 'Notebooks')
            ->assertSet('invoiceCaptureDraft', null)
            ->assertSee('data-invoice-capture-applied', false)
            ->assertSee('data-invoice-finalisation-form', false)
            ->assertSet('invoiceCaptureReviewOpen', false);

        $this->assertNotNull($component->get('invoice_image'));
        $this->assertSame($transactions, FinanceTransaction::query()->count());
        $this->assertSame($invoices, Invoice::query()->count());
        $this->assertSame(FinanceRequest::STATUS_ACCEPTED, $request->fresh()->status);

        $component->call('saveInvoiceExpense')->assertHasNoErrors();
        $invoice = Invoice::query()->where('finance_request_id', $request->id)->firstOrFail();
        $this->assertSame('№ SCAN-18', $invoice->original_invoice_no);
        $this->assertSame('18.00', $invoice->total);
        $this->assertSame(1, $invoice->items()->count());
        Storage::disk('public')->assertExists($invoice->original_image_path);
        $this->assertSame($transactions, FinanceTransaction::query()->count());
    }

    public function test_discard_and_failed_reading_preserve_the_existing_form_and_allow_retry(): void
    {
        $request = $this->invoiceContext();
        $capture = $this->mock(InvoiceCaptureService::class);
        $capture->shouldReceive('capture')->once()->ordered()->andThrow(new RuntimeException('invoice_capture.errors.no_text'));
        $capture->shouldReceive('capture')->once()->ordered()->andReturn($this->draft());

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_issuer', 'Keep this supplier')
            ->set('invoice_image', UploadedFile::fake()->image('blurred.png'))
            ->call('readInvoiceCapture')
            ->assertHasErrors(['invoice_image'])
            ->assertSet('invoice_issuer', 'Keep this supplier')
            ->set('invoice_image', UploadedFile::fake()->image('clear.png'))
            ->call('readInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSee('data-invoice-capture-review', false)
            ->call('discardInvoiceCapture')
            ->assertSet('invoiceCaptureDraft', null)
            ->assertSet('invoice_issuer', 'Keep this supplier')
            ->assertSet('invoiceCaptureReviewOpen', false);
    }

    public function test_unsupported_documents_are_rejected_before_reading(): void
    {
        $request = $this->invoiceContext();
        $this->mock(InvoiceCaptureService::class)->shouldNotReceive('capture');

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->create('script.html', 1, 'text/html'))
            ->assertHasErrors(['invoice_image'])
            ->assertSet('invoiceCaptureDraft', null);
    }

    public function test_capture_requires_finance_review_permission(): void
    {
        $request = $this->invoiceContext();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('finance.expense-requests.view');
        $this->actingAs($viewer);
        $this->mock(InvoiceCaptureService::class)->shouldNotReceive('capture');

        Volt::test('finance.expense-requests')
            ->set('finalisingRequestId', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->assertForbidden();
    }

    public function test_count_expenses_do_not_offer_or_accept_invoice_capture(): void
    {
        $request = $this->invoiceContext(FinancePullRequestKind::MODE_COUNT);
        $this->mock(InvoiceCaptureService::class)->shouldNotReceive('capture');

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->assertDontSee('data-invoice-capture-upload', false)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->assertForbidden();
    }

    public function test_accepting_a_different_document_replaces_the_whole_draft_even_when_fields_are_unread(): void
    {
        $request = $this->invoiceContext();
        $draft = app(InvoiceDraftParser::class)->parse('Invoice No: PARTIAL-1');
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->twice()->andReturn($this->draft(), $draft);

        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('first-invoice.png'))
            ->call('readInvoiceCapture')
            ->call('applyInvoiceCapture')
            ->set('invoice_notes', 'Notes about the first receipt')
            ->set('confirm_invoice_overage', true)
            ->call('editInvoiceItem', 0)
            ->set('invoice_item_name', 'Unfinished change to the first item')
            ->set('invoice_image', UploadedFile::fake()->image('different-invoice.png'))
            ->call('readInvoiceCapture')
            ->assertSet('original_invoice_no', '№ SCAN-18')
            ->assertSet('invoice_issuer', 'Test Supplier')
            ->assertSet('invoice_items.0.item_name', 'Notebooks')
            ->assertSet('invoice_notes', 'Notes about the first receipt')
            ->assertSet('confirm_invoice_overage', true)
            ->call('applyInvoiceCapture')
            ->assertSet('original_invoice_no', '№ PARTIAL-1')
            ->assertSet('invoice_issuer', '')
            ->assertSet('invoice_date', '')
            ->assertSet('invoice_deduction', '0')
            ->assertSet('invoice_items', [])
            ->assertSet('invoice_notes', '')
            ->assertSet('invoice_item_name', '')
            ->assertSet('invoice_item_quantity', '1')
            ->assertSet('invoice_item_unit_price', '')
            ->assertSet('editing_invoice_item_index', null)
            ->assertSet('confirm_invoice_overage', false)
            ->assertSet('finalisingRequestId', $request->id)
            ->assertHasNoErrors();

        $this->assertSame('different-invoice.png', $component->get('invoice_image')->getClientOriginalName());
        $this->assertDatabaseMissing('invoices', ['finance_request_id' => $request->id]);
    }

    public function test_correction_of_a_replacement_document_does_not_reuse_the_previous_discount(): void
    {
        $request = $this->invoiceContext();
        $draft = $this->draft();
        $draft['invoice_deduction'] = null;
        $draft['total'] = null;
        $draft['warnings'] = ['missing_total'];
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->twice()->andReturn($this->draft(), $draft);

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('first.png'))
            ->call('readInvoiceCapture')
            ->call('applyInvoiceCapture')
            ->assertSet('invoice_deduction', '2')
            ->set('invoice_image', UploadedFile::fake()->image('replacement.png'))
            ->call('readInvoiceCapture')
            ->assertSet('invoice_deduction', '2')
            ->assertSet('invoiceCaptureDraft.invoice_deduction', null)
            ->call('applyInvoiceCapture')
            ->assertHasNoErrors()
            ->assertSet('invoice_deduction', '0')
            ->assertSet('invoice_items.0.unit_price', '10');
    }

    public function test_edit_invoice_replacement_only_uploads_the_file_and_preserves_all_invoice_fields(): void
    {
        $request = $this->invoiceContext();
        $this->mock(InvoiceCaptureService::class)->shouldNotReceive('capture');
        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('original_invoice_no', 'EDIT-1')
            ->set('invoice_issuer', 'Saved supplier')
            ->set('invoice_date', '2026-09-01')
            ->set('invoice_deduction', '2')
            ->set('invoice_items', [['item_name' => 'Saved item', 'quantity' => '2', 'unit_price' => '10']])
            ->set('invoice_notes', 'Saved notes')
            ->call('saveInvoiceExpense')
            ->assertHasNoErrors();
        $invoice = Invoice::query()->where('finance_request_id', $request->id)->firstOrFail();
        Storage::disk('public')->put('finance/invoices/old.png', 'old attachment');
        $invoice->update(['original_image_path' => 'finance/invoices/old.png', 'notes' => 'Saved notes']);
        $transactions = FinanceTransaction::query()->pluck('signed_amount', 'id')->all();

        $component = Volt::test('finance.expense-requests')->call('editInvoice', $invoice->id)
            ->set('invoice_issuer', 'Supplier edited manually')
            ->set('invoice_item_name', 'Unsubmitted item')
            ->set('invoice_item_quantity', '3')
            ->set('invoice_item_unit_price', '15');
        $fields = ['original_invoice_no', 'invoice_issuer', 'invoice_date', 'invoice_deduction', 'invoice_items', 'invoice_notes', 'invoice_item_name', 'invoice_item_quantity', 'invoice_item_unit_price', 'editing_invoice_item_index'];
        $before = array_combine($fields, array_map(fn ($field) => $component->get($field), $fields));
        $component->set('invoice_image', UploadedFile::fake()->image('new-attachment.png'))
            ->assertHasNoErrors()
            ->assertSet('invoiceCaptureReviewOpen', false)
            ->assertSet('invoiceCapturePending', false)
            ->assertSet('invoiceCaptureDraft', null)
            ->assertSet('invoiceCaptureRequestId', null)
            ->assertSee('data-invoice-finalisation-form', false)
            ->assertSee(__('invoice_capture.attachment_help'))
            ->assertDontSee('data-invoice-capture-review', false)
            ->call('readInvoiceCapture')
            ->call('applyInvoiceCapture')
            ->assertHasNoErrors();
        foreach ($before as $field => $value) {
            $component->assertSet($field, $value);
        }
        $this->assertSame('new-attachment.png', $component->get('invoice_image')->getClientOriginalName());
        $this->assertSame('finance/invoices/old.png', $invoice->fresh()->original_image_path);

        $component->call('saveInvoiceExpense')->assertHasNoErrors();
        $invoice->refresh();
        $this->assertSame('№ EDIT-1', $invoice->original_invoice_no);
        $this->assertSame('Supplier edited manually', $invoice->invoicer_name);
        $this->assertSame('2026-09-01', $invoice->issue_date->toDateString());
        $this->assertSame('2.00', $invoice->discount);
        $this->assertSame('18.00', $invoice->total);
        $this->assertSame('Saved notes', $invoice->notes);
        $this->assertSame('Saved item', $invoice->items()->first()->item_name);
        $this->assertSame($transactions, FinanceTransaction::query()->pluck('signed_amount', 'id')->all());
        Storage::disk('public')->assertExists($invoice->original_image_path);
        Storage::disk('public')->assertMissing('finance/invoices/old.png');
    }

    public function test_capture_is_cleared_when_the_popup_is_closed_or_switched(): void
    {
        $request = $this->invoiceContext();
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->andReturn($this->draft());

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->call('closeFinaliseModal')
            ->assertSet('invoiceCaptureDraft', null)
            ->assertSet('invoiceCaptureReviewOpen', false)
            ->call('openFinaliseModal', $request->id)
            ->call('applyInvoiceCapture')
            ->assertHasErrors(['invoice_image']);
    }

    #[DataProvider('duplicateNumberFormats')]
    public function test_currency_and_duplicate_warnings_do_not_change_the_request_or_post_transactions(string $storedNumber, string $capturedNumber): void
    {
        $request = $this->invoiceContext();
        $invoice = Invoice::query()->create([
            'invoice_no' => 'DUPLICATE-EXAMPLE', 'original_invoice_no' => 'SCAN-18',
            'invoicer_name' => 'Test Supplier', 'invoice_type' => 'finance',
            'finance_request_id' => $request->id, 'issue_date' => '2026-09-06',
            'subtotal' => 18, 'discount' => 0, 'total' => 18, 'status' => 'issued',
        ]);
        DB::table('invoices')->where('id', $invoice->id)->update(['original_invoice_no' => $storedNumber]);
        $draft = $this->draft();
        $draft['original_invoice_no'] = $capturedNumber;
        $draft['currency'] = $request->acceptedCurrency->code === 'USD' ? 'EUR' : 'USD';
        $draft['total'] = 99999.0;
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->andReturn($draft);
        $transactions = FinanceTransaction::query()->count();

        Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('invoice.png'))
            ->call('readInvoiceCapture')
            ->assertSet('invoiceCaptureDraft.currency', $request->acceptedCurrency->code)
            ->assertSee('data-invoice-capture-currency', false)
            ->assertSee('data-invoice-capture-warning="currency_mismatch"', false)
            ->assertSee('data-invoice-capture-warning="over_approved_amount"', false)
            ->assertSee('data-invoice-capture-warning="possible_duplicate"', false);

        $this->assertSame($transactions, FinanceTransaction::query()->count());
        $this->assertSame('20.00', $request->fresh()->accepted_amount);
    }

    public function test_decline_returns_to_the_invoice_preserving_fields_items_and_uploaded_attachment(): void
    {
        $request = $this->invoiceContext();
        $this->mock(InvoiceCaptureService::class)->shouldReceive('capture')->twice()->andReturn($this->draft());
        $component = Volt::test('finance.expense-requests')
            ->call('openFinaliseModal', $request->id)
            ->set('invoice_image', UploadedFile::fake()->image('previous.png'))
            ->call('readInvoiceCapture')
            ->call('applyInvoiceCapture')
            ->set('original_invoice_no', 'KEEP-1')
            ->set('invoice_issuer', 'Keep supplier')
            ->set('invoice_date', '2026-08-20')
            ->set('invoice_deduction', '1')
            ->set('invoice_notes', 'Keep notes')
            ->set('invoice_items', [['item_name' => 'Keep item', 'quantity' => '1', 'unit_price' => '7']])
            ->call('editInvoiceItem', 0)
            ->set('invoice_item_name', 'Keep unfinished item edit')
            ->set('invoice_image', UploadedFile::fake()->image('keep-attachment.png'))
            ->assertSet('invoiceCapturePending', true)
            ->assertDontSee('data-invoice-finalisation-form', false)
            ->assertSee('data-invoice-capture-progress', false)
            ->call('readInvoiceCapture')
            ->assertSet('invoiceCapturePending', false);

        $this->assertSame(1, substr_count($component->html(), 'class="admin-modal__viewport"'));
        $component->call('discardInvoiceCapture')
            ->assertSee('data-invoice-finalisation-form', false)
            ->assertDontSee('data-invoice-capture-review', false)
            ->assertSet('original_invoice_no', 'KEEP-1')
            ->assertSet('invoice_issuer', 'Keep supplier')
            ->assertSet('invoice_date', '2026-08-20')
            ->assertSet('invoice_deduction', '1')
            ->assertSet('invoice_notes', 'Keep notes')
            ->assertSet('invoice_items.0.item_name', 'Keep item')
            ->assertSet('invoice_item_name', 'Keep unfinished item edit')
            ->assertSet('editing_invoice_item_index', 0);
        $this->assertSame('keep-attachment.png', $component->get('invoice_image')->getClientOriginalName());
        $this->assertSame(1, substr_count($component->html(), 'class="admin-modal__viewport"'));
    }

    private function invoiceContext(string $mode = FinancePullRequestKind::MODE_INVOICE): FinanceRequest
    {
        $this->seed();
        Storage::fake('public');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
        $service = app(FinanceService::class);
        $currency = $service->localCurrency();
        $fund = FinanceCashBox::query()->firstOrFail();
        $kind = FinancePullRequestKind::query()->where('mode', $mode)->firstOrFail();
        $service->postTransaction(['cash_box_id' => $fund->id, 'currency_id' => $currency->id, 'type' => 'opening_balance', 'direction' => 'in', 'amount' => 100]);
        $request = FinanceRequest::query()->create([
            'request_no' => $service->nextRequestNumber(FinanceRequest::TYPE_EXPENSE),
            'type' => FinanceRequest::TYPE_EXPENSE, 'status' => FinanceRequest::STATUS_PENDING,
            'finance_pull_request_kind_id' => $kind->id,
            'requested_currency_id' => $currency->id, 'requested_amount' => 20, 'requested_count' => 2,
            'requested_by' => $user->id,
        ]);

        return $service->acceptRequest($request, 20, $fund, $user);
    }

    private function draft(): array
    {
        return app(InvoiceDraftParser::class)->parse("Supplier: Test Supplier\nInvoice No: SCAN-18\nDate: 06/09/2026\nItem Qty Price Amount\nNotebooks 2 10 20\nDiscount: 2\nTotal: 18");
    }
}
