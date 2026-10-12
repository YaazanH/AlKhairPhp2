<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\FinanceCashBox;
use App\Models\FinanceCashBoxTransfer;
use App\Models\FinanceCurrency;
use App\Models\FinanceCurrencyExchange;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected FinanceCashBox $mainBox;

    protected FinanceCashBox $secondBox;

    protected FinanceCurrency $usd;

    protected FinanceCurrency $syp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->mainBox = FinanceCashBox::query()->where('code', 'main')->firstOrFail();
        $this->secondBox = FinanceCashBox::query()->create(['name' => 'Mobile Secondary', 'code' => 'mobile-secondary', 'is_active' => true]);
        $this->usd = FinanceCurrency::query()->where('code', 'USD')->firstOrFail();
        $this->syp = FinanceCurrency::query()->where('code', 'SYP')->firstOrFail();
        $this->mainBox->currencies()->syncWithoutDetaching([$this->usd->id, $this->syp->id]);
        $this->secondBox->currencies()->syncWithoutDetaching([$this->usd->id, $this->syp->id]);
        Sanctum::actingAs($this->manager);
    }

    public function test_manager_can_read_finance_summary_ledger_and_requests(): void
    {
        $transaction = $this->fund($this->mainBox, $this->usd, 500);
        $financeRequest = FinanceRequest::query()->create([
            'request_no' => 'REV-MOBILE-0001',
            'type' => FinanceRequest::TYPE_REVENUE,
            'status' => FinanceRequest::STATUS_PENDING,
            'requested_currency_id' => $this->usd->id,
            'requested_amount' => 40,
            'requested_by' => $this->manager->id,
            'requested_reason' => 'Mobile revenue request',
        ]);

        $this->getJson('/api/v1/finance/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.0.balance', 500)
            ->assertJsonPath('data.pending_requests.revenue', 1);
        $this->getJson('/api/v1/finance/cash-boxes')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/finance/transactions?type=income')
            ->assertOk()->assertJsonPath('data.0.id', $transaction->id)->assertJsonPath('data.0.signed_amount', 500);
        $this->getJson('/api/v1/finance/transactions/'.$transaction->id)
            ->assertOk()->assertJsonPath('data.transaction_no', $transaction->transaction_no);
        $this->getJson('/api/v1/finance/requests?type=revenue&status=pending')
            ->assertOk()->assertJsonPath('data.0.id', $financeRequest->id);
        $this->getJson('/api/v1/finance/requests/'.$financeRequest->id)
            ->assertOk()->assertJsonPath('data.type', FinanceRequest::TYPE_REVENUE);
    }

    public function test_manager_can_post_adjustment_and_disabled_setting_is_honoured(): void
    {
        $this->postJson('/api/v1/finance/adjustments', [
            'amount' => 125,
            'cash_box_id' => $this->mainBox->id,
            'currency_id' => $this->usd->id,
            'description' => 'Opening mobile balance',
            'direction' => 'in',
            'transaction_date' => '2026-10-12',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.signed_amount', 125);

        $this->assertDatabaseHas('finance_transactions', ['cash_box_id' => $this->mainBox->id, 'signed_amount' => 125]);

        AppSetting::storeValue('finance', 'cash_box_manual_adjustment_enabled', false, 'boolean');
        $this->postJson('/api/v1/finance/adjustments', [
            'amount' => 10,
            'cash_box_id' => $this->mainBox->id,
            'currency_id' => $this->usd->id,
            'description' => 'Disabled',
            'direction' => 'in',
        ])->assertNotFound();
    }

    public function test_manager_can_transfer_and_exchange_through_finance_service(): void
    {
        $this->fund($this->mainBox, $this->usd, 500);

        $this->postJson('/api/v1/finance/transfers', [
            'amount' => 100,
            'currency_id' => $this->usd->id,
            'from_cash_box_id' => $this->mainBox->id,
            'notes' => 'Mobile transfer',
            'to_cash_box_id' => $this->secondBox->id,
            'transfer_date' => '2026-10-12',
        ])->assertCreated()->assertJsonPath('data.amount', 100);

        $transfer = FinanceCashBoxTransfer::query()->firstOrFail();
        $this->assertSame(2, FinanceTransaction::query()->where('pair_uuid', $transfer->pair_uuid)->count());
        $this->getJson('/api/v1/finance/transfers')->assertOk()->assertJsonPath('data.0.id', $transfer->id);

        $this->postJson('/api/v1/finance/exchanges', [
            'exchange_date' => '2026-10-12',
            'from_amount' => 50,
            'from_cash_box_id' => $this->mainBox->id,
            'from_currency_id' => $this->usd->id,
            'notes' => 'Mobile exchange',
            'to_cash_box_id' => $this->secondBox->id,
            'to_currency_id' => $this->syp->id,
        ])->assertCreated()->assertJsonPath('data.from_amount', 50);

        $exchange = FinanceCurrencyExchange::query()->firstOrFail();
        $this->assertSame(2, FinanceTransaction::query()->where('pair_uuid', $exchange->pair_uuid)->count());
        $this->getJson('/api/v1/finance/exchanges')->assertOk()->assertJsonPath('data.0.id', $exchange->id);
    }

    public function test_finance_operations_require_matching_permissions(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/finance/summary')->assertForbidden();
        $this->getJson('/api/v1/finance/cash-boxes')->assertForbidden();
        $this->getJson('/api/v1/finance/transactions')->assertForbidden();
        $this->getJson('/api/v1/finance/transfers')->assertForbidden();
        $this->getJson('/api/v1/finance/exchanges')->assertForbidden();
        $this->getJson('/api/v1/finance/requests')->assertForbidden();
        $this->postJson('/api/v1/finance/adjustments', [])->assertForbidden();
        $this->postJson('/api/v1/finance/transfers', [])->assertForbidden();
        $this->postJson('/api/v1/finance/exchanges', [])->assertForbidden();
    }

    public function test_request_viewer_only_sees_own_requests_without_review_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('finance.revenue-requests.view');
        $own = FinanceRequest::query()->create([
            'request_no' => 'REV-MOBILE-OWN',
            'type' => FinanceRequest::TYPE_REVENUE,
            'status' => FinanceRequest::STATUS_PENDING,
            'requested_currency_id' => $this->usd->id,
            'requested_amount' => 10,
            'requested_by' => $viewer->id,
        ]);
        $other = FinanceRequest::query()->create([
            'request_no' => 'REV-MOBILE-OTHER',
            'type' => FinanceRequest::TYPE_REVENUE,
            'status' => FinanceRequest::STATUS_PENDING,
            'requested_currency_id' => $this->usd->id,
            'requested_amount' => 20,
            'requested_by' => $this->manager->id,
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/finance/requests?type=revenue')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/finance/requests/'.$own->id)->assertOk();
        $this->getJson('/api/v1/finance/requests/'.$other->id)->assertForbidden();
    }

    protected function fund(FinanceCashBox $cashBox, FinanceCurrency $currency, float $amount): FinanceTransaction
    {
        return app(FinanceService::class)->postTransaction([
            'amount' => $amount,
            'cash_box_id' => $cashBox->id,
            'currency_id' => $currency->id,
            'description' => 'Test opening balance',
            'direction' => 'in',
            'entered_by' => $this->manager->id,
            'transaction_date' => '2026-10-01',
            'type' => 'manual_adjustment',
        ]);
    }
}
