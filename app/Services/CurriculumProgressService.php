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
            'curriculum.subjects.lessons.topics',
            'curriculumProgresses',
            'curriculumTopicProgresses',
            'customCurriculumLessons',
        ]);

        if (! $group->curriculum) {
            return ['total' => 0, 'completed' => 0.0, 'percentage' => 0.0];
        }

        $lessons = $group->curriculum->subjects->flatMap->lessons;
        $progress = $group->curriculumProgresses->keyBy('curriculum_lesson_id');
        $topicProgressIds = $group->curriculumTopicProgresses->pluck('curriculum_lesson_topic_id')->flip();
        $standardCompleted = $lessons->sum(function ($lesson) use ($progress, $topicProgressIds): float {
            if ($lesson->topics->isNotEmpty()) {
                return $lesson->topics->every(fn ($topic) => $topicProgressIds->has($topic->id)) ? 1.0 : 0.0;
            }

            return $this->statusWeight($progress->get($lesson->id)?->status);
        });
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
            'curriculum.subjects.lessons.resource',
            'curriculum.subjects.lessons.topics',
            'curriculumProgresses.teacher',
            'curriculumTopicProgresses.teacher',
            'customCurriculumLessons.teacher',
        ]);

        if (! $group->curriculum) {
            return collect();
        }
        $progress = $group->curriculumProgresses->keyBy('curriculum_lesson_id');
        $topicProgress = $group->curriculumTopicProgresses->keyBy('curriculum_lesson_topic_id');

        $subjects = $group->curriculum->subjects->map(function ($subject) use ($progress, $topicProgress) {
            $lessons = $subject->lessons->map(function ($lesson) use ($progress, $topicProgress) {
                $record = $progress->get($lesson->id);
                $topics = $lesson->topics->map(function ($topic) use ($topicProgress): array {
                    $topicRecord = $topicProgress->get($topic->id);

                    return [
                        'id' => $topic->id,
                        'name' => $topic->name,
                        'status' => $topicRecord ? 'taught' : 'untaught',
                        'taught_on' => $topicRecord?->taught_on,
                        'teacher' => $topicRecord?->teacher,
                    ];
                })->values();
                $hasTopics = $topics->isNotEmpty();
                $topicsComplete = $hasTopics && $topics->every(fn (array $topic) => $topic['status'] === 'taught');

                return [
                    'id' => $lesson->id,
                    'chapter_number' => $lesson->chapter_number,
                    'name' => $lesson->name,
                    'resource_id' => $lesson->curriculum_resource_id,
                    'resource_name' => $lesson->resource?->book_name,
                    'page_count' => $lesson->page_count,
                    'importance' => $lesson->importance,
                    'status' => $hasTopics ? ($topicsComplete ? 'taught' : 'untaught') : ($record?->status ?: 'untaught'),
                    'taught_on' => $hasTopics && ! $topicsComplete ? null : $record?->taught_on,
                    'teacher' => $hasTopics && ! $topicsComplete ? null : $record?->teacher,
                    'has_topics' => $hasTopics,
                    'topics' => $topics,
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
                'chapter_number' => null,
                'name' => $lesson->name,
                'resource_id' => null,
                'page_count' => $lesson->page_count,
                'importance' => $lesson->importance,
                'status' => $lesson->status,
                'taught_on' => $lesson->taught_on,
                'teacher' => $lesson->teacher,
                'has_topics' => false,
                'topics' => collect(),
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
        return match ($status) {
            'taught' => 1.0, 'partial' => 0.5, default => 0.0
        };
    }
}
