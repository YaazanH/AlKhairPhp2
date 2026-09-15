<?php

namespace Tests\Feature;

use App\Services\InvoiceCaptureService;
use App\Services\InvoiceOcrService;
use App\Services\InvoiceVisionDraft;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InvoiceVisionTest extends TestCase
{
    public static function reviewLanguages(): array
    {
        return ['Arabic' => ['ar', 'Arabic'], 'English' => ['en', 'English']];
    }

    #[DataProvider('reviewLanguages')]
    public function test_vision_extracts_structured_fields_from_images_without_requiring_a_fixed_layout(string $locale, string $language): void
    {
        app()->setLocale($locale);
        config(['invoice_capture.engine' => 'vision', 'invoice_capture.vision_url' => 'http://127.0.0.1:11434', 'invoice_capture.vision_model' => 'qwen3.5:9b']);
        $this->mock(InvoiceOcrService::class)->shouldReceive('images')->once()->andReturn([
            ['label' => 'Page 1: full page', 'image' => 'page-one-base64'],
            ['label' => 'Page 1: top right detail (same page, overlapping)', 'image' => 'detail-base64'],
            ['label' => 'Page 2: full page', 'image' => 'page-two-base64'],
        ]);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode($this->response())]])]);

        $draft = app(InvoiceCaptureService::class)->capture(UploadedFile::fake()->image('handwritten.png'));

        $this->assertSame('مكتبة النور', $draft['invoice_issuer']);
        $this->assertSame('HW-18', $draft['original_invoice_no']);
        $this->assertSame('2026-09-06', $draft['invoice_date']);
        $this->assertSame([['item_name' => 'دفاتر مدرسية', 'quantity' => '2', 'unit_price' => '1500']], $draft['invoice_items']);
        $this->assertSame([], $draft['warnings']);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:11434/api/chat'
            && $request['model'] === 'qwen3.5:9b'
            && $request['format']['type'] === 'object'
            && $request['messages'][1]['images'] === ['page-one-base64', 'detail-base64', 'page-two-base64']
            && str_contains($request['messages'][1]['content'], 'Image 2: Page 1: top right detail')
            && str_contains($request['messages'][1]['content'], 'Image 3: Page 2: full page')
            && str_contains($request['messages'][0]['content'], 'untrusted document content')
            && str_contains($request['messages'][0]['content'], "notes in {$language} only")
            && str_contains($request['messages'][0]['content'], 'Keep supplier and item names in their original language'));
    }

    public function test_uncertain_metadata_and_inconsistent_item_amounts_are_not_applied(): void
    {
        $result = $this->response();
        $result['invoice_date'] = '2026-02-31';
        $result['uncertain_fields'] = ['original_invoice_no'];
        $result['invoice_items'][] = ['item_name' => 'أقلام', 'quantity' => 10, 'unit_price' => 100, 'amount' => 500, 'uncertain' => false];
        $result['invoice_items'][] = ['item_name' => 'غير واضح', 'quantity' => null, 'unit_price' => 100, 'amount' => 100, 'uncertain' => true];
        $result['total'] = 3500;
        $draft = app(InvoiceVisionDraft::class)->normalize($result);

        $this->assertNull($draft['original_invoice_no']);
        $this->assertNull($draft['invoice_date']);
        $this->assertCount(1, $draft['invoice_items']);
        $this->assertContains('uncertain_fields', $draft['warnings']);
        $this->assertContains('unreadable_rows', $draft['warnings']);
        $this->assertContains('total_mismatch', $draft['warnings']);
    }

    public static function translatedReviewNotes(): array
    {
        $english = 'The handwritten quantity 4 for «دفاتر مدرسية» is unclear. Check the line total of 40 and unit price of 10.';
        $arabic = 'الكمية المكتوبة بخط اليد ٤ للصنف «دفاتر مدرسية» غير واضحة. راجع إجمالي الصنف ٤٠ وسعر الوحدة ١٠.';

        return [
            'Arabic' => ['ar', $english, $arabic, 'راجع التاريخ في الأصل.'],
            'English' => ['en', $arabic, $english, 'Check the date on the original.'],
        ];
    }

    #[DataProvider('translatedReviewNotes')]
    public function test_foreign_language_notes_are_translated_locally_without_changing_invoice_values(string $locale, string $original, string $translated, string $matchingNote): void
    {
        app()->setLocale($locale);
        config(['invoice_capture.engine' => 'vision', 'invoice_capture.vision_url' => 'http://127.0.0.1:11434', 'invoice_capture.vision_model' => 'qwen3.5:9b']);
        $this->mock(InvoiceOcrService::class)->shouldReceive('images')->once()->andReturn([['label' => 'Page 1', 'image' => 'receipt-image']]);
        $result = $this->response();
        $result['notes'] = [$matchingNote, $original];
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:11434/api/chat' => Http::sequence()
            ->push(['done' => true, 'message' => ['content' => json_encode($result)]])
            ->push(['done' => true, 'message' => ['content' => json_encode(['notes' => [$translated]])]])]);

        $draft = app(InvoiceCaptureService::class)->capture(UploadedFile::fake()->image('receipt.png'));
        $expected = app(InvoiceVisionDraft::class)->normalize($result);
        $expected['notes'] = [$matchingNote, $translated];
        $this->assertSame($expected, $draft);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => ! isset($request['messages'][1]['images'])
            && $request['model'] === 'qwen3.5:9b'
            && json_decode($request['messages'][1]['content'], true)['notes'] === [$original]);
    }

    public function test_arabic_notes_can_keep_english_receipt_names_without_an_extra_model_call(): void
    {
        app()->setLocale('ar');
        config(['invoice_capture.engine' => 'vision', 'invoice_capture.vision_url' => 'http://127.0.0.1:11434', 'invoice_capture.vision_model' => 'qwen3.5:9b']);
        $this->mock(InvoiceOcrService::class)->shouldReceive('images')->once()->andReturn([['label' => 'Page 1', 'image' => 'receipt-image']]);
        $result = $this->response();
        $result['invoice_items'][0]['item_name'] = 'A4 Notebook';
        $result['notes'] = ['راجع كتابة اسم الصنف «A4 Notebook» في الأصل.'];
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['done' => true, 'message' => ['content' => json_encode($result)]])]);

        $draft = app(InvoiceCaptureService::class)->capture(UploadedFile::fake()->image('receipt.png'));
        $this->assertSame($result['notes'], $draft['notes']);
        Http::assertSentCount(1);
    }

    public static function invalidNoteTranslations(): array
    {
        return [
            'still Arabic in English mode' => ['en', ['notes' => ['الكمية 4 غير واضحة.']]],
            'still English in Arabic mode' => ['ar', ['notes' => ['Quantity 4 is unclear.']]],
            'mixed Arabic and English' => ['ar', ['notes' => ['الكمية 4 is unclear.']]],
            'changed number' => ['ar', ['notes' => ['الكمية 40 غير واضحة.']]],
            'lost number' => ['ar', ['notes' => ['الكمية غير واضحة.']]],
            'missing note' => ['ar', ['notes' => []]],
            'invalid JSON' => ['ar', '{'],
            'service failed' => ['ar', null],
        ];
    }

    #[DataProvider('invalidNoteTranslations')]
    public function test_failed_note_translation_uses_a_localized_message_and_keeps_the_invoice(string $locale, array|string|null $translation): void
    {
        app()->setLocale($locale);
        config(['invoice_capture.engine' => 'vision', 'invoice_capture.vision_url' => 'http://127.0.0.1:11434', 'invoice_capture.vision_model' => 'qwen3.5:9b']);
        $this->mock(InvoiceOcrService::class)->shouldReceive('images')->once()->andReturn([['label' => 'Page 1', 'image' => 'receipt-image']]);
        $result = $this->response();
        $result['notes'] = [$locale === 'ar' ? 'Quantity 4 is unclear.' : 'الكمية 4 غير واضحة.'];
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:11434/api/chat' => Http::sequence()
            ->push(['done' => true, 'message' => ['content' => json_encode($result)]])
            ->push(['done' => true, 'message' => ['content' => is_string($translation) ? $translation : json_encode($translation)]], $translation === null ? 503 : 200)]);

        $draft = app(InvoiceCaptureService::class)->capture(UploadedFile::fake()->image('receipt.png'));
        $this->assertSame([__('invoice_capture.notes_translation_unavailable')], $draft['notes']);
        $this->assertSame('HW-18', $draft['original_invoice_no']);
        $this->assertSame(2500, $draft['total']);
        $this->assertSame('1500', $draft['invoice_items'][0]['unit_price']);
    }

    public function test_missing_fields_are_not_replaced_by_guessed_defaults(): void
    {
        $result = $this->response();
        $result['currency'] = null;
        $result['invoice_deduction'] = null;
        $result['total'] = null;
        $result['invoice_items'][0]['quantity'] = null;
        $draft = app(InvoiceVisionDraft::class)->normalize($result);

        $this->assertNull($draft['currency']);
        $this->assertNull($draft['invoice_deduction']);
        $this->assertSame([], $draft['invoice_items']);
        $this->assertContains('missing_total', $draft['warnings']);
    }

    public function test_ambiguous_zero_counts_are_not_completed_using_arithmetic(): void
    {
        $result = $this->response();
        $result['total'] = 340000;
        $result['uncertain_fields'] = ['total'];
        $result['invoice_items'] = [
            ['item_name' => 'Ambiguous zeros', 'quantity' => 40, 'unit_price' => null, 'amount' => 340000, 'uncertain' => true],
            ['item_name' => 'Dropped zero', 'quantity' => 4, 'unit_price' => 8500, 'amount' => 340000, 'uncertain' => false],
            ['item_name' => 'Legible zeros', 'quantity' => 40, 'unit_price' => 10000, 'amount' => 400000, 'uncertain' => false],
        ];

        $draft = app(InvoiceVisionDraft::class)->normalize($result);

        $this->assertNull($draft['total']);
        $this->assertSame([['item_name' => 'Legible zeros', 'quantity' => '40', 'unit_price' => '10000']], $draft['invoice_items']);
        $this->assertContains('uncertain_fields', $draft['warnings']);
        $this->assertContains('unreadable_rows', $draft['warnings']);
        $this->assertSame('', $draft['review_items'][0]['quantity']);
        $this->assertSame('', $draft['review_items'][0]['unit_price']);
        $this->assertSame('Ambiguous zeros', $draft['review_items'][0]['item_name']);
    }

    public function test_public_endpoints_and_cloud_models_are_rejected_before_receipt_images_are_prepared(): void
    {
        $this->mock(InvoiceOcrService::class)->shouldNotReceive('images');
        Http::preventStrayRequests();
        foreach ([['https://ollama.com', 'qwen3.5:9b'], ['http://127.0.0.1:11434', 'qwen3.5:cloud']] as [$url, $model]) {
            config(['invoice_capture.engine' => 'vision', 'invoice_capture.vision_url' => $url, 'invoice_capture.vision_model' => $model]);
            try {
                app(InvoiceCaptureService::class)->capture(UploadedFile::fake()->image('receipt.png'));
                $this->fail('Cloud processing must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('invoice_capture.errors.local_only', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_malformed_or_truncated_model_output_is_rejected(): void
    {
        config(['invoice_capture.engine' => 'vision', 'invoice_capture.vision_url' => 'http://127.0.0.1:11434', 'invoice_capture.vision_model' => 'qwen3.5:9b']);
        $this->mock(InvoiceOcrService::class)->shouldReceive('images')->andReturn([['label' => 'Page 1: full page', 'image' => 'image']]);
        Http::fake(['127.0.0.1:11434/api/chat' => Http::sequence()
            ->push(['done' => true, 'done_reason' => 'length', 'message' => ['content' => '{']])
            ->push(['done' => true, 'done_reason' => 'stop', 'message' => ['content' => '{"original_invoice_no": "fake"}']])]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                app(InvoiceCaptureService::class)->capture(UploadedFile::fake()->image('receipt.png'));
                $this->fail('Invalid model output must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('invoice_capture.errors.invalid_response', $exception->getMessage());
            }
        }
    }

    private function response(): array
    {
        return [
            'original_invoice_no' => 'HW-18', 'invoice_issuer' => 'مكتبة النور', 'invoice_date' => '2026-09-06',
            'currency' => 'SYP', 'invoice_deduction' => 500, 'total' => 2500,
            'invoice_items' => [['item_name' => 'دفاتر مدرسية', 'quantity' => 2, 'unit_price' => 1500, 'amount' => 3000, 'uncertain' => false]],
            'uncertain_fields' => [], 'notes' => [],
        ];
    }
}
