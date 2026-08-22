<?php

namespace App\Models;

use App\Services\CourseLifecycleService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'starts_on',
        'ends_on',
        'is_current',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $academicYear): void {
            if (! static::query()->where('is_current', true)->exists()) {
                $academicYear->is_current = true;
            }
        });

        static::saved(function (self $academicYear): void {
            if ($academicYear->is_current) {
                static::query()
                    ->whereKeyNot($academicYear->getKey())
                    ->update(['is_current' => false]);
            }

            if ($academicYear->wasChanged('is_active') && ! $academicYear->is_active) {
                $academicYear->courses()
                    ->each(fn (Course $course) => app(CourseLifecycleService::class)->finish($course));
            }
        });
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
