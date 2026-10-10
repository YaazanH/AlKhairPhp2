<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionVoucherRedemption extends LandlordModel
{
    protected $fillable = [
        'subscription_voucher_id',
        'tenant_id',
        'tenant_subscription_id',
        'charge_entry_id',
        'original_price_syp',
        'discount_syp',
        'final_charge_syp',
    ];

    protected function casts(): array
    {
        return [
            'original_price_syp' => 'integer',
            'discount_syp' => 'integer',
            'final_charge_syp' => 'integer',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(SubscriptionVoucher::class, 'subscription_voucher_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscriptionLedgerEntry::class, 'charge_entry_id');
    }
}
