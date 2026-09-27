<?php

namespace App\Support;

use App\Models;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class DataAuditVisibility
{
    public const ROUTINE_MODELS = [
        Models\MemorizationSession::class,
        Models\QuranFinalTest::class,
        Models\QuranPartialTest::class,
        Models\QuranTest::class,
        Models\PointTransaction::class,
        Models\AssessmentResult::class,
        Models\GroupAttendanceDay::class,
        Models\StudentAttendanceDay::class,
        Models\StudentAttendanceRecord::class,
        Models\TeacherAttendanceDay::class,
        Models\TeacherAttendanceRecord::class,
        Models\StudentPageAchievement::class,
        Models\GroupCurriculumLessonProgress::class,
        Models\GroupCurriculumTopicProgress::class,
    ];

    public static function hiddenTypes(string $event): array
    {
        return match ($event) {
            'created' => [
                Models\Student::class,
                Models\ParentProfile::class,
                Models\Enrollment::class,
                Models\FinanceTransaction::class,
                Models\FinanceRequest::class,
                Models\FinanceCurrencyExchange::class,
                Models\FinanceCashBoxTransfer::class,
                Models\Invoice::class,
                Models\InvoiceItem::class,
                Models\Payment::class,
                Models\ActivityExpense::class,
                Models\ActivityPayment::class,
                Models\CoursePointMarketInvoice::class,
                ...self::ROUTINE_MODELS,
            ],
            'updated' => [Models\Enrollment::class, ...self::ROUTINE_MODELS],
            default => [],
        };
    }

    public static function ignoredUpdateFields(string $type): array
    {
        return match ($type) {
            Models\Student::class => ['quran_current_juz_id'],
            Models\SystemBackup::class => ['sha256', 'size_bytes', 'manifest_summary', 'verified_at'],
            default => [],
        };
    }

    /** Keep substantive fields in mixed movements, including historical records. */
    public static function visibleEntries(array $entries, ?string $event): array
    {
        if ($event !== 'updated') {
            return $entries;
        }

        return collect($entries)->filter(fn ($entry): bool => is_array($entry))->map(function (array $entry): ?array {
            $ignored = [...self::ignoredUpdateFields($entry['subject_type'] ?? ''), 'updated_at'];
            $before = is_array($entry['before'] ?? null) ? $entry['before'] : [];
            $after = is_array($entry['after'] ?? null) ? $entry['after'] : [];
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
                if (in_array($field, $ignored, true)
                    || (array_key_exists($field, $before) && array_key_exists($field, $after)
                        && self::valuesAreEquivalent($before[$field], $after[$field]))) {
                    unset($before[$field], $after[$field]);
                }
            }
            $entry['before'] = $before;
            $entry['after'] = $after;

            return $before === [] && $after === [] ? null : $entry;
        })->filter()->values()->all();
    }

    public static function valuesAreEquivalent(mixed $before, mixed $after): bool
    {
        return self::canonicalValue($before) === self::canonicalValue($after);
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (is_string($value) && in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (is_array($value)) {
            // Object key order is formatting; list order and scalar types remain meaningful.
            if (! array_is_list($value)) {
                ksort($value);
            }
            $value = array_map(self::canonicalValue(...), $value);
        }

        return $value;
    }

    public static function apply(Builder $query): Builder
    {
        foreach (['created', 'updated'] as $event) {
            $query->where(fn (Builder $query) => $query
                ->where('event', '!=', $event)
                ->orWhereNull('event')
                ->orWhereNotIn('subject_type', self::hiddenTypes($event))
                ->orWhereNull('subject_type'));
        }

        // Inspect each update, preserving any substantive change in a mixed bundle.
        // This is a display filter; historical activity records are never deleted.
        $hiddenIds = (clone $query)->where('event', 'updated')
            ->select(['id', 'subject_type', 'properties'])
            ->cursor()->filter(function (Activity $activity): bool {
                $entries = $activity->getProperty('entries', []);
                if (! is_array($entries) || $entries === []) {
                    $entries = [[
                        'subject_type' => $activity->subject_type,
                        'before' => $activity->getProperty('before', []),
                        'after' => $activity->getProperty('after', []),
                    ]];
                }
                $entries = array_map(fn (array $entry): array => $entry + ['subject_type' => $activity->subject_type], array_filter($entries, 'is_array'));

                return self::visibleEntries($entries, 'updated') === [];
            })->pluck('id')->all();

        return $query->whereIntegerNotInRaw('id', $hiddenIds);
    }
}
