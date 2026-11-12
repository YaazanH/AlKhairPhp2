<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\FinanceCashBox;
use App\Models\FinanceCurrency;
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Models\User;
use App\Services\FinanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

class FinanceReportImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_saved_reports_resolve_and_embed_images_from_the_active_public_disk(): void
    {
        $this->seed();
        Storage::fake('public');
        Storage::fake('local');
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $background = 'finance/reports/backgrounds/tenant.png';
        $logo = 'finance/reports/logos/tenant.png';
        $stamp = 'finance/reports/stamps/tenant.png';
        $signature = 'users/signatures/tenant.png';
        foreach ([$background, $logo, $stamp, $signature] as $path) {
            Storage::disk('public')->put($path, $image);
        }
        $user = User::factory()->create(['finance_signature_path' => $signature]);
        $this->actingAs($user);
        AppSetting::storeValue('finance', 'report_stamp_path', $stamp);
        $service = app(FinanceReportService::class);
        $template = $service->defaultLedgerTemplate();
        $template->update(['background_image' => $background, 'logo_image' => $logo]);
        $report = $service->ledgerReport($template, FinanceCashBox::query()->firstOrFail(), FinanceCurrency::query()->firstOrFail(), '2026-01-01', '2026-01-31', $user);

        $this->assertSame($background, $report['template']['background_image_path']);
        $this->assertSame($this->imageUri($background), $report['template']['background_image_pdf_src']);
        $this->assertSame($this->imageUri($logo), $report['template']['logo_image_pdf_src']);
        $this->assertSame(Storage::disk('public')->url($logo), $template->logo_image_url);
        $this->assertSame(Storage::disk('public')->url($logo), (new FinanceReportTemplate(['logo_image' => 'https://old.example/storage/'.$logo]))->logo_image_url);
        $this->assertSame(Storage::disk('public')->path($signature), $report['issuer_signature_pdf_src']);
        $this->assertSame(Storage::disk('public')->path($stamp), $report['report_stamp_pdf_src']);

        $old = $report;
        unset($old['template']['background_image_path'], $old['template']['logo_image_path']);
        $old['template']['background_image_pdf_src'] = 'file:///old-server/storage/app/public/'.$background;
        $old['template']['background_image_url'] = 'https://old-tenant.example/storage/'.$background;
        $old['template']['logo_image_pdf_src'] = 'file:///old-server/storage/app/public/'.$logo;
        $old['pdf_renderer'] = 'mpdf-fixed-ledger-v32';
        $old['fund_reports'] = [$old];
        $generated = FinanceGeneratedReport::query()->create([
            'report_type' => 'ledger', 'filters' => [], 'report_data' => $old,
            'pdf_path' => 'reports/stale.pdf', 'generated_by' => $user->id,
        ]);
        Storage::disk('local')->put('reports/stale.pdf', '%PDF stale image paths');
        $reopened = $service->generatedLedgerReport($generated);

        $this->assertSame($this->imageUri($background), $reopened['template']['background_image_pdf_src']);
        $this->assertSame(Storage::disk('public')->url($background), $reopened['template']['background_image_url']);
        $this->assertSame($this->imageUri($logo), $reopened['template']['logo_image_pdf_src']);
        $this->assertSame(Storage::disk('public')->path($signature), $reopened['fund_reports'][0]['issuer_signature_pdf_src']);
        $this->assertSame(Storage::disk('public')->path($stamp), $reopened['fund_reports'][0]['report_stamp_pdf_src']);
        $this->assertEquals($report['closing_balance'], $reopened['closing_balance']);
        $this->assertSame($report['rows'], $reopened['rows']);

        $freshPdf = $service->renderLedgerPdf($report);
        $storedPath = $service->ensureStoredLedgerPdf($generated, $reopened);
        $savedPdf = Storage::disk('local')->get($storedPath);
        $this->assertStringStartsWith('%PDF', $freshPdf);
        $this->assertStringStartsWith('%PDF', $savedPdf);
        $this->assertGreaterThan(0, substr_count($freshPdf, '/Subtype /Image'));
        $this->assertGreaterThan(0, substr_count($savedPdf, '/Subtype /Image'));
        $this->assertSame('mpdf-fixed-ledger-v33-tenant-images', $generated->fresh()->report_data['pdf_renderer']);
    }

    public function test_old_asset_references_are_resolved_inside_the_active_disk_without_global_fallback(): void
    {
        Storage::fake('public');
        $path = 'finance/reports/logos/logo.png';
        Storage::disk('public')->put($path, 'tenant-owned image');
        $method = new ReflectionMethod(FinanceReportService::class, 'pdfAssetSource');
        $service = app(FinanceReportService::class);
        foreach ([$path, '/storage/'.$path, 'https://old.example/storage/'.$path, 'file:///old/storage/app/public/tenants/11111111-1111-1111-1111-111111111111/'.$path] as $reference) {
            $this->assertSame($this->imageUri($path), $method->invoke($service, $reference));
        }
        $this->assertNull($method->invoke($service, '/storage/finance/reports/missing.png'));
        $this->assertNull($method->invoke($service, '/storage/../other-tenant/logo.png'));
        $this->assertNull($method->invoke($service, 'file:///outside-the-public-disk/logo.png'));
    }

    private function imageUri(string $path): string
    {
        $absolute = str_replace('\\', '/', Storage::disk('public')->path($path));

        return 'file://'.(str_starts_with($absolute, '/') ? '' : '/').$absolute;
    }
}
