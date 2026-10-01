<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSubscriptionAllocation extends LandlordModel
{
    protected $fillable = [
        'payment_entry_id',
        'charge_entry_id',
        'amount_syp',
    ];

    protected function casts(): array
    {
        return [
            'amount_syp' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscriptionLedgerEntry::class, 'payment_entry_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscriptionLedgerEntry::class, 'charge_entry_id');
    }
}
