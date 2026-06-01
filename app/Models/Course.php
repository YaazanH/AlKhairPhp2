<?php

namespace App\Models;

use App\Services\PointLedgerService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saved(function (Course $course): void {
            if ($course->wasChanged('is_active')) {
                app(PointLedgerService::class)->syncCourseEnrollmentCaches($course);
            }
        });

        static::deleted(fn (Course $course) => app(PointLedgerService::class)->syncCourseEnrollmentCaches($course));
        static::restored(fn (Course $course) => app(PointLedgerService::class)->syncCourseEnrollmentCaches($course));
    }

    protected $fillable = [
        'name',
        'description',
        'starts_on',
        'ends_on',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
