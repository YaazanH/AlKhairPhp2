<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'enrollment_id',
        'point_type_id',
        'policy_id',
        'source_type',
        'source_id',
        'points',
        'entered_by',
        'entered_at',
        'notes',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'entered_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function pointType(): BelongsTo
    {
        return $this->belongsTo(PointType::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PointPolicy::class, 'policy_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeEffectiveActive(Builder $query): Builder
    {
        return $query
            ->notVoided()
            ->whereHas('enrollment.group.course', fn (Builder $builder) => $builder->where('is_active', true));
    }

    public function scopeInactiveSource(Builder $query): Builder
    {
        return $query
            ->notVoided()
            ->where(function (Builder $builder) {
                $builder
                    ->whereHas('enrollment.group.course', fn (Builder $courseQuery) => $courseQuery->where('is_active', false))
                    ->orWhereDoesntHave('enrollment.group.course');
            });
    }

    public function effectiveState(): string
    {
        if ($this->voided_at) {
            return 'voided';
        }

        return $this->isEffectivelyActive() ? 'active' : 'inactive_source';
    }

    public function isEffectivelyActive(): bool
    {
        if ($this->voided_at) {
            return false;
        }

        if (
            $this->relationLoaded('enrollment')
            && $this->enrollment
            && $this->enrollment->relationLoaded('group')
            && $this->enrollment->group
            && $this->enrollment->group->relationLoaded('course')
        ) {
            return (bool) $this->enrollment->group->course?->is_active;
        }

        return $this->enrollment()
            ->whereHas('group.course', fn (Builder $builder) => $builder->where('is_active', true))
            ->exists();
    }
}
