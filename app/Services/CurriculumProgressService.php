<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Support\Collection;

class CurriculumProgressService
{
    public function summary(Group $group): array
    {
        $group->loadMissing([
            'curriculum.subjects.definition',
            'curriculum.subjects.lessons',
            'curriculumProgresses',
            'customCurriculumLessons',
        ]);

        if (! $group->curriculum) return ['total' => 0, 'completed' => 0.0, 'percentage' => 0.0];

        $lessons = $group->curriculum->subjects->flatMap->lessons;
        $progress = $group->curriculumProgresses->keyBy('curriculum_lesson_id');
        $standardCompleted = $lessons->sum(fn ($lesson) => $this->statusWeight($progress->get($lesson->id)?->status));
        $custom = $group->customCurriculumLessons;
        $completed = $standardCompleted + $custom->sum(fn ($lesson) => $this->statusWeight($lesson->status));
        $total = $lessons->count() + $custom->count();

        return [
            'total' => $total,
            'completed' => round($completed, 1),
            'percentage' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
        ];
    }

    public function subjects(Group $group): Collection
    {
        $group->loadMissing([
            'curriculum.subjects.definition',
            'curriculum.subjects.resources',
            'curriculum.subjects.lessons',
            'curriculumProgresses.teacher',
            'customCurriculumLessons.teacher',
        ]);

        if (! $group->curriculum) return collect();
        $progress = $group->curriculumProgresses->keyBy('curriculum_lesson_id');

        $subjects = $group->curriculum->subjects->map(function ($subject) use ($progress) {
            $lessons = $subject->lessons->map(function ($lesson) use ($progress) {
                $record = $progress->get($lesson->id);
                return [
                    'id' => $lesson->id,
                    'name' => $lesson->name,
                    'page_count' => $lesson->page_count,
                    'importance' => $lesson->importance,
                    'status' => $record?->status ?: 'untaught',
                    'taught_on' => $record?->taught_on,
                    'teacher' => $record?->teacher,
                    'custom' => false,
                ];
            });
            $completed = $lessons->sum(fn (array $lesson) => $this->statusWeight($lesson['status']));
            return [
                'id' => $subject->id,
                'name' => $subject->definition?->name,
                'resources' => $subject->resources,
                'lessons' => $lessons,
                'percentage' => $lessons->count() ? round(($completed / $lessons->count()) * 100, 1) : 0,
            ];
        });

        $customBySubject = $group->customCurriculumLessons->groupBy('subject_name');
        foreach ($customBySubject as $subjectName => $lessons) {
            $rows = $lessons->map(fn ($lesson) => [
                'id' => $lesson->id,
                'name' => $lesson->name,
                'page_count' => $lesson->page_count,
                'importance' => $lesson->importance,
                'status' => $lesson->status,
                'taught_on' => $lesson->taught_on,
                'teacher' => $lesson->teacher,
                'custom' => true,
            ])->values();
            $completed = $rows->sum(fn (array $lesson) => $this->statusWeight($lesson['status']));
            $subjects->push([
                'id' => 'custom-'.md5((string) $subjectName),
                'name' => $subjectName,
                'resources' => collect(),
                'lessons' => $rows,
                'percentage' => $rows->count() ? round(($completed / $rows->count()) * 100, 1) : 0,
            ]);
        }

        return $subjects->values();
    }

    public function statusWeight(?string $status): float
    {
        return match ($status) { 'taught' => 1.0, 'partial' => 0.5, default => 0.0 };
    }
}
