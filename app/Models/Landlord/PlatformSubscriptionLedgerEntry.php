<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSubscriptionLedgerEntry extends LandlordModel
{
    public const TYPE_OFFLINE_PAYMENT = 'offline_payment';
    public const TYPE_RENEWAL = 'renewal';

    protected $fillable = ['uuid', 'tenant_id', 'tenant_subscription_id', 'credit_syp', 'debit_syp', 'type', 'reference', 'metadata', 'recorded_by_platform_administrator_id'];

    protected function casts(): array { return ['credit_syp' => 'integer', 'debit_syp' => 'integer', 'metadata' => 'array']; }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(PlatformAdministrator::class, 'recorded_by_platform_administrator_id'); }
}