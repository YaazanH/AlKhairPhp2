<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TenantSupportRequest extends Model
{
    public const TYPE_PROBLEM = 'problem';

    public const TYPE_SUGGESTION = 'suggestion';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_FORWARDED = 'forwarded';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RELEASED = 'released';

    public const STATUS_DECLINED = 'declined';

    public const IMPACT_INDIVIDUAL = 'individual';

    public const IMPACT_SEVERAL_USERS = 'several_users';

    public const IMPACT_ALL_USERS = 'all_users';

    protected $fillable = [
        'type', 'priority', 'incident_reference', 'status', 'subject', 'message', 'expected_result', 'impact', 'reported_url', 'browser_info', 'app_version',
        'submitted_by_user_id', 'forwarded_by_user_id', 'forwarded_at', 'tenant_admin_note',
    ];

    protected function casts(): array
    {
        return ['forwarded_at' => 'datetime'];
    }

    public static function statusesForType(string $type): array
    {
        return match ($type) {
            self::TYPE_PROBLEM => [
                self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_FORWARDED,
                self::STATUS_RESOLVED, self::STATUS_CLOSED,
            ],
            self::TYPE_SUGGESTION => [
                self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_FORWARDED,
                self::STATUS_PLANNED, self::STATUS_IN_PROGRESS, self::STATUS_RELEASED,
                self::STATUS_DECLINED,
            ],
            default => [],
        };
    }

    public static function impactOptions(): array
    {
        return [
            self::IMPACT_INDIVIDUAL => 'Only me or one person',
            self::IMPACT_SEVERAL_USERS => 'A group or several people',
            self::IMPACT_ALL_USERS => 'Most or all users',
        ];
    }

    public static function newIncidentReference(): string
    {
        do {
            $reference = 'INC-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (self::query()->where('incident_reference', $reference)->exists());

        return $reference;
    }

    public static function attentionStatuses(): array
    {
        return [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_SUBMITTED => 'New',
            self::STATUS_UNDER_REVIEW => 'In review',
            self::STATUS_FORWARDED => 'Forwarded to Platform',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_CLOSED => 'Closed',
            self::STATUS_PLANNED => 'Planned',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_RELEASED => 'Released',
            self::STATUS_DECLINED => 'Declined',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenantSupportMessage::class)->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TenantSupportAttachment::class)->oldest();
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
