<?php

namespace App\Models;

use App\Services\PointLedgerService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saved(function (Course $course): void {
            if ($course->is_default && ($course->wasRecentlyCreated || $course->wasChanged('is_default'))) {
                static::query()->whereKeyNot($course->id)->update(['is_default' => false]);
            }

            if ($course->wasChanged('is_active') || $course->wasChanged('awards_points')) {
                app(PointLedgerService::class)->syncCourseEnrollmentCaches($course);
            }
        });

        static::deleted(fn (Course $course) => app(PointLedgerService::class)->syncCourseEnrollmentCaches($course));
        static::restored(fn (Course $course) => app(PointLedgerService::class)->syncCourseEnrollmentCaches($course));
    }

    protected $fillable = [
        'academic_year_id',
        'name',
        'description',
        'starts_on',
        'ends_on',
        'finished_at',
        'is_active',
        'is_default',
        'awards_points',
        'course_finished_was_awarding_points',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'finished_at' => 'datetime',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'awards_points' => 'boolean',
            'course_finished_was_awarding_points' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CourseSchedule::class);
    }

    public function calendarEntries(): HasMany
    {
        return $this->hasMany(CourseCalendarEntry::class)->orderBy('date')->orderBy('id');
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    public function pointMarketDepartments(): HasMany
    {
        return $this->hasMany(CoursePointMarketDepartment::class);
    }

    public function pointMarketInvoices(): HasMany
    {
        return $this->hasMany(CoursePointMarketInvoice::class);
    }

    public function pointMarketItems(): HasMany
    {
        return $this->hasMany(CoursePointMarketItem::class);
    }
}
