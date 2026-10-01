<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSupportCase extends LandlordModel
{
    public const STATUS_FORWARDED = 'forwarded';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_WAITING_FOR_TENANT = 'waiting_for_tenant';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RELEASED = 'released';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'tenant_id', 'tenant_support_request_id', 'type', 'status', 'subject', 'message',
        'platform_note', 'platform_replied_at', 'forwarded_at',
    ];

    protected function casts(): array
    {
        return ['forwarded_at' => 'datetime', 'platform_replied_at' => 'datetime'];
    }

    public static function statusesForType(string $type): array
    {
        return match ($type) {
            'problem' => [
                self::STATUS_FORWARDED, self::STATUS_UNDER_REVIEW, self::STATUS_INVESTIGATING,
                self::STATUS_WAITING_FOR_TENANT, self::STATUS_RESOLVED, self::STATUS_CLOSED,
            ],
            'suggestion' => [
                self::STATUS_FORWARDED, self::STATUS_UNDER_REVIEW, self::STATUS_PLANNED,
                self::STATUS_IN_PROGRESS, self::STATUS_RELEASED, self::STATUS_DECLINED,
            ],
            default => [],
        };
    }

    public static function attentionStatuses(): array
    {
        return [self::STATUS_FORWARDED, self::STATUS_WAITING_FOR_TENANT];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_FORWARDED => 'New',
            self::STATUS_UNDER_REVIEW => 'Acknowledged',
            self::STATUS_INVESTIGATING => 'Investigating',
            self::STATUS_WAITING_FOR_TENANT => 'Waiting for tenant',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_PLANNED => 'Planned',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_RELEASED => 'Released',
            self::STATUS_DECLINED => 'Declined',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
