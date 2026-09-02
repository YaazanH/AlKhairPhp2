<?php

namespace App\Services;

use App\Models\DataQualityResolution;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Support\ArabicSearch;
use App\Support\PhoneNumberFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DataQualityService
{
    public function issues(): Collection
    {
        $resolutions = DataQualityResolution::query()->get()->keyBy('issue_key');

        return collect()
            ->concat($this->duplicateStudents())
            ->concat($this->duplicateParents())
            ->concat($this->overlappingEnrollments())
            ->concat($this->groupsOverCapacity())
            ->concat($this->missingParentContacts())
            ->map(function (array $issue) use ($resolutions): array {
                $resolution = $resolutions->get($issue['key']);
                $issue['status'] = $resolution?->status ?? 'open';
                $issue['resolution_notes'] = $resolution?->notes;
                $issue['resolved_at'] = $resolution?->resolved_at;

                return $issue;
            })
            ->sortBy(fn (array $issue): array => [
                ['high' => 0, 'medium' => 1, 'low' => 2][$issue['severity']] ?? 3,
                $issue['title'],
            ])
            ->values();
    }

    protected function duplicateStudents(): Collection
    {
        return Student::query()
            ->with('parentProfile:id,father_name')
            ->get()
            ->groupBy(fn (Student $student): string => $this->normaliseName($student->first_name.' '.$student->last_name).'|'.($student->birth_date?->format('Y-m-d') ?? ''))
            ->filter(fn (Collection $students, string $key): bool => ! str_ends_with($key, '|') && $students->count() > 1)
            ->flatMap(function (Collection $students): Collection {
                return $students->values()->crossJoin($students->values())
                    ->filter(fn (array $pair): bool => $pair[0]->id < $pair[1]->id)
                    ->map(function (array $pair): array {
                        [$first, $second] = $pair;
                        $labels = [
                            $first->first_name.' '.$first->last_name.' · '.$first->student_number,
                            $second->first_name.' '.$second->last_name.' · '.$second->student_number,
                        ];

                        return $this->issue(
                            'duplicate_student',
                            [$first->id, $second->id],
                            'high',
                            __('data_governance.quality.types.duplicate_student'),
                            __('data_governance.quality.reasons.same_student_identity'),
                            $labels,
                            'student',
                        );
                    });
            });
    }

    protected function duplicateParents(): Collection
    {
        return ParentProfile::query()
            ->get()
            ->groupBy(function (ParentProfile $parent): string {
                $phone = PhoneNumberFormatter::normalize($parent->father_phone ?: $parent->mother_phone ?: $parent->home_phone) ?? '';

                return $this->normaliseName($parent->father_name).'|'.$phone;
            })
            ->filter(fn (Collection $parents, string $key): bool => ! str_starts_with($key, '|') && ! str_ends_with($key, '|') && $parents->count() > 1)
            ->flatMap(function (Collection $parents): Collection {
                return $parents->values()->crossJoin($parents->values())
                    ->filter(fn (array $pair): bool => $pair[0]->id < $pair[1]->id)
                    ->map(fn (array $pair): array => $this->issue(
                        'duplicate_parent',
                        [$pair[0]->id, $pair[1]->id],
                        'high',
                        __('data_governance.quality.types.duplicate_parent'),
                        __('data_governance.quality.reasons.same_parent_identity'),
                        [$pair[0]->father_name.' · '.$pair[0]->parent_number, $pair[1]->father_name.' · '.$pair[1]->parent_number],
                        'parent',
                    ));
            });
    }

    protected function overlappingEnrollments(): Collection
    {
        return Enrollment::query()
            ->where('status', 'active')
            ->with(['student:id,first_name,last_name,student_number', 'group:id,course_id,name', 'group.course:id,name'])
            ->get()
            ->filter(fn (Enrollment $enrollment): bool => $enrollment->group?->course_id !== null)
            ->groupBy(fn (Enrollment $enrollment): string => $enrollment->student_id.'|'.$enrollment->group->course_id)
            ->filter(fn (Collection $enrollments): bool => $enrollments->count() > 1)
            ->map(function (Collection $enrollments): array {
                $first = $enrollments->first();

                return $this->issue(
                    'overlapping_enrollment',
                    $enrollments->pluck('id')->all(),
                    'high',
                    __('data_governance.quality.types.overlapping_enrollment'),
                    __('data_governance.quality.reasons.multiple_course_enrollments', ['course' => $first->group->course?->name]),
                    collect([$first->student?->first_name.' '.$first->student?->last_name])
                        ->concat($enrollments->map(fn (Enrollment $enrollment): string => $enrollment->group?->name ?? '—'))
                        ->all(),
                    'enrollment',
                );
            });
    }

    protected function groupsOverCapacity(): Collection
    {
        return Group::query()
            ->where('capacity', '>', 0)
            ->withCount(['enrollments as active_enrollments_count' => fn ($query) => $query->where('status', 'active')])
            ->with('course:id,name')
            ->get()
            ->filter(fn (Group $group): bool => $group->active_enrollments_count > $group->capacity)
            ->map(fn (Group $group): array => $this->issue(
                'group_over_capacity',
                [$group->id],
                'medium',
                __('data_governance.quality.types.group_over_capacity'),
                __('data_governance.quality.reasons.capacity_exceeded', [
                    'current' => $group->active_enrollments_count,
                    'capacity' => $group->capacity,
                ]),
                [$group->name, $group->course?->name ?? '—'],
                'group',
            ));
    }

    protected function missingParentContacts(): Collection
    {
        return ParentProfile::query()
            ->with('user:id,phone')
            ->get()
            ->filter(fn (ParentProfile $parent): bool => blank($parent->father_phone)
                && blank($parent->mother_phone)
                && blank($parent->home_phone)
                && blank($parent->user?->phone))
            ->map(fn (ParentProfile $parent): array => $this->issue(
                'missing_parent_contact',
                [$parent->id],
                'medium',
                __('data_governance.quality.types.missing_parent_contact'),
                __('data_governance.quality.reasons.no_contact_method'),
                [$parent->father_name, $parent->parent_number],
                'parent',
            ));
    }

    protected function issue(string $type, array $ids, string $severity, string $title, string $reason, array $records, string $entityType): array
    {
        sort($ids);

        return [
            'key' => hash('sha256', $type.':'.implode(',', $ids)),
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'reason' => $reason,
            'records' => array_values(array_filter($records, fn (mixed $value): bool => filled($value))),
            'entity_type' => $entityType,
            'entity_ids' => $ids,
        ];
    }

    protected function normaliseName(?string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim(ArabicSearch::normalize((string) $value))) ?? '');
    }
}
